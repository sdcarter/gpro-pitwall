<?php

declare(strict_types=1);

namespace App\Service;

use App\Cache\CacheInterface;
use App\Repository\RaceDetailRepository;
use App\Repository\RaceObservationRepository;
use App\Repository\RaceSetupRepository;
use PDO;
use Throwable;

/**
 * Silently imports a completed race from the GPRO API the first time it is
 * seen. Called once per page load from bootstrap; the 1-hour API cache makes
 * the per-request cost a single cache read on all but the first hit.
 */
final class RaceImportService
{
    public function __construct(
        private readonly GproApiClient $api,
        private readonly RaceObservationRepository $repo,
        private readonly RaceSetupRepository $setupRepo,
        private readonly RaceDetailRepository $detailRepo,
        private readonly PDO $db,
        private readonly CacheInterface $cache,
    ) {
    }

    public function checkAndImportLatest(): void
    {
        try {
            $data = $this->api->getLatestRaceAnalysis();
        } catch (Throwable) {
            return;
        }

        $season = (int) ($data['selSeasonNb'] ?? 0);
        $race   = (int) ($data['selRaceNb']   ?? 0);

        if ($season === 0 || $race === 0) {
            return;
        }

        // Already stored — most common hot-path case.
        if ($this->repo->hasRecord($season, $race)) {
            return;
        }

        // Skip marker: set when a previous attempt produced no importable data
        // (e.g. API responded but trackName was empty, track not yet in DB).
        // Prevents hourly retries on a persistently unimportable response.
        $skipKey = "race_import_skip_{$season}_{$race}";
        if ($this->cache->has($skipKey)) {
            return;
        }

        $imported = $this->importFromPayload($data, $season, $race);

        if (!$imported) {
            // 24-hour back-off — long enough to avoid churn, short enough to
            // pick up late-posted analysis or a newly seeded track.
            $this->cache->set($skipKey, 1, 86400);
        }
    }

    /**
     * Fetches and imports one specific completed race. Used by the CLI
     * importer (manual/historical backfill) so it shares the exact same
     * mapping logic as the automatic per-page-load import above.
     */
    public function importRace(int $season, int $race, bool $forceRefresh = false): bool
    {
        $data = $this->api->getRaceAnalysis($season, $race, $forceRefresh);

        return $this->importFromPayload($data, $season, $race);
    }

