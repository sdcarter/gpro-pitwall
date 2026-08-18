<?php

declare(strict_types=1);

/**
 * Imports a single completed race from the GPRO RaceAnalysis API endpoint
 * into the race_observations table.
 *
 * Usage:
 *   php bin/import_race_analysis.php --season=111 --race=7
 *   php bin/import_race_analysis.php --season=111 --race=7 --user-id=1
 */

$opts = getopt('', ['season:', 'race:', 'user-id:', 'help']);

if (isset($opts['help']) || !isset($opts['season'], $opts['race'])) {
    echo "Usage: php bin/import_race_analysis.php --season=<N> --race=<N> [--user-id=<N>]\n";
    exit(isset($opts['help']) ? 0 : 1);
}

$season  = (int) $opts['season'];
$race    = (int) $opts['race'];
$userId  = isset($opts['user-id']) ? (int) $opts['user-id'] : null;

// CLI scripts don't have a real session; suppress session-write side-effects.
if (PHP_SAPI === 'cli' && !isset($_SESSION)) {
    $_SESSION = [];
}

$container = require __DIR__ . '/../bootstrap.php';

/** @var \PDO $db */
$db = $container['db'];

/** @var \App\Repository\UserRepository $users */
$users = $container['service.user_repo'];

/** @var \App\Service\GproApiClient $apiClient */
$apiClient = $container['service.api_client'];

// ── Resolve the user whose token will be used ─────────────────────────────────

if ($userId !== null) {
    $user = $users->findById($userId);
    if ($user === null) {
        die("Error: user {$userId} not found\n");
    }
} else {
    // Find the first live user with a stored API token.
    $stmt = $db->query(
        "SELECT id FROM users WHERE api_token IS NOT NULL AND api_token != '' AND deleted_at IS NULL LIMIT 1"
    );
    $row = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
    if (!$row) {
        die("Error: no user with an API token found. Log in via the web UI and save your GPRO token first.\n");
    }
    $user = $users->findById((int) $row['id']);
}

if (empty($user['api_token'])) {
    die("Error: user has no API token stored\n");
}

$apiClient->setToken($user['api_token']);

// ── Import via the shared service (same mapping logic as auto-import) ─────────

/** @var \App\Service\RaceImportService $raceImport */
$raceImport = $container['service.race_import'];

echo "Fetching RaceAnalysis S{$season} R{$race} for user #{$user['id']}…\n";

try {
    $imported = $raceImport->importRace($season, $race);
} catch (\Throwable $e) {
    die("Error fetching race analysis: " . $e->getMessage() . "\n");
}

if (!$imported) {
    die("Error: unexpected API response (no trackName, or track not yet seeded). Is this race finished?\n");
}

echo "✅ Imported S{$season} R{$race}\n";

$summaryStmt = $db->prepare(
    'SELECT track_name, weather, fuel_per_km FROM race_observations WHERE season = :s AND race_number = :r'
);
$summaryStmt->execute([':s' => $season, ':r' => $race]);
$summary = $summaryStmt->fetch(\PDO::FETCH_ASSOC);

if ($summary) {
    echo "   Track     : {$summary['track_name']}\n";
    echo "   Weather   : " . ($summary['weather'] ?? 'unknown') . "\n";
    echo "   Fuel/km   : " . ($summary['fuel_per_km'] !== null ? $summary['fuel_per_km'] . ' L/km' : 'n/a (track not seeded?)') . "\n";
}
