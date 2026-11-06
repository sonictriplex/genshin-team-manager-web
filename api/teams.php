<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_check.php';

// Erzwingt Session-Authentifizierung und liefert die ID des Nutzers
$user_id = require_login();
$method = $_SERVER['REQUEST_METHOD'];

/** Begrenzt einen Zahlenwert auf einen Bereich (Fallback bei ungültiger Eingabe). */
function clamp_int($value, int $min, int $max, int $default): int {
    if (!is_numeric($value)) return $default;
    return max($min, min($max, (int)$value));
}

/** Platzhaltertexte wie "— Zweites 2er-Set (optional) —" werden zu einem leeren Wert. */
function clean_set($value): string {
    $v = trim((string)$value);
    if ($v === '' || mb_strpos($v, '—') === 0) return '';
    return $v;
}

/**
 * Bringt artifact_stats in ein sauberes, immer gleiches Format:
 * - Rückgabe ist ein assoziatives Array (Slot => Daten) oder leer
 * - jeder Slot hat genau 4 Substats der Form {stat, val}
 */
function normalize_artifact_stats($stats): array {
    if (!is_array($stats)) return [];

    $clean = [];
    foreach ($stats as $slotName => $slot) {
        if (!is_string($slotName) || !is_array($slot)) continue;

        $subsIn = (isset($slot['substats']) && is_array($slot['substats']))
        ? array_values($slot['substats'])
        : [];

        $subs = [];
        for ($i = 0; $i < 4; $i++) {
            $e = $subsIn[$i] ?? [];

            // Altformat: "KT% + 3.9"
            if (is_string($e)) {
                $parts = explode('+', $e, 2);
                $e = ['stat' => trim($parts[0]), 'val' => trim($parts[1] ?? '')];
            }
            if (!is_array($e)) $e = [];

            $subs[] = [
                'stat' => (string)($e['stat'] ?? ''),
                'val'  => (string)($e['val'] ?? '')
            ];
        }

        $slot['substats'] = $subs;
        $clean[$slotName] = $slot;
    }
    return $clean;
}

