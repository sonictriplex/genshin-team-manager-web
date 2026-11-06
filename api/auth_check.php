<?php
// api/auth_check.php
if (session_status() === PHP_SESSION_NONE) {
    // Secure-Flag nur setzen, wenn die Verbindung wirklich über HTTPS läuft
    // (auch hinter einem Reverse-Proxy wie nginx erkennbar)
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 86400 * 7, // Session 7 Tage gültig
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

/**
 * Prüft ob der Nutzer eingeloggt ist.
 * Gibt die user_id zurück oder bricht mit 401 Unauthorized ab.
 */
function require_login() {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(["error" => "Nicht eingeloggt"], JSON_UNESCAPED_UNICODE);
        exit();
    }
    return (int)$_SESSION['user_id'];
}
?>
