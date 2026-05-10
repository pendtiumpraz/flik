<div x-data="flikChatbot()"
     @click.outside="chatOpen = false"
     @keydown.escape.window="chatOpen = false"
     class="fixed bottom-4 right-4 md:bottom-6 md:right-6 z-[200]">

    <!-- Chat Panel -->
    <div x-show="chatOpen"
         x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-4"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 translate-y-4"
         class="absolute bottom-16 right-0 w-[calc(100vw-2rem)] sm:w-80 md:w-96 max-w-md rounded-2xl overflow-hidden shadow-2xl"
         style="background: linear-gradient(180deg, #1a1a1a 0%, #141210 100%); border: 1px solid rgba(197,165,90,0.3); box-shadow: 0 20px 60px -10px rgba(0,0,0,0.8)">

        <!-- Header -->
        <div class="px-4 py-3 flex items-center justify-between" style="background: linear-gradient(135deg, rgba(197,165,90,0.15), rgba(197,165,90,0.05)); border-bottom: 1px solid rgba(197,165,90,0.2)">
            <div class="flex items-center gap-2.5">
                <div class="relative">
                    <div class="w-8 h-8 rounded-full flex items-center justify-center" style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                        <x-icon name="sparkles" :size="14" class="text-black" />
                    </div>
                    <div class="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full bg-green-500 ring-2 ring-[#1a1a1a]"></div>
                </div>
                <div>
                    <div class="text-sm font-bold text-white">FLiK Assistant</div>
                    <div class="text-[10px] text-green-400">● Online</div>
                </div>
            </div>
            <button @click="chatOpen = false" class="text-gray-500 hover:text-[#C5A55A] transition-colors">
                <x-icon name="x" :size="18" />
            </button>
        </div>

        <!-- Messages -->
        <div class="p-3 h-72 overflow-y-auto space-y-2.5" id="chatMessages" x-ref="messagesEl"
             style="scrollbar-width: thin; scrollbar-color: rgba(197,165,90,0.3) transparent">
            <template x-for="(msg, idx) in messages" :key="idx">
                <div :class="msg.role === 'user' ? 'flex justify-end' : 'flex justify-start gap-2'">
                    <template x-if="msg.role === 'bot'">
                        <div class="w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5" style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                            <x-icon name="sparkles" :size="10" class="text-black" />
                        </div>
                    </template>
                    <div :class="msg.role === 'user' ? 'rounded-br-sm' : 'rounded-bl-sm'"
                         class="max-w-[80%] px-3 py-2 text-xs rounded-2xl whitespace-pre-wrap break-words chat-bubble"
                         :style="msg.role === 'user' ? 'background: linear-gradient(135deg, #C5A55A, #E8D5A3); color: #000' : 'background: rgba(255,255,255,0.05); color: #ddd; border: 1px solid rgba(197,165,90,0.15)'"
                         x-html="msg.role === 'bot' ? renderMarkdown(msg.text) : escapeHtml(msg.text)"></div>
                </div>
            </template>

            <!-- Typing indicator -->
            <div x-show="isLoading" x-cloak class="flex justify-start gap-2">
                <div class="w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5" style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                    <x-icon name="sparkles" :size="10" class="text-black" />
                </div>
                <div class="px-3 py-2 text-xs rounded-2xl rounded-bl-sm" style="background: rgba(255,255,255,0.05); border: 1px solid rgba(197,165,90,0.15)">
                    <span class="inline-flex gap-1">
                        <span class="w-1.5 h-1.5 rounded-full animate-bounce" style="background:#C5A55A; animation-delay: 0ms"></span>
                        <span class="w-1.5 h-1.5 rounded-full animate-bounce" style="background:#C5A55A; animation-delay: 150ms"></span>
                        <span class="w-1.5 h-1.5 rounded-full animate-bounce" style="background:#C5A55A; animation-delay: 300ms"></span>
                    </span>
                </div>
            </div>
        </div>

        <!-- Input -->
        <form @submit.prevent="sendMessage()"
              class="p-3 flex gap-2" style="border-top: 1px solid rgba(197,165,90,0.15)">
            <input x-model="input"
                   type="text"
                   :disabled="isLoading"
                   placeholder="Tanya apa saja..."
                   class="flex-1 px-3 py-2 text-xs text-white placeholder-gray-500 rounded-lg focus:outline-none focus:border-[#C5A55A] transition-colors disabled:opacity-50"
                   style="background: rgba(255,255,255,0.04); border: 1px solid rgba(197,165,90,0.2)">
            <button type="submit"
                    :disabled="isLoading || !input.trim()"
                    class="w-9 h-9 rounded-lg flex items-center justify-center transition-opacity hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed"
                    style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                <x-icon name="play-solid" :size="12" class="text-black" />
            </button>
        </form>
    </div>

    <!-- Floating Trigger Button -->
    <button @click="chatOpen = !chatOpen"
            x-show="!chatOpen"
            x-transition
            class="w-14 h-14 md:w-16 md:h-16 rounded-full flex items-center justify-center shadow-2xl hover:scale-110 transition-transform relative group"
            style="background: linear-gradient(135deg, #C5A55A, #E8D5A3); box-shadow: 0 10px 30px -5px rgba(197,165,90,0.5)">
        <x-icon name="sparkles" :size="24" class="text-black" />
        <!-- Pulse animation -->
        <span class="absolute inset-0 rounded-full animate-ping opacity-20" style="background: #C5A55A"></span>
        <!-- Tooltip -->
        <span class="absolute right-full mr-3 top-1/2 -translate-y-1/2 px-2.5 py-1.5 rounded-md text-xs font-medium text-white opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none whitespace-nowrap" style="background: rgba(0,0,0,0.85); border: 1px solid rgba(197,165,90,0.3)">
            Ask FLiK Assistant
        </span>
    </button>
