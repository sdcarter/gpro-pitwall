<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

class RaceObservationRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function hasRecord(int $season, int $race): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM race_observations WHERE season = :s AND race_number = :r LIMIT 1'
        );
        $stmt->execute([':s' => $season, ':r' => $race]);
        return $stmt->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $row */
    public function upsert(array $row): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO race_observations (
                track_name, track_id, season, race_number, weather, avg_temp, laps,
                concentration, aggressiveness, experience, technical_insight, weight,
                eng_lvl, ele_lvl, susp_lvl,
                fuel_per_km, tyre_compound, tyre_supplier, tyre_wear_pct, pit_count,
                source
            ) VALUES (
                :track_name, :track_id, :season, :race_number, :weather, :avg_temp, :laps,
                :concentration, :aggressiveness, :experience, :technical_insight, :weight,
                :eng_lvl, :ele_lvl, :susp_lvl,
                :fuel_per_km, :tyre_compound, :tyre_supplier, :tyre_wear_pct, :pit_count,
                :source
            )
            ON CONFLICT(track_name, season, race_number, source) DO UPDATE SET
                track_id          = excluded.track_id,
                weather           = excluded.weather,
                avg_temp          = excluded.avg_temp,
                laps              = excluded.laps,
                concentration     = excluded.concentration,
                aggressiveness    = excluded.aggressiveness,
                experience        = excluded.experience,
                technical_insight = excluded.technical_insight,
                weight            = excluded.weight,
                eng_lvl           = excluded.eng_lvl,
                ele_lvl           = excluded.ele_lvl,
                susp_lvl          = excluded.susp_lvl,
                fuel_per_km       = excluded.fuel_per_km,
                tyre_compound     = excluded.tyre_compound,
                tyre_supplier     = excluded.tyre_supplier,
                tyre_wear_pct     = excluded.tyre_wear_pct,
                pit_count         = excluded.pit_count,
                imported_at       = datetime('now')
        ");

        $stmt->execute([
            ':track_name'        => $row['track_name'] ?? '',
            ':track_id'          => $row['track_id'] ?? null,
            ':season'            => (int) ($row['season'] ?? 0),
            ':race_number'       => (int) ($row['race_number'] ?? 0),
            ':weather'           => $row['weather'] ?? null,
            ':avg_temp'          => isset($row['avg_temp'])          ? (float) $row['avg_temp']          : null,
            ':laps'              => isset($row['laps'])              ? (int)   $row['laps']              : null,
            ':concentration'     => isset($row['concentration'])     ? (int)   $row['concentration']     : null,
            ':aggressiveness'    => isset($row['aggressiveness'])    ? (int)   $row['aggressiveness']    : null,
            ':experience'        => isset($row['experience'])        ? (int)   $row['experience']        : null,
            ':technical_insight' => isset($row['technical_insight']) ? (int)   $row['technical_insight'] : null,
            ':weight'            => isset($row['weight'])            ? (float) $row['weight']            : null,
            ':eng_lvl'           => isset($row['eng_lvl'])           ? (float) $row['eng_lvl']           : null,
            ':ele_lvl'           => isset($row['ele_lvl'])           ? (float) $row['ele_lvl']           : null,
            ':susp_lvl'          => isset($row['susp_lvl'])          ? (float) $row['susp_lvl']          : null,
            ':fuel_per_km'       => isset($row['fuel_per_km'])       ? (float) $row['fuel_per_km']       : null,
            ':tyre_compound'     => $row['tyre_compound']  ?? null,
            ':tyre_supplier'     => $row['tyre_supplier']  ?? null,
            ':tyre_wear_pct'     => isset($row['tyre_wear_pct'])     ? (float) $row['tyre_wear_pct']     : null,
            ':pit_count'         => isset($row['pit_count'])         ? (int)   $row['pit_count']         : null,
            ':source'            => $row['source'] ?? 'api',
        ]);
    }

    /**
     * Returns dry-weather observations with a non-null fuel_per_km that can
     * be joined to the tracks table for coefficient fitting.
     *
     * @return list<array<string, mixed>>
     */
    public function findForFitting(): array
    {
        $stmt = $this->db->query("
            SELECT ro.*, t.fuel_per_lap, t.lap_length
            FROM race_observations ro
            JOIN tracks t ON t.name = ro.track_name
            WHERE ro.weather = 'dry'
              AND ro.fuel_per_km IS NOT NULL
              AND t.fuel_per_lap IS NOT NULL
              AND t.fuel_per_lap > 0
            ORDER BY ro.season, ro.race_number
        ");
        return $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<int> $ids */
    public function markCalibrated(array $ids): void
    {
        if (empty($ids)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->db->prepare("UPDATE race_observations SET calibrated = 1 WHERE id IN ({$placeholders})")
            ->execute($ids);
    }
}
