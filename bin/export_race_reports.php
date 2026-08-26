<?php

declare(strict_types=1);

/**
 * Exports every stored race as a human-readable Markdown file suited for
 * grounding NotebookLM or any LLM with your personal race history.
 *
 * One file per race: var/exports/S<season>_R<race>_<track>.md
 *
 * Optional filters:
 *   --season=<N>   export only races from season N
 *   --race=<N>     combined with --season: export exactly one race
 *
 * Usage:
 *   task export-races
 *   task export-races -- --season=112
 *   task export-races -- --season=112 --race=1
 */

if (PHP_SAPI === 'cli' && !isset($_SESSION)) {
    $_SESSION = [];
}

$container = require __DIR__ . '/../bootstrap.php';

/** @var PDO $db */
$db = $container['db'];

$obsRepo    = new \App\Repository\RaceObservationRepository($db);
$detailRepo = new \App\Repository\RaceDetailRepository($db);
$setupRepo  = new \App\Repository\RaceSetupRepository($db);

// ── CLI argument parsing ──────────────────────────────────────────────────────

$opts   = getopt('', ['season:', 'race:']);
$season = isset($opts['season']) ? (int) $opts['season'] : null;
$race   = isset($opts['race'])   ? (int) $opts['race']   : null;

if ($race !== null && $season === null) {
    fwrite(STDERR, "Error: --race requires --season.\n");
    exit(1);
}

// ── Resolve races to export ───────────────────────────────────────────────────

$filters = [];
if ($season !== null) {
    $filters['season'] = $season;
}

$observations = $obsRepo->findAll($filters);

if ($race !== null) {
    $observations = array_values(array_filter(
        $observations,
        fn(array $r) => (int) $r['race_number'] === $race
    ));
}

if (count($observations) === 0) {
    echo "No races found for the given filters.\n";
    exit(0);
}

// ── Output directory ──────────────────────────────────────────────────────────

$outputDir = __DIR__ . '/../var/exports';
if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true)) {
    fwrite(STDERR, "Error: could not create output directory {$outputDir}\n");
    exit(1);
}

// ── Export loop ───────────────────────────────────────────────────────────────

$written = 0;

foreach ($observations as $obs) {
    $s         = (int) $obs['season'];
    $r         = (int) $obs['race_number'];
    $trackSlug = preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $obs['track_name']));
    $filename  = sprintf('%s/S%d_R%02d_%s.md', $outputDir, $s, $r, $trackSlug);

    $laps         = $detailRepo->getLaps($s, $r);
    $pits         = $detailRepo->getPits($s, $r);
    $parts        = $detailRepo->getCarParts($s, $r);
    $transactions = $detailRepo->getTransactions($s, $r);
    $practiceLaps = $detailRepo->getPracticeLaps($s, $r);
    $setups       = $setupRepo->findForRace($s, $r);

    $md = buildReport($obs, $laps, $pits, $parts, $transactions, $practiceLaps, $setups);

    if (file_put_contents($filename, $md) === false) {
        fwrite(STDERR, "Error writing {$filename}\n");
        continue;
    }

    printf("  wrote %s\n", basename($filename));
    $written++;
}

printf("\nExported %d race report(s) to %s\n", $written, realpath($outputDir));

// ── Report builder ────────────────────────────────────────────────────────────

/**
 * @param array<string, mixed>   $obs
 * @param list<array<string, mixed>> $laps
 * @param list<array<string, mixed>> $pits
 * @param list<array<string, mixed>> $parts
 * @param list<array<string, mixed>> $transactions
 * @param list<array<string, mixed>> $practiceLaps
 * @param list<array<string, mixed>> $setups
 */
