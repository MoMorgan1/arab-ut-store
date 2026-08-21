# Admin pre-merge checklist

- [ ] Every route and mutation is backend-authorized by the approved matrix.
- [ ] Admin/Staff MFA and recent reauthentication contracts are covered.
- [ ] No credentials/secrets occur in Inertia props, HTML, URLs, logs, audit
      metadata, browser storage, errors, or cacheable responses.
- [ ] Financial/state mutations are validated, locked, idempotent where needed,
      and audited.
- [ ] Server table operations remain correct across pagination/filter/sort.
- [ ] No fake/deferred module, duplicate business rule, generic settings table,
      or automation-authority violation was introduced.
- [ ] Clean Code, Test Guard, Docs Guard, React best practices, composition,
      web guidelines, and final polish reviews pass for their changed surfaces.
- [ ] Arabic/English at 320/390/768/1440, keyboard, visible focus, 44px targets,
      200% zoom, reduced motion, overflow, and console checks pass.
- [ ] Focused tests, full PHP/JS gates, MariaDB lifecycle/concurrency, production
      build, and Chromium acceptance pass with fresh evidence.
- [ ] Canonical docs and the Admin skill reflect the final implementation.
