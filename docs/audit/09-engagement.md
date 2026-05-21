# Audit 09 — Engagement Features

Date: 2026-05-20
Branch: main
Scope: Watchlist, Ratings, Comments (nested replies + reactions), Watch streaks + achievements + leaderboards, Rewards/Coins gamification, Movie Trivia Quiz Game.

## Summary

The engagement surface is overall **wired and reachable** from the UI. All seven controllers route correctly, persist correctly, and every route resolves to a navigable entry point.

There is, however, **structural drift** between the legacy gamification stack (`user_levels` + `daily_rewards` driven by `RewardsController`) and the swarm-era stack (`watch_streaks` + `streak_history` driven by `StreakService`). Both run in parallel against the same user; `StreakService` mirrors back into the legacy column to keep the rewards page rendering, but the two systems compute streaks slightly differently (Asia/Jakarta day vs server `now()->toDateString()`), give different rewards, and can be claimed independently. See **Issue E-1** below.

Two genuine bugs:
- **E-2** `RewardsController@index` queries `Achievement::active()` but `daily_rewards` table also feeds `claimedToday`; column name `last_streak_date` is cast `date` but compared with `->toDateString()` in places — works, but the parallel codepath is easy to break.
- **E-3** `UserLevel::addXp()` is **not transactional** and reads `xp_for_next_level` from a dynamically recomputed accessor inside a `while` loop — a concurrent `addXp()` from two requests (e.g. quiz submit + streak milestone in the same second) will race.

Everything else (watchlist toggle, rating store, comment store + delete + reactions, quiz, freeze purchase, leaderboards) is solid.

---

## Critical-checks scorecard

| # | Question | Verdict | Evidence |
|---|----------|---------|----------|
| 1 | Are all routes reachable from UI? | YES (with caveats — see notes) | `resources/views/components/header.blade.php` + `resources/views/components/movies/show.blade.php` + `resources/views/components/home/streak-widget.blade.php` |
| 2 | Comment reactions render under each comment? | YES | `resources/views/components/movies/show.blade.php:514` (top-level) and `:536` (replies), both gated by `@auth` |
| 3 | Streak widget on home page? | YES | `resources/views/main.blade.php:33` inside `@auth` block |
| 4 | Leaderboards nav link? | YES (single link, 3-tab page) | Desktop: `header.blade.php:117` "Leaderboards" → `leaderboards.streaks`; mobile menu: `:310`; profile page deep links to all three at `profile/show.blade.php:149-162` |
| 5 | Quiz reachable from movie detail? | YES | `resources/views/components/movies/show.blade.php:225` "Mainkan Trivia Quiz" button |
| 6 | Achievement showcase on profile? | YES | `resources/views/profile/show.blade.php:120-141` (preview card with "View All →" → `profile.achievements` page); standalone view at `resources/views/profile/achievements.blade.php` |
| 7 | Coin earning + spending flows? | YES, but **see E-1** | Earning: quiz submit, daily reward, streak milestones. Spending: streak freeze purchase. No other spend sinks yet (no marketplace, no badge purchase, no rental). |

---

## Inventory

### Routes (all in `routes/web.php`, inside the `auth` group)

