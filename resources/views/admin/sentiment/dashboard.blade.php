@php
    // Colours match the admin gold-on-charcoal theme. Pulled into PHP so we
    // can re-use them across multiple inline-style blocks without sprinkling
    // hex codes everywhere.
    $col = [
        'positive' => '#22c55e',
        'negative' => '#ef4444',
        'neutral'  => '#94a3b8',
        'mixed'    => '#eab308',
        'gold'     => '#C5A55A',
    ];

    /**
     * Render a horizontal proportion bar for [pos, neg, neu, mixed].
     */
    $proportionBar = function(int $pos, int $neg, int $neu, int $mix) use ($col): string {
        $total = max(1, $pos + $neg + $neu + $mix);
        $segments = [
            ['w' => $pos / $total * 100, 'c' => $col['positive']],
            ['w' => $neg / $total * 100, 'c' => $col['negative']],
            ['w' => $neu / $total * 100, 'c' => $col['neutral']],
            ['w' => $mix / $total * 100, 'c' => $col['mixed']],
        ];
        $out = '<div style="display:flex;height:8px;border-radius:999px;overflow:hidden;background:#0f0f0f">';
        foreach ($segments as $s) {
            if ($s['w'] <= 0) continue;
            $out .= '<div style="width:' . number_format($s['w'], 2) . '%;background:' . $s['c'] . '"></div>';
        }
        $out .= '</div>';
        return $out;
    };

    $title = $scopedMovie
        ? 'Sentiment — ' . $scopedMovie->title
        : 'Sentiment Dashboard';

    // Pick which stats block drives the headline cards.
    $headline = $scopedStats ?? [
        'total'    => $overall['analysed'],
        'positive' => $overall['positive'],
        'negative' => $overall['negative'],
        'neutral'  => $overall['neutral'],
        'mixed'    => $overall['mixed'],
        'avg_score'=> $overall['avg_score'],
    ];

    // Trend strip — compute max for height scaling.
    $maxTrend = max(1, $trend->max(fn($r) => $r->total));
@endphp

