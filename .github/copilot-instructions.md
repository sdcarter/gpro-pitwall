# Copilot Instructions — GPRO Pitwall Fork

This is a fork of MVinhas/gpro-pitwall, a PHP/SQLite race-weekend cockpit for
the browser game Grand Prix Racing Online (GPRO). The fork runs locally in a
Podman container and adds a self-calibrating data pipeline.

Read `docs/race-data-workflow.md` for the tracked operating runbook and
`docs/race-analysis-data-contract.md` before changing RaceAnalysis persistence.
The versioned API reference is `docs/api/gpro-public-api.yml`.

## Stack
- PHP 8.5, Apache, SQLite (no external DB)
- Twig templates, no JS framework
- Podman + compose.yaml for local dev
- Task (Taskfile.yml) as the command runner

## Architecture

### Existing upstream code (do not break)
- `src/Service/StrategyService.php` — fuel + tyre model, reads `secrets.php` coefficients
- `src/Service/GproApiClient.php` — GPRO API facade with per-user caching
- `src/Database/DatabaseSeeder.php` — runs on every request (version-gated), SCHEMA_VERSION = 12
- `bootstrap.php` — DI container, all services wired here
- `config/game_constants.php` — public game data, track ID→name map
- `config/secrets.php` — calibrated coefficients (gitignored, never commit)

### Fork additions
- `race_observations` — personal race summary, calibration inputs, full raw payload, qualifying/risk/energy/car/overtaking/financial data
- `race_setups` — actual Q1/Q2/Race setup dial values used by GPRO
- `race_laps`, `race_pits`, `race_car_parts`, `race_transactions`, `race_practice_laps` — normalized per-race timeline and detail
- `src/Repository/RaceObservationRepository.php`, `RaceSetupRepository.php`, `RaceDetailRepository.php` — persistence and Race History read models
- `src/Service/RaceImportService.php` — maps the entire RaceAnalysis response; automatic import on authenticated page loads and shared manual import path
- `src/Controller/RaceHistoryController.php` + `_tab_race_history.twig` — filterable Race History list and full drill-down
- `bin/import_race_analysis.php` — imports or re-imports a specified race through `RaceImportService::importRace()`
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

Fuel consumption must include pit refuels:
```
fuel used = startFuel + Σ(refilledTo - fuelLeft) - finishFuel
fuel_per_km = fuel used / (laps × local track lap_length)
```
Never use the API's `trackId` to join the local `tracks` table: its ID scheme is
different. Normalize `trackName` by stripping a trailing country suffix, then
look up by local track name.

## Tyre model
Multiplicative exponentials: `factor ^ value` for each variable.
All factors in `secrets.php['tyre_calc']`. Currently `0.0` — causes wrong stop
counts. Needs 30+ observations per compound to fit.

## DB migration pattern
- `SCHEMA_VERSION` in DatabaseSeeder — bump when adding tables/columns
- All DDL is `IF NOT EXISTS` / `ALTER TABLE` guarded — idempotent
- `migrate()` runs on every request but skips if version is current

## Calibration state (as of fork setup)
- Track base fuel rates: seeded; validate their quality separately from personal factors
- fuel_factors: zeroed until enough dry observations and credible diagnostics exist
- tyre_calc/setup/car-wear: raw inputs and outcomes are retained, but no personal fitter exists yet
- boost_dry/wet: community-measured, not personally inferrable
- Community-style Strategy/Training/Car Wear recommendations come from curated `secrets.php` coefficients; do not imply they are learned from this personal history

## Per-race workflow
```bash
# Automatic: open an authenticated page after results post, then inspect Race History.
task import-race -- --season=<S> --race=<R>
task fit-fuel       # reports status; candidate snippet once sufficient data exists
# paste snippet → config/secrets.php ['fuel_factors']
task backup-db      # snapshot before manual SQL or schema work
```

The CLI importer is the correct way to reprocess a race after an import-mapping
change. Do not delete valid old races just because a Rookie reset changed driver
or car levels: real predictor variation is useful to the fuel regression.

## Container notes
- `vendor/` is in a named Docker volume (populated from image at first start)
- `corp-ca.crt` in `docker-certs/` is required for builds on Slalom network
- `.env` changes require `task down && task up` to take effect
- All persistent data lives in volume-mounted project files — safe across restarts
- `task down` clears `var/cache` and `var/mail`, not the SQLite database

## Key constraints
- Do not remove or weaken any existing security behaviour
- Do not change existing method signatures or public API
- New DB changes must go through DatabaseSeeder::migrate() — bump SCHEMA_VERSION
- Preserve upstream compatibility — no changes to existing table schemas
- Preserve all RaceAnalysis source data: `raw_payload` is the verbatim safety net; new useful fields should also be normalized when they need filtering, fitting, or display