| Route | Method | Controller | Name | Reachable from |
|-------|--------|------------|------|----------------|
| `/my-list` | GET | `WatchlistController@index` | `watchlist.index` | header `My List` dropdown, profile menu, mobile nav |
| `/watchlist/toggle` | POST | `WatchlistController@toggle` | `watchlist.toggle` | movie detail button, watchlist remove button |
| `/rating` | POST | `RatingController@store` | `rating.store` | movie detail star-row |
| `/rating` | DELETE | `RatingController@destroy` | `rating.destroy` | **Not wired in any view** — orphan endpoint (see Issue E-4) |
| `/comment` | POST | `CommentController@store` (throttle:comments) | `comment.store` | movie detail comment form |
| `/comment/{comment}` | DELETE | `CommentController@destroy` | `comment.destroy` | movie detail (owner / staff only) |
| `/comments/{comment}/react` | POST (throttle:comments) | `CommentReactionController@toggle` | `comment.react` | `<x-comments.reaction-bar>` Alpine factory |
| `/rewards` | GET | `RewardsController@index` | `rewards.index` | header "Rewards", profile menu, mobile nav |
| `/rewards/claim-daily` | POST | `RewardsController@claimDaily` | `rewards.claimDaily` | rewards page daily check-in form |
| `/profile/achievements` | GET | `ProfileController@achievements` | `profile.achievements` | profile show "View All →" |
| `/streak/freeze` | POST | `StreakController@freeze` | `streak.freeze` | streak widget on home + main |
| `/leaderboards/streaks` | GET (throttle:search) | `LeaderboardController@streaks` | `leaderboards.streaks` | header "Leaderboards", profile page, streak widget |
| `/leaderboards/xp` | GET (throttle:search) | `LeaderboardController@xp` | `leaderboards.xp` | leaderboard tab strip + profile page |
| `/leaderboards/watches` | GET (throttle:search) | `LeaderboardController@watches` | `leaderboards.watches` | leaderboard tab strip + profile page |
| `/movie/{movie}/quiz` | GET | `QuizController@start` | `quiz.start` | movie detail "Mainkan Trivia Quiz" button |
| `/movie/{movie}/quiz` | POST | `QuizController@submit` | `quiz.submit` | quiz play form (`$refs.submitForm`) |
| `/movie/{movie}/quiz/leaderboard` | GET | `QuizController@leaderboard` | `quiz.leaderboard` | quiz result page "Leaderboard Penuh" link |

### Models

| Model | Table | Notes |
|-------|-------|-------|
| `Watchlist` | `watchlists` | Plain pivot — `user_id` + `movie_id` unique by convention only, no DB unique index in migration |
| `Rating` | `ratings` | `score 1-10`, optional `review` text. `updateOrCreate` keyed on `(user_id, movie_id)` |
| `Comment` | `comments` | `parent_id` nullable for replies. `setBodyAttribute` runs `HtmlSanitizer` on save. `topLevel` scope filters `whereNull('parent_id')`. Has `reactions()`, `reactionsByType()` (5min cache), `toggleReaction()`, `reactionByUser()` |
| `CommentReaction` | `comment_reactions` | `(comment_id, user_id)` unique. `REACTIONS = [like, love, laugh, wow, sad, angry]` + `EMOJI` map |
| `Coin` | `coins` | Write-only ledger (`$guarded = ['*']`). `Coin::earn()`, `Coin::spend()` (negative amount), `Coin::balanceFor()` |
| `UserLevel` | `user_levels` | `level`, `xp`, `total_coins`, `watch_streak` (legacy mirror), `last_streak_date`. `addXp()` mutates level, NOT transactional (E-3) |
| `Achievement` | `achievements` | `slug` unique, `tier ∈ {bronze,silver,gold,platinum}`, `tier_color` accessor, `coin_reward`, `xp_reward`. Pivot `user_achievements` with `unlocked_at` |
| `MovieQuizQuestion` | `movie_quiz_questions` | `option_a..d`, `correct_option` CHAR(1), `difficulty` enum, `explanation` nullable |
| `QuizAttempt` | `quiz_attempts` | `score 0-100`, `total_questions`, `correct_count`, `time_seconds`, `completed_at`. `scopeBestPerUser` for leaderboard |
| `WatchStreak` | `watch_streaks` | One row per user (unique FK). `current_streak`, `longest_streak`, `last_watch_date` (date), `freeze_credits`. No `created_at`, only `updated_at` |
| `StreakHistoryEntry` | `streak_history` | Append-only `(user_id, date)` unique. Stores per-day reward & milestone marker |

### Services

