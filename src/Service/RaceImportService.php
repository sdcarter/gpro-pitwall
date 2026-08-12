<?php

declare(strict_types=1);

namespace App\Service;

use App\Cache\CacheInterface;
use App\Repository\RaceObservationRepository;
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

    /** @param array<string, mixed> $data */
    private function importFromPayload(array $data, int $season, int $race): bool
    {
        $trackName = trim((string) ($data['trackName'] ?? ''));
        if ($trackName === '') {
            return false;
        }

        $stmt = $this->db->prepare('SELECT id, lap_length FROM tracks WHERE name = :n LIMIT 1');
        $stmt->execute([':n' => $trackName]);
        $trackRow    = $stmt->fetch(PDO::FETCH_ASSOC);
        $trackId     = $trackRow ? (int)   $trackRow['id']         : null;
        $lapLengthKm = $trackRow ? (float) $trackRow['lap_length'] : 0.0;

        $laps = (int) ($data['nbLaps'] ?? 0);
        if ($laps === 0 && is_array($data['laps'] ?? null)) {
            $laps = count($data['laps']);
        }

        $fuelPerKm  = null;
        $startFuel  = (float) ($data['startFuel']  ?? 0);
        $finishFuel = (float) ($data['finishFuel'] ?? 0);
        if ($laps > 0 && $lapLengthKm > 0 && ($startFuel - $finishFuel) > 0) {
            $fuelPerKm = round(($startFuel - $finishFuel) / ($laps * $lapLengthKm), 4);
        }

        $dryLaps = $rainLaps = 0;
        foreach ((array) ($data['laps'] ?? []) as $lap) {
            if (!is_array($lap)) {
                continue;
            }
            $w = strtolower((string) ($lap['weather'] ?? $lap['w'] ?? ''));
            if (str_contains($w, 'rain') || str_contains($w, 'wet')) {
                $rainLaps++;
            } elseif ($w !== '') {
                $dryLaps++;
            }
        }
        $weather = match (true) {
            $rainLaps === 0 && $dryLaps > 0 => 'dry',
            $dryLaps  === 0 && $rainLaps > 0 => 'wet',
            $rainLaps > 0   && $dryLaps > 0  => 'mixed',
            default                           => null,
        };

        $driver    = $data['driver'] ?? [];
        $driverPre = $driver['pre'] ?? $driver;

        $engLvl  = isset($data['lvlEngine'])        ? (float) $data['lvlEngine']
            : (isset($data['engine']['level'])       ? (float) $data['engine']['level']      : null);
        $eleLvl  = isset($data['lvlElectronics'])   ? (float) $data['lvlElectronics']
            : (isset($data['electronics']['level'])  ? (float) $data['electronics']['level'] : null);
        $suspLvl = isset($data['lvlSusp'])          ? (float) $data['lvlSusp']
            : (isset($data['susp']['level'])         ? (float) $data['susp']['level']        : null);

        $avgTemp = is_array($data['weather'] ?? null) ? ($data['weather']['temp'] ?? null) : null;

        $this->repo->upsert([
            'track_name'        => $trackName,
            'track_id'          => $trackId,
            'season'            => $season,
            'race_number'       => $race,
            'weather'           => $weather,
            'avg_temp'          => $avgTemp,
            'laps'              => $laps ?: null,
            'concentration'     => isset($driverPre['concentration'])   ? (int)   $driverPre['concentration']   : null,
            'aggressiveness'    => isset($driverPre['aggressiveness'])  ? (int)   $driverPre['aggressiveness']  : null,
            'experience'        => isset($driverPre['experience'])      ? (int)   $driverPre['experience']      : null,
            'technical_insight' => isset($driverPre['techInsight'])     ? (int)   $driverPre['techInsight']
                : (isset($driverPre['technical_insight'])                ? (int)   $driverPre['technical_insight'] : null),
            'weight'            => isset($driverPre['weight'])          ? (float) $driverPre['weight']          : null,
            'eng_lvl'           => $engLvl,
            'ele_lvl'           => $eleLvl,
            'susp_lvl'          => $suspLvl,
            'fuel_per_km'       => $fuelPerKm,
            'tyre_compound'     => null,
            'tyre_supplier'     => null,
            'tyre_wear_pct'     => isset($data['finishTyres']) ? (float) $data['finishTyres'] : null,
            'pit_count'         => is_array($data['pits'] ?? null) ? count($data['pits']) : null,
            'source'            => 'api',
        ]);

        return true;
    }
}
