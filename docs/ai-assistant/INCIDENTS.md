# Incidents

## 2026-08-19/20 — Blank storefront after deployment

- **Symptom:** The HTTP deployment succeeded, but the React/Inertia storefront was blank.
- **Technical cause:** Invalid persistent-layout resolution reached the production JavaScript bundle while server health stayed green.
- **Corrective commits:** `53f8d40` and `29f4fb3`.
- **Detection gap:** CI had no real-browser mount.
- **Prevention:** Chromium storefront smoke runs before Hostinger release packaging.
- **Remaining limit:** This does not prove Safari visuals or checkout behavior.