| Service | Purpose |
|---------|---------|
| `App\Services\Gamification\StreakService` | Single entry for `recordWatch()` (called from `WatchHistoryController::updateProgress`), `purchaseFreeze()`, `grantFreezeCredit()` (cron), `topStreaks()`, `rankFor()`. Milestones at days 1/3/7/14/30/100/365 with XP+coins+slug |
| `App\Services\Ai\Tasks\QuizQuestionGenerator` | AI quiz generation, idempotent (delete-by-movie + bulk insert in transaction), graceful empty-return on AI failure, MIN_COUNT=3, MAX_COUNT=20, prompt locked to strict-JSON Indonesian |

### Views

| View | Bound to | Notes |
|------|----------|-------|
| `components/movies/show.blade.php` | `VelflixController@show` | Watchlist toggle (line 195), rating row (352), comments list + reaction bars (455–541), comment form (429), quiz CTA (225) |
| `watchlist/index.blade.php` | `WatchlistController@index` | Grid + remove button |
| `rewards/index.blade.php` | `RewardsController@index` | Daily check-in 7-day grid, achievement preview grid, top-10 XP leaderboard |
| `profile/achievements.blade.php` | `ProfileController@achievements` | Full grid w/ category filter, locked-state grayscale |
| `quiz/play.blade.php` | `QuizController@start` | Alpine `quizGame()` factory, hidden form submit, time tracker |
| `quiz/result.blade.php` | `QuizController@submit` | Score hero, per-question breakdown, embedded top-10 |
| `quiz/leaderboard.blade.php` | `QuizController@leaderboard` | Top-50 best score per user |
| `leaderboards/streaks.blade.php` | `LeaderboardController@streaks` | Tab strip, viewer-rank callout, top-50 |
| `leaderboards/xp.blade.php` | `LeaderboardController@xp` | Glob confirmed |
| `leaderboards/watches.blade.php` | `LeaderboardController@watches` | Glob confirmed |
| `components/home/streak-widget.blade.php` | Self-contained (queries `WatchStreak` directly) | Mounted in `resources/views/main.blade.php:33` inside `@auth` |
| `components/comments/reaction-bar.blade.php` | Rendered inline | Mirrors `CommentReaction::REACTIONS` + `EMOJI` literals (intentional 2-touch when adding reactions) |

### Migrations

| File | Tables |
|------|--------|
| `2026_03_07_100001_create_watchlists_table.php` | `watchlists` |
| `2026_03_07_100003_create_ratings_table.php` | `ratings` |
| `2026_03_07_100004_create_comments_table.php` | `comments` |
| `2026_03_07_100006_create_gamification_tables.php` | `coins`, `achievements`, `user_achievements`, `user_levels`, `daily_rewards` |
| `2026_05_10_010005_add_moderation_to_comments.php` | `comments.moderation_*` |
| `2026_05_10_010010_add_sentiment_to_comments.php` | `comments.sentiment*` |
| `2026_05_10_030001_create_movie_quiz_questions_table.php` | `movie_quiz_questions` |
| `2026_05_10_030002_create_quiz_attempts_table.php` | `quiz_attempts` |
| `2026_05_10_030003_add_spoiler_columns_to_comments.php` | `comments.is_spoiler`, `spoiler_confidence`, `spoiler_checked_at` |
| `2026_05_10_140001_create_watch_streaks_table.php` | `watch_streaks` |
| `2026_05_10_140002_create_streak_history_table.php` | `streak_history` |
| `2026_05_10_170001_create_comment_reactions_table.php` | `comment_reactions` (unique `(comment_id, user_id)`, index `(comment_id, reaction)`) |
| `2026_05_10_170002_add_reaction_counts_to_comments.php` | `comments.reactions_count`, `comments.top_reaction` |

---

## Findings

### E-1 — Two parallel streak systems (HIGH)
Both stacks coexist and both can update the user's streak in the same day:

- **Legacy**: `RewardsController@claimDaily` writes to `user_levels.watch_streak` + `user_levels.last_streak_date` + inserts a `daily_rewards` row + pays `[5,10,15,20,30,50,100]` coins per day (caps at day 7, rolls over).
- **Modern**: `StreakService::recordWatch()` called from `WatchHistoryController::updateProgress` writes to `watch_streaks` + `streak_history`, pays milestones at days 1/3/7/14/30/100/365, mirrors back into `user_levels.watch_streak` and `user_levels.last_streak_date` via `syncLegacyStreakColumn()`.

Day-boundary semantics differ:
- legacy uses PHP `now()->subDay()->toDateString()` (server TZ),
- service uses `Carbon::now('Asia/Jakarta')->startOfDay()`.

A user who claims their daily reward at 23:30 server-time AND watches a video that triggers the modern path at 23:35 will hit both code paths. The "modern" path's `syncLegacyStreakColumn()` will overwrite the legacy `watch_streak` that `claimDaily` just bumped — quietly drift but visible streak rewards still flow correctly (each path checks its own claim/idempotency table).

Recommendation: deprecate `RewardsController@claimDaily` and migrate the 7-day cumulative reward calendar onto `StreakService` (or vice versa). Decide one source of truth before adding a third feature on top.

### E-2 — Daily-rewards `claimedToday` & streak rollover edge case (LOW)
`RewardsController@claimDaily` (lines 65-70) only resets `watch_streak` to `1` when the last date is neither yesterday nor today — but if `last_streak_date` is `null` (first-ever claim), the first `if` is skipped and the `else if` becomes `!null || ...` which is true, so it sets `watch_streak = 1`. That works, but it's fragile to read. More importantly, the controller uses `\DB::table('daily_rewards')->insert(...)` without checking the unique constraint at the application layer — relying on the earlier `exists()` check. Race between two tabs claiming simultaneously will throw a unique-violation 500 (not caught).

### E-3 — `UserLevel::addXp()` is not concurrency-safe (MEDIUM)
```php
public function addXp(int $amount): void {
    $this->xp += $amount;
    while ($this->xp >= $this->xp_for_next_level) {
        $this->xp -= $this->xp_for_next_level;
        $this->level++;
    }
    $this->save();
}
```
This is `app/Models/UserLevel.php:35`. Two concurrent grants (e.g. `QuizController::submit` awards `+50 XP` while `StreakService::awardMilestones` awards `+100 XP` in the same second) both read the same `xp` value, both compute a level-up locally, and the later `save()` wins. Lost write of one of the XP awards.

Fix sketch: wrap in `DB::transaction()` with `lockForUpdate()`, or use atomic `increment()` + recompute level in a follow-up query.

### E-4 — `rating.destroy` route is orphaned (LOW)
`RatingController@destroy` exists and is routed at `DELETE /rating`, but no view ever calls it. The movie detail page only renders the rating star buttons (POST). Users can change their rating (it `updateOrCreate`s) but cannot remove it. Either wire a "Hapus Rating" button on the movie detail page, or drop the route + method.

### E-5 — Watchlist table lacks DB-level uniqueness (LOW)
`Watchlist::create()` in `WatchlistController@toggle` happens after a `where()->first()` check, so duplicates are unlikely in practice — but there's no unique index on `(user_id, movie_id)` in `2026_03_07_100001_create_watchlists_table.php`. A double-submit (two browser tabs) can create two rows. The toggle handler would then leave the second row when the user clicks again. Worth adding `$table->unique(['user_id', 'movie_id'])`.

### E-6 — Comment delete UI doesn't soft-delete; `restore`/`forceDelete` policy methods return `false` (LOW)
`Comment` model has no `SoftDeletes` trait — `CommentController@destroy` does a hard delete. The `CommentPolicy::restore()` and `::forceDelete()` methods exist and return `false` (admin bypass via `Gate::before` overrides for `forceDelete`). This is intentional based on the docblocks, but it means moderation cannot "soft-suspend" — only delete or change moderation_status. Mention only because the policy file implies a soft-delete plan that hasn't landed.

