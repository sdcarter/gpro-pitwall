# RaceAnalysis Data Contract

This document maps `RaceAnalysisResponse` fields to local persistence. The full
versioned source is [the API snapshot](api/gpro-public-api.yml). `raw_payload`
always retains the complete response; normalization exists so the data can be
queried, audited, displayed, or fitted.

| API section | Local destination | Handling |
|---|---|---|
| `selSeasonNb`, `selRaceNb`, `trackName` | `race_observations` | Summary identity; strip trailing country from `trackName` before local track lookup |
| `laps[]` | `race_laps` | Position, tyres, condition, fuel, weather, temperature/humidity, time, boost, events |
| `pits[]`, `startFuel`, `finishFuel`, `finishTyres` | `race_pits`, `race_observations` | Pit detail; importer calculates fuel/km including refuels |
| `setupsUsed[]` | `race_setups` | Actual Q1/Q2/Race dial values and tyre choice |
| `practiceLaps[]` | `race_practice_laps` | Practice times, setup values, driver feedback |
| `driver`, `driverChanges` | `race_observations` | Driver pre-race stats plus post-race deltas |
| `engine`, `electronics`, `chassis`, `susp`, `FWing`, `RWing`, `underbody`, `sidepods`, `cooling`, `gear`, `brakes` | `race_car_parts` | Part level and start/finish wear |
| `tyreSupplier`, weather, qualifying, risk, energy | `race_observations` | Summary fields for display and future analysis |
| Overtake counters and `problems` | `race_observations`; per-lap events in `race_laps` | Counts are raw; blocked attempts are derived as attempts minus successful |
| `transactions`, `total`, `currentBalance` | `race_transactions`, `race_observations` | Line items plus earnings/balance summary |

## Computed Import Fields

| Field | Calculation |
|---|---|
| `fuel_per_km` | `(startFuel + sum(refilledTo - fuelLeft) - finishFuel) / (laps * local track lap_length)` |
| `weather` | `dry`, `wet`, or `mixed` from the per-lap weather series |
| `avg_temp` | Mean of per-lap temperature |
| `tyre_compound` | Most-used per-lap tyre compound |
| `pit_count` | Count of `pits[]` |

## Change Protocol

When a new API field matters:

1. Confirm its documented shape in `docs/api/gpro-public-api.yml` and a
   sanitized fixture.
2. Keep it in `raw_payload` regardless.
3. Add normalized storage only when it supports a real query/display/fitting
   need, using `DatabaseSeeder` migrations.
4. Add fixture assertions and run `task audit-race-data` after re-importing a
   real affected race.