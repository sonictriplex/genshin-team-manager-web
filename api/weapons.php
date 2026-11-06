<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

try {
    // Holt alle Daten aus der Tabelle weapons
    $stmt = $pdo->query("SELECT * FROM weapons ORDER BY name ASC");
    $weaponsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Wandelt das Spaltenfeld ab- und fehlersicher um
    $weapons = array_map(function($w) {
        return [
            'id'          => $w['id'] ?? null,
            'name'        => $w['name'] ?? '',
            'weapon_type' => $w['weapon_type'] ?? $w['type'] ?? '',
            'rarity'      => isset($w['rarity']) ? (int)$w['rarity'] : 4
        ];
    }, $weaponsRaw);

    echo json_encode($weapons, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log("weapons.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "error" => "Fehler beim Laden der Waffen."
    ], JSON_UNESCAPED_UNICODE);
}
?>