### E-7 — Quiz answer payload trusts client question IDs (LOW)
`QuizController@submit` reads `answers[questionId] = letter` from the form, then loops over `MovieQuizQuestion::where('movie_id', $movie->id)` server-side and matches by ID. If a malicious client submits answers keyed by IDs that aren't in this movie's set, those keys are simply ignored — so this is fine. Worth noting that there's no defence against a client retrying with different `answers` payloads to brute-force the correct option; the per-attempt persistence is unique per submit but nothing rate-limits the `quiz.submit` POST. Consider `throttle:5,1` on `quiz.submit` to slow brute-forcing.

### E-8 — Streak widget renders an unaffordable "Beli Freeze" button when balance < 50 (COSMETIC)
`resources/views/components/home/streak-widget.blade.php:71-82` shows the disabled button when `freeze_credits < 7`. It correctly disables the button when the user can't afford it, but the price tag (`50 coins`) still says the same thing — fine. The flash message comparison at line 98 uses `str_contains((string) session('success'), 'reeze')` (lowercase r-less "reeze") which is a clever match for both "Freeze" and "freeze" but is fragile to copy-changes; safer to use a dedicated session key.

### E-9 — `RewardsController` directly accesses `\DB::table('daily_rewards')` instead of a model (STYLE)
There is no `App\Models\DailyReward` model (only a `use` import that doesn't resolve to anything). This works because `\DB::table()` is used everywhere, but the dangling `use App\Models\DailyReward;` at the top of the controller is dead. Either create the model or drop the import.

### E-10 — Rewards page leaderboard hard-codes top 10 by `(level desc, xp desc)` (INFO)
`RewardsController@index` line 35-40 builds a top-10 leaderboard locally, duplicating logic from `LeaderboardController@xp` which exposes the same with paging + viewer rank. Two cards, two queries, one truth. Centralise in `LeaderboardController` or extract to a shared service.

---

## Things that work well

- **Comment reactions architecture** is clean: model toggle method, Pusher-aware observer (`CommentReactionObserver` registered in `AppServiceProvider:114`), Alpine factory consumes the JSON, throttle shares the `comments` limiter, cache key bumped by observer.
- **Streak service idempotency**: same-day repeats are no-ops, `firstOrCreate` on history row, transaction wraps read-modify-write, freeze-credit fallback before reset.
- **Quiz cheat-resistance**: correct answers never leave the server — `QuizController::start` strips `correct_option`/`explanation` before `view()`; `quiz/play.blade.php` even has a comment noting "we don't expose correct answers client-side".
- **Spoiler comments** are blur-gated with a click-to-reveal Alpine widget (`movies/show.blade.php:473-503`), with an AI-detected badge when confidence ≥ 0.7.
- **Achievement page** correctly grayscales locked tiles, shows tier corner badge, rewards strip, hover "Earned X ago" tooltip.
- **Reaction bar** mirrors model constants in a literal — explicit two-touch change for new reactions is documented in the blade comment.

---

## Recommended next actions

1. **(High)** Pick a single streak source of truth. Either retire `RewardsController@claimDaily` (and migrate `daily_rewards` payouts into `StreakService::recordWatch`) or stop calling `StreakService::recordWatch` from `WatchHistoryController` and let the user manually claim. Currently both fire and silently overwrite each other's display column.
2. **(Med)** Make `UserLevel::addXp()` atomic — wrap in transaction with row lock, or do an atomic increment then a level-recompute UPDATE.
3. **(Low)** Add unique index `watchlists(user_id, movie_id)`.
4. **(Low)** Add `throttle:5,1` to `quiz.submit` to deter brute-forcing.
5. **(Low)** Either expose `RatingController@destroy` in the movie detail UI (e.g. "Hapus rating saya" link below the star row when `$userRating` is set) or remove the endpoint.
6. **(Low)** Try-catch the unique-violation in `RewardsController@claimDaily` insert path; convert to a friendly "already claimed" flash instead of a 500.
7. **(Style)** Drop the unused `use App\Models\DailyReward;` import in `RewardsController`. Centralise the rewards-page leaderboard via `LeaderboardController`.
8. **(Test)** Only `Gamification\StreakServiceTest` exists. No feature tests for `WatchlistController`, `RatingController`, `CommentController`, `CommentReactionController`, `QuizController`, `RewardsController`, `LeaderboardController`. Add at minimum:
   - `RatingControllerTest::test_updates_existing_rating_in_place`
   - `CommentReactionControllerTest::test_toggle_off_when_same_reaction_clicked_twice`
   - `QuizControllerTest::test_correct_answers_never_in_play_payload`
   - `RewardsControllerTest::test_claim_daily_is_idempotent_within_day`

---

## File map (absolute paths)

Controllers:
- `D:\AI\velflix\velflix\app\Http\Controllers\WatchlistController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\RatingController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\CommentController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\CommentReactionController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\RewardsController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\QuizController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\LeaderboardController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\StreakController.php`

Models:
- `D:\AI\velflix\velflix\app\Models\Watchlist.php`
- `D:\AI\velflix\velflix\app\Models\Rating.php`
- `D:\AI\velflix\velflix\app\Models\Comment.php`
- `D:\AI\velflix\velflix\app\Models\CommentReaction.php`
- `D:\AI\velflix\velflix\app\Models\Coin.php`
- `D:\AI\velflix\velflix\app\Models\UserLevel.php`
- `D:\AI\velflix\velflix\app\Models\Achievement.php`
- `D:\AI\velflix\velflix\app\Models\MovieQuizQuestion.php`
- `D:\AI\velflix\velflix\app\Models\QuizAttempt.php`
- `D:\AI\velflix\velflix\app\Models\WatchStreak.php`
- `D:\AI\velflix\velflix\app\Models\StreakHistoryEntry.php`

Services / Tasks:
- `D:\AI\velflix\velflix\app\Services\Gamification\StreakService.php`
- `D:\AI\velflix\velflix\app\Services\Ai\Tasks\QuizQuestionGenerator.php`

Policy / Observer:
- `D:\AI\velflix\velflix\app\Policies\CommentPolicy.php`
- `D:\AI\velflix\velflix\app\Observers\CommentReactionObserver.php`

Views:
- `D:\AI\velflix\velflix\resources\views\components\movies\show.blade.php`
- `D:\AI\velflix\velflix\resources\views\components\comments\reaction-bar.blade.php`
- `D:\AI\velflix\velflix\resources\views\components\home\streak-widget.blade.php`
- `D:\AI\velflix\velflix\resources\views\main.blade.php` (home; mounts streak widget at line 33)
- `D:\AI\velflix\velflix\resources\views\watchlist\index.blade.php`
- `D:\AI\velflix\velflix\resources\views\rewards\index.blade.php`
- `D:\AI\velflix\velflix\resources\views\profile\achievements.blade.php`
- `D:\AI\velflix\velflix\resources\views\profile\show.blade.php`
- `D:\AI\velflix\velflix\resources\views\quiz\play.blade.php`
- `D:\AI\velflix\velflix\resources\views\quiz\result.blade.php`
- `D:\AI\velflix\velflix\resources\views\quiz\leaderboard.blade.php`
- `D:\AI\velflix\velflix\resources\views\leaderboards\streaks.blade.php`
- `D:\AI\velflix\velflix\resources\views\leaderboards\xp.blade.php`
- `D:\AI\velflix\velflix\resources\views\leaderboards\watches.blade.php`
- `D:\AI\velflix\velflix\resources\views\components\header.blade.php` (nav links)

Routes / Tests:
- `D:\AI\velflix\velflix\routes\web.php` (engagement section lines 182-205, 255-284, 331-334)
- `D:\AI\velflix\velflix\tests\Feature\Gamification\StreakServiceTest.php` (only existing feature test)
