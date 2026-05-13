# FLiK Incident Response Runbook

**Owner:** Security WG
**Last reviewed:** 2026-05-13
**Audience:** On-call engineers, Security WG, Customer Success, Legal/DPO.

This runbook governs how FLiK detects, classifies, contains, eradicates, and communicates security incidents. It is deliberately opinionated — **follow it, don't improvise**, and write down deviations in the post-mortem.

---

## 1. Severity levels

| Sev | Definition | Examples | Page on-call? | Comms |
|-----|------------|----------|--------------|-------|
| **P0** | Active, mass-impact compromise OR confirmed PII breach OR full-site outage > 5 min | Database dump posted publicly · APP_KEY leaked publicly · Admin account hijacked and abused · Active credential-stuffing wave with confirmed account takeovers · DRM master key in attacker's hands | YES (PagerDuty + phone + Slack) | Status page within 30 min, customer email within 4 h, regulator within 72 h if PII |
| **P1** | Active attack with limited blast radius OR vulnerability exploitable now but not yet abused | One admin account suspected phished but not yet used · A single user's account taken over · DRM token leak limited to one movie · Webhook signature bypass with no losses yet | YES (PagerDuty + Slack) | Internal-only initially; customer comms if individual user affected |
| **P2** | Vulnerability discovered but no active exploit OR contained low-impact event | Disclosure of internal endpoint via stack trace · Out-of-date dependency with known CVE not in attack surface · Failed brute-force burst that hit rate limit · Spam/abuse spike | Slack `#sec-alerts` | None external |
| **P3** | Hygiene / hardening work | Missing security header · Stale audit log entry · Linting finding from `pentest-checklist.md` | Ticket only | None |

**Default rule of thumb:** if you are unsure between two severities, choose the higher one. It is easy to downgrade; explaining a delayed response to a P0 you classified as P2 is not.

---

## 2. Roles & responsibilities

| Role | Who | Responsibilities |
|------|-----|------------------|
| **Incident Commander (IC)** | First responder of on-call rotation, may hand off | Owns the timeline. Calls severity. Decides on containment actions. Writes the post-mortem. Single point of decision-making. |
| **Communications (Comms)** | Customer Success lead (P0/P1) | Drafts status page updates, customer email, regulator notification. Speaks to press. **The IC does not talk to the public.** |
| **Investigator** | Engineer with deepest knowledge of affected component | Gathers evidence, reproduces, identifies root cause, proposes fix. **Preserve evidence first** before remediation (snapshots, log copies, DB dumps). |
| **Scribe** | Anyone not otherwise busy | Live-logs every action with timestamp in the incident channel. Becomes the timeline in the post-mortem. |
| **Legal / DPO** | Legal counsel | Determines if the incident triggers UU PDP (Indonesia), GDPR (EU users), or other notification obligations. Owns regulator comms. |

For P0 the IC MUST also page the CTO and CEO (FYI, not for decision-making).

---

## 3. Response timeline

### 0 – 15 min — Detect, confirm, classify

1. **Acknowledge** the page or report within 5 min.
2. **Open** an incident channel: `#inc-YYYYMMDD-short-name` in Slack.
3. **Confirm** the incident is real, not a false positive.
   - Cross-check at least two signals (alert + log + reproduce) before declaring.
4. **Classify** severity (table §1).
5. **Assemble** the team in the channel:
   - P0: IC + Comms + Investigator + Scribe + DPO + CTO.
   - P1: IC + Investigator + Scribe.
   - P2: IC + Investigator.
6. **Pin** the incident summary message in the channel:
   ```
   :rotating_light: INCIDENT [P_]
   What:   <one sentence>
   When:   <UTC start time>
   IC:     @<handle>
   Comms:  @<handle>
   Status: investigating
   ```

### 15 min – 1 h — Contain

Goal: stop the bleeding. **Containment beats forensics** during this phase.

Common containment actions (pick what applies; runbooks in §6):
- Revoke a session / token / API key.
- Block an IP or country at CloudFlare.
- Disable a user account.
- Take a feature offline (`config('features.X') = false`).
- Force-logout-all (`Auth::logoutOtherDevices` for affected users, or session table truncate).
- Rotate a leaked secret (see §6.2).
- Throttle harder (raise rate limits, drop to authenticated-only).
- Pull a release (rollback deploy).

