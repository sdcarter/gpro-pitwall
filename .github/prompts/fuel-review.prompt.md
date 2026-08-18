---
description: "Assess personal fuel coefficient readiness using current observations and fitter diagnostics without modifying secrets.php."
argument-hint: "Optional question about a coefficient or race"
agent: "Calibration Analyst"
---

Run `task fit-fuel` and summarize observation count, predictor variation,
collinearity/fit diagnostics, and whether results are insufficient,
exploratory-only, or credible enough for a manual trial. Do not modify
`config/secrets.php`; make explicit that any pasted candidate remains a manual
decision.