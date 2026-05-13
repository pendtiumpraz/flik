<?php

namespace App\Providers;

use App\Contracts\Ai\AiClientContract;
use App\Contracts\Storage\CdnStorageContract;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Security\HtmlSanitizer;
use App\Services\Storage\BunnyStorageService;
use App\Services\Storage\S3StorageService;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

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
            return new BunnyStorageService();
        });

        $this->app->singleton('storage.s3', function () {
            return new S3StorageService();
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
        $this->app->singleton(HtmlSanitizer::class, fn () => new HtmlSanitizer());
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Force every generated URL onto HTTPS in production so route() /
        // url() / asset() never emit http:// links that would trigger mixed-
        // content blocks or break OAuth redirect URI matching. We deliberately
        // skip local + testing so artisan serve & PHPUnit keep working over
        // plain HTTP (Vite dev server too).
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        Gate::define('admin', function (User $user) {
            return $user->username === 'admin';
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
    }
}