    /** @param array<string, mixed> $data */
    private function importFromPayload(array $data, int $season, int $race): bool
    {
        $rawTrackName = trim((string) ($data['trackName'] ?? ''));
        if ($rawTrackName === '') {
            return false;
        }

        // The API's top-level trackName includes the country, e.g.
        // "Estoril (Portugal)"; our tracks table stores the bare name.
        // Note: the API's own trackId is GPRO's numbering scheme, which does
        // NOT match our local tracks.id (auto-increment on seed) — never use
        // it directly to look up a local track row.
        $trackName = trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', $rawTrackName));

        $stmt = $this->db->prepare('SELECT id, lap_length FROM tracks WHERE name = :n LIMIT 1');
        $stmt->execute([':n' => $trackName]);
        $trackRow    = $stmt->fetch(PDO::FETCH_ASSOC);
        $trackId     = $trackRow ? (int)   $trackRow['id']         : null;
        $lapLengthKm = $trackRow ? (float) $trackRow['lap_length'] : 0.0;

        $lapRows = (array) ($data['laps'] ?? []);
        $laps    = count($lapRows);

        // Total fuel burned = starting load + every pit refuel - what's left
        // at the flag. Using only (startFuel - finishFuel) ignores fuel added
        // at pit stops and badly undercounts multi-stop races.
        $fuelPerKm  = null;
        $startFuel  = (float) ($data['startFuel']  ?? 0);
        $finishFuel = (float) ($data['finishFuel'] ?? 0);
        $refueled   = 0.0;
        foreach ((array) ($data['pits'] ?? []) as $pit) {
            if (!is_array($pit) || !isset($pit['refilledTo'], $pit['fuelLeft'])) {
                continue;
            }
            $refueled += max(0.0, (float) $pit['refilledTo'] - (float) $pit['fuelLeft']);
        }
        $totalFuelUsed = $startFuel + $refueled - $finishFuel;
        if ($laps > 0 && $lapLengthKm > 0 && $totalFuelUsed > 0) {
            $fuelPerKm = round($totalFuelUsed / ($laps * $lapLengthKm), 4);
        }

        $dryLaps = $rainLaps = 0;
        $tempSum = $tempCount = 0;
        $tyreCounts = [];
        foreach ($lapRows as $lap) {
            if (!is_array($lap)) {
                continue;
            }
            $w = strtolower((string) ($lap['weather'] ?? ''));
            if (str_contains($w, 'rain') || str_contains($w, 'wet')) {
                $rainLaps++;
            } elseif ($w !== '') {
                $dryLaps++;
            }
            if (isset($lap['temp'])) {
                $tempSum += (float) $lap['temp'];
                $tempCount++;
            }
            if (!empty($lap['tyres'])) {
                $tyre = (string) $lap['tyres'];
                $tyreCounts[$tyre] = ($tyreCounts[$tyre] ?? 0) + 1;
            }
        }
        $weather = match (true) {
            $rainLaps === 0 && $dryLaps > 0 => 'dry',
            $dryLaps  === 0 && $rainLaps > 0 => 'wet',
            $rainLaps > 0   && $dryLaps > 0  => 'mixed',
            default                           => null,
        };
        $avgTemp = $tempCount > 0 ? round($tempSum / $tempCount, 1) : null;

        $tyreCompound = null;
        if ($tyreCounts !== []) {
            arsort($tyreCounts);
            $tyreCompound = array_key_first($tyreCounts);
        }

        // Driver stats are a flat object under 'driver', not nested pre/post.
        $driver = $data['driver'] ?? [];
        $driverChanges = $data['driverChanges'] ?? [];

        $engLvl  = isset($data['engine']['lvl'])       ? (float) $data['engine']['lvl']       : null;
        $eleLvl  = isset($data['electronics']['lvl'])  ? (float) $data['electronics']['lvl']  : null;
        $suspLvl = isset($data['susp']['lvl'])          ? (float) $data['susp']['lvl']          : null;

        $this->repo->upsert([
            'track_name'        => $trackName,
            'track_id'          => $trackId,
            'season'            => $season,
            'race_number'       => $race,
            'weather'           => $weather,
            'avg_temp'          => $avgTemp,
            'laps'              => $laps ?: null,
            'concentration'     => isset($driver['con']) ? (int)   $driver['con'] : null,
            'aggressiveness'    => isset($driver['agr']) ? (int)   $driver['agr'] : null,
            'experience'        => isset($driver['exp']) ? (int)   $driver['exp'] : null,
            'technical_insight' => isset($driver['tei']) ? (int)   $driver['tei'] : null,
            'weight'            => isset($driver['wei']) ? (float) $driver['wei'] : null,
            'eng_lvl'           => $engLvl,
            'ele_lvl'           => $eleLvl,
            'susp_lvl'          => $suspLvl,
            'fuel_per_km'       => $fuelPerKm,
            'tyre_compound'     => $tyreCompound,
            'tyre_supplier'     => $data['tyreSupplier']['name'] ?? null,
            'tyre_wear_pct'     => isset($data['finishTyres']) ? (float) $data['finishTyres'] : null,
            'pit_count'         => is_array($data['pits'] ?? null) ? count($data['pits']) : null,
            'source'            => 'api',
            // Keep the full API response so future models can mine fields
            // (wing/chassis/setup, per-lap detail, etc.) without re-fetching.
            'raw_payload'       => json_encode($data, JSON_UNESCAPED_SLASHES) ?: null,
            'oa_change'         => isset($driverChanges['OA'])  ? (float) $driverChanges['OA']  : null,
            'con_change'        => isset($driverChanges['con']) ? (float) $driverChanges['con'] : null,
            'tal_change'        => isset($driverChanges['tal']) ? (float) $driverChanges['tal'] : null,
            'agr_change'        => isset($driverChanges['agr']) ? (float) $driverChanges['agr'] : null,
            'exp_change'        => isset($driverChanges['exp']) ? (float) $driverChanges['exp'] : null,
            'tei_change'        => isset($driverChanges['tei']) ? (float) $driverChanges['tei'] : null,
            'sta_change'        => isset($driverChanges['sta']) ? (float) $driverChanges['sta'] : null,
            'cha_change'        => isset($driverChanges['cha']) ? (float) $driverChanges['cha'] : null,
            'mot_change'        => isset($driverChanges['mot']) ? (float) $driverChanges['mot'] : null,
            'rep_change'        => isset($driverChanges['rep']) ? (float) $driverChanges['rep'] : null,
            'wei_change'        => isset($driverChanges['wei']) ? (float) $driverChanges['wei'] : null,
            'earnings_total'    => isset($data['total'])          ? (float) $data['total']          : null,
            'balance_after'     => isset($data['currentBalance']) ? (float) $data['currentBalance'] : null,
            'q1_time'           => $data['q1Time'] ?? null,
            'q1_pos'            => isset($data['q1Pos']) ? (int) $data['q1Pos'] : null,
            'q2_time'           => $data['q2Time'] ?? null,
            'q2_pos'            => isset($data['q2Pos']) ? (int) $data['q2Pos'] : null,
            'q1_risk'           => $data['q1Risk']         ?? null,
            'q2_risk'           => $data['q2Risk']         ?? null,
            'start_risk'        => $data['startRisk']      ?? null,
            'overtake_risk'     => $data['overtakeRisk']   ?? null,
            'defend_risk'       => $data['defendRisk']     ?? null,
            'clear_dry_risk'    => $data['clearDryRisk']   ?? null,
            'clear_wet_risk'    => $data['clearWetRisk']   ?? null,
            'problem_risk'      => $data['problemRisk']    ?? null,
            'technical_problems' => !empty($data['problems']) ? json_encode($data['problems']) : null,
            'q1_energy_from'    => isset($data['q1Energy']['from'])   ? (float) $data['q1Energy']['from']   : null,
            'q1_energy_to'      => isset($data['q1Energy']['to'])     ? (float) $data['q1Energy']['to']     : null,
            'q2_energy_from'    => isset($data['q2Energy']['from'])   ? (float) $data['q2Energy']['from']   : null,
            'q2_energy_to'      => isset($data['q2Energy']['to'])     ? (float) $data['q2Energy']['to']     : null,
            'race_energy_from'  => isset($data['raceEnergy']['from']) ? (float) $data['raceEnergy']['from'] : null,
            'race_energy_to'    => isset($data['raceEnergy']['to'])   ? (float) $data['raceEnergy']['to']   : null,
            'car_power'         => isset($data['carPower'])  ? (float) $data['carPower']  : null,
            'car_handl'         => isset($data['carHandl'])  ? (float) $data['carHandl']  : null,
            'car_accel'         => isset($data['carAccel'])  ? (float) $data['carAccel']  : null,
            'ot_attempts'        => isset($data['otAttempts'])       ? (int) $data['otAttempts']       : null,
            'overtakes'          => isset($data['overtakes'])        ? (int) $data['overtakes']        : null,
            'ot_attempts_on_you' => isset($data['otAttemptsOnYou'])  ? (int) $data['otAttemptsOnYou']  : null,
            'overtakes_on_you'   => isset($data['overtakesOnYou'])   ? (int) $data['overtakesOnYou']   : null,
        ]);

        $this->importSetupsUsed($data, $season, $race);
        $this->detailRepo->upsertLaps($season, $race, array_values($lapRows));
        $this->detailRepo->upsertPits($season, $race, array_values((array) ($data['pits'] ?? [])));
        $this->detailRepo->upsertCarParts($season, $race, [
            'engine'      => $data['engine']      ?? [],
            'electronics' => $data['electronics'] ?? [],
            'chassis'     => $data['chassis']     ?? [],
            'susp'        => $data['susp']        ?? [],
            'fwing'       => $data['FWing']       ?? [],
            'rwing'       => $data['RWing']       ?? [],
            'underbody'   => $data['underbody']   ?? [],
            'sidepods'    => $data['sidepods']    ?? [],
            'cooling'     => $data['cooling']     ?? [],
            'gear'        => $data['gear']        ?? [],
            'brakes'      => $data['brakes']      ?? [],
        ]);
        $this->detailRepo->replaceTransactions($season, $race, array_values((array) ($data['transactions'] ?? [])));
        $this->detailRepo->upsertPracticeLaps($season, $race, array_values((array) ($data['practiceLaps'] ?? [])));

        return true;
    }

    /**
     * Persists the real (0-999) setup dial values GPRO's own simulation used
     * for each session — ground truth, not a recommendation.
     *
     * @param array<string, mixed> $data
     */
    private function importSetupsUsed(array $data, int $season, int $race): void
    {
        foreach ((array) ($data['setupsUsed'] ?? []) as $setup) {
            if (!is_array($setup) || empty($setup['session'])) {
                continue;
            }

            $this->setupRepo->upsert([
                'season'      => $season,
                'race_number' => $race,
                'session'     => (string) $setup['session'],
                'set_fwing'   => $setup['setFWing'] ?? null,
                'set_rwing'   => $setup['setRWing'] ?? null,
                'set_eng'     => $setup['setEng']   ?? null,
                'set_bra'     => $setup['setBra']   ?? null,
                'set_gear'    => $setup['setGear']  ?? null,
                'set_susp'    => $setup['setSusp']  ?? null,
                'set_tyres'   => $setup['setTyres'] ?? null,
                'source'      => 'api',
            ]);
        }
    }
}
