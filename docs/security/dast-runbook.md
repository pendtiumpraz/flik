# DAST Runbook (OWASP ZAP)

Operational guide for running, triaging, and responding to FLiK's Dynamic Application Security Testing pipeline.

- **Workflow**: [`.github/workflows/dast.yml`](../../.github/workflows/dast.yml)
- **Rule overrides**: [`.zap/rules.tsv`](../../.zap/rules.tsv)
- **ZAP config docs**: [`.zap/README.md`](../../.zap/README.md)

## When it runs

| Trigger | Scope | Frequency |
| ------- | ----- | --------- |
| `schedule` (cron `0 8 * * 1`) | Baseline scan only | Every Monday 08:00 UTC |
| `workflow_dispatch` | Baseline + Full active scan | Manual |

The scheduled run targets the URL set in repo variable `STAGING_URL` (falls back to `https://staging.flik.example.com`).

## How to trigger manually

1. Open GitHub → **Actions** → **DAST (OWASP ZAP)**.
2. Click **Run workflow** (top right).
3. Optionally override `target_url` (defaults to `STAGING_URL` repo variable).
4. Click the green **Run workflow** button.

The full active scan only runs on manual dispatch — the cron run skips it to avoid hammering staging weekly.

## Required repo configuration

Set under **Settings → Secrets and variables → Actions → Variables**:

| Variable | Required? | Example | Purpose |
| -------- | --------- | ------- | ------- |
| `STAGING_URL` | Recommended | `https://staging.flik.id` | Default target for scheduled scans. If unset, the workflow falls back to `https://staging.flik.example.com` (a placeholder that will fail). |

No secrets are required — ZAP scans the public surface of the staging site. If we later add an authenticated scan, we'll provision a dedicated `DAST_TEST_USER` secret and document it here.

## Severity policy

| ZAP risk | Action | Blocks deploy? |
| -------- | ------ | -------------- |
| **HIGH** | Must be fixed or explicitly suppressed in `.zap/rules.tsv` with sign-off from a security reviewer. | **Yes** |
| **MEDIUM** | Open a tracking issue (`security/dast`), fix within the current sprint. | No, but re-evaluate if not closed within 14 days. |
| **LOW** | Tracked; bulk-triaged each release. | No |
| **INFO** | Reference only. | No |

> **Hard rule**: any HIGH finding from the baseline or full scan blocks the next production deploy until resolved or suppressed. The deploy workflow checks for open `security/dast` issues with the `severity/high` label.

## Triage flow when CI fails

1. Click into the failed `zap-baseline` (or `zap-full`) job.
2. Download the `report_html.html` artifact from the job summary — this is the full ZAP report.
3. For each FAIL:
   - **True positive** → file a fix PR. Reference the auto-opened GitHub issue in the PR description.
   - **False positive** → add an entry to `.zap/rules.tsv` with:
     - The rule ID
     - Action `IGNORE` (silence) or `WARN` (downgrade but keep visible)
     - A trailing comment explaining *why* it's not exploitable in our context
   - Get a security reviewer (`@security-reviewers` team) approval on the suppression PR.
4. Re-run the workflow to confirm green.

## False-positive suppressions currently in effect

See [`.zap/rules.tsv`](../../.zap/rules.tsv). Summary:

- `10015` — Cache-control on auth-only pages (Laravel auth middleware sets the right headers contextually; ZAP misclassifies)
- `10016` — `X-XSS-Protection` header (deprecated by all modern browsers; we rely on CSP)
- `10049` — Storable/cacheable content (downgraded to WARN; needs case-by-case review)
- `10038` — CSP report-only (downgraded to WARN while we tighten the policy)

Any new entries here require a PR with security review.

## Local reproduction

See [`.zap/README.md`](../../.zap/README.md) for the `docker run zaproxy/zap-stable …` recipes. Always reproduce findings locally before suppressing — about 10% of CI-only failures are caused by transient staging hiccups.

## Escalation

| Situation | Contact |
| --------- | ------- |
| Suspected critical vuln (auth bypass, SSRF, RCE) | `#sec-incident` Slack channel + page on-call security |
| ZAP CI infra broken (action upgrade, runner OOM) | `#flik-platform` Slack channel |
| Need to permanently disable a rule | Open PR editing `.zap/rules.tsv` + tag `@security-reviewers` |

## Roadmap

- [ ] Add authenticated scan once `DAST_TEST_USER` provisioned
- [ ] Wire scan results into Grafana via the ZAP JSON report
- [ ] Add per-PR ZAP scan against ephemeral preview environment (once preview envs land)
