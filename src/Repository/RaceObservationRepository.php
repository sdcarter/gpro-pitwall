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
                source, raw_payload,
                oa_change, con_change, tal_change, agr_change, exp_change,
                tei_change, sta_change, cha_change, mot_change, rep_change, wei_change,
                earnings_total, balance_after,
                q1_time, q1_pos, q2_time, q2_pos,
                q1_risk, q2_risk, start_risk, overtake_risk, defend_risk,
                clear_dry_risk, clear_wet_risk, problem_risk, technical_problems,
                q1_energy_from, q1_energy_to, q2_energy_from, q2_energy_to,
                race_energy_from, race_energy_to, car_power, car_handl, car_accel,
                ot_attempts, overtakes, ot_attempts_on_you, overtakes_on_you
            ) VALUES (
                :track_name, :track_id, :season, :race_number, :weather, :avg_temp, :laps,
                :concentration, :aggressiveness, :experience, :technical_insight, :weight,
                :eng_lvl, :ele_lvl, :susp_lvl,
                :fuel_per_km, :tyre_compound, :tyre_supplier, :tyre_wear_pct, :pit_count,
                :source, :raw_payload,
                :oa_change, :con_change, :tal_change, :agr_change, :exp_change,
                :tei_change, :sta_change, :cha_change, :mot_change, :rep_change, :wei_change,
                :earnings_total, :balance_after,
                :q1_time, :q1_pos, :q2_time, :q2_pos,
                :q1_risk, :q2_risk, :start_risk, :overtake_risk, :defend_risk,
                :clear_dry_risk, :clear_wet_risk, :problem_risk, :technical_problems,
                :q1_energy_from, :q1_energy_to, :q2_energy_from, :q2_energy_to,
                :race_energy_from, :race_energy_to, :car_power, :car_handl, :car_accel,
                :ot_attempts, :overtakes, :ot_attempts_on_you, :overtakes_on_you
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
                raw_payload       = excluded.raw_payload,
                oa_change         = excluded.oa_change,
                con_change        = excluded.con_change,
                tal_change        = excluded.tal_change,
                agr_change        = excluded.agr_change,
                exp_change        = excluded.exp_change,
                tei_change        = excluded.tei_change,
                sta_change        = excluded.sta_change,
                cha_change        = excluded.cha_change,
                mot_change        = excluded.mot_change,
                rep_change        = excluded.rep_change,
                wei_change        = excluded.wei_change,
                earnings_total    = excluded.earnings_total,
                balance_after     = excluded.balance_after,
                q1_time           = excluded.q1_time,
                q1_pos            = excluded.q1_pos,
                q2_time           = excluded.q2_time,
                q2_pos            = excluded.q2_pos,
                q1_risk           = excluded.q1_risk,
                q2_risk           = excluded.q2_risk,
                start_risk        = excluded.start_risk,
                overtake_risk     = excluded.overtake_risk,
                defend_risk       = excluded.defend_risk,
                clear_dry_risk    = excluded.clear_dry_risk,
                clear_wet_risk    = excluded.clear_wet_risk,
                problem_risk      = excluded.problem_risk,
                technical_problems = excluded.technical_problems,
                q1_energy_from    = excluded.q1_energy_from,
                q1_energy_to      = excluded.q1_energy_to,
                q2_energy_from    = excluded.q2_energy_from,
                q2_energy_to      = excluded.q2_energy_to,
                race_energy_from  = excluded.race_energy_from,
                race_energy_to    = excluded.race_energy_to,
                car_power         = excluded.car_power,
                car_handl         = excluded.car_handl,
                car_accel         = excluded.car_accel,
                ot_attempts       = excluded.ot_attempts,
                overtakes         = excluded.overtakes,
                ot_attempts_on_you = excluded.ot_attempts_on_you,
                overtakes_on_you  = excluded.overtakes_on_you,
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
            ':raw_payload'       => $row['raw_payload'] ?? null,
            ':oa_change'         => isset($row['oa_change'])  ? (float) $row['oa_change']  : null,
            ':con_change'        => isset($row['con_change']) ? (float) $row['con_change'] : null,
            ':tal_change'        => isset($row['tal_change']) ? (float) $row['tal_change'] : null,
            ':agr_change'        => isset($row['agr_change']) ? (float) $row['agr_change'] : null,
            ':exp_change'        => isset($row['exp_change']) ? (float) $row['exp_change'] : null,
            ':tei_change'        => isset($row['tei_change']) ? (float) $row['tei_change'] : null,
            ':sta_change'        => isset($row['sta_change']) ? (float) $row['sta_change'] : null,
            ':cha_change'        => isset($row['cha_change']) ? (float) $row['cha_change'] : null,
            ':mot_change'        => isset($row['mot_change']) ? (float) $row['mot_change'] : null,
            ':rep_change'        => isset($row['rep_change']) ? (float) $row['rep_change'] : null,
            ':wei_change'        => isset($row['wei_change']) ? (float) $row['wei_change'] : null,
            ':earnings_total'    => isset($row['earnings_total']) ? (float) $row['earnings_total'] : null,
            ':balance_after'     => isset($row['balance_after'])  ? (float) $row['balance_after']  : null,
            ':q1_time'           => $row['q1_time'] ?? null,
            ':q1_pos'            => isset($row['q1_pos']) ? (int) $row['q1_pos'] : null,
            ':q2_time'           => $row['q2_time'] ?? null,
            ':q2_pos'            => isset($row['q2_pos']) ? (int) $row['q2_pos'] : null,
            ':q1_risk'           => $row['q1_risk'] ?? null,
            ':q2_risk'           => $row['q2_risk'] ?? null,
            ':start_risk'        => $row['start_risk'] ?? null,
            ':overtake_risk'     => $row['overtake_risk'] ?? null,
            ':defend_risk'       => $row['defend_risk'] ?? null,
            ':clear_dry_risk'    => $row['clear_dry_risk'] ?? null,
            ':clear_wet_risk'    => $row['clear_wet_risk'] ?? null,
            ':problem_risk'      => $row['problem_risk'] ?? null,
            ':technical_problems' => $row['technical_problems'] ?? null,
            ':q1_energy_from'    => isset($row['q1_energy_from'])   ? (float) $row['q1_energy_from']   : null,
            ':q1_energy_to'      => isset($row['q1_energy_to'])     ? (float) $row['q1_energy_to']     : null,
            ':q2_energy_from'    => isset($row['q2_energy_from'])   ? (float) $row['q2_energy_from']   : null,
            ':q2_energy_to'      => isset($row['q2_energy_to'])     ? (float) $row['q2_energy_to']     : null,
            ':race_energy_from'  => isset($row['race_energy_from']) ? (float) $row['race_energy_from'] : null,
            ':race_energy_to'    => isset($row['race_energy_to'])   ? (float) $row['race_energy_to']   : null,
            ':car_power'         => isset($row['car_power'])  ? (float) $row['car_power']  : null,
            ':car_handl'         => isset($row['car_handl'])  ? (float) $row['car_handl']  : null,
            ':car_accel'         => isset($row['car_accel'])  ? (float) $row['car_accel']  : null,
            ':ot_attempts'       => isset($row['ot_attempts'])        ? (int) $row['ot_attempts']        : null,
            ':overtakes'         => isset($row['overtakes'])          ? (int) $row['overtakes']          : null,
            ':ot_attempts_on_you' => isset($row['ot_attempts_on_you']) ? (int) $row['ot_attempts_on_you'] : null,
            ':overtakes_on_you'  => isset($row['overtakes_on_you'])   ? (int) $row['overtakes_on_you']   : null,
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

    /**
     * Filtered race list for the Race History tab. All filters are optional;
     * the track-characteristic filters (overtaking/grip/tyre_wear/
     * fuel_consumption) join to the tracks table's own Low/Medium/High text
     * columns — the numeric track variables (wing_split, base_suspension,
     * etc.) are deliberately not exposed as filters.
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function findAll(array $filters = []): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['season'])) {
            $where[]            = 'ro.season = :season';
            $params[':season']  = (int) $filters['season'];
        }
        if (!empty($filters['track_name'])) {
            $where[]              = 'ro.track_name = :track_name';
            $params[':track_name'] = (string) $filters['track_name'];
        }
        if (!empty($filters['weather'])) {
            $where[]            = 'ro.weather = :weather';
            $params[':weather'] = (string) $filters['weather'];
        }
        if (!empty($filters['tyre_supplier'])) {
            $where[]                  = 'ro.tyre_supplier = :tyre_supplier';
            $params[':tyre_supplier'] = (string) $filters['tyre_supplier'];
        }
        foreach (['overtaking', 'grip', 'tyre_wear', 'fuel_consumption'] as $trackAttr) {
            if (!empty($filters[$trackAttr])) {
                $where[]              = "t.{$trackAttr} = :{$trackAttr}";
                $params[":{$trackAttr}"] = (string) $filters[$trackAttr];
            }
        }

        $sql = "
            SELECT ro.*, t.overtaking, t.grip, t.tyre_wear, t.fuel_consumption
            FROM race_observations ro
            LEFT JOIN tracks t ON t.name = ro.track_name
        ";
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY ro.season DESC, ro.race_number DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public function findOne(int $season, int $race): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM race_observations WHERE season = :s AND race_number = :r LIMIT 1');
        $stmt->execute([':s' => $season, ':r' => $race]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return list<int> */
    public function distinctSeasons(): array
    {
        $stmt = $this->db->query('SELECT DISTINCT season FROM race_observations ORDER BY season DESC');
        return $stmt === false ? [] : array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @return list<string> */
    public function distinctTracks(): array
    {
        $stmt = $this->db->query('SELECT DISTINCT track_name FROM race_observations ORDER BY track_name');
        return $stmt === false ? [] : array_values($stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<string> */
    public function distinctTyreSuppliers(): array
    {
        $stmt = $this->db->query(
            "SELECT DISTINCT tyre_supplier FROM race_observations WHERE tyre_supplier IS NOT NULL ORDER BY tyre_supplier"
        );
        return $stmt === false ? [] : array_values($stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Distinct values actually in use (only tracks you've raced at) for one
     * of the tracks table's Low/Medium/High text columns.
     *
     * @return list<string>
     */
    public function distinctTrackAttr(string $column): array
    {
        if (!in_array($column, ['overtaking', 'grip', 'tyre_wear', 'fuel_consumption'], true)) {
            return [];
        }
        $stmt = $this->db->query("
            SELECT DISTINCT t.{$column}
            FROM tracks t
            JOIN race_observations ro ON ro.track_name = t.name
            WHERE t.{$column} IS NOT NULL AND t.{$column} != ''
            ORDER BY t.{$column}
        ");
        return $stmt === false ? [] : array_values($stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
