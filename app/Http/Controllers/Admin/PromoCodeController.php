<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PromoCode;
use App\Models\SubscriptionPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin CRUD for PromoCode + bulk generation.
 *
 * All actions are double-gated: this controller is only reachable from
 * within the `auth + can:admin` route group, and per-route can: guards
 * layer the `promo.manage` permission on top. Bulk-generate is its own
 * top-level POST so it can be linked from the index page without
 * polluting the resource's verbs.
 */
class PromoCodeController extends Controller
{
    private const PER_PAGE = 25;
    private const BULK_MAX = 500;

    public function index(Request $request): View
    {
        $query = PromoCode::query()->withCount('redemptions')->latest();

        if ($search = trim((string) $request->input('q', ''))) {
            $upper = strtoupper($search);
            $query->where(function ($q) use ($search, $upper) {
                $q->where('code', 'like', "%{$upper}%")
                  ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            } elseif ($status === 'expired') {
                $query->whereNotNull('expires_at')->where('expires_at', '<=', now());
            }
        }

        $codes = $query->paginate(self::PER_PAGE)->withQueryString();
        $plans = SubscriptionPlan::orderBy('sort_order')->get(['id', 'name', 'slug']);

        return view('admin.promo-codes.index', [
            'title' => 'Promo Codes',
            'codes' => $codes,
            'plans' => $plans,
        ]);
    }

    public function create(): View
    {
        return view('admin.promo-codes.create', [
            'title' => 'Create Promo Code',
            'plans' => SubscriptionPlan::orderBy('sort_order')->get(['id', 'name', 'slug']),
            'types' => PromoCode::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatePayload($request);

        $data['code'] = strtoupper(trim($data['code']));
        $data['created_by_user_id'] = $request->user()?->id;

        PromoCode::create($data);

        return redirect()->route('admin.promo-codes.index')
            ->with('success', "Promo code {$data['code']} berhasil dibuat.");
    }

    public function edit(PromoCode $promoCode): View
    {
        return view('admin.promo-codes.edit', [
            'title'     => 'Edit Promo Code',
            'promoCode' => $promoCode,
            'plans'     => SubscriptionPlan::orderBy('sort_order')->get(['id', 'name', 'slug']),
            'types'     => PromoCode::TYPES,
        ]);
    }

    public function update(Request $request, PromoCode $promoCode): RedirectResponse
    {
        $data = $this->validatePayload($request, $promoCode->id);

        $data['code'] = strtoupper(trim($data['code']));

        $promoCode->update($data);

        return redirect()->route('admin.promo-codes.index')
            ->with('success', "Promo code {$promoCode->code} berhasil diperbarui.");
    }

    public function destroy(PromoCode $promoCode): RedirectResponse
    {
        $code = $promoCode->code;
        $promoCode->delete();

        return redirect()->route('admin.promo-codes.index')
            ->with('success', "Promo code {$code} dihapus.");
    }

    /**
     * Generate N random codes that share a single template (same
     * discount_type/value/window/plans). Useful for influencer
     * campaigns where every code must be unique but the underlying
     * offer is the same.
     */
    public function bulkGenerate(Request $request): RedirectResponse
    {
        $request->validate([
            'prefix'                  => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9]*$/'],
            'count'                   => ['required', 'integer', 'min:1', 'max:' . self::BULK_MAX],
            'name'                    => ['required', 'string', 'max:120'],
            'discount_type'           => ['required', Rule::in(PromoCode::TYPES)],
            'discount_value'          => ['required', 'numeric', 'min:0'],
            'max_uses_per_user'       => ['nullable', 'integer', 'min:0'],
            'min_subscription_months' => ['nullable', 'integer', 'min:1'],
            'starts_at'               => ['nullable', 'date'],
            'expires_at'              => ['nullable', 'date', 'after:starts_at'],
            'applies_to_plans'        => ['nullable', 'array'],
            'applies_to_plans.*'      => ['integer', 'exists:subscription_plans,id'],
            'is_first_time_only'      => ['nullable', 'boolean'],
        ]);

        $prefix = strtoupper((string) $request->input('prefix', ''));
        $count  = (int) $request->input('count');

        $created = 0;
        $attempts = 0;
        $maxAttempts = $count * 4; // safety: avoid infinite loops on near-collision

        while ($created < $count && $attempts < $maxAttempts) {
            $attempts++;

            $random = Str::upper(Str::random(8));
            $code = $prefix !== '' ? $prefix . '-' . $random : $random;

            if (PromoCode::query()->where('code', $code)->exists()) {
                continue; // try again with a different suffix
            }

            PromoCode::create([
                'code'                    => $code,
                'name'                    => $request->input('name'),
                'description'             => $request->input('description'),
                'discount_type'           => $request->input('discount_type'),
                'discount_value'          => (float) $request->input('discount_value'),
                'applies_to_plans'        => $request->input('applies_to_plans') ?: null,
                'max_uses'                => 1, // bulk = unique-per-redeemer
                'max_uses_per_user'       => (int) $request->input('max_uses_per_user', 1),
                'min_subscription_months' => (int) $request->input('min_subscription_months', 1),
                'starts_at'               => $request->input('starts_at'),
                'expires_at'              => $request->input('expires_at'),
                'is_active'               => true,
                'is_first_time_only'      => (bool) $request->input('is_first_time_only', false),
                'created_by_user_id'      => $request->user()?->id,
            ]);

            $created++;
        }

        return redirect()->route('admin.promo-codes.index')
            ->with('success', "Berhasil generate {$created} promo code.");
    }

    /**
     * Shared validator for store + update. On update we relax the
     * `code` unique rule to ignore the current row.
     *
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        $codeRule = ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_\-]+$/'];
        $codeRule[] = Rule::unique('promo_codes', 'code')->ignore($ignoreId);

        return $request->validate([
            'code'                    => $codeRule,
            'name'                    => ['required', 'string', 'max:120'],
            'description'             => ['nullable', 'string'],
            'discount_type'           => ['required', Rule::in(PromoCode::TYPES)],
            'discount_value'          => ['required', 'numeric', 'min:0'],
            'applies_to_plans'        => ['nullable', 'array'],
            'applies_to_plans.*'      => ['integer', 'exists:subscription_plans,id'],
            'max_uses'                => ['nullable', 'integer', 'min:1'],
            'max_uses_per_user'       => ['nullable', 'integer', 'min:0'],
            'min_subscription_months' => ['nullable', 'integer', 'min:1'],
            'starts_at'               => ['nullable', 'date'],
            'expires_at'              => ['nullable', 'date', 'after:starts_at'],
            'is_active'               => ['nullable', 'boolean'],
            'is_first_time_only'      => ['nullable', 'boolean'],
        ]);
    }
}