if ($method === 'GET') {
    try {
        // Teams des aktuell eingeloggten Benutzers abfragen
        $stmtTeams = $pdo->prepare("SELECT id, name FROM teams WHERE user_id = ? ORDER BY id ASC");
        $stmtTeams->execute([$user_id]);
        $teams = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);

        $result = new stdClass();

        foreach ($teams as $team) {
            $teamName = $team['name'];

            // Mitglieder des Teams mit den User-Builds DIESES Benutzers verknüpfen
            $stmtMembers = $pdo->prepare("
            SELECT
            tm.char_name,
            uc.level, uc.constellation, uc.weapon, uc.weapon_level, uc.refinement,
            uc.artifact, uc.artifact_2, uc.talent_attack, uc.talent_skill, uc.talent_burst,
            uc.artifact_stats_json,
            c.element, c.weapon_type, c.region, c.rarity, c.icon_path
            FROM team_members tm
            LEFT JOIN characters c ON tm.char_name = c.name
            LEFT JOIN user_characters uc ON (tm.char_name = uc.char_name AND uc.user_id = ?)
            WHERE tm.team_id = ?
            ORDER BY tm.slot_order ASC
            ");
            $stmtMembers->execute([$user_id, $team['id']]);
            $members = $stmtMembers->fetchAll(PDO::FETCH_ASSOC);

            $teamMembers = [];
            foreach ($members as $m) {
                // WICHTIG: Ein leeres PHP-Array würde als [] ausgegeben werden.
                // Das Frontend braucht aber immer ein Objekt {}.
                $stats = !empty($m['artifact_stats_json']) ? json_decode($m['artifact_stats_json'], true) : null;
                if (!is_array($stats) || count($stats) === 0) {
                    $stats = new stdClass();
                }

                $teamMembers[] = [
                    "character" => [
                        "name"        => $m['char_name'],
                        "element"     => $m['element'] ?? 'Anemo',
                        "weapon_type" => $m['weapon_type'] ?? 'Einhandschwert',
                        "region"      => $m['region'] ?? 'Unbekannt',
                        "rarity"      => (int)($m['rarity'] ?? 4),
                        "icon_path"   => $m['icon_path'] ?? null
                    ],
                    "saved" => [
                        "name"           => $m['char_name'],
                        "level"          => (int)($m['level'] ?? 90),
                        "constellation"  => (int)($m['constellation'] ?? 0),
                        "weapon"         => $m['weapon'] ?? '',
                        "weapon_level"   => (int)($m['weapon_level'] ?? 90),
                        "refinement"     => (int)($m['refinement'] ?? 1),
                        "artifact"       => $m['artifact'] ?? '',
                        "artifact_2"     => $m['artifact_2'] ?? '',
                        "artifact_stats" => $stats,
                        "talents"        => [
                            "attack" => (int)($m['talent_attack'] ?? 1),
                            "skill"  => (int)($m['talent_skill'] ?? 1),
                            "burst"  => (int)($m['talent_burst'] ?? 1)
                        ]
                    ]
                ];
            }
            $result->$teamName = $teamMembers;
        }

        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit();

    } catch (Exception $e) {
        http_response_code(500);
        error_log("teams.php GET: " . $e->getMessage());
        echo json_encode(["error" => "Fehler beim Laden der Teams."], JSON_UNESCAPED_UNICODE);
        exit();
    }

} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $teamName = trim($data['name'] ?? '');
    $members = is_array($data['members'] ?? null) ? $data['members'] : [];

    if (empty($teamName)) {
        http_response_code(400);
        echo json_encode(["error" => "Teamname darf nicht leer sein."], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if (mb_strlen($teamName) > 255) {
        http_response_code(400);
        echo json_encode(["error" => "Teamname ist zu lang."], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if (count($members) > 4) {
        http_response_code(400);
        echo json_encode(["error" => "Ein Team darf maximal 4 Charaktere enthalten."], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $pdo->beginTransaction();
    try {
        // Team für diesen Nutzer anlegen oder das vorhandene verwenden – in EINEM Statement.
        // Dank UNIQUE (user_id, name) gibt es auch bei parallelen Requests (Doppelklick)
        // nie zwei gleiche Teams. LAST_INSERT_ID(id) liefert in beiden Fällen die Team-ID.
        // Die Zeile ist danach bis zum Commit gesperrt, ein paralleler Request wartet.
        $stmtTeam = $pdo->prepare("
        INSERT INTO teams (user_id, name) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
        ");
        $stmtTeam->execute([$user_id, $teamName]);
        $teamId = (int)$pdo->lastInsertId();

        // Bisherige Mitglieder entfernen (bei einem neuen Team gibt es keine)
        $stmtDel = $pdo->prepare("DELETE FROM team_members WHERE team_id = ?");
        $stmtDel->execute([$teamId]);

        $seenChars = [];
        $slot = 0;

        // Charaktere und Builds für diesen Nutzer speichern
        foreach ($members as $m) {
            if (!is_array($m)) continue;

            $charName = trim($m['name'] ?? '');
            if ($charName === '') continue;

            // Doppelte Charaktere im selben Team überspringen
            if (isset($seenChars[$charName])) continue;
            $seenChars[$charName] = true;

            // Artefaktwerte normalisieren (immer Objekt, immer 4 Substats als {stat, val})
            $cleanStats = normalize_artifact_stats($m['artifact_stats'] ?? []);
            $statsJson = json_encode(
                count($cleanStats) > 0 ? $cleanStats : new stdClass(),
                                     JSON_UNESCAPED_UNICODE
            );

            $talents = is_array($m['talents'] ?? null) ? $m['talents'] : [];

            // 1. Charakter-Build in user_characters (user_id + char_name) aktualisieren
            $stmtChar = $pdo->prepare("
            INSERT INTO user_characters (
                user_id, char_name, level, constellation, weapon, weapon_level, refinement,
                artifact, artifact_2, talent_attack, talent_skill, talent_burst, artifact_stats_json
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            level=VALUES(level), constellation=VALUES(constellation), weapon=VALUES(weapon),
                                      weapon_level=VALUES(weapon_level), refinement=VALUES(refinement), artifact=VALUES(artifact),
                                      artifact_2=VALUES(artifact_2), talent_attack=VALUES(talent_attack), talent_skill=VALUES(talent_skill),
                                      talent_burst=VALUES(talent_burst), artifact_stats_json=VALUES(artifact_stats_json)
            ");
            $stmtChar->execute([
                $user_id,
                $charName,
                clamp_int($m['level'] ?? 90, 1, 90, 90),
                               clamp_int($m['constellation'] ?? 0, 0, 6, 0),
                               (string)($m['weapon'] ?? ''),
                               clamp_int($m['weapon_level'] ?? 90, 1, 90, 90),
                               clamp_int($m['refinement'] ?? 1, 1, 5, 1),
                               clean_set($m['artifact'] ?? ''),
                               clean_set($m['artifact_2'] ?? ''),
                               clamp_int($talents['attack'] ?? 1, 1, 13, 1),
                               clamp_int($talents['skill'] ?? 1, 1, 13, 1),
                               clamp_int($talents['burst'] ?? 1, 1, 13, 1),
                               $statsJson
            ]);

            // 2. Mitglied dem Team zuordnen
            $stmtMember = $pdo->prepare("INSERT INTO team_members (team_id, char_name, slot_order) VALUES (?, ?, ?)");
            $stmtMember->execute([$teamId, $charName, $slot]);
            $slot++;
        }

        $pdo->commit();
        echo json_encode(["message" => "Team '$teamName' erfolgreich gespeichert!"], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        error_log("teams.php POST: " . $e->getMessage());
        echo json_encode(["error" => "Fehler beim Speichern des Teams."], JSON_UNESCAPED_UNICODE);
    }
    exit();

} elseif ($method === 'DELETE') {
    $teamName = trim($_GET['name'] ?? '');

    if (empty($teamName)) {
        http_response_code(400);
        echo json_encode(["error" => "Teamname zum Löschen fehlt."], JSON_UNESCAPED_UNICODE);
        exit();
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM teams WHERE user_id = ? AND name = ?");
        $stmt->execute([$user_id, $teamName]);

        echo json_encode(["message" => "Team '$teamName' wurde erfolgreich gelöscht!"], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(500);
        error_log("teams.php DELETE: " . $e->getMessage());
        echo json_encode(["error" => "Fehler beim Löschen des Teams."], JSON_UNESCAPED_UNICODE);
    }
    exit();
}
