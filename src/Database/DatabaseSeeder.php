<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use App\Security\ApiTokenCrypto;

class DatabaseSeeder
{
    /**
     * Bump this whenever a migration/seed step is added or changed below — or
     * when data/tracks.csv changes: prod is SFTP-only, so this gate is the only
     * thing that makes a warm database re-run seedTracksFromCsv. The boot path
     * calls migrate() on every request; this version lets a warm database skip
     * the entire DDL + scan + legacy-encryption pass.
     */
    private const int SCHEMA_VERSION = 12;

    /**
     * @param array<string, string> $statsSchema
     * @param list<string>          $divisions
     * @param array<string, mixed>  $secrets
     */
    public function __construct(
        private readonly PDO $db,
        private readonly array $statsSchema,
        private readonly array $divisions,
        private array $secrets,
        private readonly ApiTokenCrypto $apiTokenCrypto,
    ) {
    }

    public function migrate(): void
    {
        // migrate() runs on every request (see bootstrap.php). Skip the whole
        // sequence once the DB is at the current schema version — turns ~15 DDL
        // statements, the PRAGMA table_info scans, and the per-user legacy-token
        // re-encryption into a single cheap PRAGMA read on the hot path.
        $stmt = $this->db->query('PRAGMA user_version');
        $current = $stmt === false ? 0 : (int) $stmt->fetchColumn();
        if ($current >= self::SCHEMA_VERSION) {
            return;
        }

        $this->createUsersTable();
        $this->createVerificationTokensTable();
        $this->createIndexVerificationUser();
        $this->createPersistentTokensTable();
        $this->createPendingRegistrationsTable();
        $this->createAuditLogTable();

        $this->createPilotsTable();
        $this->createMetadataTable();
        $this->createTracksTable();
        $this->createTrainingsTable();
        $this->createCarPartCoefficientsTable();
        $this->createGameConstantsTable();
        $this->dropDeprecatedTables();
        $this->createRaceObservationsTable();
        $this->applyRaceObservationMigrations();
        $this->createRaceSetupsTable();
        $this->createRaceDetailTables();
        $this->applyRaceDetailMigrations();


        $this->seedTrainings();
        $this->seedCarPartCoefficients();
        $this->seedGameConstants();
        $this->seedTracksFromCsv();


        $this->applyUserMigrations();
        $this->purgeUnverifiedUsers();
        $this->hardenUserUniqueIndexes();
        $this->encryptLegacyApiTokens();

        // Stamp the schema version last: if any step above throws, the version
        // stays behind and the next boot retries the full migrate.
        $this->db->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
    }

    private function createRaceObservationsTable(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS race_observations (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                track_name        TEXT    NOT NULL,
                track_id          INTEGER,
                season            INTEGER NOT NULL,
                race_number       INTEGER NOT NULL,
                weather           TEXT    CHECK(weather IN ('dry','wet','mixed')),
                avg_temp          REAL,
                laps              INTEGER,
                concentration     INTEGER,
                aggressiveness    INTEGER,
                experience        INTEGER,
                technical_insight INTEGER,
                weight            REAL,
                eng_lvl           REAL,
                ele_lvl           REAL,
                susp_lvl          REAL,
                fuel_per_km       REAL,
                tyre_compound     TEXT,
                tyre_supplier     TEXT,
                tyre_wear_pct     REAL,
                pit_count         INTEGER,
                source            TEXT NOT NULL DEFAULT 'api',
                calibrated        INTEGER NOT NULL DEFAULT 0,
                raw_payload       TEXT,
                imported_at       TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE(track_name, season, race_number, source)
            )
        ");
    }

    /**
     * Adds columns to race_observations that were introduced after its
     * initial CREATE TABLE — needed so a warm database (which skips the
     * CREATE TABLE IF NOT EXISTS above) still picks them up.
     */
    private function applyRaceObservationMigrations(): void
    {
        $stmt = $this->db->query('PRAGMA table_info(race_observations)');
        $cols = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);
        $existingCols = array_column($cols, 'name');

        if (!in_array('raw_payload', $existingCols, true)) {
            $this->db->exec('ALTER TABLE race_observations ADD COLUMN raw_payload TEXT');
        }

