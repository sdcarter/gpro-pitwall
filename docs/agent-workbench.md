# Agent Workbench Guide

This repository is prepared for VS Code Agent Workbench through the tracked
files in `.github/`. Other workbenches can use this document and the same
tracked docs/tests even when they do not automatically discover VS Code custom
agents.

## Canonical Context

Every agent should read these before modifying the race pipeline:

1. `.github/copilot-instructions.md` for project invariants.
2. [Race data workflow](race-data-workflow.md) for operating procedures.
3. [RaceAnalysis data contract](race-analysis-data-contract.md) for field
   mapping and computed values.
4. [API snapshot](api/gpro-public-api.yml) when changing API coverage.
5. `tests/Fixtures/race-analysis-complete.json` and its importer test for the
   complete sanitized contract example.

Useful implementation entry points are `src/Database/DatabaseSeeder.php` for
migrations, `src/Service/RaceImportService.php` for mapping, the three race
repositories for persistence/read models, and
`templates/partials/_tab_race_history.twig` for the inspection UI.

## Roles

| Role | Use for | Must not do |
|---|---|---|
| Race Data Contract Analyst | API/schema/fixture coverage review | Edit files or inspect private payloads |
| Race Pipeline Engineer | Imports, migrations, Race History, tests, audit | Alter secrets or commit personal data |
| Calibration Analyst | Fuel diagnostics and future fitting design | Write `config/secrets.php` automatically |
| GPRO Data Reviewer | Read-only review of correctness and data loss | Edit or call the live API |

The VS Code definitions are in `.github/agents/`. For another workbench, copy
the corresponding role statement and constraints into that workbench's agent
configuration rather than creating incompatible duplicate instructions in the
codebase.

## Mandatory Validation By Change Type

| Change | Minimum checks |
|---|---|
| Race importer, schema, repository, or detail mapping | Synthetic importer test, `task audit-race-data`, relevant PHPStan |
| Race History display/read model | Synthetic importer test, Twig lint, relevant PHPStan |
| Fitter | Unit/integration test where possible, `task fit-fuel`; never auto-write secrets |
| API contract snapshot or mapping | Contract review, fixture update, importer test |

## Privacy Boundary

Commit the API snapshot, sanitized fixtures, docs, code, and tests. Never
commit `config/secrets.php`, SQLite databases, backups, authentic manager data,
tokens, or raw payload exports. `raw_payload` remains local operational data;
the fixture proves its shape without disclosing a real manager's history.