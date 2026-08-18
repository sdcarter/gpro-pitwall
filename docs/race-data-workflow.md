# Personal Race Data Workflow

This is the tracked operational runbook for the personal race-data pipeline and
the version-controlled source of truth for engineers and coding agents.

## Before the Race

Start the local app when needed, then use Cockpit and Strategy normally:

```bash
task up
```

The local URL is `http://localhost:8080`. A normal authenticated page load
performs the regular GPRO sync; it does not replace post-race history review.

## After Results Post

1. Open any authenticated app page. `RaceImportService::checkAndImportLatest()`
   imports the newest completed race once; its latest-result lookup is cached
   for one hour.
2. Open **Race History** and inspect the drill-down. A complete API import has
   race laps, pits, setups, parts, transactions, and practice laps when GPRO
   returned those sections.
3. Re-import a specific race after a mapping fix or if the automatic import was
   missed:

   ```bash
   task import-race -- --season=<S> --race=<R>
   ```

   The CLI delegates to `RaceImportService::importRace()`, the same mapping used
   by automatic import.
4. Check the personal fuel model:

   ```bash
   task fit-fuel
   ```

   Candidate coefficients need at least seven dry observations. Early output
   is exploratory: inspect variation, collinearity, residuals, and later
   holdout performance before changing private `config/secrets.php`.

## Data Handling Rules

- Preserve `raw_payload`: it is the verbatim RaceAnalysis safety net.
- Normalize a raw field when it is needed for filtering, display, inspection,
  auditing, or fitting. See [the data contract](race-analysis-data-contract.md).
- GPRO `trackId` does **not** match local `tracks.id`. Strip a country suffix
  from `trackName` and join to local tracks by bare name.
- Fuel consumed must include pit refuels:

  ```text
  startFuel + sum(refilledTo - fuelLeft) - finishFuel
  ```

- Keep valid races through Rookie driver/car resets. The resulting driver and
  car-level variation helps identify fuel-model predictors. Delete or repair a
  row only when the captured value is demonstrably wrong.
- Keep `config/secrets.php`, SQLite databases, backups, and real raw payloads
  private. Never add them to a fixture, test, or commit.

## Maintenance

```bash
task audit-race-data
task backup-db
task seed-tracks
task test
task analyse
task logs
task shell
task down
```

`task audit-race-data` flags incomplete imported sections and obvious data
quality gaps. `task backup-db` snapshots `gpro_pilots.sqlite` to `var/backups/`.
Run `task seed-tracks` after `data/tracks.csv` changes. `task down` clears cache
and mail, but does not delete the persistent SQLite database.

## Calibration Scope

The hosted app's Strategy, Training, Car Wear, and setup recommendations rely
on community-curated `secrets.php` coefficients. Personal history does not
automatically infer these models. The current personal fitter is fuel only.
Tyre, setup, and car-wear inputs/outcomes are retained for future work; Testing
sessions offer more controlled evidence than race outcomes alone.