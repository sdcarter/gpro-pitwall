# GPRO API Contract Snapshot

[gpro-public-api.yml](gpro-public-api.yml) is a versioned snapshot of the GPRO
public API contract used by this repository. It was copied from the read-only
mounted sibling repository at `/posh-pro/data/gpro-public-api.yml` on
2026-08-18.

| Property | Value |
|---|---|
| SHA-256 | `2459e3c1e579245133f0e36b5d32944f1c0b89920d4f5be6009fd10e34613e69` |
| Lines | 116,651 |
| Primary importer operation | `GET /{lang}/backend/api/v2/RaceAnalysis` |
| Relevant schema | `RaceAnalysisResponse` |

This is a checked-in **reference contract**, not a credential or a replacement
for runtime API validation. When the upstream contract changes, review the diff
against [the RaceAnalysis data contract](../race-analysis-data-contract.md),
update the importer/fixture/tests where appropriate, then record the new source
date and checksum here.

Do not put real manager payloads, API tokens, or personally identifying data in
this directory.