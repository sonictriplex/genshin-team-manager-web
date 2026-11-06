<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

try {
    $stmt = $pdo->query("SELECT id, name, bonus_2pc, bonus_4pc FROM artifacts ORDER BY name ASC");
    $artifacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($artifacts, JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    error_log("artifacts.php: " . $e->getMessage());
    echo json_encode(["error" => "Fehler beim Laden der Artefakte."], JSON_UNESCAPED_UNICODE);
}
?>
