# Copilot Instructions — GPRO Pitwall Fork

This is a fork of MVinhas/gpro-pitwall, a PHP/SQLite race-weekend cockpit for
the browser game Grand Prix Racing Online (GPRO). The fork runs locally in a
Podman container and adds a self-calibrating data pipeline.

## Stack
- PHP 8.5, Apache, SQLite (no external DB)
- Twig templates, no JS framework
- Podman + compose.yaml for local dev
- Task (Taskfile.yml) as the command runner

## Architecture

### Existing upstream code (do not break)
- `src/Service/StrategyService.php` — fuel + tyre model, reads `secrets.php` coefficients
- `src/Service/GproApiClient.php` — GPRO API facade with per-user caching
- `src/Database/DatabaseSeeder.php` — runs on every request (version-gated), SCHEMA_VERSION = 8
- `bootstrap.php` — DI container, all services wired here
- `config/game_constants.php` — public game data, track ID→name map
- `config/secrets.php` — calibrated coefficients (gitignored, never commit)

### Fork additions
- `race_observations` table — personal race history for calibration
- `src/Repository/RaceObservationRepository.php` — upsert/findForFitting
- `bin/import_race_analysis.php` — pulls RaceAnalysis API → race_observations
- `bin/fit_fuel_coefficients.php` — OLS fuel coefficient fitter

### Sibling repo (read-only, mounted in container)
- `/posh-pro/data/tracks.json` — real API data for all 64 GPRO tracks
- `/posh-pro/races/` — BBCode race files (posh-pro format, season 111)

## Fuel model
```
fuel_per_km = track.fuel_per_lap + Δ
Δ = conc×β1 + agg×β2 + exp×β3 + te×β4 + eng_lvl×β5 + ele_lvl×β6 + β7
```
All β coefficients are in `secrets.php['fuel_factors']`. Currently `0.0` — will
be fitted via `bin/fit_fuel_coefficients.php` once 7+ dry observations exist.

## Tyre model
Multiplicative exponentials: `factor ^ value` for each variable.
All factors in `secrets.php['tyre_calc']`. Currently `0.0` — causes wrong stop
counts. Needs 30+ observations per compound to fit.

## DB migration pattern
- `SCHEMA_VERSION` in DatabaseSeeder — bump when adding tables/columns
- All DDL is `IF NOT EXISTS` / `ALTER TABLE` guarded — idempotent
- `migrate()` runs on every request but skips if version is current

## Calibration state (as of fork setup)
- Track base fuel rates: seeded (0.75 L/km default, 3 tracks observed)
- fuel_factors: zeroed — needs more races
- tyre_calc: zeroed — wrong stop counts, needs much more data
- boost_dry/wet: zeroed — community-measured, not inferrable

## Per-race workflow
```bash
task import-race -- --season=<S> --race=<R>
task fit-fuel       # reports status, outputs snippet when ready
# paste snippet → config/secrets.php ['fuel_factors']
```

## Container notes
- `vendor/` is in a named Docker volume (populated from image at first start)
- `corp-ca.crt` in `docker-certs/` is required for builds on Slalom network
- `.env` changes require `task down && task up` to take effect
- All persistent data lives in volume-mounted project files — safe across restarts

## Key constraints
- Do not remove or weaken any existing security behaviour
- Do not change existing method signatures or public API
- New DB changes must go through DatabaseSeeder::migrate() — bump SCHEMA_VERSION
- Preserve upstream compatibility — no changes to existing table schemas
