<?php

declare(strict_types=1);

/**
 * Computes candidate fuel_factors coefficients from race_observations using
 * Ordinary Least Squares on dry-weather races.
 *
 * Model:
 *   observed_fuel_per_km = track.fuel_per_lap + Δ
 *   Δ = conc×β1 + agg×β2 + exp×β3 + te×β4 + eng_lvl×β5 + ele_lvl×β6 + β7
 *
 * Outputs a ready-to-paste PHP snippet for config/secrets.php['fuel_factors'].
 * Requires at minimum 7 observations with sufficient variance across predictors.
 */

if (PHP_SAPI === 'cli' && !isset($_SESSION)) {
    $_SESSION = [];
}

$container = require __DIR__ . '/../bootstrap.php';

/** @var \PDO $db */
$db = $container['db'];

$repo = new \App\Repository\RaceObservationRepository($db);
$rows = $repo->findForFitting();

// ── Data status report ────────────────────────────────────────────────────────

$n = count($rows);
echo "Race observations (dry, non-null fuel_per_km, matched to tracks): {$n}\n";

// Minimum observations to attempt a full 7-parameter fit.
const MIN_OBS = 7;

if ($n < MIN_OBS) {
    echo "\n⚠️  Need at least " . MIN_OBS . " dry observations to fit fuel_factors.\n";
    echo "   Current: {$n}\n\n";
    echo "How to build up observations:\n";
    echo "  Automatic: open an authenticated page after results post.\n";
    echo "  Manual/re-import: task import-race -- --season=<S> --race=<R>\n\n";
    echo "What each additional observation gives you:\n";
    foreach ($rows as $r) {
        printf("  S%d R%d  %-22s  fuel=%.4f  con=%s agg=%s exp=%s te=%s eng=%.1f ele=%.1f\n",
            $r['season'], $r['race_number'], $r['track_name'],
            $r['fuel_per_km'],
            $r['concentration'] ?? '?', $r['aggressiveness'] ?? '?',
            $r['experience'] ?? '?', $r['technical_insight'] ?? '?',
            $r['eng_lvl'] ?? 0, $r['ele_lvl'] ?? 0
        );
    }

    // Output the current config unchanged so the script is always pasteable.
    outputCurrentConfig($container['config']['secrets']['fuel_factors'] ?? []);
    exit(0);
}

// ── Check predictor variance ──────────────────────────────────────────────────

$predictors = ['concentration', 'aggressiveness', 'experience', 'technical_insight', 'eng_lvl', 'ele_lvl'];
$variances  = [];
foreach ($predictors as $p) {
    $vals = array_map(static fn($r) => (float) ($r[$p] ?? 0), $rows);
    $mean = array_sum($vals) / $n;
    $var  = array_sum(array_map(static fn($v) => ($v - $mean) ** 2, $vals)) / $n;
    $variances[$p] = $var;
}

$lowVar = array_filter($variances, static fn($v) => $v < 1.0);
if (!empty($lowVar)) {
    echo "\n⚠️  Low variance in: " . implode(', ', array_keys($lowVar)) . "\n";
    echo "   These coefficients cannot be identified reliably yet.\n";
    echo "   Continue collecting races with different driver/car configurations.\n\n";
}

// ── OLS via Gaussian elimination on normal equations ─────────────────────────

// Build design matrix X (n×7) and response vector y (n×1).
// Columns: [conc, agg, exp, te, eng_lvl, ele_lvl, 1]
$X = [];
$y = [];
foreach ($rows as $r) {
    $X[] = [
        (float) ($r['concentration']     ?? 0),
        (float) ($r['aggressiveness']    ?? 0),
        (float) ($r['experience']        ?? 0),
        (float) ($r['technical_insight'] ?? 0),
        (float) ($r['eng_lvl']           ?? 0),
        (float) ($r['ele_lvl']           ?? 0),
        1.0,
    ];
    // Residual from the track's current base rate.
    $y[] = (float) $r['fuel_per_km'] - (float) $r['fuel_per_lap'];
}

$cols = 7;

// X'X (7×7 symmetric matrix)
$XtX = array_fill(0, $cols, array_fill(0, $cols, 0.0));
for ($i = 0; $i < $cols; $i++) {
    for ($j = 0; $j < $cols; $j++) {
        $sum = 0.0;
        for ($k = 0; $k < $n; $k++) {
            $sum += $X[$k][$i] * $X[$k][$j];
        }
        $XtX[$i][$j] = $sum;
    }
}

// X'y (7×1 vector)
$Xty = array_fill(0, $cols, 0.0);
for ($i = 0; $i < $cols; $i++) {
    $sum = 0.0;
    for ($k = 0; $k < $n; $k++) {
        $sum += $X[$k][$i] * $y[$k];
    }
    $Xty[$i] = $sum;
}

// Solve (X'X)β = X'y via Gaussian elimination with partial pivoting.
$beta = solveLinearSystem($XtX, $Xty, $cols);

if ($beta === null) {
    echo "Error: normal equations are singular — predictors are collinear.\n";
    echo "This usually means all observations have the same driver or car config.\n";
    outputCurrentConfig($container['config']['secrets']['fuel_factors'] ?? []);
    exit(1);
}

// ── Goodness of fit ───────────────────────────────────────────────────────────

