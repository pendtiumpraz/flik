<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiProvider;
use App\Models\AiUsageLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AiUsageController extends Controller
{
    /**
     * Admin dashboard for AI usage analytics.
     */
    public function index()
    {
        $now      = Carbon::now();
        $since7d  = $now->copy()->subDays(7);
        $since30d = $now->copy()->subDays(30);

        $spend7d  = (float) AiUsageLog::where('created_at', '>=', $since7d)->sum('cost_usd');
        $spend30d = (float) AiUsageLog::where('created_at', '>=', $since30d)->sum('cost_usd');

        $totals30d = AiUsageLog::where('created_at', '>=', $since30d)
            ->selectRaw('
                COUNT(*) as call_count,
                SUM(input_tokens) as input_tokens,
                SUM(output_tokens) as output_tokens,
                SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as success_count,
                AVG(latency_ms) as avg_latency
            ')
            ->first();

        $callCount30d = (int) ($totals30d->call_count ?? 0);
        $tokenSum30d  = (int) (($totals30d->input_tokens ?? 0) + ($totals30d->output_tokens ?? 0));
        $successRate  = $callCount30d > 0
            ? round(((int) $totals30d->success_count / $callCount30d) * 100, 1)
            : 100.0;
        $avgLatency   = $totals30d->avg_latency ? (int) round((float) $totals30d->avg_latency) : null;

        $stats = [
            'spend_7d'        => round($spend7d, 4),
            'spend_30d'       => round($spend30d, 4),
            'total_tokens_30d' => $tokenSum30d,
            'total_calls_30d' => $callCount30d,
            'success_rate_30d' => $successRate,
            'avg_latency_ms'  => $avgLatency,
            'all_time_spend'  => (float) AiProvider::sum('total_cost_usd'),
            'all_time_tokens' => (int) AiProvider::sum('total_tokens_used'),
        ];

        // Per-provider breakdown (last 30 days). LEFT JOIN so providers with zero usage still appear.
        $perProvider = AiProvider::query()
            ->leftJoin('ai_usage_logs', function ($join) use ($since30d) {
                $join->on('ai_usage_logs.ai_provider_id', '=', 'ai_providers.id')
                    ->where('ai_usage_logs.created_at', '>=', $since30d);
            })
            ->select(
                'ai_providers.id',
                'ai_providers.name',
                'ai_providers.provider',
                'ai_providers.model',
                'ai_providers.is_active',
                'ai_providers.is_default',
                DB::raw('COUNT(ai_usage_logs.id) as call_count'),
                DB::raw('COALESCE(SUM(ai_usage_logs.input_tokens), 0) as input_tokens'),
                DB::raw('COALESCE(SUM(ai_usage_logs.output_tokens), 0) as output_tokens'),
                DB::raw('COALESCE(SUM(ai_usage_logs.cost_usd), 0) as cost_usd'),
                DB::raw('AVG(ai_usage_logs.latency_ms) as avg_latency')
            )
            ->groupBy(
                'ai_providers.id',
                'ai_providers.name',
                'ai_providers.provider',
                'ai_providers.model',
                'ai_providers.is_active',
                'ai_providers.is_default'
            )
            ->orderByDesc('cost_usd')
            ->orderBy('ai_providers.priority')
            ->get();

        // Per-task breakdown (last 30 days)
        $perTask = AiUsageLog::query()
            ->where('created_at', '>=', $since30d)
            ->select(
                'task_type',
                DB::raw('COUNT(*) as call_count'),
                DB::raw('SUM(input_tokens) as input_tokens'),
                DB::raw('SUM(output_tokens) as output_tokens'),
                DB::raw('SUM(cost_usd) as cost_usd'),
                DB::raw('SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as success_count'),
                DB::raw('AVG(latency_ms) as avg_latency')
            )
            ->groupBy('task_type')
            ->orderByDesc('cost_usd')
            ->get();

        // Recent calls (last 50)
        $recent = AiUsageLog::with('provider:id,name,provider,model')
            ->latest()
            ->limit(50)
            ->get();

        // Daily spend sparkline (last 14 days) — used for a simple bar chart
        $dailyRaw = AiUsageLog::query()
            ->where('created_at', '>=', $now->copy()->subDays(13)->startOfDay())
            ->select(
                DB::raw('DATE(created_at) as day'),
                DB::raw('SUM(cost_usd) as cost_usd'),
                DB::raw('SUM(input_tokens + output_tokens) as tokens')
            )
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $daily = [];
        for ($i = 13; $i >= 0; $i--) {
            $d = $now->copy()->subDays($i)->toDateString();
            $row = $dailyRaw->get($d);
            $daily[] = [
                'day'    => $d,
                'label'  => Carbon::parse($d)->format('d M'),
                'cost'   => $row ? round((float) $row->cost_usd, 4) : 0.0,
                'tokens' => $row ? (int) $row->tokens : 0,
            ];
        }

        return view('admin.ai-usage.index', compact(
            'stats', 'perProvider', 'perTask', 'recent', 'daily'
        ));
    }
}
