<?php

declare(strict_types=1);

/**
 * Reports retained RaceAnalysis coverage and flags API imports whose expected
 * normalized sections are missing. It never changes data.
 */

if (PHP_SAPI === 'cli' && !isset($_SESSION)) {
    $_SESSION = [];
}

$container = require __DIR__ . '/../bootstrap.php';

/** @var PDO $db */
$db = $container['db'];

$summary = queryOrFail($db, "
    SELECT
        COUNT(*) AS races,
        SUM(CASE WHEN source = 'api' THEN 1 ELSE 0 END) AS api_races,
        SUM(CASE WHEN raw_payload IS NOT NULL AND raw_payload != '' THEN 1 ELSE 0 END) AS raw_payloads,
        SUM(CASE WHEN fuel_per_km IS NOT NULL THEN 1 ELSE 0 END) AS fuel_rows
    FROM race_observations
")->fetch(PDO::FETCH_ASSOC);

printf("Race observations: %d total, %d API, %d with raw payload, %d with fuel/km\n",
    (int) ($summary['races'] ?? 0),
    (int) ($summary['api_races'] ?? 0),
    (int) ($summary['raw_payloads'] ?? 0),
    (int) ($summary['fuel_rows'] ?? 0),
);

$tables = ['race_setups', 'race_laps', 'race_pits', 'race_car_parts', 'race_transactions', 'race_practice_laps'];
foreach ($tables as $table) {
    $count = (int) queryOrFail($db, "SELECT COUNT(*) FROM {$table}")->fetchColumn();
    printf("  %-22s %d rows\n", $table . ':', $count);
}

$missingRaw = queryOrFail($db, "
    SELECT season, race_number, track_name
    FROM race_observations
    WHERE source = 'api' AND (raw_payload IS NULL OR raw_payload = '')
    ORDER BY season, race_number
")->fetchAll(PDO::FETCH_ASSOC);

$incomplete = queryOrFail($db, "
    SELECT ro.season, ro.race_number, ro.track_name,
           (SELECT COUNT(*) FROM race_laps rl WHERE rl.season = ro.season AND rl.race_number = ro.race_number) AS laps,
           (SELECT COUNT(*) FROM race_setups rs WHERE rs.season = ro.season AND rs.race_number = ro.race_number) AS setups,
           (SELECT COUNT(*) FROM race_car_parts rcp WHERE rcp.season = ro.season AND rcp.race_number = ro.race_number) AS parts
    FROM race_observations ro
    WHERE ro.source = 'api'
      AND (ro.laps IS NOT NULL AND ro.laps > 0)
      AND (
          (SELECT COUNT(*) FROM race_laps rl WHERE rl.season = ro.season AND rl.race_number = ro.race_number) = 0
          OR (SELECT COUNT(*) FROM race_setups rs WHERE rs.season = ro.season AND rs.race_number = ro.race_number) = 0
          OR (SELECT COUNT(*) FROM race_car_parts rcp WHERE rcp.season = ro.season AND rcp.race_number = ro.race_number) = 0
      )
    ORDER BY ro.season, ro.race_number
")->fetchAll(PDO::FETCH_ASSOC);

$implausibleFuel = queryOrFail($db, "
    SELECT season, race_number, track_name, fuel_per_km
    FROM race_observations
    WHERE fuel_per_km IS NOT NULL AND (fuel_per_km < 0.3 OR fuel_per_km > 1.5)
    ORDER BY season, race_number
")->fetchAll(PDO::FETCH_ASSOC);

if ($missingRaw === [] && $incomplete === [] && $implausibleFuel === []) {
    echo "Audit: OK — no API retention gaps or implausible fuel values found.\n";
    exit(0);
}

echo "Audit warnings:\n";
foreach ($missingRaw as $row) {
    printf("  missing raw payload: S%d R%d %s\n", $row['season'], $row['race_number'], $row['track_name']);
}
foreach ($incomplete as $row) {
    printf("  incomplete API detail: S%d R%d %s (laps=%d, setups=%d, parts=%d)\n",
        $row['season'], $row['race_number'], $row['track_name'], $row['laps'], $row['setups'], $row['parts']);
}
foreach ($implausibleFuel as $row) {
    printf("  implausible fuel/km: S%d R%d %s = %.4f\n",
        $row['season'], $row['race_number'], $row['track_name'], $row['fuel_per_km']);
}

exit(0);

function queryOrFail(PDO $db, string $sql): PDOStatement
{
    $statement = $db->query($sql);
    if ($statement === false) {
        throw new RuntimeException('Race-data audit query failed');
    }

    return $statement;
}