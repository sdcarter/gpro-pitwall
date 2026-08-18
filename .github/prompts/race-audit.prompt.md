---
description: "Run the local personal race-data health audit and explain missing, incomplete, or implausible imported RaceAnalysis records."
argument-hint: "Optional season/race or concern"
agent: "Race Pipeline Engineer"
---

Run `task audit-race-data`. If an argument names a race or concern, inspect the
relevant normalized records and raw-payload availability without displaying
private payload contents. Classify each issue as an import mapping defect, an
expected historical-data gap, or data needing manual confirmation. Propose the
smallest repair, then re-run the audit if you make a change.