/**
 * Alpine factory for the per-comment reaction pill bar.
 *
 *   <div x-data="commentReactions({id, initial, mine})">
 *     <button @click="toggle('like')" :class="{active: mine==='like'}">
 *       <span x-text="counts.like || ''"></span>
 *     </button>
 *     ...
 *   </div>
 *
 * State machine:
 *   - Optimistic update on click (snappy UI, no waiting on round-trip)
 *   - POST /comments/{id}/react with the chosen reaction
 *   - On success: REPLACE local counts with the server's authoritative
 *     map (covers the case where two users reacted simultaneously)
 *   - On failure: revert the optimistic mutation
 *
 * Realtime: subscribes to the private channel
 *   `movie.{movieId}.comments`
 * and listens for `comment.reaction.updated`. Only fires when
 * `window.Echo` was bootstrapped (i.e. Pusher creds present). When
 * Echo is missing the component still works — it just won't see live
 * updates from other users.
 *
 * Registered globally as `window.commentReactions` by the import in
 * resources/js/app.js so Blade `x-data="commentReactions({...})"`
 * resolves without a per-page <script>.
 */

// Canonical list — must mirror App\Models\CommentReaction::REACTIONS.
// Used so we can iterate and zero-fill the counts map.
export const REACTIONS = ['like', 'love', 'laugh', 'wow', 'sad', 'angry'];

/**
 * Zero-fill any missing reaction keys so the Alpine x-text bindings
 * resolve to a defined slot instead of `undefined` (which would render
 * literally as "undefined" in the DOM).
 *
 * @param {Object} counts
 * @returns {Object}
 */
function normalizeCounts(counts) {
    const out = {};
    for (const r of REACTIONS) {
        out[r] = Number((counts && counts[r]) || 0);
    }
    return out;
}

export function commentReactions({ id, initial = {}, mine = '', movieId = null } = {}) {
    return {
        id,
        movieId,
        counts: normalizeCounts(initial),
        mine: mine || null,
        busy: false,
        echoChannel: null,

        get total() {
            return REACTIONS.reduce((sum, r) => sum + (this.counts[r] || 0), 0);
        },

        init() {
            // Optional Echo subscription — gracefully skipped when Pusher
            // is not configured (window.Echo is undefined in that case).
            if (typeof window === 'undefined' || !window.Echo || !this.movieId) {
                return;
            }
            try {
                this.echoChannel = window.Echo.private('movie.' + this.movieId + '.comments');
                this.echoChannel.listen('.comment.reaction.updated', (payload) => {
                    if (!payload || Number(payload.comment_id) !== Number(this.id)) {
                        return;
                    }
                    // Authoritative replacement — don't try to merge.
                    this.counts = normalizeCounts(payload.counts || {});
                });
            } catch (e) {
                // Echo not initialised properly (e.g. PUSHER_KEY missing
                // at runtime) — degrade silently to non-realtime mode.
                console.warn('[FLiK] comment reactions: Echo subscribe failed', e);
            }
        },

        destroy() {
            if (this.echoChannel && window.Echo && this.movieId) {
                try {
                    window.Echo.leave('movie.' + this.movieId + '.comments');
                } catch (e) {
                    // Best-effort cleanup.
                }
            }
        },

        async toggle(reaction) {
            if (this.busy || !REACTIONS.includes(reaction)) {
                return;
            }

            // ── Snapshot for revert-on-failure ───────────────────────
            const prevCounts = { ...this.counts };
            const prevMine = this.mine;

            // ── Optimistic mutation ──────────────────────────────────
            if (this.mine === reaction) {
                // Toggle off
                this.counts[reaction] = Math.max(0, (this.counts[reaction] || 0) - 1);
                this.mine = null;
            } else {
                if (this.mine && this.counts[this.mine] > 0) {
                    this.counts[this.mine] -= 1;
                }
                this.counts[reaction] = (this.counts[reaction] || 0) + 1;
                this.mine = reaction;
            }

            this.busy = true;
            try {
                const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';
                const res = await fetch(`/comments/${this.id}/react`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ reaction }),
                });

                if (!res.ok) {
                    throw new Error('HTTP ' + res.status);
                }

                const json = await res.json();
                if (!json.success) {
                    throw new Error(json.message || 'Failed');
                }

                // ── Authoritative replace from server ────────────────
                this.counts = normalizeCounts(json.counts || {});
                this.mine = json.reaction_user || null;
            } catch (e) {
                // ── Revert optimistic mutation ───────────────────────
                this.counts = prevCounts;
                this.mine = prevMine;
                console.warn('[FLiK] comment reaction toggle failed', e);
            } finally {
                this.busy = false;
            }
        },
    };
}

// Expose for inline x-data="commentReactions(...)" in Blade. Alpine
// resolves bare identifiers against the global scope after `alpine:init`.
if (typeof window !== 'undefined') {
    window.commentReactions = commentReactions;
}
