<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

try {
    $stmt = $pdo->query("SELECT * FROM characters ORDER BY name ASC");
    $charactersRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $characters = array_map(function($c) {
        return [
            'id'          => $c['id'] ?? null,
            'name'        => $c['name'] ?? '',
            'element'     => $c['element'] ?? 'Anemo',
            'weapon_type' => $c['weapon_type'] ?? $c['type'] ?? 'Einhandschwert',
            'region'      => $c['region'] ?? 'Unbekannt',
            'rarity'      => isset($c['rarity']) ? (int)$c['rarity'] : 4,
                            'icon_path'   => $c['icon_path'] ?? null
        ];
    }, $charactersRaw);

    echo json_encode($characters, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log("characters.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "error" => "Fehler beim Laden der Charaktere."
    ], JSON_UNESCAPED_UNICODE);
}
?>
