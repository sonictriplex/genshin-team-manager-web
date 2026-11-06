<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_check.php';

$action = $_GET['action'] ?? '';

// 1. Status prüfen (Wer bin ich?)
if ($action === 'status') {
    if (isset($_SESSION['user_id'])) {
        echo json_encode([
            "logged_in" => true,
            "username"  => $_SESSION['username'],
            "user_id"   => $_SESSION['user_id']
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(["logged_in" => false], JSON_UNESCAPED_UNICODE);
    }
    exit();
}

$data = json_decode(file_get_contents("php://input"), true) ?? [];

// 2. Registrierung
if ($action === 'register') {
    $username = trim($data['username'] ?? '');
    $password = $data['password'] ?? '';

    if (strlen($username) < 3 || strlen($password) < 6) {
        http_response_code(400);
        echo json_encode(["error" => "Benutzername (min. 3 Zeichen) oder Passwort (min. 6 Zeichen) zu kurz."], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
        $stmt->execute([$username, $hash]);

        // Neue Session-ID gegen Session-Fixation
        session_regenerate_id(true);

        $_SESSION['user_id'] = (int)$pdo->lastInsertId();
        $_SESSION['username'] = $username;

        echo json_encode(["success" => true, "username" => $username], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) { // Duplicate Entry
            http_response_code(409);
            echo json_encode(["error" => "Dieser Benutzername ist bereits vergeben."], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            error_log("auth.php register: " . $e->getMessage());
            echo json_encode(["error" => "Registrierung fehlgeschlagen (Datenbankfehler)."], JSON_UNESCAPED_UNICODE);
        }
    }
    exit();
}

// 3. Login
if ($action === 'login') {
    $username = trim($data['username'] ?? '');
    $password = $data['password'] ?? '';

    $stmt = $pdo->prepare("SELECT id, username, password_hash FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        // Neue Session-ID gegen Session-Fixation
        session_regenerate_id(true);

        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];

        echo json_encode(["success" => true, "username" => $user['username']], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(401);
        echo json_encode(["error" => "Benutzername oder Passwort falsch."], JSON_UNESCAPED_UNICODE);
    }
    exit();
}

// 4. Logout
if ($action === 'logout') {
    // Nur per POST, damit ein Logout nicht über einen fremden Link/Bild ausgelöst werden kann
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(["error" => "Logout nur per POST erlaubt."], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Session-Daten leeren, Cookie löschen, Session zerstören
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
                  'path'     => $p['path'],
                  'domain'   => $p['domain'],
                  'secure'   => $p['secure'],
                  'httponly' => $p['httponly'],
                  'samesite' => $p['samesite'] ?: 'Lax'
        ]);
    }
    session_destroy();

    echo json_encode(["success" => true], JSON_UNESCAPED_UNICODE);
    exit();
}
?>
