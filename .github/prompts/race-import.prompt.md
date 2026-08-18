---
description: "Import or re-import a specified completed GPRO race, audit its normalized capture, and report what was retained."
argument-hint: "--season=<S> --race=<R>"
agent: "Race Pipeline Engineer"
---

Use the season/race arguments supplied with this prompt to run
`task import-race -- --season=<S> --race=<R>`, then `task audit-race-data`.
Verify the race in Race History's backing records without exposing the raw JSON.
Report which sections were captured: summary, laps, pits, setups, parts,
financials, and practice data. Do not change private secrets.