# Audit 12 — Notifications

Date: 2026-05-21
Branch: main
Scope: AdminNotifier (admin realtime bell + Pusher fan-out), admin notification UI, email campaigns + per-recipient tracking, web push (VAPID, hand-rolled RFC 8291), transactional email (verify / reset / new-device / gift), user-facing `Notification` model, daily security digest cron, 7 admin listeners + 2 user-facing security listeners.

## Summary

The notifications domain is **broadly wired** end-to-end. The admin bell, Pusher fan-out, polling fallback, mark-as-read flow, every one of the seven admin listeners (Comment, User, Payment, Subscription, Security, Encoding, FailedJob), the email-campaign pipeline (compose → segment → enqueue → personalised mailable → open/click pixel tracking), the hand-rolled VAPID web-push sender, branded VerifyEmail / ResetPassword / NewDeviceLogin mailables, and the daily security-digest cron — all are present and individually plausible. Listeners uniformly use the same "no-op when AdminNotifier binding is missing" guard so they are safe to deploy out-of-order.

That said, there are a handful of **real bugs and gaps** worth flagging:

- **N-1 (HIGH)** — `GiftSubscriptionService::sendGiftEmail()` renders `Mail::send('emails.gift-received', …)` but **the view does not exist** under `resources/views/emails/`. Every gift email throws `InvalidArgumentException: View [emails.gift-received] not found`, gets swallowed by the broad `catch (\Throwable)` block, and the recipient never gets the gift. The audit scope explicitly calls out "transactional emails (verify/reset/new-device/gift) all branded" — gift fails this check.
- **N-2 (MED)** — `EmailCampaign.scheduled_at` is accepted by the form, validated `date|after_or_equal:now`, and persisted, but **never honored anywhere**. `EmailCampaignController@send` immediately calls `CampaignDispatcher::enqueue()` which immediately flips `draft → sending`. There is no scheduler command, no `STATUS_QUEUED` writer, no Cron entry that scans `where('scheduled_at', '<=', now())`. Setting a future `scheduled_at` only delays the *admin's manual* "Send now" click.
- **N-3 (LOW)** — `subscribeEcho()` in `admin-notifications.js:199` subscribes to one channel per role in `window.AUTH_USER_ROLES` AND `admin-notifications.all-admins`. When an admin holds 4 roles, every fan-out emits 5 sockets, but every realtime payload that targets all-admins is **delivered N times** in the same tab (once per role channel the admin happens to be on, plus once on the all-admins channel) when audience is "all_admins" routed only to the all-admins channel — actually safe here because the all-admins channel is only used for `audience='all_admins'` (mutually exclusive with role lists). HOWEVER, when audience is `'admin,super_admin'` and the same user holds both, `onIncoming()` fires twice. The de-dup in `onIncoming` filters `item.id` from the array, but `unreadCount += 1` runs unconditionally → **inflated badge count**.
- **N-4 (LOW)** — `WebPushSender::sendToAll()` uses `Cache::increment()` for chunk metadata via `PushSubscription::chunkById(500)`, BUT it counts `failure_count` for **each non-2xx response including transient 5xx**. A subscription that receives 5 consecutive transient 503s gets pruned by the `healthy()` scope (`MAX_FAILURE_COUNT = 5`) even though Apple/Google may have just been rate-limiting. No exponential-backoff or grace window; only 404/410 should permadrop the row, other failures should retry on the next broadcast.
- **N-5 (LOW)** — `NotificationController` (user-facing, not admin) bulk-marks via `auth()->user()->notifications()->unread()->update(['read_at' => now()])` — no policy check, no rate-limit, single user-scoped query so it's fine; but the same controller's `markAsRead(Notification $notification)` uses `$this->authorize('markAsRead', $notification)` which requires a `NotificationPolicy` class. That class exists (referenced in the comment) but no Gate-bound policy is registered. Single-mark requests fall back to the default `Gate::denies()` deny path and return 403 unless `NotificationPolicy` is auto-discovered (Laravel 12 default is yes for `App\Policies\NotificationPolicy`). Worth a quick test to verify the auto-discovery resolves correctly with the `App\Models\Notification` class (collides with `Illuminate\Notifications\DatabaseNotification` naming).
- **N-6 (LOW)** — Severity colour mismatch between PHP and JS. `AdminNotification::severityColor()` uses `#ef4444 / #f59e0b / #3b82f6` (info=blue), `admin-notifications.js:severityColor()` uses `#ef4444 / #eab308 / #3b82f6` (warning is **amber #f59e0b** in PHP, **yellow #eab308** in JS). Cosmetic, but the bell stripe and the inbox-page badge will not match.
- **N-7 (LOW)** — `EmailCampaignController@send` is gated by `can:marketing.email_ab` (a permission slug seeded by RolePermissionSeeder), but the controller itself does NOT re-check after the `before_send` lifecycle window. A campaign that was draft when authorised but cancelled by a parallel admin between view render and submit hits a graceful "Tidak bisa mengirim — status sekarang: cancelled." rather than 422 — fine, but the audit `EmailCampaign` mutation does not write an `audit_logs` row. Compare with `MoviesController` which writes audit logs on every state transition.
- **N-8 (INFO)** — `EncodingJobUpdatedListener` and `SubscriptionCancelledListener` are NOT registered in `EventServiceProvider::$listen` — they are wired via model **Observers** (`EncodingJobAdminNotifyObserver`, `SubscriptionAdminNotifyObserver`) in `AppServiceProvider::boot()` that re-dispatch the listener as a queued job. The structure works but is split between two providers; a fresh dev will not find these wirings by grepping `EventServiceProvider`.

Everything else (admin bell rendering, Pusher channel auth, tracking pixel / click rewriter, branded auth emails, AdminNotifier audience canonicalisation, AdminNotifier per-user unread-cache invalidation, web-push hand-rolled aes128gcm + VAPID JWT) is well-implemented and aware of failure modes.

---

## Critical-checks scorecard

| # | Question | Verdict | Evidence |
|---|----------|---------|----------|
| 1 | Bell in admin layout actually subscribes to channels? | YES | `resources/views/components/admin/layout.blade.php:265` mounts `<x-admin.notification-bell />`. Layout exposes `window.PUSHER_KEY`, `PUSHER_CLUSTER`, `AUTH_USER_ROLES` (lines 151–155). `admin-notifications.js:subscribeEcho()` (line 196) calls `window.Echo.private('admin-notifications.{role}').listen('.created')` per role + always on `admin-notifications.all-admins`. `routes/channels.php:47-68` authorises both channel shapes with `isStaff()` / `hasRole()`. Polling fallback at `pollTick()` (line 231) when `window.Echo` is absent. |
| 2 | All 7 admin event listeners actually fire? | MOSTLY — see N-8 caveat | `EventServiceProvider`: `NewUserListener` (Registered), `SecurityEventListener` (SecurityEventLogged), `FailedJobListener` (JobFailed), `NewCommentListener` (Comment::created via boot()). `AppServiceProvider`: `EncodingJobUpdatedListener` (via observer), `SubscriptionCancelledListener` (via observer). `PaymentEventListener` is dispatched manually from `PaymentController:350`. **All 7 are reachable**, but two come via observers, not the listen array. |
| 3 | Daily security digest cron registered? | YES | `app/Console/Kernel.php:48-52` schedules `flik:security:daily-digest` `dailyAt('08:00')->timezone('Asia/Jakarta')->withoutOverlapping()->onOneServer()`. Command exists at `app/Console/Commands/SecurityDailyDigest.php`. |
| 4 | Email campaign send → mailable → tracking → counters? | YES (modulo N-2/N-7) | `EmailCampaignController@send` → `CampaignDispatcher::enqueue()` → flips status with atomic UPDATE-WHERE (closes race) → segments stream users via `chunk(1000)` → buffer + insert 100-row chunks → per-recipient `SendCampaignEmail::dispatch` onto `ai-batch` queue. `SendCampaignEmail@handle` calls `Mail::to(...)->send(new CampaignMailable($campaign, $recipient))` → `CampaignMailable` does token substitution + `rewriteLinks()` (wraps `<a href>` through `email.track.click`) + injects 1×1 GIF pixel via `email.track.open`. Tracking endpoints (`EmailTrackingController@open` and `@click`) use `recordOpen` / `recordClick` with `DB::transaction` + idempotent updates, increment campaign-level `open_count` / `click_count` / `bounce_count` atomically. |
| 5 | Push: VAPID generates, subscribe works, broadcast reaches device? | YES | `GenerateVapidKeys` command exists (`app/Console/Commands/GenerateVapidKeys.php`). `push-notifications.js` subscribes via `pushManager.subscribe()` and POSTs to `/api/push/subscribe`. `PushSubscriptionController@subscribe` validates payload, upserts by `endpoint_hash = sha1(endpoint)` (unique-indexed). `BroadcastPushMessage` job calls `WebPushSender::sendToAll()` which iterates `PushSubscription::forAudience($message->audience)->healthy()->chunkById(500)` and ships RFC 8291 `aes128gcm` payloads with RFC 8292 `Authorization: vapid t=…, k=…` VAPID JWT. Test command `php artisan flik:push:test` exists. |
| 6 | Web push gracefully degrades when VAPID missing? | YES | `WebPushSender::enabled()` returns `false` when either VAPID key is absent (or wrong length). `PushSubscriptionController` returns HTTP 503 `{success:false, reason:'not_configured'}` on subscribe/unsubscribe when keys are missing. `BroadcastPushMessage` logs + aborts cleanly. `<x-push-opt-in>` blade is wrapped in `@if (config('services.push.public_key'))` and the meta `vapid-public-key` tag is only emitted when configured (`layout.blade.php:107-109`). JS `vapidPublicKey()` returns `null` → `requestSubscription()` short-circuits to `false`. |
| 7 | Transactional emails (verify / reset / new-device / gift) all branded? | **NO — gift fails (N-1)** | `VerifyEmailNotification` → `emails.auth.verify.blade.php` (exists, branded). `ResetPasswordNotification` → `emails.auth.reset.blade.php` (exists, branded). `NewDeviceLogin` mailable → `emails.security.new-device-login.blade.php` (exists, branded). `GiftSubscriptionService::sendGiftEmail` → `Mail::send('emails.gift-received', …)` — **VIEW DOES NOT EXIST** (`Glob: resources/views/emails/gift*` → no files). The failure is swallowed silently by the wrapping try/catch in `sendGiftEmail()`. |

---

## Inventory

### Routes (notification surface)

| Route | Method | Controller | Name | Notes |
|-------|--------|------------|------|-------|
| `/notifications` | GET | `NotificationController@index` | `notifications.index` | User-facing inbox (`auth` group) |
| `/notifications/{notification}/read` | POST | `NotificationController@markAsRead` | `notifications.read` | Authorise via `NotificationPolicy::markAsRead` (see N-5) |
| `/notifications/read-all` | POST | `NotificationController@markAllAsRead` | `notifications.readAll` | Bulk mark, single SQL update |
| `/notifications/count` | GET | `NotificationController@count` | `notifications.count` | JSON for header badge |
| `/admin/notifications` | GET | `Admin\NotificationController@index` | `admin.notifications.index` | Paginated 30/page, category + severity + state filters |
| `/admin/notifications/unread-count` | GET | `…@unreadCount` | `admin.notifications.unread-count` | Polling fallback (30s cache) |
| `/admin/notifications/{adminNotification}` | GET | `…@show` | `admin.notifications.show` | JSON, side-effect-marks-read |
| `/admin/notifications/{adminNotification}/read` | POST | `…@markRead` | `admin.notifications.read` | 204 No Content |
| `/admin/notifications/read-all` | POST | `…@markAllRead` | `admin.notifications.read-all` | Bulk via `AdminNotifier::markAllReadFor` |
| `/admin/email-campaigns` | resource | `Admin\EmailCampaignController` | `admin.email-campaigns.*` | `can:marketing.email_ab` |
| `/admin/email-campaigns/ai-draft` | POST | `…@aiDraft` | `…ai-draft` | `CampaignCopyGenerator` |
| `/admin/email-campaigns/preview-audience` | POST | `…@previewAudience` | `…preview-audience` | `SegmentBuilder::estimate + sampleEmails` |
| `/admin/email-campaigns/{c}/send` | POST | `…@send` | `…send` | Flips `draft → sending`, immediate dispatch (see N-2) |
| `/admin/email-campaigns/{c}/cancel` | POST | `…@cancel` | `…cancel` | Workers honour via `if status==cancelled return` |
| `/admin/email-campaigns/{c}/report` | GET | `…@report` | `…report` | Top-10 links + 20-row failure tail |
| `/email/track/open/{trackingId}.gif` | GET | `EmailTrackingController@open` | `email.track.open` | Public, `throttle:1000,1`, returns 1×1 GIF always |
| `/email/track/click/{trackingId}` | GET | `EmailTrackingController@click` | `email.track.click` | Public, validates base64url `?u=`, rejects non-http(s) schemes |
| `/api/push/subscribe` | POST | `PushSubscriptionController@subscribe` | `push.subscribe` | Auth optional, `throttle:60,1` |
| `/api/push/unsubscribe` | POST | `PushSubscriptionController@unsubscribe` | `push.unsubscribe` | `throttle:60,1` |
| `/admin/push` | GET | `Admin\PushBroadcastController@index` | `admin.push.index` | `can:push.send`, shows subscriber stats |
| `/admin/push/create` | GET | `…@create` | `admin.push.create` | Audience picker (all / role / segment) |
| `/admin/push` | POST | `…@store` | `admin.push.store` | Creates `PushMessage`, dispatches `BroadcastPushMessage` |

### Broadcasting channels (`routes/channels.php`)

| Channel | Auth callback | Used by |
|---------|---------------|---------|
| `admin-notifications.all-admins` | Any `isStaff()`-eligible role | `AdminNotificationCreated` when `audience='all_admins'` |
| `admin-notifications.{role}` | Super-admin OR `hasRole($role)` | `AdminNotificationCreated` when audience is a role-list |
| `movie.{movieId}.comments` | Any authenticated user | `CommentReactionUpdated` (out of scope here) |
| `watch-party.{roomCode}` | Watch-party member | Out of scope |

### Models

| Model | Table | Notes |
|-------|-------|-------|
| `AdminNotification` | `admin_notifications` | `severity ∈ {info,warning,critical}` enum + service-layer normaliser. Immutable (`UPDATED_AT = null`). `scopeForUser` uses `CONCAT(',', audience, ',') LIKE '%,role,%'` to anchor role names safely between separators. |
| `AdminNotificationRead` | `admin_notification_reads` | Pivot. `updateOrCreate` keyed on `(admin_notification_id, user_id)` (unique-indexed). Service uses `DB::table()->insertOrIgnore()` for bulk path. |
| `Notification` | `notifications` | User-facing. `read_at` nullable, `scopeUnread`, `markAsRead()`. Fillable: `user_id, type, title, message, action_url, read_at`. |
| `EmailCampaign` | `email_campaigns` | 5-state machine (draft/queued/sending/sent/cancelled). System cols `status,sent_at,*_count` in `$guarded`. `openRate()`, `clickRate()` helpers. `STATUS_QUEUED` is defined but **unused at runtime** (N-2). |
| `EmailRecipient` | `email_recipients` | `tracking_id` 32-char Str::random — treated as capability token. `sent_at / opened_at / first_clicked_at / bounced_at / failed_at` flags. |
| `EmailLinkClick` | `email_link_clicks` | One row per click (not unique). Powers top-link breakdown chart. |
| `PushSubscription` | `push_subscriptions` | `endpoint_hash = sha1(endpoint)` unique-indexed (the endpoint URL is too long for direct unique index). `MAX_FAILURE_COUNT=5` triggers `healthy()` scope drop (see N-4). `scopeForAudience` parses `all | role:X | user:N | segment:authenticated|anonymous`. |
| `PushMessage` | `push_messages` | `toPayload()` shapes the service-worker JSON. Audience regex validated by controller. |

### Services

| Service | Purpose |
|---------|---------|
| `App\Services\Notifications\AdminNotifier` | Single public surface for all admin bell writes — `notify()`, `markAsRead()`, `markAllReadFor()`, `unreadCountFor()` (30s per-user cache), `recentForUser()`. Audience canonicalisation (sorted+deduped role list), severity validation, broadcast-error trapping, per-audience cache invalidation. |
| `App\Services\Email\SegmentBuilder` | JSON segment DSL → `Builder<User>`. 7 leaf types + `and`/`or` composites with `MAX_DEPTH=6` cap. Empty/unknown shapes return `whereRaw('0=1')` — safer than throwing. `byCustomEmails()` deliberately skips `email_verified_at` (admin-explicit override). |
| `App\Services\Email\CampaignDispatcher` | `enqueue()` flips draft→sending with optimistic `UPDATE WHERE status=draft` (race-safe). Streams users in 1000-row chunks → 100-row insert buffers → per-recipient job dispatch. `enqueueCustomEmails` separately handles non-user emails. |
| `App\Services\Push\WebPushSender` | Hand-rolled RFC 8291 aes128gcm + RFC 8292 VAPID JWT (ES256). `enabled()` short-circuit. `sendToAll()` chunkById(500), prunes only on HTTP 404/410, otherwise `markFailed()` (see N-4). Windows openssl.cnf fallback at line 320-326. |

### Jobs

| Job | Queue | Tries | Notes |
|-----|-------|-------|-------|
| `SendCampaignEmail` | `ai-batch` (forced via `onQueue`) | 3 | Exponential backoff [60,300,900]s. On final failure stamps `failed_at + bounced_at` + bumps `bounce_count`. Idempotent (skips when `sent_at !== null`). Honours `STATUS_CANCELLED`. Calls `maybeMarkSent()` to flip campaign → `sent` when no pending rows. |
| `BroadcastPushMessage` | `default` (no explicit queue) | 1 (no retry) | 600s timeout for large audiences. Idempotent (`isDelivered()` short-circuit). |
| `AdminNotificationCreated` (ShouldBroadcastNow) | n/a — broadcasts inline | n/a | Wrapped in try/catch inside `AdminNotifier::notify` so broadcaster failure never crashes the originating request. |

### Listeners

| Listener | Bound to | Audience | Notes |
|----------|----------|----------|-------|
| `Admin\NewCommentListener` | `Comment::created` (via `AppServiceProvider::boot` model event) | `moderator` | Skips replies (`parent_id !== null`). Escalates severity to `warning` when `is_spoiler=true`. |
| `Admin\NewUserListener` | `Registered` (via `EventServiceProvider::$listen`) | `[admin, super_admin]` | |
| `Admin\PaymentEventListener` | Manual `::dispatch($context)` from `PaymentController:350` | `finance` (success/failed), `[finance, super_admin]` (chargeback) | NOT a real event listener — directly dispatched. Documented in class docblock. |
| `Admin\SubscriptionCancelledListener` | `Subscription::observe(SubscriptionAdminNotifyObserver)` | `finance` | Observer only fires on TRANSITION (`wasChanged('status')` + new value is `cancelled`). |
| `Admin\SecurityEventListener` | `SecurityEventLogged` (via `EventServiceProvider::$listen`) | severity-mapped: super_admin_only (critical), [admin, super_admin] (warning), admin (info) | Throttles `info` events: max 5/(event,actor)/5min via `Cache::increment`. Critical/warning always emit. |
| `Admin\EncodingJobUpdatedListener` | `EncodingJob::observe(EncodingJobAdminNotifyObserver)` | `content_editor` (completed), `[content_editor, admin]` (failed) | Only terminal-state transitions; observer guards via `wasChanged('status')`. |
| `Admin\FailedJobListener` | `JobFailed` (via `EventServiceProvider::$listen`) | `[admin, super_admin]` | Self-referential guard (`isSelfReferential()`) prevents infinite recursion when a notification job itself fails. Escalates to `critical` when same job fails ≥3 times/hour. |
| `SendLoginAlert` | `Login` (via `EventServiceProvider::$listen`) | n/a (user-facing) | Calls `LoginAlertService::recordAndCheck`, on `should_alert=true` inserts a `notifications` row + queues `NewDeviceLogin` mailable. |
| `PushSecurityAlerts` | `SecurityEventLogged` (synchronous, NOT ShouldQueue) | n/a (Slack/Discord webhook) | Sync to keep webhook delivery in the originating request lifecycle; throttling lives inside `SecurityAlertService`. |

### Migrations

| File | Tables |
|------|--------|
| `2026_05_10_060001_create_admin_notifications_table.php` | `admin_notifications` — `(category, created_at)`, `(audience, created_at)`, `severity` indexes |
| `2026_05_10_060002_create_admin_notification_reads_table.php` | `admin_notification_reads` — unique `(admin_notification_id, user_id)` |
| `2026_05_10_110001_create_push_subscriptions_table.php` | `push_subscriptions` — unique `endpoint_hash`, `failure_count` |
| `2026_05_10_110002_create_push_messages_table.php` | `push_messages` |
| `2026_05_10_120001_create_email_campaigns_table.php` | `email_campaigns` — 5-state enum, denormalised counters |
| `2026_05_10_120002_create_email_recipients_table.php` | `email_recipients` — `tracking_id` unique, FK to campaign |
| `2026_05_10_120003_create_email_link_clicks_table.php` | `email_link_clicks` — many-per-recipient |

### Views / JS

| File | Purpose |
|------|---------|
| `resources/views/components/admin/notification-bell.blade.php` | Bell + Alpine `x-data="adminNotifBell()"`, dropdown panel, mute toggle, mark-all-read button, 360px panel with severity stripe |
| `resources/js/admin-notifications.js` | Alpine factory; Echo subscribe per role + all-admins, 30s polling fallback, WebAudio chime fallback when `/sounds/notification-chime.mp3` 404s |
| `resources/js/echo.js` | Initialises `window.Echo` only when `PUSHER_KEY` is set; CSRF + `/broadcasting/auth` endpoint |
| `resources/js/push-notifications.js` | `window.FlikPush` API: subscribe / unsubscribe / dismiss; reads VAPID public key from `<meta name="vapid-public-key">` |
| `resources/views/components/push-opt-in.blade.php` | Floating banner — only renders when `config('services.push.public_key')` is set |
| `resources/views/components/cookie-banner.blade.php` | Cookie consent (3 categories), used by analytics opt-in. Mounted from `components/layout.blade.php:231` |
| `resources/views/emails/auth/verify.blade.php` | FLiK-branded verification email (dark theme `#0a0a0a`) |
| `resources/views/emails/auth/reset.blade.php` | FLiK-branded password-reset email |
| `resources/views/emails/security/new-device-login.blade.php` | FLiK-branded new-device alert |
| `resources/views/emails/campaign.blade.php` | Campaign body Blade shell |
| `resources/views/emails/daily-admin-report.blade.php` | Daily AI report (separate from security digest) |
| **`resources/views/emails/gift-received.blade.php`** | **MISSING — see N-1** |

### Console commands

| Command | Schedule | Notes |
|---------|----------|-------|
| `flik:security:daily-digest` | `dailyAt('08:00')->timezone('Asia/Jakarta')` | Always-on digest to super_admins, ignores `SECURITY_ALERTS_ENABLED` |
| `flik:push:generate-vapid-keys` (`GenerateVapidKeys`) | manual | Generates ES256 keypair, prints `VAPID_PUBLIC_KEY` + `VAPID_PRIVATE_KEY` |
| `flik:push:test` (`PushTest`) | manual | Smoke-test a single subscription |

---

## Findings

### N-1 — `gift-received.blade.php` missing → every gift email silently fails (HIGH)

`app/Services/Billing/GiftSubscriptionService.php:198`:

```php
Mail::send('emails.gift-received', $payload, function ($message) use ($to, $gift) {
    $subject = 'Kamu menerima hadiah FLiK '.($gift->plan?->name ?? 'Premium').'!';
    $message->to($to)->subject($subject);
});
```

`Glob: resources/views/emails/gift*.blade.php` returns no files. The Mail::send throws `InvalidArgumentException: View [emails.gift-received] not found.` — swallowed by the outer `catch (\Throwable $e)`. Recipient never receives the gift, only a `Log::warning('GiftSubscriptionService::sendGiftEmail failed', …)` is emitted. The audit task's checklist explicitly requires "(verify/reset/new-device/gift) all branded".

Fix: create `resources/views/emails/gift-received.blade.php` matching the dark-gold FLiK chrome of the other emails. Optionally refactor `sendGiftEmail()` into a proper `App\Mail\GiftReceivedMailable extends Mailable` for consistency with `NewDeviceLogin` + `CampaignMailable`.

### N-2 — `EmailCampaign.scheduled_at` is stored but never honored (MED)

Stored at create/update, validated `nullable|date|after_or_equal:now`, but `EmailCampaignController@send` immediately calls `CampaignDispatcher::enqueue()` and the dispatcher flips `draft → sending` and dispatches recipients right now. There is:
- no scheduler command (no `flik:email:dispatch-scheduled`),
- no Cron entry,
- no code path that ever writes `STATUS_QUEUED` (the const exists but is unused at runtime),
- no `where('scheduled_at', '<=', now())->where('status', 'draft')` scanner.

Either remove the column from the form + DB, OR add a scheduled command:

```php
$schedule->command('flik:email:dispatch-scheduled')->everyFiveMinutes();
// Command: EmailCampaign::where('status','draft')->whereNotNull('scheduled_at')
//   ->where('scheduled_at','<=',now())->each(fn($c) => $dispatcher->enqueue($c));
```

### N-3 — Bell unread count over-counted when admin holds multiple targeted roles (LOW)

`admin-notifications.js:onIncoming()`:

```js
this.items = [item, ...this.items.filter((i) => i.id !== item.id)].slice(0, 20);
this.unreadCount = this.unreadCount + 1;  // ← runs once per channel delivery
```

When `audience='admin,super_admin'` and the same admin holds both roles, the listener fires twice (once per private channel subscription). The `items` array correctly dedupes by id, but the counter is incremented twice → badge says `2` for one notification.

Fix: in the dedupe branch, only `unreadCount++` when the item was NOT already in the array:

```js
const exists = this.items.some(i => i.id === item.id);
this.items = [item, ...this.items.filter(i => i.id !== item.id)].slice(0, 20);
if (!exists) this.unreadCount += 1;
```

### N-4 — Web Push transient failures count against `MAX_FAILURE_COUNT` (LOW)

`WebPushSender::sendToAll()` only permadrops on HTTP 404/410; everything else (incl. 503 / 429 / network exception) calls `markFailed()` which increments `failure_count`. After 5 transient failures the subscription falls out of the `healthy()` scope and stops receiving future broadcasts. A single APNs/FCM rate-limit incident with 5 broadcasts in close succession could prune thousands of legitimate subscriptions.

Fix: only count 4xx (excluding 429) toward the failure counter; reset on first successful delivery (already done in `markDelivered`, good); add `if ($result['status'] === 429) continue;` to avoid bumping the counter on rate-limit responses.

### N-5 — `NotificationPolicy` lookup needs verification (LOW)

`NotificationController@markAsRead` calls `$this->authorize('markAsRead', $notification)` which requires a policy. Laravel 12 auto-discovers `App\Policies\NotificationPolicy` for `App\Models\Notification`. Worth a quick `php artisan tinker` `Gate::policies()` check to confirm — the framework's `Illuminate\Notifications\DatabaseNotification` is also called `Notification` and could potentially confuse the resolver depending on root namespace + use statements.

### N-6 — Severity colour drift (PHP vs JS) (LOW / COSMETIC)

`AdminNotification::severityColor()` returns `#f59e0b` for warning; `admin-notifications.js:severityColor()` returns `#eab308`. Pick one (amber-500 `#f59e0b` is the Tailwind canonical and matches `notif-bell-badge`'s red-500 family).

### N-7 — Email-campaign mutations not audit-logged (LOW)

`AdminAction` lifecycle changes (send / cancel / destroy) on `EmailCampaign` do not write to `audit_logs`. Compare with movies / users CRUD which DO write audit rows. A campaign mass-send is one of the more financially-impactful admin actions; it deserves the same paper trail.

Fix: inject `AuditLogger` into `EmailCampaignController` and call `$audit->log('email_campaign.send', $campaign, ['recipient_count' => $queued])` in `send()`, `cancel()`, `destroy()`, and on substantial `update()`s.

### N-8 — Listener wiring split between EventServiceProvider and AppServiceProvider (INFO)

`EncodingJobUpdatedListener` and `SubscriptionCancelledListener` are NOT in `$listen` — they are wired via `Subscription::observe(SubscriptionAdminNotifyObserver)` + `EncodingJob::observe(EncodingJobAdminNotifyObserver)` in `AppServiceProvider::boot()`. Each observer's `updated()` method re-dispatches the listener as a queued job after a `wasChanged('status')` guard. The architecture works (and the `wasChanged` guard is correct — needed for transition detection that `$listen` can't easily express), but a fresh dev grepping `EventServiceProvider` for "what listeners run" will miss these two. Either:
- Add a `@see` comment in `EventServiceProvider::$listen` documenting the observer wiring, OR
- Promote the observers to model events `dispatched` array that the service provider lists explicitly.

### N-9 — `NewCommentListener` is bound to `Comment::created` via boot() closure (INFO)

`EventServiceProvider::boot()` registers `NewCommentListener` as a 3rd model-creation closure on `Comment::created` — alongside `ModerateNewComment` and `DetectSpoilerOnComment`. The order is implicit and the listener is dispatched via `::dispatch($comment)` (queued, not invoked synchronously). Fine, but the listener also has a `handle(Created|Comment $event)` signature suggesting it COULD be bound via `$listen => [Comment::class . '::created' => …]` for clarity. Minor — current setup works.

---

## Things that work well

- **AdminNotifier audience canonicalisation** — `notify()` sorts + dedupes role arrays so two callers passing `['admin','finance']` and `['finance','admin']` produce identical `audience` strings (helps the `(audience,created_at)` index). The empty-array fallback to `all_admins` + log warning is a safe default.
- **Per-user unread cache** — `unreadCountFor()` is `Cache::remember`-cached for 30s, keyed only on user id. `invalidateUnreadCacheForAudience()` walks the audience-eligible user set and forgets each user's key on every fan-out — pragmatic blunt instrument acknowledged in the code comments.
- **AdminNotificationCreated broadcast resilience** — uses `ShouldBroadcastNow` for sub-second latency and is wrapped in try/catch inside the notifier so a missing Pusher key never crashes the originating request. Polling fallback in the JS bell covers the resulting gap.
- **EmailTrackingController defence-in-depth** — open-pixel is rate-limited to 1000/min/IP, returns the inline GIF bytes (no disk/CDN dependency), and uses `DB::transaction + whereNull('opened_at') update` for atomic first-open detection. Click endpoint validates the base64url `?u=` URL through both scheme check (http/https only) and `filter_var(FILTER_VALIDATE_URL)`. Open-redirect protection is correct — non-http schemes fall back to `url('/')` instead of leaking 404s that would enumerate valid tokens.
- **CampaignDispatcher race-safety** — uses `UPDATE … WHERE status='draft'` as a row-level lock to atomically claim a campaign; if two admins click "Send" simultaneously the second one's UPDATE affects 0 rows and `enqueue()` cleanly returns 0.
- **CampaignMailable link rewriting** — only wraps `<a href>` for http(s) schemes; skips `mailto:`, `tel:`, `sms:`, `javascript:`, anchors, and the tracker URL itself (avoids double-wrapping on retry).
- **WebPushSender VAPID + aes128gcm correctness** — hand-rolled per RFC 8291 / 8292 with explicit comments mapping each step to its RFC section. Windows openssl.cnf fallback at line 320-326 is the kind of pragmatic test-it-on-the-actual-target detail that shows real implementation work. `derToJoseSignature` handles ES256 signature conversion correctly.
- **AdminNotification model-level audience query** — uses `CONCAT(',', audience, ',') LIKE '%,role,%'` which correctly anchors role names between separators so `admin` does NOT match `super_admin`. Subtle but important — naïve `LIKE %admin%` would have leaked notifications across audiences.
- **NewCommentListener docblock** — explicitly documents being safe to deploy ahead of peer NOTIF #1 (the AdminNotifier service): the `app()->bound($class) && !class_exists($class)` guard means listeners log + no-op rather than crash if the binding isn't there.
- **PushSubscriptionController graceful degradation** — returns HTTP 503 with `reason: 'not_configured'` when VAPID is missing, giving the JS layer a single clean banner instead of generic crash text.
- **FailedJobListener recursion guard** — `isSelfReferential($jobName)` prevents the listener from running on a failed AdminNotifier-broadcast job, which would otherwise queue another notif that itself fails infinitely.

---

## Recommended next actions

1. **(HIGH)** Create `resources/views/emails/gift-received.blade.php` with the dark-gold FLiK chrome. Optional bonus: refactor `GiftSubscriptionService::sendGiftEmail` into a `Mailable` class to match the rest of the transactional surface.
2. **(MED)** Decide on `scheduled_at`: either drop the column + form field, or wire `flik:email:dispatch-scheduled` into the Console scheduler.
3. **(LOW)** Fix the JS unread-count over-count in `admin-notifications.js:onIncoming` by only incrementing when the id is genuinely new.
4. **(LOW)** Whitelist HTTP 429 / 5xx in `WebPushSender::sendToAll` so transient failures don't permadrop subscriptions; consider an exponential-backoff column on `push_subscriptions` instead of a hard counter.
5. **(LOW)** Verify `NotificationPolicy` is auto-resolved (or register it explicitly in `AuthServiceProvider::$policies`). Add a feature test: `NotificationControllerTest::test_user_can_mark_their_own_notification`.
6. **(LOW)** Align severity colours (`#f59e0b` everywhere).
7. **(LOW)** Audit-log every `EmailCampaign` send / cancel / destroy via `AuditLogger` — this is a high-impact admin action with no current paper trail.
8. **(INFO)** Add a `@see` comment in `EventServiceProvider::$listen` referencing the two listeners wired via observers in `AppServiceProvider::boot()`.
9. **(Test gap)** **No notification-domain tests exist.** Add at minimum:
   - `AdminNotifierTest::test_notify_creates_row_and_dispatches_broadcast`
   - `AdminNotifierTest::test_unread_count_cache_invalidates_on_new_notif`
   - `AdminNotifierTest::test_audience_canonical_form_is_sorted_deduped`
   - `EmailCampaignTest::test_send_flips_status_atomically`
   - `EmailTrackingControllerTest::test_open_pixel_idempotent`
   - `EmailTrackingControllerTest::test_click_rejects_non_http_schemes`
   - `WebPushSenderTest::test_send_returns_not_configured_when_vapid_missing`
   - `PushSubscriptionControllerTest::test_subscribe_upserts_by_endpoint_hash`
   - `NewCommentListenerTest::test_skips_replies` (parent_id !== null)
   - `SecurityEventListenerTest::test_info_events_are_throttled` (5/window)
10. **(Doc)** README does not mention the VAPID generation step (`php artisan flik:push:generate-vapid-keys`) — worth a one-liner in the setup section.

---

## File map (absolute paths)

Services:
- `D:\AI\velflix\velflix\app\Services\Notifications\AdminNotifier.php`
- `D:\AI\velflix\velflix\app\Services\Email\SegmentBuilder.php`
- `D:\AI\velflix\velflix\app\Services\Email\CampaignDispatcher.php`
- `D:\AI\velflix\velflix\app\Services\Push\WebPushSender.php`
- `D:\AI\velflix\velflix\app\Services\Billing\GiftSubscriptionService.php` (see N-1)

Controllers:
- `D:\AI\velflix\velflix\app\Http\Controllers\NotificationController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\EmailTrackingController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\PushSubscriptionController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\NotificationController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\EmailCampaignController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\PushBroadcastController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\PaymentController.php` (line 350 dispatches `PaymentEventListener`)

Models:
- `D:\AI\velflix\velflix\app\Models\AdminNotification.php`
- `D:\AI\velflix\velflix\app\Models\AdminNotificationRead.php`
- `D:\AI\velflix\velflix\app\Models\Notification.php`
- `D:\AI\velflix\velflix\app\Models\EmailCampaign.php`
- `D:\AI\velflix\velflix\app\Models\EmailRecipient.php`
- `D:\AI\velflix\velflix\app\Models\EmailLinkClick.php`
- `D:\AI\velflix\velflix\app\Models\PushSubscription.php`
- `D:\AI\velflix\velflix\app\Models\PushMessage.php`

Events / Listeners / Observers:
- `D:\AI\velflix\velflix\app\Events\AdminNotificationCreated.php`
- `D:\AI\velflix\velflix\app\Events\SecurityEventLogged.php`
- `D:\AI\velflix\velflix\app\Listeners\Admin\NewCommentListener.php`
- `D:\AI\velflix\velflix\app\Listeners\Admin\NewUserListener.php`
- `D:\AI\velflix\velflix\app\Listeners\Admin\PaymentEventListener.php`
- `D:\AI\velflix\velflix\app\Listeners\Admin\SubscriptionCancelledListener.php`
- `D:\AI\velflix\velflix\app\Listeners\Admin\SecurityEventListener.php`
- `D:\AI\velflix\velflix\app\Listeners\Admin\EncodingJobUpdatedListener.php`
- `D:\AI\velflix\velflix\app\Listeners\Admin\FailedJobListener.php`
- `D:\AI\velflix\velflix\app\Listeners\SendLoginAlert.php`
- `D:\AI\velflix\velflix\app\Listeners\PushSecurityAlerts.php`
- `D:\AI\velflix\velflix\app\Observers\EncodingJobAdminNotifyObserver.php`
- `D:\AI\velflix\velflix\app\Observers\SubscriptionAdminNotifyObserver.php`
- `D:\AI\velflix\velflix\app\Providers\EventServiceProvider.php`
- `D:\AI\velflix\velflix\app\Providers\AppServiceProvider.php` (observer registrations at lines 106-107)

Mail / Notifications:
- `D:\AI\velflix\velflix\app\Mail\CampaignMailable.php`
- `D:\AI\velflix\velflix\app\Mail\NewDeviceLogin.php`
- `D:\AI\velflix\velflix\app\Notifications\Auth\VerifyEmailNotification.php`
- `D:\AI\velflix\velflix\app\Notifications\Auth\ResetPasswordNotification.php`

Jobs:
- `D:\AI\velflix\velflix\app\Jobs\SendCampaignEmail.php`
- `D:\AI\velflix\velflix\app\Jobs\BroadcastPushMessage.php`

Console:
- `D:\AI\velflix\velflix\app\Console\Kernel.php` (security digest at lines 48-52)
- `D:\AI\velflix\velflix\app\Console\Commands\SecurityDailyDigest.php`
- `D:\AI\velflix\velflix\app\Console\Commands\GenerateVapidKeys.php`
- `D:\AI\velflix\velflix\app\Console\Commands\PushTest.php`

Views:
- `D:\AI\velflix\velflix\resources\views\components\admin\notification-bell.blade.php`
- `D:\AI\velflix\velflix\resources\views\components\admin\layout.blade.php` (bell mount at line 265; Pusher key exposure at lines 151-155)
- `D:\AI\velflix\velflix\resources\views\components\push-opt-in.blade.php`
- `D:\AI\velflix\velflix\resources\views\components\cookie-banner.blade.php`
- `D:\AI\velflix\velflix\resources\views\components\layout.blade.php` (push-opt-in + cookie-banner mounts at lines 231, 238; vapid meta at line 109)
- `D:\AI\velflix\velflix\resources\views\emails\auth\verify.blade.php`
- `D:\AI\velflix\velflix\resources\views\emails\auth\reset.blade.php`
- `D:\AI\velflix\velflix\resources\views\emails\security\new-device-login.blade.php`
- `D:\AI\velflix\velflix\resources\views\emails\campaign.blade.php`
- `D:\AI\velflix\velflix\resources\views\emails\daily-admin-report.blade.php`
- **MISSING: `D:\AI\velflix\velflix\resources\views\emails\gift-received.blade.php`** (see N-1)

JS:
- `D:\AI\velflix\velflix\resources\js\admin-notifications.js`
- `D:\AI\velflix\velflix\resources\js\push-notifications.js`
- `D:\AI\velflix\velflix\resources\js\echo.js`
- `D:\AI\velflix\velflix\resources\js\cookie-consent.js`
- `D:\AI\velflix\velflix\resources\js\app.js` (imports at lines 23, 29, 35, 41)

Routes / Channels:
- `D:\AI\velflix\velflix\routes\web.php` (admin notifications 924-933; email tracking 488-494; push subscribe 371-376; admin push 912-917; admin email campaigns 841-853; user notifications 250-253)
- `D:\AI\velflix\velflix\routes\channels.php` (admin channel auth callbacks 47-68)

Migrations:
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_060001_create_admin_notifications_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_060002_create_admin_notification_reads_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_110001_create_push_subscriptions_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_110002_create_push_messages_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_120001_create_email_campaigns_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_120002_create_email_recipients_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_120003_create_email_link_clicks_table.php`

Tests: none exist for this domain (see recommended action #9).