$yMean = array_sum($y) / $n;
$ssTot = 0.0;
$ssRes = 0.0;
for ($k = 0; $k < $n; $k++) {
    $yHat   = 0.0;
    for ($j = 0; $j < $cols; $j++) {
        $yHat += $X[$k][$j] * $beta[$j];
    }
    $ssTot += ($y[$k] - $yMean) ** 2;
    $ssRes += ($y[$k] - $yHat) ** 2;
}
$r2 = $ssTot > 0 ? 1.0 - $ssRes / $ssTot : 0.0;

echo "\nFit results ({$n} observations):\n";
printf("  R²: %.4f  (residual std: %.5f L/km)\n\n", $r2, $n > $cols ? sqrt($ssRes / ($n - $cols)) : 0.0);

echo "Per-race residuals:\n";
for ($k = 0; $k < $n; $k++) {
    $r    = $rows[$k];
    $yHat = 0.0;
    for ($j = 0; $j < $cols; $j++) {
        $yHat += $X[$k][$j] * $beta[$j];
    }
    $resid = $y[$k] - $yHat;
    printf("  S%d R%d %-22s  observed_Δ=%+.4f  fitted_Δ=%+.4f  resid=%+.5f\n",
        $r['season'], $r['race_number'], $r['track_name'],
        $y[$k], $yHat, $resid
    );
}

// ── Output ────────────────────────────────────────────────────────────────────

[$bConc, $bAgg, $bExp, $bTe, $bEng, $bEle, $bConst] = $beta;

echo "\n" . str_repeat('─', 60) . "\n";
echo "Paste into config/secrets.php ['fuel_factors']:\n";
echo str_repeat('─', 60) . "\n";
printf("    'fuel_factors' => [\n");
printf("        'conc'     => %+.6f,\n", $bConc);
printf("        'agg'      => %+.6f,\n", $bAgg);
printf("        'exp'      => %+.6f,\n", $bExp);
printf("        'te'       => %+.6f,\n", $bTe);
printf("        'eng_lvl'  => %+.6f,\n", $bEng);
printf("        'ele_lvl'  => %+.6f,\n", $bEle);
printf("        'constant' => %+.6f,\n", $bConst);
printf("    ],\n");
echo str_repeat('─', 60) . "\n";

if (!empty($lowVar)) {
    echo "\n⚠️  Low-variance coefficients are unreliable — treat them as 0 until\n";
    echo "   you have more races with varied " . implode('/', array_keys($lowVar)) . ".\n";
}

echo "\n// TODO: tyre fitting needs N >= 30 dry observations per compound.\n";
echo "// Expected output format when ready:\n";
echo "//   'tyre_calc' => [ 'factors' => [ 'track_wear' => X, 'avg_temp' => X, ... ] ]\n";

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Solve Ax = b via Gaussian elimination with partial pivoting.
 * Returns null if the matrix is singular.
 *
 * @param array<int, array<int, float>> $A
 * @param array<int, float> $b
 * @return array<int, float>|null
 */
function solveLinearSystem(array $A, array $b, int $n): ?array
{
    // Build augmented matrix [A|b].
    $M = [];
    for ($i = 0; $i < $n; $i++) {
        $M[$i] = $A[$i];
        $M[$i][$n] = $b[$i];
    }

    for ($col = 0; $col < $n; $col++) {
        // Partial pivot.
        $maxRow = $col;
        $maxVal = abs($M[$col][$col]);
        for ($row = $col + 1; $row < $n; $row++) {
            if (abs($M[$row][$col]) > $maxVal) {
                $maxVal = abs($M[$row][$col]);
                $maxRow = $row;
            }
        }
        if ($maxVal < 1e-12) {
            return null; // Singular.
        }
        [$M[$col], $M[$maxRow]] = [$M[$maxRow], $M[$col]];

        // Eliminate below.
        $pivot = $M[$col][$col];
        for ($row = $col + 1; $row < $n; $row++) {
            $factor = $M[$row][$col] / $pivot;
            for ($j = $col; $j <= $n; $j++) {
                $M[$row][$j] -= $factor * $M[$col][$j];
            }
        }
    }

    // Back-substitution.
    $x = array_fill(0, $n, 0.0);
    for ($i = $n - 1; $i >= 0; $i--) {
        $x[$i] = $M[$i][$n];
        for ($j = $i + 1; $j < $n; $j++) {
            $x[$i] -= $M[$i][$j] * $x[$j];
        }
        $x[$i] /= $M[$i][$i];
    }

    return $x;
}

/** @param array<string, float> $current */
function outputCurrentConfig(array $current): void
{
    echo "\nCurrent fuel_factors (unchanged):\n";
    echo "    'fuel_factors' => [\n";
    echo "        'conc'     => " . ($current['conc']     ?? 0.0) . ",\n";
    echo "        'agg'      => " . ($current['agg']      ?? 0.0) . ",\n";
    echo "        'exp'      => " . ($current['exp']      ?? 0.0) . ",\n";
    echo "        'te'       => " . ($current['te']        ?? 0.0) . ",\n";
    echo "        'eng_lvl'  => " . ($current['eng_lvl']  ?? 0.0) . ",\n";
    echo "        'ele_lvl'  => " . ($current['ele_lvl']  ?? 0.0) . ",\n";
    echo "        'constant' => " . ($current['constant'] ?? 0.0) . ",\n";
    echo "    ],\n";
}