**Before each action**, the IC asks:
> "What does this break, and is the trade-off worth it?"

Document each action in the channel as it happens (Scribe captures into timeline).

### 1 h – 24 h — Eradicate & recover

1. **Root cause analysis** by Investigator.
2. **Patch** in code, config, or infrastructure.
3. **Test** the fix in staging.
4. **Deploy** with extra eyes on monitoring.
5. **Verify** the original signal stops firing.
6. **Restore** any taken-down features.
7. **Notify** affected users (Comms drives — see §5).
8. Move incident state to `mitigated` then `resolved`.

### 24 – 72 h — Post-mortem

The IC writes a blameless post-mortem within 72 h of resolution and posts in `#sec-postmortems`. Template:

```markdown
# Post-mortem: <incident name> (PX, YYYY-MM-DD)
- IC: @
- Duration: HH:MM (UTC start → UTC end)
- Customer impact: (rows affected, downtime, etc.)
- Severity called at: HH:MM

## Timeline (UTC)
- HH:MM — alert fired
- HH:MM — IC acknowledged
- HH:MM — root cause identified
- HH:MM — fix deployed
- HH:MM — verified
- HH:MM — resolved

## What happened
2–3 paragraphs.

## Root cause
The single underlying cause — be specific.

## What went well
- ...

## What didn't go well
- ...

## Action items
| # | Action | Owner | Due | Tracking |
|---|--------|-------|-----|----------|
| 1 | ... | @ | YYYY-MM-DD | LINK |
```

**No blame.** Discuss systems and processes, not people.

---

## 4. Evidence preservation

Before any destructive remediation:
1. Snapshot the affected database (`mysqldump` to encrypted off-host backup).
2. Copy `audit_logs`, `ai_usage_logs`, and Laravel logs for the relevant window into a write-once bucket (`s3://flik-incidents/<inc-id>/`).
3. `git rev-parse HEAD` of the running release.
4. CF `Logpush` extract for the window (HTTP access logs).
5. If an account is implicated, dump that user's row + `watch_history` + `comments` + `subscriptions` rows into the bucket before any disabling.
6. Note the chain of custody (who pulled what, when) in the incident channel.

For P0 / PII incidents, retain evidence for **at least 12 months** (regulator may request).

---

## 5. Communication templates

### 5.1 Status page (early notice — within 30 min of P0/P1)

```
[Investigating] We are investigating reports of <symptom>. Some users may
experience <impact>. Updates here every 30 minutes.
```

### 5.2 Status page (mitigated)

```
[Mitigated] We have identified the cause and applied a fix. We continue to
monitor. A full post-incident report will be posted within 7 days.
```

### 5.3 Status page (resolved)

```
[Resolved] The incident is fully resolved as of <UTC time>. A detailed
post-incident report is available at <link>.
```

### 5.4 Customer notification — account-specific (P1/P0)

```
Subject: Tindakan keamanan pada akun FLiK Anda

Halo,

Pada <date WIB>, kami mendeteksi <activity> pada akun Anda. Sebagai
tindakan pencegahan, kami telah:
- Memutus semua sesi aktif di semua perangkat
- Mengharuskan pergantian password pada login berikutnya
- <other action(s)>

Tidak ada bukti bahwa data pembayaran Anda terdampak.

Silakan login kembali di https://flik.id/login dan tetapkan password baru.
Aktifkan 2FA di Pengaturan > Keamanan.

Jika Anda tidak mengenali aktivitas ini, balas email ini atau hubungi
support@flik.id.

— Tim Keamanan FLiK
```

### 5.5 Customer notification — mass (P0 PII breach, GDPR Art. 34)

Send within **72 hours** of becoming aware (GDPR Art. 33 → regulator; Art. 34 → users when "high risk").

```
Subject: Important security notice about your FLiK account

We are writing to let you know about a security incident that may have
affected your personal information.

What happened
On <date UTC>, we detected <plain-language description>. Our investigation
shows that the following information may have been accessed:
- <item 1>
- <item 2>

What was NOT affected
- <if true: payment card numbers — we do not store these>
- <if true: passwords — they are stored as one-way hashes>

What we are doing
- We have <containment summary>.
- We have engaged independent security experts to assist.
- We have notified relevant regulators (Kominfo / EU DPA).

What you should do
- Change your password (link)
- Enable 2FA (link)
- Watch for phishing emails impersonating FLiK
- Review your account activity (link)

For more information, visit https://flik.id/security/incident-<id>
or contact security@flik.id.

— FLiK Security Team
```

