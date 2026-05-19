{{--
  Shared form for Email Campaign create/edit.

  Inputs in scope:
    $campaign    EmailCampaign|null
    $plans       Collection of SubscriptionPlan {id,name}
    $segmentJson string — pretty-printed segment JSON
    $action      string — submit URL
    $method      'POST' | 'PUT'
--}}
@php
    $isEdit = isset($campaign) && $campaign !== null;
@endphp

<form method="POST" action="{{ $action }}" x-data="emailCampaignForm()" x-init="init()" id="campaign-form">
    @csrf
    @if($method === 'PUT')
        @method('PUT')
    @endif

    {{-- ━━━ Basics ━━━ --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:24px;margin-bottom:20px">
        <h3 style="font-size:15px;font-weight:600;margin-bottom:16px;color:#C5A55A">1. Basics</h3>

        <div class="form-group">
            <label>Campaign Name <span style="color:#ef4444">*</span></label>
            <input type="text" name="name" class="form-input"
                   value="{{ old('name', $campaign->name ?? '') }}"
                   placeholder="e.g. Q3 Win-Back Horror Releases" required maxlength="160">
            @error('name')<small style="color:#ef4444">{{ $message }}</small>@enderror
        </div>

        <div style="display:grid;grid-template-columns:2fr 1fr;gap:16px">
            <div class="form-group">
                <label>Subject <span style="color:#ef4444">*</span></label>
                <input type="text" name="subject" class="form-input" x-model="subject"
                       value="{{ old('subject', $campaign->subject ?? '') }}"
                       placeholder="Halo @{{first_name}}, ada film baru..." required maxlength="200">
                <small style="color:#666">Tokens: <code>&#123;&#123;first_name&#125;&#125;</code>, <code>&#123;&#123;plan_name&#125;&#125;</code></small>
                @error('subject')<small style="color:#ef4444">{{ $message }}</small>@enderror
            </div>
            <div class="form-group">
                <label>Scheduled At</label>
                <input type="datetime-local" name="scheduled_at" class="form-input"
                       value="{{ old('scheduled_at', $campaign?->scheduled_at?->format('Y-m-d\TH:i')) }}">
                <small style="color:#666">Optional; UI ref only — send is manual.</small>
            </div>
        </div>

        <div class="form-group">
            <label>Preheader</label>
            <input type="text" name="preheader" class="form-input"
                   value="{{ old('preheader', $campaign->preheader ?? '') }}"
                   placeholder="Snippet preview di inbox" maxlength="160">
            @error('preheader')<small style="color:#ef4444">{{ $message }}</small>@enderror
        </div>
    </div>

    {{-- ━━━ AI Assist ━━━ --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:24px;margin-bottom:20px">
        <h3 style="font-size:15px;font-weight:600;margin-bottom:16px;color:#C5A55A">2. AI Copy Assist <span style="font-size:11px;color:#666;font-weight:400">(optional)</span></h3>

        <div style="display:grid;grid-template-columns:2fr 1fr;gap:16px">
            <div class="form-group">
                <label>Goal</label>
                <textarea x-model="aiGoal" class="form-input" rows="2"
                          placeholder="e.g. Re-engage inactive users with new horror releases this week"></textarea>
            </div>
            <div class="form-group">
                <label>Tone</label>
                <select x-model="aiTone" class="form-input">
                    <option value="warm">Warm</option>
                    <option value="cinematic">Cinematic</option>
                    <option value="urgent">Urgent</option>
                    <option value="playful">Playful</option>
                    <option value="formal">Formal</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label>Audience Description</label>
            <input type="text" x-model="aiAudience" class="form-input"
                   placeholder="e.g. Pelanggan premium yang belum nonton 30 hari terakhir">
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <button type="button" class="btn btn-gold" @click="generateAiDraft()" :disabled="aiLoading">
                <span x-text="aiLoading ? 'Generating…' : '✨ Generate Draft'"></span>
            </button>
            <span x-show="aiError" x-text="aiError" style="color:#ef4444;font-size:12px"></span>
        </div>

        <template x-if="aiSubjects.length > 0">
            <div style="margin-top:16px;padding:16px;background:#0f0f0f;border:1px solid #2a2a2a;border-radius:8px">
                <div style="font-size:11px;color:#C5A55A;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">Subject Variants — klik untuk pakai</div>
                <template x-for="(s, i) in aiSubjects" :key="i">
                    <div style="padding:8px;border-bottom:1px solid #1a1a1a;cursor:pointer;font-size:13px"
                         @click="useSubject(s)" x-text="`${i+1}. ${s}`"></div>
                </template>
            </div>
        </template>
    </div>

    {{-- ━━━ Body ━━━ --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:24px;margin-bottom:20px">
        <h3 style="font-size:15px;font-weight:600;margin-bottom:16px;color:#C5A55A">3. Body</h3>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
            <div class="form-group">
                <label>HTML Body <span style="color:#ef4444">*</span></label>
                <textarea name="html_body" class="form-input" x-model="htmlBody"
                          rows="14" required
                          style="font-family:'Menlo','Consolas',monospace;font-size:12px"></textarea>
                @error('html_body')<small style="color:#ef4444">{{ $message }}</small>@enderror
            </div>
            <div class="form-group">
                <label>Live Preview</label>
                <iframe x-ref="previewFrame" style="width:100%;height:336px;background:#fff;border:1px solid #2a2a2a;border-radius:8px"></iframe>
            </div>
        </div>

        <div class="form-group">
            <label>Plain-text Body <span style="color:#666;font-weight:400">(optional, auto-generated if blank)</span></label>
            <textarea name="plain_body" class="form-input" rows="6"
                      style="font-family:'Menlo','Consolas',monospace;font-size:12px"
                      placeholder="Fallback untuk mail client yang tidak render HTML">{{ old('plain_body', $campaign->plain_body ?? '') }}</textarea>
        </div>
    </div>

    {{-- ━━━ Segment ━━━ --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:24px;margin-bottom:20px">
        <h3 style="font-size:15px;font-weight:600;margin-bottom:16px;color:#C5A55A">4. Audience Segment</h3>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
            <div>
                <div class="form-group">
                    <label>Segment Type</label>
                    <select x-model="segmentType" @change="rebuildSegment()" class="form-input">
                        <option value="all">All verified users</option>
                        <option value="role">By role</option>
                        <option value="plan">Active subscribers to plan</option>
                        <option value="inactive_days">Inactive for N days</option>
                        <option value="new_signups">New signups (last N days)</option>
                        <option value="custom_emails">Custom email list</option>
                    </select>
                </div>

                <div class="form-group" x-show="segmentType === 'role'">
                    <label>Role Name</label>
                    <input type="text" x-model="segmentRole" @input="rebuildSegment()" class="form-input"
                           placeholder="e.g. user, subscriber, admin">
                </div>

                <div class="form-group" x-show="segmentType === 'plan'">
                    <label>Subscription Plan</label>
                    <select x-model.number="segmentPlanId" @change="rebuildSegment()" class="form-input">
                        <option value="0">— Pick plan —</option>
                        @foreach($plans as $plan)
                            <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="form-group" x-show="segmentType === 'inactive_days' || segmentType === 'new_signups'">
                    <label>Days</label>
                    <input type="number" x-model.number="segmentDays" @input="rebuildSegment()"
                           class="form-input" min="1" max="365">
                </div>

                <div class="form-group" x-show="segmentType === 'custom_emails'">
                    <label>Email List (one per line)</label>
                    <textarea x-model="segmentEmails" @input="rebuildSegment()" class="form-input" rows="6"
                              placeholder="a@example.com&#10;b@example.com"></textarea>
                </div>

                <div class="form-group">
                    <label>Segment JSON <small style="color:#666">(advanced — edit directly)</small></label>
                    <textarea name="segment" x-model="segmentJson"
                              class="form-input" rows="6"
                              style="font-family:'Menlo','Consolas',monospace;font-size:12px"></textarea>
                </div>
            </div>

            <div>
                <button type="button" class="btn btn-ghost" @click="previewAudience()" :disabled="previewLoading">
                    <span x-text="previewLoading ? 'Loading…' : 'Preview Audience'"></span>
                </button>

                <template x-if="previewCount !== null">
                    <div style="margin-top:16px;padding:16px;background:#0f0f0f;border:1px solid #2a2a2a;border-radius:8px">
                        <div style="font-size:11px;color:#C5A55A;text-transform:uppercase;letter-spacing:1px">Audience Size</div>
                        <div style="font-size:32px;font-weight:700;color:#fff;font-family:'Outfit',sans-serif" x-text="previewCount.toLocaleString()"></div>
                        <div style="font-size:11px;color:#666;margin-top:8px">Sample emails:</div>
                        <ul style="font-size:11px;color:#aaa;margin-top:4px;padding-left:16px">
                            <template x-for="e in previewSample" :key="e">
                                <li x-text="e"></li>
                            </template>
                        </ul>
                    </div>
                </template>
                <template x-if="previewError">
                    <div style="margin-top:16px;color:#ef4444;font-size:12px" x-text="previewError"></div>
                </template>
            </div>
        </div>
    </div>

    {{-- ━━━ Submit ━━━ --}}
    <div style="display:flex;gap:8px;justify-content:flex-end">
        <a href="{{ route('admin.email-campaigns.index') }}" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-gold">{{ $isEdit ? 'Save Changes' : 'Create Draft' }}</button>
    </div>
</form>

@push('scripts')
<script>
@php
    // Default body uses a {{first_name}} token literal — written without
    // doubled-braces so Blade doesn't try to evaluate it during compile.
    $defaultHtmlBody = '<p>Halo ' . '{{first_name}},</p><p>...</p>';
@endphp
function emailCampaignForm() {
    return {
        subject:   @json(old('subject', $campaign->subject ?? '')),
        htmlBody:  @json(old('html_body', $campaign->html_body ?? $defaultHtmlBody)),
        segmentJson: @json($segmentJson),

        segmentType: 'all',
        segmentRole: '',
        segmentPlanId: 0,
        segmentDays: 30,
        segmentEmails: '',

        aiGoal: '', aiTone: 'warm', aiAudience: '',
        aiLoading: false, aiError: '', aiSubjects: [],

        previewLoading: false, previewError: '', previewCount: null, previewSample: [],

        init() {
            this.syncFromJson();
            this.refreshPreview();
            this.$watch('htmlBody', () => this.refreshPreview());
        },

        syncFromJson() {
            try {
                const seg = JSON.parse(this.segmentJson);
                if (seg && typeof seg.type === 'string') {
                    this.segmentType = seg.type;
                    if (seg.type === 'role') this.segmentRole = seg.name || '';
                    if (seg.type === 'plan') this.segmentPlanId = parseInt(seg.plan_id || 0, 10);
                    if (seg.type === 'inactive_days' || seg.type === 'new_signups') this.segmentDays = parseInt(seg.days || 30, 10);
                    if (seg.type === 'custom_emails') this.segmentEmails = (seg.emails || []).join('\n');
                }
            } catch (e) { /* ignore */ }
        },

        rebuildSegment() {
            let seg = { type: this.segmentType };
            if (this.segmentType === 'role') seg.name = this.segmentRole;
            if (this.segmentType === 'plan') seg.plan_id = this.segmentPlanId;
            if (this.segmentType === 'inactive_days' || this.segmentType === 'new_signups') seg.days = this.segmentDays;
            if (this.segmentType === 'custom_emails') {
                seg.emails = this.segmentEmails.split(/\r?\n/).map(s => s.trim()).filter(Boolean);
            }
            this.segmentJson = JSON.stringify(seg, null, 2);
        },

        refreshPreview() {
            const frame = this.$refs.previewFrame;
            if (!frame) return;
            const doc = frame.contentDocument || frame.contentWindow.document;
            doc.open(); doc.write(this.htmlBody); doc.close();
        },

        useSubject(s) { this.subject = s; document.querySelector('input[name=subject]').value = s; },

        async generateAiDraft() {
            this.aiLoading = true; this.aiError = ''; this.aiSubjects = [];
            try {
                const r = await fetch(@json(route('admin.email-campaigns.ai-draft')), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                    body: JSON.stringify({ goal: this.aiGoal, tone: this.aiTone, audience: this.aiAudience }),
                });
                const data = await r.json();
                if (!data.ok) { this.aiError = data.error || 'Failed'; return; }
                this.aiSubjects = data.draft.subjects || [];
                if (data.draft.html_body) this.htmlBody = data.draft.html_body;
                if (data.draft.preheader) document.querySelector('input[name=preheader]').value = data.draft.preheader;
                if (data.draft.plain_body) document.querySelector('textarea[name=plain_body]').value = data.draft.plain_body;
                this.refreshPreview();
            } catch (e) { this.aiError = e.message; }
            finally { this.aiLoading = false; }
        },

        async previewAudience() {
            this.previewLoading = true; this.previewError = ''; this.previewCount = null;
            try {
                const seg = JSON.parse(this.segmentJson);
                const r = await fetch(@json(route('admin.email-campaigns.preview-audience')), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                    body: JSON.stringify({ segment: seg }),
                });
                const data = await r.json();
                if (!data.ok) { this.previewError = data.error || 'Invalid segment'; return; }
                this.previewCount = data.count; this.previewSample = data.sample || [];
            } catch (e) { this.previewError = e.message; }
            finally { this.previewLoading = false; }
        },
    };
}
</script>
@endpush
