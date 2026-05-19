<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailCampaign;
use App\Models\EmailLinkClick;
use App\Models\EmailRecipient;
use App\Models\SubscriptionPlan;
use App\Services\Ai\Tasks\CampaignCopyGenerator;
use App\Services\Email\CampaignDispatcher;
use App\Services\Email\SegmentBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * EmailCampaignController — admin CRUD + lifecycle ops for campaigns.
 *
 * Permission model (`marketing.email_ab` is the seeded slug for this
 * surface — `marketing.email` is the sidebar label, both gate the same
 * actions):
 *   - index / show / report ← read access
 *   - create / store / edit / update ← compose
 *   - aiDraft / previewAudience       ← compose helpers
 *   - send                            ← terminal: flips draft → sending
 *   - cancel                          ← terminal: flips sending/queued → cancelled
 *
 * The route wiring lives in routes/web.php under the admin group with
 * `can:marketing.email_ab` on every action; per-method permission docs
 * here are advisory only.
 */
class EmailCampaignController extends Controller
{
    public function __construct(
        protected SegmentBuilder $segments,
        protected CampaignDispatcher $dispatcher,
        protected CampaignCopyGenerator $copyGenerator,
    ) {}

    // ── List + report ─────────────────────────────────────────

    public function index(): View
    {
        $campaigns = EmailCampaign::query()
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('admin.email-campaigns.index', [
            'campaigns' => $campaigns,
        ]);
    }

    public function show(EmailCampaign $emailCampaign): RedirectResponse
    {
        // No dedicated show view yet — drafts edit, finished go to report.
        return $emailCampaign->isEditable()
            ? redirect()->route('admin.email-campaigns.edit', $emailCampaign)
            : redirect()->route('admin.email-campaigns.report', $emailCampaign);
    }

    public function report(EmailCampaign $emailCampaign): View
    {
        $emailCampaign->loadCount('recipients');

        $bouncedCount = EmailRecipient::query()
            ->where('email_campaign_id', $emailCampaign->id)
            ->whereNotNull('failed_at')
            ->count();

        // Per-link click counts (top-10 destinations).
        $linkBreakdown = EmailLinkClick::query()
            ->select('original_url', DB::raw('COUNT(*) as click_count'))
            ->whereIn(
                'email_recipient_id',
                EmailRecipient::query()
                    ->where('email_campaign_id', $emailCampaign->id)
                    ->select('id'),
            )
            ->groupBy('original_url')
            ->orderByDesc('click_count')
            ->limit(10)
            ->get();

        $recentFailures = EmailRecipient::query()
            ->where('email_campaign_id', $emailCampaign->id)
            ->whereNotNull('failed_at')
            ->orderByDesc('failed_at')
            ->limit(20)
            ->get(['email', 'error_reason', 'failed_at']);

        return view('admin.email-campaigns.report', [
            'campaign'       => $emailCampaign,
            'bouncedCount'   => $bouncedCount,
            'linkBreakdown'  => $linkBreakdown,
            'recentFailures' => $recentFailures,
        ]);
    }

    // ── Create / store ────────────────────────────────────────

