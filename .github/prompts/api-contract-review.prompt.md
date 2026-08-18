---
description: "Review GPRO API snapshot, RaceAnalysis importer, schema, fixture, and data contract for coverage drift."
agent: "Race Data Contract Analyst"
---

Compare `docs/api/gpro-public-api.yml`,
`docs/race-analysis-data-contract.md`, the sanitized fixture, and
`RaceImportService`. Identify fields that are missing, raw-only by design,
normalized, or insufficiently tested. Do not fetch the live API or modify files
unless the user explicitly asks for implementation.