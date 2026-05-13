<?php
/**
 * install_db.php – Tengeneza database na tables zote + seeds
 *
 * Inafanya nini:
 *   1. Inaunda database iramba_rms kama haipo
 *   2. Inatekeleza database.sql (tables zote + seeds za msingi)
 *
 * Jinsi ya kuendesha:
 *   php sql/install_db.php
 *   AU fungua: http://localhost/district-results-system/sql/install_db.php
 *
 * Akaunti itakayoundwa:
 *   Super Admin: username=super  pass=Admin@123
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

// ── Load config ────────────────────────────────────────────────────────
$env_path = __DIR__ . '/../.env';
if (file_exists($env_path)) {
    foreach (file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($val);
    }
}

$host = $_ENV['DB_HOST'] ?? 'localhost';
$port = $_ENV['DB_PORT'] ?? '3306';
$name = $_ENV['DB_NAME'] ?? 'iramba_rms';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';

$sql_file = __DIR__ . '/database.sql';

if (!file_exists($sql_file)) {
    echo "KOSA: Faili haipo: $sql_file\n";
    exit(1);
}

echo "=== IRAMBA RMS – Database Install ===\n\n";
echo "Host  : $host:$port\n";
echo "DB    : $name\n";
echo "Faili : $sql_file\n\n";

// ── Connect (bila DB kuchagua – tutaunda kwanza) ───────────────────────
try {
    $dsn = "mysql:host=$host;port=$port;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "KOSA la connection: " . $e->getMessage() . "\n";
    echo "Angalia .env – DB_HOST, DB_USER, DB_PASS\n";
    exit(1);
}

// ── Tekeleza SQL statement kwa statement ──────────────────────────────
$sql_content = file_get_contents($sql_file);

// Split by semicolon, skip empty lines and comments
$statements = array_filter(
    array_map('trim', explode(';', $sql_content)),
    fn($s) => strlen($s) > 3 && !preg_match('/^--/', $s)
);

$ok    = 0;
$skip  = 0;
$error = 0;

foreach ($statements as $stmt) {
    $stmt = trim($stmt);
    if (empty($stmt)) continue;

    try {
        $pdo->exec($stmt);

        // Detect what was done
        $first = strtoupper(substr($stmt, 0, 6));
        if (in_array($first, ['CREATE', 'INSERT', 'UPDATE'])) {
            $label = match(true) {
                str_starts_with(strtoupper($stmt), 'CREATE DATABASE') => '  [DB]     Database imeundwa/ipo tayari',
                str_starts_with(strtoupper($stmt), 'CREATE TABLE')    => '  [TABLE]  ' . _extract_name($stmt, 'TABLE'),
                str_starts_with(strtoupper($stmt), 'CREATE INDEX')    => '  [INDEX]  ' . _extract_name($stmt, 'INDEX'),
                str_starts_with(strtoupper($stmt), 'INSERT')          => '  [SEED]   Data imeingizwa',
                str_starts_with(strtoupper($stmt), 'UPDATE')          => '  [UPDATE] Data imesasishwa',
                default                                                => '  [OK]',
            };
            echo $label . "\n";
        }
        $ok++;
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        // Ignore "already exists" and "Duplicate entry" errors – safe to skip
        if (str_contains($msg, 'already exists') || str_contains($msg, 'Duplicate entry')) {
            $skip++;
        } else {
            echo "  [WARN] " . substr($stmt, 0, 60) . "...\n";
            echo "         " . $msg . "\n";
            $error++;
        }
    }
}

echo "\n=== MATOKEO ===\n";
echo "OK     : $ok statements\n";
echo "Skip   : $skip (tayari zipo)\n";
if ($error > 0) {
    echo "Makosa : $error (angalia maonyo hapo juu)\n";
} else {
    echo "Makosa : 0\n";
}

// ── Verify tables ──────────────────────────────────────────────────────
$pdo->exec("USE `$name`");
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "\nTables zilizoundwa (" . count($tables) . "):\n";
foreach ($tables as $t) {
    echo "  ✓ $t\n";
}

echo "\n=== IMEKAMILIKA ===\n";
echo "Ingia: http://localhost/district-results-system/\n";
echo "  Super Admin: username=super  pass=Admin@123\n";
echo "\nKama unataka test data:\n";
echo "  php sql/seed_full.php\n";

// ── Helper ────────────────────────────────────────────────────────────
function _extract_name(string $sql, string $keyword): string {
    if (preg_match('/(?:' . $keyword . ')\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $sql, $m)) {
        return $m[1];
    }
    return '';
}