    public function create(): View
    {
        return view('admin.email-campaigns.create', [
            'campaign'   => null,
            'plans'      => SubscriptionPlan::query()->orderBy('id')->get(['id', 'name']),
            'segmentJson'=> json_encode(['type' => 'all'], JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateCampaignPayload($request);

        $campaign = EmailCampaign::create([
            'name'                => $data['name'],
            'subject'             => $data['subject'],
            'preheader'           => $data['preheader'],
            'html_body'           => $data['html_body'],
            'plain_body'          => $data['plain_body'],
            'segment_definition'  => $data['segment_definition'],
            'audience_estimated'  => $this->safeEstimate($data['segment_definition']),
            'scheduled_at'        => $data['scheduled_at'],
            'created_by_user_id'  => $request->user()?->id,
        ]);

        return redirect()
            ->route('admin.email-campaigns.edit', $campaign)
            ->with('success', "Campaign '{$campaign->name}' created sebagai draft.");
    }

    // ── Edit / update ─────────────────────────────────────────

    public function edit(EmailCampaign $emailCampaign): View|RedirectResponse
    {
        if (!$emailCampaign->isEditable()) {
            return redirect()
                ->route('admin.email-campaigns.report', $emailCampaign)
                ->with('error', 'Campaign sudah dikirim — tidak bisa diedit lagi.');
        }

        return view('admin.email-campaigns.edit', [
            'campaign'    => $emailCampaign,
            'plans'       => SubscriptionPlan::query()->orderBy('id')->get(['id', 'name']),
            'segmentJson' => json_encode(
                $emailCampaign->segment_definition,
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
            ),
        ]);
    }

    public function update(Request $request, EmailCampaign $emailCampaign): RedirectResponse
    {
        if (!$emailCampaign->isEditable()) {
            return redirect()
                ->route('admin.email-campaigns.report', $emailCampaign)
                ->with('error', 'Campaign sudah dikirim — tidak bisa diedit lagi.');
        }

        $data = $this->validateCampaignPayload($request);

        $emailCampaign->fill([
            'name'                => $data['name'],
            'subject'             => $data['subject'],
            'preheader'           => $data['preheader'],
            'html_body'           => $data['html_body'],
            'plain_body'          => $data['plain_body'],
            'segment_definition'  => $data['segment_definition'],
            'audience_estimated'  => $this->safeEstimate($data['segment_definition']),
            'scheduled_at'        => $data['scheduled_at'],
        ])->save();

        return redirect()
            ->route('admin.email-campaigns.edit', $emailCampaign)
            ->with('success', 'Campaign tersimpan.');
    }

    public function destroy(EmailCampaign $emailCampaign): RedirectResponse
    {
        if (!$emailCampaign->isEditable()) {
            return redirect()
                ->route('admin.email-campaigns.index')
                ->with('error', 'Hanya draft yang bisa dihapus.');
        }

        $emailCampaign->delete();

        return redirect()
            ->route('admin.email-campaigns.index')
            ->with('success', 'Draft campaign dihapus.');
    }

    // ── Compose helpers ───────────────────────────────────────

    public function aiDraft(Request $request): JsonResponse
    {
        $data = $request->validate([
            'goal'     => 'required|string|max:500',
            'tone'     => 'nullable|string|in:' . implode(',', CampaignCopyGenerator::TONES),
            'audience' => 'nullable|string|max:300',
        ]);

        try {
            $draft = $this->copyGenerator->generate(
                goal: $data['goal'],
                tone: $data['tone'] ?? 'warm',
                audience: $data['audience'] ?? '',
            );

            return response()->json([
                'ok'    => true,
                'draft' => $draft,
            ]);
        } catch (\Throwable $e) {
            Log::error('EmailCampaignController aiDraft failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok'    => false,
                'error' => 'Gagal generate draft AI: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function previewAudience(Request $request): JsonResponse
    {
        $data = $request->validate([
            'segment' => 'required|array',
        ]);

        $segment = $data['segment'];

        try {
            $count = $this->segments->estimate($segment);
            $sample = $this->segments->sampleEmails($segment, limit: 10);

            return response()->json([
                'ok'     => true,
                'count'  => $count,
                'sample' => $sample,
            ]);
        } catch (\Throwable $e) {
            Log::warning('EmailCampaignController previewAudience failed', [
                'segment' => $segment,
                'error'   => $e->getMessage(),
            ]);

            return response()->json([
                'ok'    => false,
                'error' => 'Segment tidak valid: ' . $e->getMessage(),
            ], 422);
        }
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function send(EmailCampaign $emailCampaign): RedirectResponse
    {
        if ($emailCampaign->status !== EmailCampaign::STATUS_DRAFT) {
            return back()->with('error', "Tidak bisa mengirim — status sekarang: {$emailCampaign->status}.");
        }

        try {
            $queued = $this->dispatcher->enqueue($emailCampaign);
        } catch (\Throwable $e) {
            Log::error('EmailCampaignController send failed', [
                'campaign_id' => $emailCampaign->id,
                'error'       => $e->getMessage(),
            ]);
            return back()->with('error', 'Gagal enqueue campaign: ' . $e->getMessage());
        }

        if ($queued === 0) {
            return back()->with('error', 'Segment tidak menghasilkan recipient — periksa konfigurasi segment.');
        }

        return redirect()
            ->route('admin.email-campaigns.report', $emailCampaign)
            ->with('success', "Campaign queued ke {$queued} penerima.");
    }

    public function cancel(EmailCampaign $emailCampaign): RedirectResponse
    {
        if (!$emailCampaign->isCancellable()) {
            return back()->with('error', "Status saat ini ({$emailCampaign->status}) tidak bisa dibatalkan.");
        }

        $emailCampaign->forceFill(['status' => EmailCampaign::STATUS_CANCELLED])->save();

        // Pending jobs may still be in the queue — SendCampaignEmail checks
        // the campaign status before sending so already-dispatched jobs will
        // gracefully no-op when they pick this up.
        return redirect()
            ->route('admin.email-campaigns.report', $emailCampaign)
            ->with('success', 'Campaign dibatalkan. Pending jobs akan di-skip oleh worker.');
    }

    // ── Helpers ───────────────────────────────────────────────

    /**
     * Validate the create/update form payload and normalise the segment JSON.
     *
     * @return array{
     *     name:string, subject:string, preheader:?string,
     *     html_body:string, plain_body:?string,
     *     segment_definition: array<string, mixed>,
     *     scheduled_at: ?string,
     * }
     */
    private function validateCampaignPayload(Request $request): array
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:160',
            'subject'      => 'required|string|max:200',
            'preheader'    => 'nullable|string|max:160',
            'html_body'    => 'required|string|max:200000',
            'plain_body'   => 'nullable|string|max:200000',
            'segment'      => 'required',
            'scheduled_at' => 'nullable|date|after_or_equal:now',
        ]);

        $rawSegment = $validated['segment'];

        // The composer UI submits the segment as a JSON-encoded string for
        // safer form-encoding, but also accepts an array (for API callers).
        if (is_string($rawSegment)) {
            $decoded = json_decode($rawSegment, true);
            if (!is_array($decoded)) {
                abort(422, 'Segment harus berupa JSON object yang valid.');
            }
            $segment = $decoded;
        } elseif (is_array($rawSegment)) {
            $segment = $rawSegment;
        } else {
            abort(422, 'Segment harus berupa array atau JSON object.');
        }

        if (!isset($segment['type']) || !is_string($segment['type'])) {
            abort(422, 'Segment harus punya field "type".');
        }

        return [
            'name'                => $validated['name'],
            'subject'             => $validated['subject'],
            'preheader'           => $validated['preheader'] ?? null,
            'html_body'           => $validated['html_body'],
            'plain_body'          => $validated['plain_body'] ?? null,
            'segment_definition'  => $segment,
            'scheduled_at'        => $validated['scheduled_at'] ?? null,
        ];
    }

    /**
     * Estimate the audience size, swallowing any error so a malformed
     * segment doesn't block the save (saves as 0).
     *
     * @param  array<string, mixed>  $segment
     */
    private function safeEstimate(array $segment): int
    {
        try {
            return $this->segments->estimate($segment);
        } catch (\Throwable $e) {
            Log::warning('safeEstimate fallback to 0', [
                'segment' => $segment,
                'error'   => $e->getMessage(),
            ]);
            return 0;
        }
    }
}
