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

// ── Fetch the race analysis ───────────────────────────────────────────────────

echo "Fetching RaceAnalysis S{$season} R{$race} for user #{$user['id']}…\n";

try {
    $data = $apiClient->getRaceAnalysis($season, $race);
} catch (\Throwable $e) {
    die("Error fetching race analysis: " . $e->getMessage() . "\n");
}

if (empty($data['trackName'])) {
    die("Error: unexpected API response (no trackName). Is this race finished?\n");
}

// ── Map API response to observation row ───────────────────────────────────────

$trackName = trim((string) $data['trackName']);

// Resolve the DB track id by name.
$trackStmt = $db->prepare("SELECT id, lap_length FROM tracks WHERE name = :name LIMIT 1");
$trackStmt->execute([':name' => $trackName]);
$trackRow = $trackStmt->fetch(\PDO::FETCH_ASSOC);
$trackId     = $trackRow ? (int) $trackRow['id'] : null;
$lapLengthKm = $trackRow ? (float) $trackRow['lap_length'] : 0.0;

// Total laps.
$laps = (int) ($data['nbLaps'] ?? 0);
if ($laps === 0 && !empty($data['laps']) && is_array($data['laps'])) {
    $laps = count($data['laps']);
}

// Fuel consumption: (startFuel - finishFuel) / (laps × lap_length).
$startFuel  = (float) ($data['startFuel'] ?? 0);
$finishFuel = (float) ($data['finishFuel'] ?? 0);
$fuelPerKm  = null;
if ($laps > 0 && $lapLengthKm > 0) {
    $totalKm   = $laps * $lapLengthKm;
    $fuelUsed  = $startFuel - $finishFuel;
    if ($fuelUsed > 0) {
        $fuelPerKm = round($fuelUsed / $totalKm, 4);
    }
}

// Weather: count dry vs rain laps from the laps array.
$dryLaps  = 0;
$rainLaps = 0;
if (!empty($data['laps']) && is_array($data['laps'])) {
    foreach ($data['laps'] as $lap) {
        if (!is_array($lap)) {
            continue;
        }
        // The weather field may be 'Dry', 'Rain', 'Mist', etc.
        $w = strtolower((string) ($lap['weather'] ?? $lap['w'] ?? ''));
        if (str_contains($w, 'rain') || str_contains($w, 'wet')) {
            $rainLaps++;
        } elseif ($w !== '') {
            $dryLaps++;
        }
    }
}
$weather = match(true) {
    $rainLaps === 0 && $dryLaps > 0 => 'dry',
    $dryLaps === 0 && $rainLaps > 0 => 'wet',
    $rainLaps > 0  && $dryLaps > 0  => 'mixed',
    default                          => null,
};

// Average temperature.
$avgTemp = null;
if (!empty($data['weather']) && is_array($data['weather'])) {
    $avgTemp = isset($data['weather']['temp']) ? (float) $data['weather']['temp'] : null;
}

// Driver stats from the 'driver' object (pre-race stats are what we want).
$driver = $data['driver'] ?? [];
$driverPre = $driver['pre'] ?? $driver; // some responses have a nested 'pre' key
$concentration    = isset($driverPre['concentration'])  ? (int) $driverPre['concentration']  : null;
$aggressiveness   = isset($driverPre['aggressiveness']) ? (int) $driverPre['aggressiveness'] : null;
$experience       = isset($driverPre['experience'])     ? (int) $driverPre['experience']     : null;
$technicalInsight = isset($driverPre['techInsight'])    ? (int) $driverPre['techInsight']
    : (isset($driverPre['technical_insight'])           ? (int) $driverPre['technical_insight'] : null);
$weight           = isset($driverPre['weight'])         ? (float) $driverPre['weight']       : null;

// Car component levels — the Practice/Q1 response uses lvlEngine etc.
// RaceAnalysis may expose them differently; try multiple key styles.
$engLvl  = isset($data['lvlEngine'])      ? (float) $data['lvlEngine']
    : (isset($data['engine']['level'])    ? (float) $data['engine']['level']    : null);
$eleLvl  = isset($data['lvlElectronics']) ? (float) $data['lvlElectronics']
    : (isset($data['electronics']['level']) ? (float) $data['electronics']['level'] : null);
$suspLvl = isset($data['lvlSusp'])        ? (float) $data['lvlSusp']
    : (isset($data['susp']['level'])      ? (float) $data['susp']['level']      : null);

// Tyre info from pits or laps.
$tyreCompound = null;
$tyreSupplier = null;
$tyreWearPct  = isset($data['finishTyres']) ? (float) $data['finishTyres'] : null;
if (!empty($data['pits']) && is_array($data['pits'])) {
    $firstPit = reset($data['pits']);
    if (is_array($firstPit)) {
        $tyreCompound = $firstPit['tyreCompound'] ?? $firstPit['tyre'] ?? null;
    }
}
$tyreCompound ??= (string) ($data['startTyres'] ?? '');

// Pit count.
$pitCount = !empty($data['pits']) && is_array($data['pits']) ? count($data['pits']) : null;

// ── Write to race_observations ────────────────────────────────────────────────

$repo = new \App\Repository\RaceObservationRepository($db);
$repo->upsert([
    'track_name'        => $trackName,
    'track_id'          => $trackId,
    'season'            => $season,
    'race_number'       => $race,
    'weather'           => $weather,
    'avg_temp'          => $avgTemp,
    'laps'              => $laps ?: null,
    'concentration'     => $concentration,
    'aggressiveness'    => $aggressiveness,
    'experience'        => $experience,
    'technical_insight' => $technicalInsight,
    'weight'            => $weight,
    'eng_lvl'           => $engLvl,
    'ele_lvl'           => $eleLvl,
    'susp_lvl'          => $suspLvl,
    'fuel_per_km'       => $fuelPerKm,
    'tyre_compound'     => $tyreCompound ?: null,
    'tyre_supplier'     => $tyreSupplier,
    'tyre_wear_pct'     => $tyreWearPct,
    'pit_count'         => $pitCount,
    'source'            => 'api',
]);

echo "✅ Imported S{$season} R{$race} — {$trackName}\n";
echo "   Weather   : " . ($weather ?? 'unknown') . "\n";
echo "   Fuel/km   : " . ($fuelPerKm !== null ? $fuelPerKm . ' L/km' : 'n/a (lap_length missing from tracks table?)') . "\n";
echo "   Dry laps  : {$dryLaps}  Rain laps: {$rainLaps}\n";

if ($fuelPerKm === null) {
    echo "\nNote: run 'task seed-tracks' first so lap_length is available for fuel rate calculation.\n";
}