        // Post-race driver stat deltas (RaceAnalysis's `driverChanges`) and a
        // per-race earnings summary (`total` / `currentBalance`).
        $driverChangeCols = [
            'oa_change', 'con_change', 'tal_change', 'agr_change', 'exp_change',
            'tei_change', 'sta_change', 'cha_change', 'mot_change', 'rep_change', 'wei_change',
        ];
        foreach ($driverChangeCols as $col) {
            if (!in_array($col, $existingCols, true)) {
                $this->db->exec("ALTER TABLE race_observations ADD COLUMN {$col} REAL");
            }
        }
        if (!in_array('earnings_total', $existingCols, true)) {
            $this->db->exec('ALTER TABLE race_observations ADD COLUMN earnings_total REAL');
        }
        if (!in_array('balance_after', $existingCols, true)) {
            $this->db->exec('ALTER TABLE race_observations ADD COLUMN balance_after REAL');
        }

        // Qualifying, risk profile, driver energy, car stats, overtaking
        // counts, and technical problems — everything RaceAnalysis reports
        // beyond the race itself.
        $textCols = [
            'q1_time', 'q2_time', 'q1_risk', 'q2_risk', 'start_risk', 'overtake_risk',
            'defend_risk', 'clear_dry_risk', 'clear_wet_risk', 'problem_risk', 'technical_problems',
        ];
        foreach ($textCols as $col) {
            if (!in_array($col, $existingCols, true)) {
                $this->db->exec("ALTER TABLE race_observations ADD COLUMN {$col} TEXT");
            }
        }
        $intCols = ['q1_pos', 'q2_pos', 'ot_attempts', 'overtakes', 'ot_attempts_on_you', 'overtakes_on_you'];
        foreach ($intCols as $col) {
            if (!in_array($col, $existingCols, true)) {
                $this->db->exec("ALTER TABLE race_observations ADD COLUMN {$col} INTEGER");
            }
        }
        $realCols = [
            'q1_energy_from', 'q1_energy_to', 'q2_energy_from', 'q2_energy_to',
            'race_energy_from', 'race_energy_to', 'car_power', 'car_handl', 'car_accel',
        ];
        foreach ($realCols as $col) {
            if (!in_array($col, $existingCols, true)) {
                $this->db->exec("ALTER TABLE race_observations ADD COLUMN {$col} REAL");
            }
        }
    }

    /**
     * Real setup dial values (0-999) the manager actually entered for each
     * session of a race, as reported by RaceAnalysis's `setupsUsed`. This is
     * ground truth GPRO's own simulation consumed — unlike our own setup
     * recommendation, it can be joined to that race's wear/outcome data to
     * eventually calibrate the setup formula.
     */
    private function createRaceSetupsTable(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS race_setups (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                season       INTEGER NOT NULL,
                race_number  INTEGER NOT NULL,
                session      TEXT    NOT NULL CHECK(session IN ('Q1','Q2','Race')),
                set_fwing    INTEGER,
                set_rwing    INTEGER,
                set_eng      INTEGER,
                set_bra      INTEGER,
                set_gear     INTEGER,
                set_susp     INTEGER,
                set_tyres    TEXT,
                source       TEXT NOT NULL DEFAULT 'api',
                imported_at  TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE(season, race_number, session, source)
            )
        ");
    }

    /**
     * Full per-race detail beyond the race_observations summary row: the
     * lap-by-lap timeline (standings, tyre/fuel state, weather), pit stop
     * detail, every car part's level/wear, and the financial breakdown.
     * Nothing here is derived — every value is a direct field from
     * RaceAnalysis, kept as its own row so it stays queryable rather than
     * only living inside the raw_payload blob.
     */
    private function createRaceDetailTables(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS race_laps (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                season        INTEGER NOT NULL,
                race_number   INTEGER NOT NULL,
                lap_idx       INTEGER NOT NULL,
                position      INTEGER,
                tyre_compound TEXT,
                tyre_cond     REAL,
                fuel_load     REAL,
                weather       TEXT,
                temp          REAL,
                hum           REAL,
                lap_time      TEXT,
                boost_lap     INTEGER,
                events        TEXT,
                source        TEXT NOT NULL DEFAULT 'api',
                UNIQUE(season, race_number, lap_idx, source)
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS race_pits (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                season       INTEGER NOT NULL,
                race_number  INTEGER NOT NULL,
                pit_idx      INTEGER NOT NULL,
                lap          INTEGER,
                reason       TEXT,
                tyre_cond    REAL,
                fuel_left    REAL,
                refilled_to  REAL,
                pit_time     TEXT,
                source       TEXT NOT NULL DEFAULT 'api',
                UNIQUE(season, race_number, pit_idx, source)
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS race_car_parts (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                season       INTEGER NOT NULL,
                race_number  INTEGER NOT NULL,
                part         TEXT    NOT NULL,
                lvl          REAL,
                start_wear   REAL,
                finish_wear  REAL,
                source       TEXT NOT NULL DEFAULT 'api',
                UNIQUE(season, race_number, part, source)
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS race_transactions (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                season       INTEGER NOT NULL,
                race_number  INTEGER NOT NULL,
                description  TEXT,
                amount       REAL,
                source       TEXT NOT NULL DEFAULT 'api'
            )
        ");

        // Practice-session laps: per-lap times plus the setup dial values in
        // effect and the driver's textual feedback for that lap, exactly as
        // RaceAnalysis's `practiceLaps` reports them.
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS race_practice_laps (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                season            INTEGER NOT NULL,
                race_number       INTEGER NOT NULL,
                lap_idx           INTEGER NOT NULL,
                lap_time          TEXT,
                net_time          TEXT,
                mistake_time      TEXT,
                set_fwing         INTEGER,
                set_rwing         INTEGER,
                set_engine        INTEGER,
                set_brakes        INTEGER,
                set_gear          INTEGER,
                set_susp          INTEGER,
                set_tyres         TEXT,
                driver_comments   TEXT,
                source            TEXT NOT NULL DEFAULT 'api',
                UNIQUE(season, race_number, lap_idx, source)
            )
        ");
    }

    /**
     * Adds columns to race_laps that were introduced after its initial
     * CREATE TABLE — needed so a warm database still picks them up.
     */
    private function applyRaceDetailMigrations(): void
    {
        $stmt = $this->db->query('PRAGMA table_info(race_laps)');
        $cols = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);
        $existingCols = array_column($cols, 'name');

        if (!in_array('events', $existingCols, true)) {
            $this->db->exec('ALTER TABLE race_laps ADD COLUMN events TEXT');
        }
    }

    /**
     * Removes tables no longer used by the app. Runs every boot so a
     * fresh checkout converges. Idempotent — DROP TABLE IF EXISTS.
     */
    private function dropDeprecatedTables(): void
    {
        $this->db->exec('DROP TABLE IF EXISTS track_risks');
    }

    /**
     * (Re)seed the tracks table from data/tracks.csv. Runs inside migrate()
     * because prod is SFTP-only shared hosting — there is no shell to run
     * bin/seed_tracks.php, so the first request after a deploy must do it.
     * Idempotent (INSERT OR REPLACE); a missing CSV is a silent no-op so
     * tests and CI (where data/ is gitignored) keep working.
     *
     * @return int number of tracks written
     */
    public function seedTracksFromCsv(?string $csvPath = null): int
    {
        $csvPath ??= dirname(__DIR__, 2) . '/data/tracks.csv';
        if (!is_file($csvPath)) {
            return 0;
        }

        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            return 0;
        }

        // Row 0 is the sheet title, row 1 the column headers.
        fgetcsv($handle, 0, ';', '"', '');
        fgetcsv($handle, 0, ';', '"', '');

        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO tracks (
                id, name, lap_length, laps, distance, avg_speed, corners, pit_time,
                base_wings, base_engine, base_brakes, base_gear, base_suspension,
                fuel_consumption, tyre_wear, wing_split, fuel_per_lap, fuel_per_lap_wet, tyre_wear_factor,
                wear_chassis, wear_engine, wear_fwing, wear_rwing, wear_underbody, wear_sidepod,
                wear_cooling, wear_gearbox, wear_brakes, wear_suspension, wear_electronics,
                boost_dry, boost_wet, overtaking, grip
            ) VALUES (
                :id, :name, :lap_length, :laps, :distance, :avg_speed, :corners, :pit_time,
                :base_wings, :base_engine, :base_brakes, :base_gear, :base_suspension,
                :fuel_consumption, :tyre_wear, :wing_split, :fuel_per_lap, :fuel_per_lap_wet, :tyre_wear_factor,
                :wear_chassis, :wear_engine, :wear_fwing, :wear_rwing, :wear_underbody, :wear_sidepod,
                :wear_cooling, :wear_gearbox, :wear_brakes, :wear_suspension, :wear_electronics,
                :boost_dry, :boost_wet, :overtaking, :grip
            )
        ");

        $n = static fn(?string $val): float => (float) str_replace(',', '.', $val ?? '0');

        $count = 0;
        while (($row = fgetcsv($handle, 0, ';', '"', '')) !== false) {
            if (empty($row[0])) {
                continue;
            }

            $stmt->execute([
                ':id' => $count + 1,
                ':name' => trim($row[0]),
                ':lap_length' => $n($row[6] ?? null),
                ':laps' => (int) $n($row[7] ?? null),
                ':distance' => $n($row[8] ?? null),
                ':avg_speed' => $n($row[12] ?? null),
                ':corners' => (int) $n($row[14] ?? null),
                ':pit_time' => $n($row[15] ?? null),

                ':base_wings' => $n($row[18] ?? null),
                ':base_engine' => $n($row[19] ?? null),
                ':base_brakes' => $n($row[20] ?? null),
                ':base_gear' => $n($row[21] ?? null),
                ':base_suspension' => $n($row[22] ?? null),

                ':fuel_consumption' => trim($row[4] ?? ''),
                ':tyre_wear' => trim($row[5] ?? ''),
                ':wing_split' => $n($row[24] ?? null),
                ':fuel_per_lap' => $n($row[26] ?? null),
                ':fuel_per_lap_wet' => $n($row[27] ?? null),
                ':tyre_wear_factor' => $n($row[29] ?? null),

                ':wear_chassis' => $n($row[31] ?? null),
                ':wear_engine' => $n($row[32] ?? null),
                ':wear_fwing' => $n($row[33] ?? null),
                ':wear_rwing' => $n($row[34] ?? null),
                ':wear_underbody' => $n($row[35] ?? null),
                ':wear_sidepod' => $n($row[36] ?? null),
                ':wear_cooling' => $n($row[37] ?? null),
                ':wear_gearbox' => $n($row[38] ?? null),
                ':wear_brakes' => $n($row[39] ?? null),
                ':wear_suspension' => $n($row[40] ?? null),
                ':wear_electronics' => $n($row[41] ?? null),

                // Boost-lap fuel coefficients (Tracks sheet cols AR/AS).
                ':boost_dry' => $n($row[43] ?? null),
                ':boost_wet' => $n($row[44] ?? null),

                ':overtaking' => trim($row[2] ?? ''),
                ':grip' => trim($row[16] ?? ''),
            ]);
            $count++;
        }

        fclose($handle);

        return $count;
    }

    /**
     * One-shot: re-encrypt any api_token value still stored as plaintext.
     * Safe to run repeatedly — looksEncrypted() short-circuits on ciphertext.
     */
    private function encryptLegacyApiTokens(): void
    {
        $stmt = $this->db->query(
            "SELECT id, api_token FROM users WHERE api_token IS NOT NULL AND api_token != ''"
        );
        $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);

        $update = $this->db->prepare("UPDATE users SET api_token = :token WHERE id = :id");

        foreach ($rows as $row) {
            $current = (string)$row['api_token'];
            if ($this->apiTokenCrypto->looksEncrypted($current)) {
                continue;
            }

            $update->execute([
                'token' => $this->apiTokenCrypto->encrypt($current),
                'id' => (int)$row['id'],
            ]);
        }
    }

    private function createUsersTable(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                email_encrypted TEXT NOT NULL,
                email_hash TEXT NOT NULL UNIQUE,
                is_admin INTEGER NOT NULL DEFAULT 0,
                api_token TEXT DEFAULT NULL,
                verified_at TEXT DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ";

        $this->db->exec($sql);
    }

    private function applyUserMigrations(): void
    {
        $stmt = $this->db->query('PRAGMA table_info(users)');
        $cols = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);

        $existingCols = array_column($cols, 'name');

        if (!in_array('username', $existingCols, true)) {
            $this->db->exec(
                "ALTER TABLE users ADD COLUMN username TEXT DEFAULT NULL"
            );
            $this->db->exec(
                "UPDATE users SET username = 'User_' || id WHERE username IS NULL"
            );
        }

        if (in_array('is_premium', $existingCols, true)) {
            // Premium tier removed 2026-06-01. SQLite 3.35+ supports DROP COLUMN.
            $this->db->exec("ALTER TABLE users DROP COLUMN is_premium");
        }

        if (!in_array('api_token', $existingCols, true)) {
            $this->db->exec(
                "ALTER TABLE users ADD COLUMN api_token TEXT DEFAULT NULL"
            );
        }

        if (!in_array('verified_at', $existingCols, true)) {
            $this->db->exec(
                "ALTER TABLE users ADD COLUMN verified_at TEXT DEFAULT NULL"
            );

            if (in_array('is_verified', $existingCols, true)) {
                $this->db->exec(
                    "UPDATE users
                     SET verified_at = datetime('now')
                     WHERE is_verified = 1"
                );
            }
        }

        if (!in_array('is_admin', $existingCols, true)) {
            $this->db->exec(
                "ALTER TABLE users ADD COLUMN is_admin INTEGER NOT NULL DEFAULT 0"
            );
        }

        if (!in_array('email_encrypted', $existingCols, true)) {
            $this->db->exec(
                "ALTER TABLE users ADD COLUMN email_encrypted TEXT"
            );
        }

        if (!in_array('email_hash', $existingCols, true)) {
            $this->db->exec(
                "ALTER TABLE users ADD COLUMN email_hash TEXT"
            );
        }

        if (!in_array('last_synced_at', $existingCols, true)) {
            $this->db->exec(
                "ALTER TABLE users ADD COLUMN last_synced_at TEXT DEFAULT NULL"
            );
        }

        if (!in_array('sync_status', $existingCols, true)) {
            $this->db->exec(
                "ALTER TABLE users ADD COLUMN sync_status TEXT NOT NULL DEFAULT 'idle'"
            );
        }

        if (!in_array('deleted_at', $existingCols, true)) {
            $this->db->exec(
                "ALTER TABLE users ADD COLUMN deleted_at TEXT DEFAULT NULL"
            );
        }
    }

    private function createAuditLogTable(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                actor_id INTEGER NOT NULL,
                action TEXT NOT NULL,
                target_user_id INTEGER,
                metadata_json TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ";
        $this->db->exec($sql);
    }

    private function createVerificationTokensTable(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS verification_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                code_hmac TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (user_id)
                    REFERENCES users(id)
                    ON DELETE CASCADE
            )
        ";

        $this->db->exec($sql);
    }

    private function createIndexVerificationUser(): void
    {
        $sql = "
            CREATE INDEX IF NOT EXISTS idx_verification_user
            ON verification_tokens(user_id)
        ";

        $this->db->exec($sql);
    }

    private function createPersistentTokensTable(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS persistent_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                selector TEXT NOT NULL UNIQUE,
                validator_hash TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (user_id)
                    REFERENCES users(id)
                    ON DELETE CASCADE
            )
        ";

        $this->db->exec($sql);

        $this->db->exec("
            CREATE INDEX IF NOT EXISTS idx_persistent_tokens_selector
            ON persistent_tokens(selector)
        ");

        // Rotation-grace columns. Guarded because first-request init runs
        // against the live database, which already holds issued tokens.
        $stmt = $this->db->query('PRAGMA table_info(persistent_tokens)');
        $cols = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);
        $existingCols = array_column($cols, 'name');

        if (!in_array('previous_validator_hash', $existingCols, true)) {
            $this->db->exec(
                "ALTER TABLE persistent_tokens
                 ADD COLUMN previous_validator_hash TEXT DEFAULT NULL"
            );
        }

        if (!in_array('rotated_at', $existingCols, true)) {
            $this->db->exec(
                "ALTER TABLE persistent_tokens ADD COLUMN rotated_at TEXT DEFAULT NULL"
            );
        }
    }

    /**
     * In-flight registrations awaiting email verification. Intentionally carries
     * NO unique constraint on username/email_hash: multiple people may have a
     * pending row for the same name, and the winner is decided at promotion into
     * `users` (where the UNIQUE constraints are the authority). Rows are
     * short-lived — AuthService purges expired ones opportunistically.
     */
    private function createPendingRegistrationsTable(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS pending_registrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL,
                email_encrypted TEXT NOT NULL,
                email_hash TEXT NOT NULL,
                code_hmac TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $this->db->exec("
            CREATE INDEX IF NOT EXISTS idx_pending_email_hash
            ON pending_registrations(email_hash)
        ");
        $this->db->exec("
            CREATE INDEX IF NOT EXISTS idx_pending_expires
            ON pending_registrations(expires_at)
        ");
    }

    /**
     * The new invariant is "a row in `users` is a verified account." Legacy
     * unverified rows (created by the old register-first flow, e.g. a bounced
     * verification email) are incomplete accounts that only squat a username and
     * email. Drop them so the namespace is freed and the invariant holds.
     * verification_tokens cascade via FK; soft-deleted rows are left untouched.
     */
    private function purgeUnverifiedUsers(): void
    {
        $this->db->exec(
            "DELETE FROM users WHERE verified_at IS NULL AND deleted_at IS NULL"
        );
    }

    /**
     * Guarantee the UNIQUE constraints the registration race relies on. A fresh
     * `users` table declares them inline, but a DB whose columns were added by
     * applyUserMigrations() (ALTER TABLE) may lack them. Creating the unique
     * indexes makes the constraint reliable regardless of provenance. Runs after
     * purgeUnverifiedUsers() so the duplicate ghosts are already gone; any real
     * duplicate that remained would (correctly) fail loudly here rather than
     * leave the constraint silently unenforced.
     */
    private function hardenUserUniqueIndexes(): void
    {
        $this->db->exec(
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_users_username ON users(username)"
        );
        $this->db->exec(
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email_hash ON users(email_hash)"
        );
    }

    private function createPilotsTable(): void
    {
        $columns = 'id INTEGER PRIMARY KEY AUTOINCREMENT, division TEXT NOT NULL';

        foreach ($this->statsSchema as $columnName) {
            $columns .= ", {$columnName} INTEGER NOT NULL";
        }

        $sql = "CREATE TABLE IF NOT EXISTS pilots ({$columns})";

        $this->db->exec($sql);
    }

    private function createMetadataTable(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS division_metadata (
                division TEXT PRIMARY KEY,
                last_retrieved_season INTEGER NOT NULL DEFAULT 0,
                last_retrieved_race INTEGER NOT NULL DEFAULT 1
            )
        ";

        $this->db->exec($sql);

        $stmt = $this->db->prepare(
            "INSERT OR IGNORE INTO division_metadata
             (division, last_retrieved_season, last_retrieved_race)
             VALUES (:div, 0, 1)"
        );

        foreach ($this->divisions as $division) {
            $stmt->execute([':div' => $division]);
        }
    }


    private function createTracksTable(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS tracks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT UNIQUE NOT NULL,
                lap_length REAL,
                laps INTEGER,
                distance REAL,
                avg_speed REAL,
                corners INTEGER,
                pit_time REAL,
                base_wings INTEGER,
                base_engine INTEGER,
                base_brakes INTEGER,
                base_gear INTEGER,
                base_suspension INTEGER,
                fuel_consumption TEXT,
                tyre_wear TEXT,
                wing_split REAL,
                fuel_per_lap REAL,
                fuel_per_lap_wet REAL,
                tyre_wear_factor REAL,
                wear_chassis INTEGER,
                wear_engine INTEGER,
                wear_fwing INTEGER,
                wear_rwing INTEGER,
                wear_underbody INTEGER,
                wear_sidepod INTEGER,
                wear_cooling INTEGER,
                wear_gearbox INTEGER,
                wear_brakes INTEGER,
                wear_suspension INTEGER,
                wear_electronics INTEGER,
                boost_dry REAL,
                boost_wet REAL,
                overtaking TEXT,
                grip TEXT
            )
        ";

        $this->db->exec($sql);
        $this->applyTrackMigrations();
    }

    /**
     * Add columns to an already-created tracks table. Tracks data is fully
     * reseeded from data/tracks.csv, but the schema must gain the column
     * before a reseed can populate it.
     */
    private function applyTrackMigrations(): void
    {
        $stmt = $this->db->query('PRAGMA table_info(tracks)');
        $cols = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);
        $existing = array_column($cols, 'name');

        $added = ['boost_dry' => 'REAL', 'boost_wet' => 'REAL', 'overtaking' => 'TEXT', 'grip' => 'TEXT'];
        foreach ($added as $col => $type) {
            if (!in_array($col, $existing, true)) {
                $this->db->exec("ALTER TABLE tracks ADD COLUMN {$col} {$type}");
            }
        }
    }

    private function createTrainingsTable(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS trainings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT UNIQUE NOT NULL,
                cost INTEGER NOT NULL,
                gain_concentration REAL DEFAULT 0,
                gain_talent REAL DEFAULT 0,
                gain_aggressiveness REAL DEFAULT 0,
                gain_experience REAL DEFAULT 0,
                gain_technical_insight REAL DEFAULT 0,
                gain_stamina REAL DEFAULT 0,
                gain_charisma REAL DEFAULT 0,
                gain_motivation REAL DEFAULT 0,
                gain_weight REAL DEFAULT 0
            )
        ";

        $this->db->exec($sql);
    }

    private function createCarPartCoefficientsTable(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS car_part_coefficients (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                part_name TEXT NOT NULL,
                type TEXT NOT NULL,
                cha REAL DEFAULT 0,
                eng REAL DEFAULT 0,
                f_wing REAL DEFAULT 0,
                r_wing REAL DEFAULT 0,
                und REAL DEFAULT 0,
                side REAL DEFAULT 0,
                cool REAL DEFAULT 0,
                gear REAL DEFAULT 0,
                brake REAL DEFAULT 0,
                susp REAL DEFAULT 0,
                elec REAL DEFAULT 0,
                constant REAL DEFAULT 0
            )
        ";

        $this->db->exec($sql);
    }

    private function createGameConstantsTable(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS game_constants (
                category TEXT NOT NULL,
                name TEXT NOT NULL,
                value REAL NOT NULL,
                PRIMARY KEY (category, name)
            )
        ";

        $this->db->exec($sql);
    }

    private function seedTrainings(): void
    {
        $trainings = $this->secrets['trainings_seed'] ?? [];

        // Costs are set by GPRO and change between seasons, so the seed must be
        // able to reprice a row that already exists in a live DB — a plain
        // INSERT OR IGNORE would leave the old price in place forever. Only the
        // cost is overwritten: the gains are spreadsheet-verified and a session
        // whose effects we haven't confirmed carries NULL gains we must not
        // stomp on a later run.
        $stmt = $this->db->prepare(
            "INSERT INTO trainings (
                name, cost,
                gain_concentration, gain_talent, gain_aggressiveness,
                gain_experience, gain_technical_insight, gain_stamina,
                gain_charisma, gain_motivation, gain_weight
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(name) DO UPDATE SET cost = excluded.cost"
        );

        foreach ($trainings as $training) {
            $stmt->execute($training);
        }
    }

    private function seedCarPartCoefficients(): void
    {
        $coeffs = $this->secrets['car_part_coeffs_seed'] ?? [];

        $stmt = $this->db->prepare(
            "INSERT OR IGNORE INTO car_part_coefficients (
                part_name, type,
                cha, eng, f_wing, r_wing, und,
                side, cool, gear, brake, susp, elec, constant
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        foreach ($coeffs as $coeff) {
            $stmt->execute($coeff);
        }
    }

    private function seedGameConstants(): void
    {
        // Upsert, not INSERT OR IGNORE: GPRO re-tunes these between seasons
        // (tyre supplier durability especially), and a warm database would
        // otherwise keep the first value it ever saw — the constants here are
        // a snapshot to correct, never user data to preserve.
        $stmt = $this->db->prepare(
            "INSERT INTO game_constants
             (category, name, value)
             VALUES (:cat, :name, :val)
             ON CONFLICT(category, name) DO UPDATE SET value = excluded.value"
        );

        $compounds = $this->secrets['tyre_calc']['tyre_risk_factors'] ?? [];
        foreach ($compounds as $name => $value) {
            $stmt->execute([
                ':cat' => 'tyre_compound',
                ':name' => $name,
                ':val' => $value,
            ]);
        }

        $suppliers = $this->secrets['tyre_suppliers_durabilities'] ?? [];
        foreach ($suppliers as $name => $value) {
            $stmt->execute([
                ':cat' => 'tyre_brand',
                ':name' => $name,
                ':val' => $value,
            ]);
        }

        $wearLevels = $this->secrets['tyre_calc']['track_wear_values'] ?? [];
        foreach ($wearLevels as $name => $value) {
            $stmt->execute([
                ':cat' => 'track_wear_level',
                ':name' => ucfirst(strtolower((string) $name)),
                ':val' => $value,
            ]);
        }
    }
}
