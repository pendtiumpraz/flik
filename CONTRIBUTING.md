# Contributing

Contributions are **welcome** and will be fully **credited**.

- Create a new branch, make your great PR.

## Security disclosure

**Do not file public GitHub issues for security vulnerabilities.** They are
visible to scrapers the second they are created.

For anything security-sensitive, follow the process documented in
[`SECURITY.md`](SECURITY.md):

1. Email **security@flik.example.com** (PGP key at `/.well-known/pgp-key.txt`), **OR**
2. Open a private GitHub Security Advisory draft, **OR**
3. Submit the form at <https://flik.example.com/security/report>.

The full machine-readable contact metadata lives in
[`/.well-known/security.txt`](public/.well-known/security.txt) per RFC 9116.

Triage SLA is 48 hours; criticals are patched within 14 days.

## Pre-commit secret scanner

This repository ships a pre-commit hook at `.githooks/pre-commit` that
blocks commits containing common API-key shapes (OpenAI, Stripe, Google,
GitHub PAT, Slack, JWT) or any `.env` flavoured file.

**Enable it once per clone**:

```bash
git config core.hooksPath .githooks
```

The hook only inspects the lines you're adding (not the rest of the file)
so it stays fast even on large diffs. If a legitimate string trips it,
re-run the commit with `--no-verify` and explain the bypass in the PR
description so the reviewer can confirm. See
[`docs/security/secrets-audit.md`](docs/security/secrets-audit.md) for the
full audit policy and patterns covered.
