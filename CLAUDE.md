# Arab UT — working agreement

The full contract lives in [AGENTS.md](AGENTS.md). Read it before writing any code.
Design context and the UI workflow live in [.impeccable.md](.impeccable.md).

## Before you touch anything

- **Discovery, planning and owner approval are hard gates.** See AGENTS.md.
- **Frontend work has an extra gate**: inspect the existing implementation, load the
  required design skills, and extend the established visual language rather than replacing it.
- **Assistant work**: read `docs/ai-assistant/STATUS.md`, then the canonical document linked
  from `docs/ai-assistant/README.md`. Historical plans never override the newest owner decision.

## Git

- Branch first. Commit and push to that branch only.
- **Never merge into `main` and never push to `main`.** Open a pull request and wait for Mohamed.
- Merging to `main` deploys to production automatically (`.github/workflows/deploy-production.yml`
  runs on a successful `tests` run against `main`), so the merge button is the deploy button.
- Start every branch from an up-to-date `main` (`git checkout main && git pull`), and pull again
  after a merge lands. A branch left to drift is how `main` fell 203 commits behind once already.

## Gates

`npm run ci:check` and `composer test` must both pass. Playwright covers the browser paths.
Worker-reported results are claims; run the checks yourself.

## Where things are

| Looking for | Read |
| --- | --- |
| What the store sells, and its rules | `docs/product/` |
| n8n and Paylink contracts | `docs/api/` |
| Deploy, rollback, runbooks | `docs/operations/` |
| The AI assistant | `docs/ai-assistant/` (start at `STATUS.md`) |
| Why a past decision was made | `docs/decisions/` |
| Admin dashboard conventions | `.agents/skills/arab-ut-admin/` |
