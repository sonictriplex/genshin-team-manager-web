<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/helpers.php';

// Import/Export von Builds und Teams des eingeloggten Nutzers.
// Das Format ist identisch mit der Desktop-Version (Menü "Datei"):
// {format, version, exported_at, builds: [...], teams: [{name, members: [...]}]}
const EXCHANGE_FORMAT = 'genshin-team-manager';
const EXCHANGE_VERSION = 1;

$user_id = require_login();
$method = $_SERVER['REQUEST_METHOD'];

function fail(int $status, string $message): void {
    http_response_code($status);
    echo json_encode(["error" => $message], JSON_UNESCAPED_UNICODE);
    exit();
}

if ($method === 'GET') {
    try {
        $stmt = $pdo->prepare("
            SELECT char_name, level, constellation, weapon, weapon_level, refinement,
                   artifact, artifact_2, talent_attack, talent_skill, talent_burst, artifact_stats_json
            FROM user_characters WHERE user_id = ? ORDER BY char_name ASC
        ");
        $stmt->execute([$user_id]);

        $builds = [];
        foreach ($stmt->fetchAll() as $r) {
            $stats = !empty($r['artifact_stats_json']) ? json_decode($r['artifact_stats_json'], true) : null;
            $stats = normalize_artifact_stats($stats);
            $builds[] = [
                "name"           => $r['char_name'],
                "level"          => (int)$r['level'],
                "constellation"  => (int)$r['constellation'],
                "weapon"         => $r['weapon'] ?? '',
                "weapon_level"   => (int)$r['weapon_level'],
                "refinement"     => (int)$r['refinement'],
                "artifact"       => $r['artifact'] ?? '',
                "artifact_2"     => $r['artifact_2'] ?? '',
                "artifact_stats" => count($stats) > 0 ? $stats : new stdClass(),
                "talents"        => [
                    "attack" => (int)$r['talent_attack'],
                    "skill"  => (int)$r['talent_skill'],
                    "burst"  => (int)$r['talent_burst'],
                ],
            ];
        }

        $stmtTeams = $pdo->prepare("SELECT id, name FROM teams WHERE user_id = ? ORDER BY id ASC");
        $stmtTeams->execute([$user_id]);
        $stmtMembers = $pdo->prepare("SELECT char_name FROM team_members WHERE team_id = ? ORDER BY slot_order ASC");

        $teams = [];
        foreach ($stmtTeams->fetchAll() as $t) {
            $stmtMembers->execute([$t['id']]);
            $teams[] = ["name" => $t['name'], "members" => $stmtMembers->fetchAll(PDO::FETCH_COLUMN)];
        }

        echo json_encode([
            "format"      => EXCHANGE_FORMAT,
            "version"     => EXCHANGE_VERSION,
            "exported_at" => date('Y-m-d\TH:i:s'),
            "builds"      => $builds,
            "teams"       => $teams,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        error_log("transfer.php GET: " . $e->getMessage());
        fail(500, "Fehler beim Exportieren.");
    }
    exit();
}

if ($method !== 'POST') {
    fail(405, "Methode nicht erlaubt.");
}

$input = json_decode(file_get_contents('php://input'), true);
$data = is_array($input) ? ($input['data'] ?? null) : null;
$replace = is_array($input) && ($input['mode'] ?? '') === 'replace';

if (!is_array($data) || ($data['format'] ?? '') !== EXCHANGE_FORMAT) {
    fail(400, "Die Datei ist keine Export-Datei des Genshin Team-Managers.");
}
if (!is_int($data['version'] ?? null) || $data['version'] > EXCHANGE_VERSION) {
    fail(400, "Die Datei stammt aus einer neueren Version und kann nicht gelesen werden.");
}
$builds = is_array($data['builds'] ?? null) ? $data['builds'] : [];
$teams = is_array($data['teams'] ?? null) ? $data['teams'] : [];

$pdo->beginTransaction();
try {
    $known = array_flip($pdo->query("SELECT name FROM characters")->fetchAll(PDO::FETCH_COLUMN));
    $skipped = [];
    $countBuilds = 0;
    $countTeams = 0;

    if ($replace) {
        $pdo->prepare("DELETE FROM teams WHERE user_id = ?")->execute([$user_id]);
        $pdo->prepare("DELETE FROM user_characters WHERE user_id = ?")->execute([$user_id]);
    }

    $stmtBuild = $pdo->prepare("
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

    foreach ($builds as $b) {
        $name = is_array($b) ? trim((string)($b['name'] ?? '')) : '';
        if (!isset($known[$name])) {
            $skipped[$name === '' ? '(ohne Namen)' : $name] = true;
            continue;
        }
        $talents = is_array($b['talents'] ?? null) ? $b['talents'] : [];
        $stats = normalize_artifact_stats($b['artifact_stats'] ?? []);

        $stmtBuild->execute([
            $user_id, $name,
            clamp_int($b['level'] ?? 90, 1, 90, 90),
            clamp_int($b['constellation'] ?? 0, 0, 6, 0),
            (string)($b['weapon'] ?? ''),
            clamp_int($b['weapon_level'] ?? 90, 1, 90, 90),
            clamp_int($b['refinement'] ?? 1, 1, 5, 1),
            clean_set($b['artifact'] ?? ''),
            clean_set($b['artifact_2'] ?? ''),
            clamp_int($talents['attack'] ?? 1, 1, 13, 1),
            clamp_int($talents['skill'] ?? 1, 1, 13, 1),
            clamp_int($talents['burst'] ?? 1, 1, 13, 1),
            json_encode(count($stats) > 0 ? $stats : new stdClass(), JSON_UNESCAPED_UNICODE),
        ]);
        $countBuilds++;
    }

    $stmtTeam = $pdo->prepare("
        INSERT INTO teams (user_id, name) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
    ");
    $stmtDel = $pdo->prepare("DELETE FROM team_members WHERE team_id = ?");
    $stmtMember = $pdo->prepare("INSERT INTO team_members (team_id, char_name, slot_order) VALUES (?, ?, ?)");

    foreach ($teams as $t) {
        $teamName = is_array($t) ? trim((string)($t['name'] ?? '')) : '';
        if ($teamName === '' || mb_strlen($teamName) > 255) continue;

        $members = [];
        foreach (is_array($t['members'] ?? null) ? $t['members'] : [] as $m) {
            $m = is_string($m) ? trim($m) : '';
            if (!isset($known[$m])) {
                if ($m !== '') $skipped[$m] = true;
                continue;
            }
            if (!in_array($m, $members, true)) $members[] = $m;
        }

        $stmtTeam->execute([$user_id, $teamName]);
        $teamId = (int)$pdo->lastInsertId();
        $stmtDel->execute([$teamId]);
        foreach (array_slice($members, 0, 4) as $slot => $m) {
            $stmtMember->execute([$teamId, $m, $slot]);
        }
        $countTeams++;
    }

    $pdo->commit();
    $skipped = array_map('strval', array_keys($skipped));
    sort($skipped);
    echo json_encode(["builds" => $countBuilds, "teams" => $countTeams, "skipped" => $skipped], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("transfer.php POST: " . $e->getMessage());
    fail(500, "Import fehlgeschlagen, es wurde nichts verändert.");
}
