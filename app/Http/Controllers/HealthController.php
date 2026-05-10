<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * Health check endpoints. Used by:
 * - Load balancers (basic /healthz)
 * - Container orchestrators (Kubernetes, ECS) — readiness/liveness probes
 * - Monitoring (UptimeRobot, Pingdom)
 * - Microservices split later (each service will have own healthz)
 */
class HealthController extends Controller
{
    /**
     * Basic liveness check — is the app responding at all?
     * Cheap, no dependencies. Use for K8s livenessProbe.
     */
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'flik-web',
            'time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Readiness check — is the app ready to serve traffic?
     * Tests DB + cache. Use for K8s readinessProbe.
     */
    public function ready(): JsonResponse
    {
        $checks = [];
        $allHealthy = true;

        // DB
        try {
            DB::connection()->getPdo();
            $checks['database'] = ['status' => 'ok'];
        } catch (\Throwable $e) {
            $checks['database'] = ['status' => 'fail', 'error' => $e->getMessage()];
            $allHealthy = false;
        }

        // Cache (Redis or file)
        try {
            $key = '_healthz_' . uniqid();
            Cache::put($key, 'ok', 5);
            $val = Cache::get($key);
            Cache::forget($key);
            $checks['cache'] = $val === 'ok' ? ['status' => 'ok'] : ['status' => 'fail'];
            if ($val !== 'ok') $allHealthy = false;
        } catch (\Throwable $e) {
            $checks['cache'] = ['status' => 'fail', 'error' => $e->getMessage()];
            $allHealthy = false;
        }

        return response()->json([
            'status' => $allHealthy ? 'ok' : 'degraded',
            'service' => 'flik-web',
            'checks' => $checks,
            'time' => now()->toIso8601String(),
        ], $allHealthy ? 200 : 503);
    }

    /**
     * Detailed status — includes more info, slower. For dashboards/debug.
     */
    public function detailed(): JsonResponse
    {
        $info = [
            'service' => 'flik-web',
            'version' => config('app.version', 'dev'),
            'environment' => app()->environment(),
            'time' => now()->toIso8601String(),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'database_connection' => config('database.default'),
            'cache_driver' => config('cache.default'),
            'queue_driver' => config('queue.default'),
        ];

        // AI provider status
        try {
            $provider = \App\Models\AiProvider::default();
            $info['ai_default_provider'] = $provider ? "{$provider->provider}/{$provider->model}" : 'none';
        } catch (\Throwable $e) {
            $info['ai_default_provider'] = 'error';
        }

        return response()->json($info);
    }
}
