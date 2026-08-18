<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Real setup dial values (0-999) actually used per session of a race, as
 * reported by RaceAnalysis's `setupsUsed` — ground truth GPRO's own
 * simulation consumed, not a recommendation from this app.
 */
class RaceSetupRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function hasRecord(int $season, int $race): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM race_setups WHERE season = :s AND race_number = :r LIMIT 1'
        );
        $stmt->execute([':s' => $season, ':r' => $race]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return list<array<string, mixed>> ordered Q1, Q2, Race */
    public function findForRace(int $season, int $race): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM race_setups
            WHERE season = :s AND race_number = :r
            ORDER BY CASE session WHEN 'Q1' THEN 1 WHEN 'Q2' THEN 2 WHEN 'Race' THEN 3 END
        ");
        $stmt->execute([':s' => $season, ':r' => $race]);
        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string, mixed> $row */
    public function upsert(array $row): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO race_setups (
                season, race_number, session,
                set_fwing, set_rwing, set_eng, set_bra, set_gear, set_susp, set_tyres,
                source
            ) VALUES (
                :season, :race_number, :session,
                :set_fwing, :set_rwing, :set_eng, :set_bra, :set_gear, :set_susp, :set_tyres,
                :source
            )
            ON CONFLICT(season, race_number, session, source) DO UPDATE SET
                set_fwing   = excluded.set_fwing,
                set_rwing   = excluded.set_rwing,
                set_eng     = excluded.set_eng,
                set_bra     = excluded.set_bra,
                set_gear    = excluded.set_gear,
                set_susp    = excluded.set_susp,
                set_tyres   = excluded.set_tyres,
                imported_at = datetime('now')
        ");

        $stmt->execute([
            ':season'      => (int) ($row['season'] ?? 0),
            ':race_number' => (int) ($row['race_number'] ?? 0),
            ':session'     => $row['session'] ?? '',
            ':set_fwing'   => isset($row['set_fwing']) ? (int) $row['set_fwing'] : null,
            ':set_rwing'   => isset($row['set_rwing']) ? (int) $row['set_rwing'] : null,
            ':set_eng'     => isset($row['set_eng'])   ? (int) $row['set_eng']   : null,
            ':set_bra'     => isset($row['set_bra'])   ? (int) $row['set_bra']   : null,
            ':set_gear'    => isset($row['set_gear'])  ? (int) $row['set_gear']  : null,
            ':set_susp'    => isset($row['set_susp'])  ? (int) $row['set_susp']  : null,
            ':set_tyres'   => $row['set_tyres'] ?? null,
            ':source'      => $row['source'] ?? 'api',
        ]);
    }

    /**
     * Most recent 'Race' session setup used at a given track, regardless of
     * weather — a starting point for a future "recall my last setup" lookup.
     *
     * @return array<string, mixed>|null
     */
    public function findLastRaceSetupForTrack(string $trackName): ?array
    {
        $stmt = $this->db->prepare("
            SELECT rs.*
            FROM race_setups rs
            JOIN race_observations ro
                ON ro.season = rs.season AND ro.race_number = rs.race_number
            WHERE ro.track_name = :track AND rs.session = 'Race'
            ORDER BY rs.season DESC, rs.race_number DESC
            LIMIT 1
        ");
        $stmt->execute([':track' => $trackName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
