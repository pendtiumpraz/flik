<?php

namespace App\Providers;

use App\Contracts\Ai\AiClientContract;
use App\Contracts\Storage\CdnStorageContract;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Storage\BunnyStorageService;
use App\Services\Storage\S3StorageService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Gate::define('admin', function (User $user) {
            return $user->username === 'admin';
        });
    }
}