function buildReport(
    array $obs,
    array $laps,
    array $pits,
    array $parts,
    array $transactions,
    array $practiceLaps,
    array $setups
): string {
    $s    = (int) $obs['season'];
    $r    = (int) $obs['race_number'];
    $name = (string) $obs['track_name'];

    $lines = [];

    // ── Title ─────────────────────────────────────────────────────────────────
    $lines[] = "# GPRO Race Report — Season {$s}, Race {$r}: {$name}";
    $lines[] = '';

    // ── Race Overview ─────────────────────────────────────────────────────────
    $lines[] = '## Race Overview';
    $lines[] = '';
    $lines[] = mdRow('Track',          $name);
    $lines[] = mdRow('Season / Race',  "Season {$s}, Race {$r}");
    $lines[] = mdRow('Weather',        $obs['weather'] ?? '—');
    $lines[] = mdRow('Avg Temperature', fmt($obs['avg_temp'], '%.1f °C'));
    $lines[] = mdRow('Laps',           $obs['laps'] ?? '—');
    $lines[] = mdRow('Tyre Compound',  $obs['tyre_compound'] ?? '—');
    $lines[] = mdRow('Tyre Supplier',  $obs['tyre_supplier'] ?? '—');
    $lines[] = mdRow('Tyre Wear %',    fmt($obs['tyre_wear_pct'], '%.1f%%'));
    $lines[] = mdRow('Pit Stops',      $obs['pit_count'] ?? '—');
    $lines[] = mdRow('Fuel/km',        fmt($obs['fuel_per_km'], '%.4f kg/km'));
    $lines[] = '';

    // ── Driver Stats ──────────────────────────────────────────────────────────
    $lines[] = '## Driver Stats at Race Start';
    $lines[] = '';
    $lines[] = mdRow('Concentration',    $obs['concentration'] ?? '—');
    $lines[] = mdRow('Aggressiveness',   $obs['aggressiveness'] ?? '—');
    $lines[] = mdRow('Experience',       $obs['experience'] ?? '—');
    $lines[] = mdRow('Technical Insight', $obs['technical_insight'] ?? '—');
    $lines[] = mdRow('Weight (kg)',      $obs['weight'] ?? '—');
    $lines[] = '';

    // ── Car Levels ────────────────────────────────────────────────────────────
    $lines[] = '## Car Levels';
    $lines[] = '';
    $lines[] = mdRow('Engine Level',      $obs['eng_lvl'] ?? '—');
    $lines[] = mdRow('Electronics Level', $obs['ele_lvl'] ?? '—');
    $lines[] = mdRow('Suspension Level',  $obs['susp_lvl'] ?? '—');
    if (isset($obs['car_power'])) {
        $lines[] = mdRow('Car Power',    $obs['car_power']);
        $lines[] = mdRow('Car Handling', $obs['car_handl'] ?? '—');
        $lines[] = mdRow('Car Accel',    $obs['car_accel'] ?? '—');
    }
    $lines[] = '';

    // ── Qualifying ────────────────────────────────────────────────────────────
    $lines[] = '## Qualifying';
    $lines[] = '';
    $hasQ = isset($obs['q1_time']) || isset($obs['q2_time']);
    if ($hasQ) {
        $lines[] = '| Session | Time | Position | Risk | Energy From | Energy To |';
        $lines[] = '|---------|------|----------|------|-------------|-----------|';
        $lines[] = sprintf('| Q1 | %s | %s | %s | %s | %s |',
            $obs['q1_time'] ?? '—', $obs['q1_pos'] ?? '—', $obs['q1_risk'] ?? '—',
            fmt($obs['q1_energy_from']), fmt($obs['q1_energy_to']));
        $lines[] = sprintf('| Q2 | %s | %s | %s | %s | %s |',
            $obs['q2_time'] ?? '—', $obs['q2_pos'] ?? '—', $obs['q2_risk'] ?? '—',
            fmt($obs['q2_energy_from']), fmt($obs['q2_energy_to']));
    } else {
        $lines[] = '_No qualifying data stored._';
    }
    $lines[] = '';

    // ── Race Risk Settings ────────────────────────────────────────────────────
    $lines[] = '## Race Risk Settings';
    $lines[] = '';
    $lines[] = mdRow('Start Risk',    $obs['start_risk'] ?? '—');
    $lines[] = mdRow('Overtake Risk', $obs['overtake_risk'] ?? '—');
    $lines[] = mdRow('Defend Risk',   $obs['defend_risk'] ?? '—');
    $lines[] = mdRow('Clear Dry Risk', $obs['clear_dry_risk'] ?? '—');
    $lines[] = mdRow('Clear Wet Risk', $obs['clear_wet_risk'] ?? '—');
    $lines[] = mdRow('Problem Risk',  $obs['problem_risk'] ?? '—');
    $lines[] = mdRow('Race Energy From', fmt($obs['race_energy_from']));
    $lines[] = mdRow('Race Energy To',   fmt($obs['race_energy_to']));
    $lines[] = '';

    // ── Overtaking ────────────────────────────────────────────────────────────
    $lines[] = '## Overtaking';
    $lines[] = '';
    $lines[] = mdRow('Overtake Attempts',    $obs['ot_attempts'] ?? '—');
    $lines[] = mdRow('Successful Overtakes', $obs['overtakes'] ?? '—');
    $lines[] = mdRow('Attempts On You',      $obs['ot_attempts_on_you'] ?? '—');
    $lines[] = mdRow('Overtaken By Others',  $obs['overtakes_on_you'] ?? '—');
    if (isset($obs['technical_problems'])) {
        $lines[] = mdRow('Technical Problems', $obs['technical_problems']);
    }
    $lines[] = '';

    // ── Setups ────────────────────────────────────────────────────────────────
    if (count($setups) > 0) {
        $lines[] = '## Setup Dials Used';
        $lines[] = '';
        $lines[] = '| Session | F-Wing | R-Wing | Engine | Brakes | Gear | Suspension | Tyres |';
        $lines[] = '|---------|--------|--------|--------|--------|------|------------|-------|';
        foreach ($setups as $setup) {
            $lines[] = sprintf('| %s | %s | %s | %s | %s | %s | %s | %s |',
                $setup['session'] ?? '—',
                $setup['set_fwing'] ?? '—', $setup['set_rwing'] ?? '—',
                $setup['set_eng']   ?? '—', $setup['set_bra']   ?? '—',
                $setup['set_gear']  ?? '—', $setup['set_susp']  ?? '—',
                $setup['set_tyres'] ?? '—');
        }
        $lines[] = '';
    }

    // ── Car Parts ─────────────────────────────────────────────────────────────
    if (count($parts) > 0) {
        $lines[] = '## Car Parts — Wear';
        $lines[] = '';
        $lines[] = '| Part | Level | Start Wear % | Finish Wear % | Wear Delta % |';
        $lines[] = '|------|-------|-------------|--------------|--------------|';
        foreach ($parts as $part) {
            $start  = isset($part['start_wear'])  ? (float) $part['start_wear']  : null;
            $finish = isset($part['finish_wear']) ? (float) $part['finish_wear'] : null;
            $delta  = ($start !== null && $finish !== null) ? sprintf('%.1f', $finish - $start) : '—';
            $lines[] = sprintf('| %s | %s | %s | %s | %s |',
                $part['part']       ?? '—',
                fmt($part['lvl'],        '%.1f'),
                fmt($start,              '%.1f%%'),
                fmt($finish,             '%.1f%%'),
                $delta);
        }
        $lines[] = '';
    }

    // ── Pit Stops ─────────────────────────────────────────────────────────────
    if (count($pits) > 0) {
        $lines[] = '## Pit Stops';
        $lines[] = '';
        $lines[] = '| # | Lap | Reason | Tyre Cond % | Fuel Left | Refilled To | Pit Time |';
        $lines[] = '|---|-----|--------|------------|-----------|-------------|---------|';
        foreach ($pits as $pit) {
            $lines[] = sprintf('| %s | %s | %s | %s | %s | %s | %s |',
                ($pit['pit_idx'] ?? 0) + 1,
                $pit['lap']         ?? '—',
                $pit['reason']      ?? '—',
                fmt($pit['tyre_cond'],  '%.1f%%'),
                fmt($pit['fuel_left'],  '%.2f'),
                fmt($pit['refilled_to'],'%.2f'),
                $pit['pit_time']    ?? '—');
        }
        $lines[] = '';
    }

    // ── Lap-by-Lap ────────────────────────────────────────────────────────────
    if (count($laps) > 0) {
        $lines[] = '## Lap-by-Lap Timeline';
        $lines[] = '';
        $lines[] = '| Lap | Pos | Tyre | Cond % | Fuel | Weather | Temp | Hum | Time | Boost | Events |';
        $lines[] = '|-----|-----|------|--------|------|---------|------|-----|------|-------|--------|';
        foreach ($laps as $lap) {
            $events = [];
            if (!empty($lap['events']) && is_array($lap['events'])) {
                foreach ($lap['events'] as $ev) {
                    $events[] = is_array($ev) ? implode(':', array_filter($ev)) : (string) $ev;
                }
            }
            $lines[] = sprintf('| %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s |',
                $lap['lap_idx']       ?? '—',
                $lap['position']      ?? '—',
                $lap['tyre_compound'] ?? '—',
                fmt($lap['tyre_cond'],  '%.1f%%'),
                fmt($lap['fuel_load'],  '%.2f'),
                $lap['weather']       ?? '—',
                fmt($lap['temp'],       '%.1f'),
                fmt($lap['hum'],        '%.0f%%'),
                $lap['lap_time']      ?? '—',
                isset($lap['boost_lap']) && $lap['boost_lap'] ? 'Y' : '—',
                $events !== [] ? implode('; ', $events) : '—');
        }
        $lines[] = '';
    }

    // ── Practice ──────────────────────────────────────────────────────────────
    if (count($practiceLaps) > 0) {
        $lines[] = '## Practice Laps';
        $lines[] = '';
        $lines[] = '| Lap | Time | Net Time | Mistake Time | F-Wing | R-Wing | Engine | Brakes | Gear | Susp | Tyres |';
        $lines[] = '|-----|------|----------|-------------|--------|--------|--------|--------|------|------|-------|';
        foreach ($practiceLaps as $plap) {
            $lines[] = sprintf('| %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s |',
                $plap['lap_idx']      ?? '—',
                $plap['lap_time']     ?? '—',
                $plap['net_time']     ?? '—',
                $plap['mistake_time'] ?? '—',
                $plap['set_fwing']    ?? '—',
                $plap['set_rwing']    ?? '—',
                $plap['set_engine']   ?? '—',
                $plap['set_brakes']   ?? '—',
                $plap['set_gear']     ?? '—',
                $plap['set_susp']     ?? '—',
                $plap['set_tyres']    ?? '—');
        }
        $lines[] = '';
    }

    // ── Driver Changes ────────────────────────────────────────────────────────
    $changeFields = [
        'oa_change'  => 'Overall',   'con_change' => 'Concentration',
        'tal_change' => 'Talent',    'agr_change' => 'Aggressiveness',
        'exp_change' => 'Experience', 'tei_change' => 'Technical Insight',
        'sta_change' => 'Stamina',   'cha_change' => 'Charisma',
        'mot_change' => 'Motivation', 'rep_change' => 'Reputation',
        'wei_change' => 'Weight',
    ];
    $hasChanges = false;
    foreach (array_keys($changeFields) as $field) {
        if (isset($obs[$field]) && $obs[$field] != 0) {
            $hasChanges = true;
            break;
        }
    }
    if ($hasChanges) {
        $lines[] = '## Driver Attribute Changes This Race';
        $lines[] = '';
        foreach ($changeFields as $field => $label) {
            if (isset($obs[$field]) && $obs[$field] != 0) {
                $val = (float) $obs[$field];
                $lines[] = mdRow($label, ($val > 0 ? '+' : '') . $val);
            }
        }
        $lines[] = '';
    }

    // ── Finances ─────────────────────────────────────────────────────────────
    if (count($transactions) > 0 || isset($obs['earnings_total'])) {
        $lines[] = '## Finances';
        $lines[] = '';
        if (count($transactions) > 0) {
            $lines[] = '| Description | Amount (M$) |';
            $lines[] = '|-------------|------------|';
            foreach ($transactions as $txn) {
                $lines[] = sprintf('| %s | %s |',
                    $txn['description'] ?? '—',
                    fmt($txn['amount'], '%.3f'));
            }
            $lines[] = '';
        }
        if (isset($obs['earnings_total'])) {
            $lines[] = mdRow('Total Earnings', fmt($obs['earnings_total'], '%.3f M$'));
        }
        if (isset($obs['balance_after'])) {
            $lines[] = mdRow('Balance After Race', fmt($obs['balance_after'], '%.3f M$'));
        }
        $lines[] = '';
    }

    // ── Metadata ──────────────────────────────────────────────────────────────
    $lines[] = '---';
    $lines[] = sprintf('_Exported from GPRO Pitwall personal history. Source: %s._', $obs['source'] ?? 'unknown');
    $lines[] = '';

    return implode("\n", $lines);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function mdRow(string $label, mixed $value): string
{
    return "- **{$label}:** {$value}";
}

/** Format a nullable float with a sprintf pattern, or return '—'. */
function fmt(mixed $value, string $pattern = '%s'): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    return sprintf($pattern, $value);
}
