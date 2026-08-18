<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Full per-race detail beyond the race_observations summary row: lap-by-lap
 * timeline, pit stops, every car part's level/wear, and the financial
 * breakdown. Every value here is a direct RaceAnalysis field — nothing
 * derived — kept queryable instead of only living in the raw_payload blob.
 */
class RaceDetailRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @param array<int, mixed> $laps */
    public function upsertLaps(int $season, int $race, array $laps): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO race_laps (
                season, race_number, lap_idx, position, tyre_compound, tyre_cond,
                fuel_load, weather, temp, hum, lap_time, boost_lap, events, source
            ) VALUES (
                :season, :race, :lap_idx, :position, :tyre_compound, :tyre_cond,
                :fuel_load, :weather, :temp, :hum, :lap_time, :boost_lap, :events, :source
            )
            ON CONFLICT(season, race_number, lap_idx, source) DO UPDATE SET
                position      = excluded.position,
                tyre_compound = excluded.tyre_compound,
                tyre_cond     = excluded.tyre_cond,
                fuel_load     = excluded.fuel_load,
                weather       = excluded.weather,
                temp          = excluded.temp,
                hum           = excluded.hum,
                lap_time      = excluded.lap_time,
                boost_lap     = excluded.boost_lap,
                events        = excluded.events
        ");

        foreach ($laps as $lap) {
            if (!is_array($lap) || !isset($lap['idx'])) {
                continue;
            }
            $stmt->execute([
                ':season'        => $season,
                ':race'          => $race,
                ':lap_idx'       => (int) $lap['idx'],
                ':position'      => isset($lap['pos'])       ? (int)   $lap['pos']       : null,
                ':tyre_compound' => $lap['tyres']             ?? null,
                ':tyre_cond'     => isset($lap['tyreCond'])   ? (float) $lap['tyreCond']  : null,
                ':fuel_load'     => isset($lap['fuelLoad'])   ? (float) $lap['fuelLoad']  : null,
                ':weather'       => $lap['weather']           ?? null,
                ':temp'          => isset($lap['temp'])       ? (float) $lap['temp']      : null,
                ':hum'           => isset($lap['hum'])         ? (float) $lap['hum']        : null,
                ':lap_time'      => $lap['lapTime']           ?? null,
                ':boost_lap'     => isset($lap['boostLap'])   ? (int)   $lap['boostLap']  : null,
                ':events'        => !empty($lap['events']) ? json_encode($lap['events']) : null,
                ':source'        => 'api',
            ]);
        }
    }

    /** @param array<int, mixed> $pits */
    public function upsertPits(int $season, int $race, array $pits): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO race_pits (
                season, race_number, pit_idx, lap, reason, tyre_cond, fuel_left,
                refilled_to, pit_time, source
            ) VALUES (
                :season, :race, :pit_idx, :lap, :reason, :tyre_cond, :fuel_left,
                :refilled_to, :pit_time, :source
            )
            ON CONFLICT(season, race_number, pit_idx, source) DO UPDATE SET
                lap         = excluded.lap,
                reason      = excluded.reason,
                tyre_cond   = excluded.tyre_cond,
                fuel_left   = excluded.fuel_left,
                refilled_to = excluded.refilled_to,
                pit_time    = excluded.pit_time
        ");

        foreach ($pits as $pit) {
            if (!is_array($pit) || !isset($pit['idx'])) {
                continue;
            }
            $stmt->execute([
                ':season'      => $season,
                ':race'        => $race,
                ':pit_idx'     => (int) $pit['idx'],
                ':lap'         => isset($pit['lap'])        ? (int)   $pit['lap']        : null,
                ':reason'      => $pit['reason']            ?? null,
                ':tyre_cond'   => isset($pit['tyreCond'])   ? (float) $pit['tyreCond']   : null,
                ':fuel_left'   => isset($pit['fuelLeft'])   ? (float) $pit['fuelLeft']   : null,
                ':refilled_to' => isset($pit['refilledTo']) ? (float) $pit['refilledTo'] : null,
                ':pit_time'    => $pit['pitTime']           ?? null,
                ':source'      => 'api',
            ]);
        }
    }

    /** @param array<string, mixed> $parts keyed by part name, e.g. 'engine' => ['lvl'=>2,...] */
    public function upsertCarParts(int $season, int $race, array $parts): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO race_car_parts (
                season, race_number, part, lvl, start_wear, finish_wear, source
            ) VALUES (
                :season, :race, :part, :lvl, :start_wear, :finish_wear, :source
            )
            ON CONFLICT(season, race_number, part, source) DO UPDATE SET
                lvl         = excluded.lvl,
                start_wear  = excluded.start_wear,
                finish_wear = excluded.finish_wear
        ");

        foreach ($parts as $partName => $part) {
            if (!is_array($part)) {
                continue;
            }
            $stmt->execute([
                ':season'      => $season,
                ':race'        => $race,
                ':part'        => $partName,
                ':lvl'         => isset($part['lvl'])         ? (float) $part['lvl']         : null,
                ':start_wear'  => isset($part['startWear'])   ? (float) $part['startWear']   : null,
                ':finish_wear' => isset($part['finishWear'])  ? (float) $part['finishWear']  : null,
                ':source'      => 'api',
            ]);
        }
    }

    /**
     * Line items have no natural key, so replace the whole set for this
     * race on every import rather than trying to upsert individual rows.
     *
     * @param array<int, mixed> $transactions
     */
    public function replaceTransactions(int $season, int $race, array $transactions): void
    {
        $delete = $this->db->prepare(
            "DELETE FROM race_transactions WHERE season = :season AND race_number = :race AND source = 'api'"
        );
        $delete->execute([':season' => $season, ':race' => $race]);

        $insert = $this->db->prepare("
            INSERT INTO race_transactions (season, race_number, description, amount, source)
            VALUES (:season, :race, :description, :amount, 'api')
        ");

        foreach ($transactions as $txn) {
            if (!is_array($txn)) {
                continue;
            }
            $insert->execute([
                ':season'      => $season,
                ':race'        => $race,
                ':description' => $txn['desc']   ?? null,
                ':amount'      => isset($txn['amount']) ? (float) $txn['amount'] : null,
            ]);
        }
    }

    /** @return list<array<string, mixed>> ordered by lap number */
    public function getLaps(int $season, int $race): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM race_laps WHERE season = :s AND race_number = :r ORDER BY lap_idx'
        );
        $stmt->execute([':s' => $season, ':r' => $race]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Decode for the view — Twig has no json_decode filter here.
        foreach ($rows as &$row) {
            $row['events'] = $row['events'] !== null ? json_decode((string) $row['events'], true) : [];
        }
        unset($row);

        return array_values($rows);
    }

    /** @return list<array<string, mixed>> ordered by pit number */
    public function getPits(int $season, int $race): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM race_pits WHERE season = :s AND race_number = :r ORDER BY pit_idx'
        );
        $stmt->execute([':s' => $season, ':r' => $race]);
        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> ordered by part name */
    public function getCarParts(int $season, int $race): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM race_car_parts WHERE season = :s AND race_number = :r ORDER BY part'
        );
        $stmt->execute([':s' => $season, ':r' => $race]);
        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> */
    public function getTransactions(int $season, int $race): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM race_transactions WHERE season = :s AND race_number = :r ORDER BY id'
        );
        $stmt->execute([':s' => $season, ':r' => $race]);
        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<int, mixed> $laps */
    public function upsertPracticeLaps(int $season, int $race, array $laps): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO race_practice_laps (
                season, race_number, lap_idx, lap_time, net_time, mistake_time,
                set_fwing, set_rwing, set_engine, set_brakes, set_gear, set_susp,
                set_tyres, driver_comments, source
            ) VALUES (
                :season, :race, :lap_idx, :lap_time, :net_time, :mistake_time,
                :set_fwing, :set_rwing, :set_engine, :set_brakes, :set_gear, :set_susp,
                :set_tyres, :driver_comments, :source
            )
            ON CONFLICT(season, race_number, lap_idx, source) DO UPDATE SET
                lap_time        = excluded.lap_time,
                net_time        = excluded.net_time,
                mistake_time    = excluded.mistake_time,
                set_fwing       = excluded.set_fwing,
                set_rwing       = excluded.set_rwing,
                set_engine      = excluded.set_engine,
                set_brakes      = excluded.set_brakes,
                set_gear        = excluded.set_gear,
                set_susp        = excluded.set_susp,
                set_tyres       = excluded.set_tyres,
                driver_comments = excluded.driver_comments
        ");

        foreach ($laps as $lap) {
            if (!is_array($lap) || !isset($lap['idx'])) {
                continue;
            }
            $stmt->execute([
                ':season'          => $season,
                ':race'            => $race,
                ':lap_idx'         => (int) $lap['idx'],
                ':lap_time'        => $lap['lapTime']   ?? null,
                ':net_time'        => $lap['netTime']   ?? null,
                ':mistake_time'    => $lap['misTime']   ?? null,
                ':set_fwing'       => $lap['setFWing']['value']  ?? null,
                ':set_rwing'       => $lap['setRWing']['value']  ?? null,
                ':set_engine'      => $lap['setEngine']['value'] ?? null,
                ':set_brakes'      => $lap['setBrakes']['value'] ?? null,
                ':set_gear'        => $lap['setGear']['value']   ?? null,
                ':set_susp'        => $lap['setSusp']['value']   ?? null,
                ':set_tyres'       => $lap['setTyres']  ?? null,
                ':driver_comments' => !empty($lap['driComments']) ? json_encode($lap['driComments']) : null,
                ':source'          => 'api',
            ]);
        }
    }

    /** @return list<array<string, mixed>> ordered by lap number */
    public function getPracticeLaps(int $season, int $race): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM race_practice_laps WHERE season = :s AND race_number = :r ORDER BY lap_idx'
        );
        $stmt->execute([':s' => $season, ':r' => $race]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Decode for the view — Twig has no json_decode filter here.
        foreach ($rows as &$row) {
            $row['driver_comments'] = $row['driver_comments'] !== null
                ? json_decode((string) $row['driver_comments'], true)
                : [];
        }
        unset($row);

        return array_values($rows);
    }
}
