<?php

namespace App\Providers;

use App\Contracts\Ai\AiClientContract;
use App\Contracts\Storage\CdnStorageContract;
use App\Models\CommentReaction;
use App\Models\EncodingJob;
use App\Models\Subscription;
use App\Models\User;
use App\Observers\CommentReactionObserver;
use App\Observers\EncodingJobAdminNotifyObserver;
use App\Observers\SubscriptionAdminNotifyObserver;
use App\Observers\UserObserver;
use App\Services\Ai\AiClient;
use App\Services\Features\FeatureManager;
use App\Services\Security\HtmlSanitizer;
use App\Services\Storage\BunnyStorageService;
use App\Services\Storage\S3StorageService;
use Google\Cloud\Storage\StorageClient;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter;
use League\Flysystem\GoogleCloudStorage\UniformBucketLevelAccessVisibility;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // AI client contract → concrete (lets tasks DI the interface).
        $this->app->bind(AiClientContract::class, AiClient::class);

        // Named singleton bindings so callers can `app('storage.bunny')`
        // / `app('storage.s3')` regardless of which one is the default.
        $this->app->singleton('storage.bunny', function () {
            return new BunnyStorageService;
        });

        $this->app->singleton('storage.s3', function () {
            return new S3StorageService;
        });

        // Default CDN storage binding. Prefer Bunny when BUNNY_STORAGE_KEY
        // is configured, otherwise fall back to S3. BunnyStorageService
        // throws if its required env vars are missing — catch that and
        // degrade silently to S3 so boot never blows up.
        $this->app->singleton(CdnStorageContract::class, function ($app) {
            $bunnyKey = (string) (config('services.bunny.storage_key') ?? env('BUNNY_STORAGE_KEY', ''));

            if ($bunnyKey !== '') {
                try {
                    return $app->make('storage.bunny');
                } catch (\Throwable $e) {
                    Log::warning('CdnStorageContract: BunnyStorageService unavailable, falling back to S3.', [
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            return $app->make('storage.s3');
        });

        // HTML sanitizer for user-generated content (comments, chat,
        // rich-text fields). Singleton because the instance is stateless
        // and we want to avoid re-allocating DOMDocument-adjacent state
        // per call.
        $this->app->singleton(HtmlSanitizer::class, fn () => new HtmlSanitizer);

        // FeatureManager — wraps FeatureFlag::evaluate behind a
        // single injectable service so controllers can DI it and
        // tests can swap with a fake. Stateless ⇒ singleton.
        $this->app->singleton(FeatureManager::class, fn () => new FeatureManager);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Google Cloud Storage NATIVE driver ("gcs" disk). Keyless: authenticates
        // via Application Default Credentials — on a GCE VM that's the attached
        // service account through the metadata server, so no HMAC/JSON key is
        // needed (works even when org policy blocks SA-key + HMAC creation).
        //
        // Critically it uses the UniformBucketLevelAccess visibility handler, so
        // it never sends per-object ACLs — UBLA-enforced buckets reject ACLs with
        // "InvalidArgument", which is exactly why the S3-interop adapter can't be
        // used against this bucket. Object visibility is governed by the bucket's
        // IAM (allUsers:objectViewer) instead.
        Storage::extend('gcs', function ($app, array $config) {
            $client = new StorageClient(array_filter([
                'projectId' => $config['project_id'] ?? null,
                'keyFilePath' => $config['key_file'] ?? null, // null ⇒ ADC
            ]));

            $adapter = new GoogleCloudStorageAdapter(
                $client->bucket($config['bucket']),
                (string) ($config['root'] ?? ''),
                new UniformBucketLevelAccessVisibility,
            );

            return new FilesystemAdapter(
                new Filesystem($adapter, $config),
                $adapter,
                $config,
            );
        });

        // Force every generated URL onto HTTPS in production so route() /
        // url() / asset() never emit http:// links that would trigger mixed-
        // content blocks or break OAuth redirect URI matching. We deliberately
        // skip local + testing so artisan serve & PHPUnit keep working over
        // plain HTTP (Vite dev server too).
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Register model observers. UserObserver assigns the default
        // 'user' role on registration so every new account lands with a
        // sane permission baseline (vs. the previous behaviour of zero
        // roles → effectively guest-level access until manually granted).
        User::observe(UserObserver::class);

        // Admin bell — fan model state-transitions onto the in-app admin
        // notification feed. Each observer only emits on a genuine
        // transition (status field actually changed) so re-saves don't
        // duplicate notifications.
        Subscription::observe(SubscriptionAdminNotifyObserver::class);
        EncodingJob::observe(EncodingJobAdminNotifyObserver::class);

        // Reactions: keep Comment.reactions_count + top_reaction
        // denormalised columns in sync, bust the per-comment cache,
        // and (when broadcasting is configured) fan out live pill
        // updates over Pusher. See CommentReactionObserver for the
        // single-statement recompute query.
        CommentReaction::observe(CommentReactionObserver::class);

        // NOTE: the `admin` gate is now owned by AuthServiceProvider where
        // it routes through the new RBAC system (super_admin OR has 'admin'
        // role). The legacy `$user->username === 'admin'` check here was a
        // dev-only fallback that incorrectly granted access to anyone who
        // happened to register that username — removed in the role wiring
        // pass.

        // @role('admin') / @role('admin', 'content_manager')
        // Truthy when an authenticated user holds ANY of the listed roles.
        // Variadic so views can write `@role('admin', 'finance')` directly.
        Blade::if('role', function (...$roles) {
            if (! auth()->check()) {
                return false;
            }
            $flat = [];
            foreach ($roles as $r) {
                if (is_array($r)) {
                    $flat = array_merge($flat, $r);
                } else {
                    $flat[] = (string) $r;
                }
            }

            return auth()->user()->hasRole($flat);
        });

        // @hasperm('movies.create')
        // Permission-level check — routes through Gate::allows so the
        // super-admin Gate::before bypass still applies. Falls back to
        // the User::hasPermission helper when the gate is not registered
        // (e.g. permissions table not yet seeded on a fresh install).
        Blade::if('hasperm', function (string $name) {
            if (! auth()->check()) {
                return false;
            }
            $user = auth()->user();
            if (Gate::has($name)) {
                return Gate::allows($name);
            }

            return $user->hasPermission($name);
        });

        // @safe($html) — render-time HTML sanitization for cases where
        // re-sanitizing on output is preferred over (or in addition to)
        // sanitizing on save. Compiles to a single function call so the
        // Blade cache stays small.
        //
        // Usage: @safe($comment->body)
        //
        // Equivalent inline form: {!! app(HtmlSanitizer::class)->sanitize($comment->body) !!}
        Blade::directive('safe', function (string $expression): string {
            return "<?php echo app(\\App\\Services\\Security\\HtmlSanitizer::class)->sanitize($expression); ?>";
        });

        // @feature('key') ... @endfeature — gate a Blade block on a
        // feature flag for the current user. Compiles into a plain
        // if/endif so the runtime overhead is identical to writing
        // `@if(feature('key'))` by hand.
        //
        // Why a custom directive when feature() already exists? Two
        // reasons: (1) it reads more naturally to operators auditing
        // a template, and (2) the form `@feature('x', $user)` for a
        // non-current user is just as readable as the manual @if.
        Blade::if('feature', function (string $key, $user = null): bool {
            return feature($key, $user instanceof User ? $user : auth()->user());
        });

        // @setting('site.name') — echo a setting value with htmlspecial
        // chars escaping (same safety contract as {{ $foo }}). Default
        // can be passed as a 2nd arg: @setting('site.name', 'FLiK').
        Blade::directive('setting', function (string $expression): string {
            return "<?php echo e(setting($expression)); ?>";
        });
    }
}