<x-admin.layout :title="$title">

    {{-- Header --}}
    <div style="display:flex;justify-content:space-between;align-items:start;gap:16px;flex-wrap:wrap;margin-bottom:24px">
        <div>
            @if($scopedMovie)
                <a href="{{ url('/admin/sentiment') }}" style="font-size:12px;color:#777;text-decoration:none;display:inline-flex;align-items:center;gap:4px">
                    <x-icon name="chevron-left" size="14" /> Back to global view
                </a>
            @endif
            <h2 style="font-size:22px;font-weight:600;margin-top:4px;display:flex;align-items:center;gap:8px">
                <x-icon name="sparkles" size="22" style="color:{{ $col['gold'] }}" />
                {{ $scopedMovie ? $scopedMovie->title : 'Comment Sentiment' }}
            </h2>
            <p style="color:#777;font-size:13px;margin-top:4px">
                AI-classified user comments — positive ↔ negative axis. Updated by the <code style="background:#0f0f0f;padding:1px 6px;border-radius:3px;color:{{ $col['gold'] }}">ai-batch</code> queue.
            </p>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <div style="background:#1a1a1a;border:1px solid #2a2a2a;padding:8px 14px;border-radius:8px;font-size:12px;color:#aaa">
                Coverage: <strong style="color:#fff">{{ $overall['coverage'] }}%</strong>
                <span style="color:#555">({{ number_format($overall['analysed']) }} / {{ number_format($overall['total']) }})</span>
            </div>
            @if($overall['pending'] > 0)
                <div style="background:rgba(234,179,8,0.10);border:1px solid rgba(234,179,8,0.35);padding:8px 14px;border-radius:8px;font-size:12px;color:{{ $col['mixed'] }}">
                    <x-icon name="clock" size="13" /> {{ number_format($overall['pending']) }} pending
                </div>
            @endif
        </div>
    </div>

    {{-- Stat cards --}}
    <div class="grid-stats" style="margin-bottom:24px">
        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Analysed</div>
                    <div class="value">{{ number_format($headline['total']) }}</div>
                </div>
                <div class="icon" style="background:rgba(197,165,90,0.15);color:{{ $col['gold'] }}">
                    <x-icon name="sparkles" size="20" />
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Positive</div>
                    <div class="value" style="color:{{ $col['positive'] }}">{{ number_format($headline['positive']) }}</div>
                </div>
                <div class="icon" style="background:rgba(34,197,94,0.15);color:{{ $col['positive'] }}">
                    <x-icon name="heart" size="20" />
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Negative</div>
                    <div class="value" style="color:{{ $col['negative'] }}">{{ number_format($headline['negative']) }}</div>
                </div>
                <div class="icon" style="background:rgba(239,68,68,0.15);color:{{ $col['negative'] }}">
                    <x-icon name="x" size="20" />
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Neutral / Mixed</div>
                    <div class="value">
                        <span style="color:{{ $col['neutral'] }}">{{ number_format($headline['neutral']) }}</span>
                        <span style="font-size:18px;color:#444"> / </span>
                        <span style="color:{{ $col['mixed'] }};font-size:24px">{{ number_format($headline['mixed']) }}</span>
                    </div>
                </div>
                <div class="icon" style="background:rgba(148,163,184,0.15);color:{{ $col['neutral'] }}">
                    <x-icon name="info" size="20" />
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Avg Score</div>
                    <div class="value" style="color:{{
                        $headline['avg_score'] === null ? '#666'
                        : ($headline['avg_score'] > 0.15 ? $col['positive']
                        : ($headline['avg_score'] < -0.15 ? $col['negative'] : $col['neutral']))
                    }}">
                        {{ $headline['avg_score'] === null ? '—' : ($headline['avg_score'] > 0 ? '+' : '') . number_format($headline['avg_score'], 2) }}
                    </div>
                    <div style="font-size:11px;color:#555;margin-top:2px">range −1.0 to +1.0</div>
                </div>
                <div class="icon" style="background:rgba(197,165,90,0.15);color:{{ $col['gold'] }}">
                    <x-icon name="star" size="20" />
                </div>
            </div>
        </div>
    </div>

    {{-- Proportion bar --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:18px 22px;margin-bottom:24px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
            <h3 style="font-size:14px;font-weight:600">Sentiment Mix {{ $scopedMovie ? 'for ' . $scopedMovie->title : '(All Movies)' }}</h3>
            <div style="font-size:11px;color:#666">Hover segments below</div>
        </div>
        {!! $proportionBar((int) $headline['positive'], (int) $headline['negative'], (int) $headline['neutral'], (int) $headline['mixed']) !!}
        <div style="display:flex;gap:18px;margin-top:12px;flex-wrap:wrap;font-size:12px;color:#aaa">
            <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:{{ $col['positive'] }};margin-right:6px;vertical-align:middle"></span>Positive</span>
            <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:{{ $col['negative'] }};margin-right:6px;vertical-align:middle"></span>Negative</span>
            <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:{{ $col['neutral'] }};margin-right:6px;vertical-align:middle"></span>Neutral</span>
            <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:{{ $col['mixed'] }};margin-right:6px;vertical-align:middle"></span>Mixed</span>
        </div>
    </div>

    {{-- Trend strip (last 30 days) --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:18px 22px;margin-bottom:24px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
            <h3 style="font-size:14px;font-weight:600">Trend — last {{ $trendDays }} days</h3>
            <div style="font-size:11px;color:#666">Stacked bars · height = total comments analysed that day</div>
        </div>

        <div style="display:flex;align-items:flex-end;gap:3px;height:140px;overflow-x:auto;padding-bottom:4px">
            @foreach($trend as $day)
                @php
                    $total = (int) $day->total;
                    $heightPct = $total > 0 ? max(4, ($total / $maxTrend) * 100) : 2;
                    $posPct = $total > 0 ? ($day->positive / $total) * 100 : 0;
                    $negPct = $total > 0 ? ($day->negative / $total) * 100 : 0;
                    $neuPct = $total > 0 ? ($day->neutral  / $total) * 100 : 0;
                    $mixPct = $total > 0 ? ($day->mixed    / $total) * 100 : 0;
                    $tooltip = $day->date . ' · ' . $total . ' analysed'
                        . ($day->avg_score !== null ? ' · avg ' . ($day->avg_score > 0 ? '+' : '') . number_format($day->avg_score, 2) : '');
                @endphp
                <div style="flex:1 1 18px;min-width:14px;display:flex;flex-direction:column;justify-content:flex-end;align-items:stretch" title="{{ $tooltip }}">
                    <div style="height:{{ $heightPct }}%;display:flex;flex-direction:column;border-radius:3px;overflow:hidden;background:#0f0f0f">
                        @if($posPct > 0)<div style="height:{{ $posPct }}%;background:{{ $col['positive'] }}"></div>@endif
                        @if($mixPct > 0)<div style="height:{{ $mixPct }}%;background:{{ $col['mixed'] }}"></div>@endif
                        @if($neuPct > 0)<div style="height:{{ $neuPct }}%;background:{{ $col['neutral'] }}"></div>@endif
                        @if($negPct > 0)<div style="height:{{ $negPct }}%;background:{{ $col['negative'] }}"></div>@endif
                    </div>
                </div>
            @endforeach
        </div>

        <div style="display:flex;justify-content:space-between;font-size:10px;color:#555;margin-top:8px">
            <span>{{ $trend->first()?->date }}</span>
            <span>{{ $trend->last()?->date }}</span>
        </div>
    </div>

    {{-- Per-movie breakdown (only on global view) --}}
    @if($perMovie && $perMovie->isNotEmpty())
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden;margin-bottom:24px">
            <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a;display:flex;justify-content:space-between;align-items:center">
                <h3 style="font-size:15px;font-weight:600">Per Movie · Top {{ $perMovie->count() }}</h3>
                <div style="font-size:11px;color:#666">Sorted by analysed-comment volume</div>
            </div>

            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Movie</th>
                        <th style="width:140px">Avg Score</th>
                        <th>Mix</th>
                        <th style="text-align:right;width:80px">Total</th>
                        <th style="text-align:right;width:80px"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($perMovie as $row)
                        <tr>
                            <td style="color:#fff;font-weight:500">{{ $row->title }}</td>
                            <td>
                                @if($row->avg_score === null)
                                    <span style="color:#555">—</span>
                                @else
                                    @php
                                        $c = $row->avg_score > 0.15 ? $col['positive']
                                           : ($row->avg_score < -0.15 ? $col['negative'] : $col['neutral']);
                                    @endphp
                                    <span style="color:{{ $c }};font-weight:600">
                                        {{ $row->avg_score > 0 ? '+' : '' }}{{ number_format($row->avg_score, 2) }}
                                    </span>
                                @endif
                            </td>
                            <td style="min-width:200px">
                                {!! $proportionBar($row->positive, $row->negative, $row->neutral, $row->mixed) !!}
                                <div style="font-size:10px;color:#666;margin-top:4px">
                                    <span style="color:{{ $col['positive'] }}">{{ $row->positive }}+</span>
                                    · <span style="color:{{ $col['negative'] }}">{{ $row->negative }}−</span>
                                    · <span style="color:{{ $col['neutral'] }}">{{ $row->neutral }} neu</span>
                                    · <span style="color:{{ $col['mixed'] }}">{{ $row->mixed }} mix</span>
                                </div>
                            </td>
                            <td style="text-align:right;color:#aaa">{{ number_format($row->total) }}</td>
                            <td style="text-align:right">
                                <a href="{{ url('/admin/sentiment/' . $row->movie_id) }}" class="btn btn-ghost btn-sm">
                                    Drill in <x-icon name="chevron-right" size="12" />
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Recent positive / negative columns --}}
    <div class="grid-stats" style="grid-template-columns:1fr 1fr;gap:24px">
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
            <div style="padding:14px 18px;border-bottom:1px solid #2a2a2a;display:flex;align-items:center;gap:8px">
                <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:{{ $col['positive'] }}"></span>
                <h3 style="font-size:14px;font-weight:600;color:{{ $col['positive'] }}">Recent Positive</h3>
            </div>
            <div>
                @forelse($recentPositive as $c)
                    <div style="padding:14px 18px;border-bottom:1px solid #1f1f1f">
                        <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:6px">
                            <div style="font-size:12px;color:#aaa">
                                <strong style="color:#fff">{{ $c->user?->name ?? 'Anon' }}</strong>
                                <span style="color:#555">·</span>
                                <span>{{ $c->movie?->title ?? '—' }}</span>
                            </div>
                            <span style="font-size:11px;color:{{ $col['positive'] }};font-weight:600">
                                +{{ number_format((float) $c->sentiment_score, 2) }}
                            </span>
                        </div>
                        <div style="font-size:13px;color:#ddd;line-height:1.5">{{ \Illuminate\Support\Str::limit($c->body, 220) }}</div>
                        <div style="font-size:10px;color:#555;margin-top:6px">{{ optional($c->sentiment_analyzed_at)->diffForHumans() }}</div>
                    </div>
                @empty
                    <div style="padding:32px 18px;text-align:center;color:#555;font-size:13px">No positive comments analysed yet.</div>
                @endforelse
            </div>
        </div>

        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
            <div style="padding:14px 18px;border-bottom:1px solid #2a2a2a;display:flex;align-items:center;gap:8px">
                <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:{{ $col['negative'] }}"></span>
                <h3 style="font-size:14px;font-weight:600;color:{{ $col['negative'] }}">Recent Negative</h3>
            </div>
            <div>
                @forelse($recentNegative as $c)
                    <div style="padding:14px 18px;border-bottom:1px solid #1f1f1f">
                        <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:6px">
                            <div style="font-size:12px;color:#aaa">
                                <strong style="color:#fff">{{ $c->user?->name ?? 'Anon' }}</strong>
                                <span style="color:#555">·</span>
                                <span>{{ $c->movie?->title ?? '—' }}</span>
                            </div>
                            <span style="font-size:11px;color:{{ $col['negative'] }};font-weight:600">
                                {{ number_format((float) $c->sentiment_score, 2) }}
                            </span>
                        </div>
                        <div style="font-size:13px;color:#ddd;line-height:1.5">{{ \Illuminate\Support\Str::limit($c->body, 220) }}</div>
                        <div style="font-size:10px;color:#555;margin-top:6px">{{ optional($c->sentiment_analyzed_at)->diffForHumans() }}</div>
                    </div>
                @empty
                    <div style="padding:32px 18px;text-align:center;color:#555;font-size:13px">No negative comments analysed yet.</div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Footnote --}}
    <div style="margin-top:24px;background:rgba(197,165,90,0.06);border:1px solid rgba(197,165,90,0.25);border-radius:10px;padding:14px 18px">
        <div style="color:{{ $col['gold'] }};font-weight:600;font-size:12px;margin-bottom:4px">
            <x-icon name="info" size="13" /> About sentiment analysis
        </div>
        <div style="color:#aaa;font-size:12px;line-height:1.6">
            Sentiment is independent from moderation. A comment can be <em>safe</em> but <em>negative</em> ("this movie was boring"),
            or <em>flagged</em> but neutral. Re-run analysis by dispatching <code style="background:#0f0f0f;padding:1px 6px;border-radius:3px;color:{{ $col['gold'] }}">AnalyzeCommentSentiment</code>
            jobs onto the <code style="background:#0f0f0f;padding:1px 6px;border-radius:3px;color:{{ $col['gold'] }}">ai-batch</code> queue — bulk mode batches up to {{ \App\Services\Ai\Tasks\CommentSentimentAnalyzer::BULK_CHUNK_SIZE }} comments per AI call.
        </div>
    </div>

</x-admin.layout>