### 5.6 Regulator notification — Kominfo (Indonesia, UU PDP)

Required for any PII breach that affects Indonesian residents — submit to PIC Kominfo within **3 × 24 hours** of awareness (UU 27/2022 Art. 46(3)).

Template (Bahasa Indonesia, formal):

```
Kepada Yth.
Direktur Jenderal Aplikasi Informatika
Kementerian Komunikasi dan Informatika RI

Perihal: Pemberitahuan Insiden Pelindungan Data Pribadi

Bersama ini kami, PT FLiK Sinema Indonesia (Pengendali Data Pribadi),
melaporkan insiden pelindungan data pribadi sebagai berikut:

1. Waktu kejadian: <UTC + WIB>
2. Waktu diketahui: <UTC + WIB>
3. Sifat insiden: <ringkas>
4. Kategori data terdampak: <email, nama, riwayat tonton, dst.>
5. Jumlah subjek data terdampak (perkiraan): <N>
6. Dampak yang mungkin terjadi: <ringkas>
7. Langkah penanganan yang telah dilakukan: <ringkas>
8. Langkah pencegahan ke depan: <ringkas>
9. Notifikasi kepada subjek data: <sudah/jadwal>

Kontak penanggung jawab (DPO):
Nama:   <name>
Email:  dpo@flik.id
Telp:   <number>

Hormat kami,
<CEO name>
Direktur Utama
```

### 5.7 Regulator notification — EU DPA (GDPR Art. 33)

If any EU resident is affected, file with the lead supervisory authority within **72 hours**. Use the template from your DPA's portal; minimum content per Art. 33(3): nature, categories of data, approximate number of data subjects, likely consequences, measures taken.

---

## 6. Common-incident runbooks

### 6.1 Credential-stuffing wave

**Detection:** spike in `auth.login.failed`, OR success rate < 5% on `/login` for > 5 min, OR HaveIBeenPwned alert on a fresh dump containing FLiK emails.

**Response:**
1. **Confirm** with `audit_logs` query:
   ```
   SELECT user_id, ip_address, COUNT(*)
   FROM audit_logs
   WHERE action = 'auth.login.failed'
     AND created_at > NOW() - INTERVAL 1 HOUR
   GROUP BY user_id, ip_address
   ORDER BY 3 DESC LIMIT 50;
   ```
2. **Contain:**
   - Enable Cloudflare Turnstile on `/login` (`config('features.captcha_login') = true`).
   - Drop login rate limit from 5/min to 2/min per IP+email.
   - Block ASNs of the top offending IPs at CF (typically Russian/Chinese hosting).
   - Force `auth.session.invalidate_all` for any account whose password matches the leaked dump (script in `scripts/sec/invalidate_breached.php`).
3. **Notify** affected users (template §5.4).
4. **Resolve** when failed-login rate < 2× baseline for 30 min.
5. **Action items:** add 2FA roll-out, integrate HIBP password check on login.

### 6.2 Database compromise / `APP_KEY` leak

**Detection:** key visible in a leaked GitHub commit, public dump, dependabot alert, or threat-intel feed.

**Response — assume the database is dumped and the key is known.**

1. **Containment (within 1 h):**
   ```bash
   # Take site to maintenance
   php artisan down --secret=<random>

   # Rotate APP_KEY (encrypted columns will need re-encryption — see step 6)
   php artisan key:generate --show   # capture new key, do NOT replace yet

   # Rotate dependent secrets
   - DB_PASSWORD (rotate in MySQL, update .env)
   - REDIS_PASSWORD
   - DRM_JWT_KEY
   - BUNNY_TOKEN_KEY  (also rotate at Bunny dashboard)
   - BUNNY_STORAGE_KEY
   - MIDTRANS_SERVER_KEY (rotate at Midtrans dashboard)
   - PUSHER_APP_SECRET
   - All AI provider API keys (rotate at each provider)
   - MAILCHIMP_KEY
   - GOOGLE_CLIENT_SECRET
   - BACKUP_KEY (if backups encrypted)
   ```