</div>

@once
<script>
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('flikChatbot', () => ({
            chatOpen: false,
            messages: [
                { role: 'bot', text: 'Hai! Aku FLiK Assistant. Mau cari film apa hari ini?' }
            ],
            input: '',
            isLoading: false,

            async sendMessage() {
                const text = this.input.trim();
                if (!text || this.isLoading) return;

                // Push user message
                this.messages.push({ role: 'user', text });
                this.input = '';
                this.isLoading = true;
                this.scrollToBottom();

                try {
                    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content
                        || document.querySelector('input[name="_token"]')?.value;

                    const response = await fetch('/chat', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({
                            message: text,
                            // Send last 10 messages as conversation history
                            history: this.messages.slice(-10, -1).map(m => ({ role: m.role, text: m.text })),
                        }),
                    });

                    const data = await response.json();

                    if (response.ok && data.reply) {
                        this.messages.push({ role: 'bot', text: data.reply });
                    } else {
                        this.messages.push({
                            role: 'bot',
                            text: data.reply || data.error || 'Maaf, ada kesalahan. Coba lagi ya.'
                        });
                    }
                } catch (e) {
                    console.error('Chat error', e);
                    this.messages.push({
                        role: 'bot',
                        text: 'Koneksi bermasalah. Cek internet kamu ya.'
                    });
                } finally {
                    this.isLoading = false;
                    this.scrollToBottom();
                }
            },

            scrollToBottom() {
                this.$nextTick(() => {
                    const el = this.$refs.messagesEl;
                    if (el) el.scrollTop = el.scrollHeight;
                });
            },

            escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            },

            // Tiny markdown renderer: **bold**, *italic*, [text](url), newlines, lists
            renderMarkdown(text) {
                // 1. Escape HTML first
                let html = this.escapeHtml(text);

                // 2. Markdown links [text](url) → safe anchor (only allow internal /movie/* paths or http/https)
                html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, (_, label, url) => {
                    const safeUrl = (url.startsWith('/movie/') || url.startsWith('http://') || url.startsWith('https://')) ? url : '#';
                    const target = safeUrl.startsWith('http') ? ' target="_blank" rel="noopener"' : '';
                    return `<a href="${safeUrl}"${target} class="chat-link">${label}</a>`;
                });

                // 3. Bold **text**
                html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');

                // 4. Italic *text*
                html = html.replace(/(?<!\*)\*(?!\*)([^*]+)\*(?!\*)/g, '<em>$1</em>');

                // 5. Bullet list lines starting with - or *
                html = html.replace(/(^|\n)[*-] (.+)/g, '$1<li>$2</li>');
                html = html.replace(/(<li>.*<\/li>(?:\n?<li>.*<\/li>)*)/g, '<ul>$1</ul>');

                // 6. Newlines → <br>
                html = html.replace(/\n/g, '<br>');

                return html;
            },
        }));
    });
</script>
<style>
    /* Chat bubble inline element styling */
    .chat-bubble strong { color: #C5A55A; font-weight: 600; }
    .chat-bubble em { font-style: italic; }
    .chat-bubble a.chat-link {
        color: #C5A55A;
        text-decoration: underline;
        text-decoration-color: rgba(197,165,90,0.3);
        text-underline-offset: 2px;
        transition: text-decoration-color 200ms;
    }
    .chat-bubble a.chat-link:hover {
        text-decoration-color: #C5A55A;
    }
    .chat-bubble ul {
        margin: 6px 0;
        padding-left: 16px;
    }
    .chat-bubble li {
        list-style: disc;
        margin: 2px 0;
    }
</style>
@endonce
