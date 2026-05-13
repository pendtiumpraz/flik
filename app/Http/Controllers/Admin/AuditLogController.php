<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    private const PER_PAGE = 50;
    private const EXPORT_LIMIT = 10000;

    /**
     * List audit logs with filters and optional CSV export.
     */
    public function index(Request $request): \Illuminate\View\View|StreamedResponse
    {
        $filters = $this->filters($request);
        $query = $this->buildQuery($filters);

        if ($request->boolean('export')) {
            return $this->streamCsv($query->limit(self::EXPORT_LIMIT));
        }

        /** @var LengthAwarePaginator<AuditLog> $logs */
        $logs = $query
            ->with('user:id,name,email')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Sidebar/select dropdowns
        $users = User::orderBy('name')->get(['id', 'name', 'email']);
        $subjectTypes = AuditLog::query()
            ->whereNotNull('subject_type')
            ->distinct()
            ->orderBy('subject_type')
            ->pluck('subject_type');
        $actionPrefixes = AuditLog::query()
            ->selectRaw("DISTINCT SUBSTRING_INDEX(action, '.', 1) AS prefix")
            ->orderBy('prefix')
            ->pluck('prefix');

        return view('admin.audit-logs.index', [
            'logs' => $logs,
            'users' => $users,
            'subjectTypes' => $subjectTypes,
            'actionPrefixes' => $actionPrefixes,
            'filters' => $filters,
        ]);
    }

    /**
     * @return array{user_id:?int,action:?string,subject_type:?string,date_from:?string,date_to:?string,security_only:bool}
     */
    private function filters(Request $request): array
    {
        return [
            'user_id' => $request->filled('user_id') ? (int) $request->input('user_id') : null,
            'action' => $request->filled('action') ? trim((string) $request->input('action')) : null,
            'subject_type' => $request->filled('subject_type') ? (string) $request->input('subject_type') : null,
            'date_from' => $request->filled('date_from') ? (string) $request->input('date_from') : null,
            'date_to' => $request->filled('date_to') ? (string) $request->input('date_to') : null,
            'security_only' => $request->boolean('security_only'),
        ];
    }

    /**
     * @param  array{user_id:?int,action:?string,subject_type:?string,date_from:?string,date_to:?string,security_only:bool} $filters
     */
    private function buildQuery(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $query = AuditLog::query()->latest('created_at')->latest('id');

        if ($filters['user_id']) {
            $query->where('user_id', $filters['user_id']);
        }
        if ($filters['action']) {
            // Treat as prefix (e.g. "movie" matches "movie.uploaded", "movie.deleted").
            // Exact match still works because "movie.uploaded" starts with "movie.uploaded".
            $query->where('action', 'like', $filters['action'].'%');
        }
        if ($filters['subject_type']) {
            $query->where('subject_type', $filters['subject_type']);
        }
        if ($filters['date_from']) {
            $query->where('created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }
        if ($filters['date_to']) {
            $query->where('created_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }
        if ($filters['security_only']) {
            // Delegates to the model scope, which transparently falls back
            // to action-prefix matching when the column hasn't been
            // migrated yet. See AuditLog::scopeSecurityOnly().
            $query->securityOnly();
        }

        return $query;
    }

    /**
     * Stream filtered logs as a CSV download.
     */
    private function streamCsv(\Illuminate\Database\Eloquent\Builder $query): StreamedResponse
    {
        $filename = 'audit-logs-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            // Excel-friendly UTF-8 BOM
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'id', 'created_at', 'user_id', 'user_name', 'user_email',
                'action', 'subject_type', 'subject_id', 'client_ip', 'user_agent', 'meta',
            ]);

            $query->with('user:id,name,email')->chunkById(500, function ($rows) use ($out) {
                foreach ($rows as $row) {
                    /** @var AuditLog $row */
                    fputcsv($out, [
                        $row->id,
                        optional($row->created_at)->toIso8601String(),
                        $row->user_id,
                        $row->user?->name,
                        $row->user?->email,
                        $row->action,
                        $row->subject_type,
                        $row->subject_id,
                        $row->client_ip,
                        $row->user_agent,
                        $row->meta !== null ? json_encode($row->meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}