2. **Force-logout-all:**
   ```bash
   php artisan session:flush
   # OR if sessions are in DB:
   # DELETE FROM sessions;
   # OR if sessions are in Redis:
   # redis-cli --scan --pattern 'laravel_session:*' | xargs redis-cli unlink
   ```
3. **Invalidate API tokens:** truncate `personal_access_tokens` if Sanctum used; rotate any DRM session tokens via `DrmKeyService::rotateAll()`.
4. **Force password reset for all users:** mark `users.force_password_reset = true`; on next login, route to reset flow.
5. **Re-encrypt** any `encrypted` Eloquent cast columns under the new `APP_KEY`. Use `php artisan model:rotate-encryption-key --old=… --new=…` (built-in since L11).
6. **Invalidate signed URLs:** they expire when the secret rotates.
7. **Maintenance off:** `php artisan up`.
8. **Notify** all users (template §5.5) — this is a P0 PII-class incident; 72 h regulator clock has started.
9. **Forensics:** identify how the key leaked (commit history, log scrape, insider). File post-mortem.

### 6.3 DRM key leak

**Detection:** key file appears on a piracy site, or `drm_audit_events` shows decryption requests from unexpected geographies.

**Response:**
1. **Identify** which key id is compromised (filename / kid in JWT).
2. **Rotate the key** using `DrmKeyService::rotate($movieId)` — this:
   - Generates fresh AES-128 key.
   - Re-encrypts the affected HLS renditions (background job on `transcoding` queue).
   - Marks the old key revoked.
3. **Invalidate all outstanding tokens** for the affected movie:
   ```php
   DrmTokenService::revokeForMovie($movieId);
   ```
4. **Purge CDN cache** for the manifest URL at Bunny.
5. **Audit `drm_sessions`** for the abuse window: who fetched the key, from where, how often. Flag accounts for review.
6. **If multiple movies affected** treat as P1, possibly P0 (loss of catalog protection).

### 6.4 PII leak

**Detection:** discovery of FLiK PII (emails, names, watch history) on paste-bin, in a forum dump, or via researcher disclosure.

**Response — start the 72 h clock at the moment of awareness, not confirmation.**

1. **Awareness time** must be precisely recorded (Scribe pins to channel).
2. **Identify scope:** which users, which fields. Pull a definitive list (cannot be approximate when notifying users).
3. **Identify cause:** SQLi? Misconfigured S3? Insider export? Third-party (AI provider)?
4. **Containment** appropriate to cause (close the hole, not optional).
5. **Evidence preservation** per §4 — assume regulator audit.
6. **Notification:**
   - Within 24 h (target): internal CEO/Board, Legal/DPO.
   - Within 72 h (mandatory): Kominfo (§5.6), EU DPA if applicable (§5.7).
   - Within 72 h (mandatory if "high risk"): affected users (§5.5).
7. **Public statement** drafted by Comms + Legal, posted on status page and as an open letter.
8. **Independent audit** engaged within 7 days. Findings published.
9. **Post-mortem** internally within 14 days; redacted version published within 30 days.
10. **Long-tail:** monitor support inbox for affected users; provide free credit monitoring if Indonesian regulator requests.

---

## 7. Drills

- **Quarterly tabletop** for the Sec WG: pick a scenario from §6, walk through the runbook, time each step. File a "drill report" PR updating any step that proved stale.
- **Annual live drill** in staging: actually rotate `APP_KEY`, actually force-logout, actually deliver a test email to a controlled user list. Measure mean-time-to-mitigate.

---

## 8. Contacts (kept up to date in 1Password vault `flik-secops`)

| Role | Channel | Backup |
|------|---------|--------|
| Primary on-call | PagerDuty `flik-prod` | _vault_ |
| CTO | _vault_ | _vault_ |
| CEO | _vault_ | _vault_ |
| DPO / Legal | dpo@flik.id | _vault_ |
| Kominfo PIC | _vault_ | https://aduankonten.id/ |
| Cloudflare TAM | _vault_ | https://dash.cloudflare.com/ |
| Bunny support | support@bunny.net | _vault_ |
| Midtrans support | support@midtrans.com | _vault_ |
| External pentest vendor | _TBD_ | _TBD_ |
| External counsel | _vault_ | _vault_ |
| Cyber-insurance broker | _vault_ | _vault_ |
