<?php
header("Content-Type: application/json; charset=UTF-8");

// Zugangsdaten: Umgebungsvariablen haben Vorrang, sonst api/config.local.php
$configFile = __DIR__ . '/config.local.php';
$config = is_file($configFile) ? (require $configFile) : [];
if (!is_array($config)) $config = [];

$cfg = function (string $key, string $env, string $default = '') use ($config): string {
    $v = getenv($env);
    if ($v !== false && $v !== '') return $v;
    return (string)($config[$key] ?? $default);
};

$host    = $cfg('host', 'DB_HOST', 'localhost');
$db      = $cfg('name', 'DB_NAME', 'genshin-manager');
$user    = $cfg('user', 'DB_USER');
$pass    = $cfg('pass', 'DB_PASS');
$charset = $cfg('charset', 'DB_CHARSET', 'utf8mb4');

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    error_log("DB-Verbindung fehlgeschlagen: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Datenbankverbindung fehlgeschlagen."], JSON_UNESCAPED_UNICODE);
    exit();
}
