<x-admin.layout :title="'Upload Master: ' . $movie->title">

    <div style="max-width:880px">
        <div style="margin-bottom:24px">
            <a href="{{ route('admin.movies.edit', $movie) }}" style="font-size:13px;color:#888;text-decoration:none">
                &larr; Back to {{ $movie->title }}
            </a>
        </div>

        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:24px"
             x-data="movieUploader({
                 movieSlug: '{{ $movie->slug }}',
                 uploadUrl: '{{ route('admin.movies.upload-master', $movie) }}',
                 transcodeUrl: '{{ route('admin.movies.start-transcode', $movie) }}',
                 statusUrl: '{{ route('admin.movies.encoding-status', $movie) }}',
                 csrf: '{{ csrf_token() }}',
                 currentMaster: @js($movie->master_file_path),
                 currentDisk: @js($movie->master_file_disk),
                 chunkSize: 5 * 1024 * 1024,
                 directUpload: @js($directUpload),
                 signUploadUrl: '{{ route('admin.movies.sign-upload', $movie) }}',
                 finalizeUrl: '{{ route('admin.movies.finalize-upload', $movie) }}'
             })"
             x-init="init()">

            <h2 style="font-family:'Outfit',sans-serif;font-size:18px;font-weight:600;margin-bottom:6px">
                Master File Upload
            </h2>
            <p style="font-size:13px;color:#888;margin-bottom:20px">
                Upload the highest-quality master (MP4 / MOV / MKV). The transcoding pipeline will produce the ABR ladder + AES-128 segments.
            </p>

            {{-- Existing master notice --}}
            <template x-if="currentMaster && !uploadedNow">
                <div style="margin-bottom:16px;padding:12px 16px;background:#252525;border:1px solid #333;border-radius:8px;font-size:13px;color:#aaa">
                    Current master:
                    <strong style="color:#C5A55A" x-text="basename(currentMaster)"></strong>
                    <span style="color:#666" x-text="' (' + currentDisk + ')'"></span>
                </div>
            </template>

            {{-- Drop zone --}}
            <div
                @dragover.prevent="dragActive = true"
                @dragleave.prevent="dragActive = false"
                @drop.prevent="onDrop($event)"
                :style="dragActive
                    ? 'border:2px dashed #C5A55A;background:rgba(197,165,90,0.06)'
                    : 'border:2px dashed #333;background:#151515'"
                style="padding:48px 24px;border-radius:12px;text-align:center;transition:all 0.2s;cursor:pointer"
                @click="$refs.fileInput.click()">

                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#C5A55A" stroke-width="1.5"
                     style="margin:0 auto 12px;display:block">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/>
                </svg>

                <div style="font-size:15px;font-weight:500;color:#fff;margin-bottom:6px">
                    Drag &amp; drop your master file here
                </div>
                <div style="font-size:12px;color:#777">
                    or click to browse — MP4 / MOV / MKV / WebM
                </div>

                <input type="file" x-ref="fileInput" @change="onFileSelected($event)"
                       accept=".mp4,.mov,.mkv,.webm,.avi"
                       style="display:none">
            </div>

            {{-- Selected file + progress --}}
            <template x-if="selectedFile">
                <div style="margin-top:18px;padding:16px;background:#252525;border:1px solid #333;border-radius:10px">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                        <div>
                            <div style="font-size:14px;font-weight:500;color:#fff" x-text="selectedFile.name"></div>
                            <div style="font-size:12px;color:#777" x-text="formatBytes(selectedFile.size)"></div>
                        </div>
                        <div style="font-size:13px;color:#C5A55A;font-weight:600" x-text="uploadProgress + '%'"></div>
                    </div>
                    <div style="height:8px;background:#1a1a1a;border-radius:4px;overflow:hidden">
                        <div :style="'width:' + uploadProgress + '%;background:linear-gradient(90deg,#C5A55A,#E8D5A3);height:100%;transition:width 0.2s'"></div>
                    </div>
                    <div style="font-size:12px;color:#888;margin-top:8px" x-text="uploadStatusText"></div>
                </div>
            </template>

            {{-- Action buttons --}}
            <div style="display:flex;gap:10px;margin-top:20px;flex-wrap:wrap">
                <button type="button" class="btn btn-gold"
                        x-bind:disabled="!selectedFile || uploading"
                        x-bind:style="(!selectedFile || uploading) ? 'opacity:0.5;cursor:not-allowed' : ''"
                        @click="startUpload()">
                    <span x-show="!uploading">Upload Master</span>
                    <span x-show="uploading">Uploading&hellip;</span>
                </button>

                <button type="button" class="btn btn-ghost"
                        x-bind:disabled="!canTranscode || transcoding"
                        x-bind:style="(!canTranscode || transcoding) ? 'opacity:0.5;cursor:not-allowed' : ''"
                        @click="startTranscode()">
                    <span x-show="!transcoding">Start Transcoding</span>
                    <span x-show="transcoding">Queueing&hellip;</span>
                </button>
            </div>

            {{-- Encoding status panel --}}
            <template x-if="encodingJob">
                <div style="margin-top:24px;padding:18px;background:#151515;border:1px solid #2a2a2a;border-radius:12px">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                        <div style="font-size:14px;font-weight:600;color:#fff">
                            Encoding Job <span style="color:#666;font-weight:400" x-text="'#' + encodingJob.job_id"></span>
                        </div>
                        <span class="badge" x-bind:class="statusBadgeClass(encodingJob.status)" x-text="encodingJob.status"></span>
                    </div>

                    <div style="height:8px;background:#0f0f0f;border-radius:4px;overflow:hidden;margin-bottom:8px">
                        <div :style="'width:' + (encodingJob.progress_percent || 0) + '%;background:linear-gradient(90deg,#C5A55A,#E8D5A3);height:100%;transition:width 0.3s'"></div>
                    </div>
                    <div style="font-size:12px;color:#888;display:flex;justify-content:space-between">
                        <span x-text="(encodingJob.progress_percent || 0) + '% complete'"></span>
                        <span x-show="encodingJob.started_at" x-text="'Started ' + formatTime(encodingJob.started_at)"></span>
                    </div>

                    <template x-if="encodingJob.error_message">
                        <div style="margin-top:12px;padding:10px 14px;background:rgba(220,38,38,0.1);border:1px solid rgba(220,38,38,0.3);border-radius:8px;font-size:12px;color:#ef4444">
                            <strong>Error:</strong> <span x-text="encodingJob.error_message"></span>
                        </div>
                    </template>

                    <template x-if="encodingJob.status === 'completed'">
                        <div style="margin-top:12px;padding:10px 14px;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);border-radius:8px;font-size:13px;color:#22c55e">
                            Encoding complete &mdash; the movie is ready for playback.
                        </div>
                    </template>
                </div>
            </template>

            {{-- Flash error --}}
            <template x-if="errorMessage">
                <div style="margin-top:16px;padding:12px 16px;background:rgba(220,38,38,0.1);border:1px solid rgba(220,38,38,0.3);border-radius:8px;color:#ef4444;font-size:13px"
                     x-text="errorMessage"></div>
            </template>
        </div>
    </div>

    <script>
        // Alpine component definition. Must be registered BEFORE Alpine boots
        // (Alpine is loaded with `defer` in the layout, so this <script> tag
        // runs first and the function is in scope by the time x-data evaluates).
        function movieUploader(opts) {
            return {
                ...opts,
                dragActive: false,
                selectedFile: null,
                uploading: false,
                uploadProgress: 0,
                uploadStatusText: '',
                uploadedNow: false,
                transcoding: false,
                canTranscode: false,
                encodingJob: null,
                pollTimer: null,
                errorMessage: '',

                init() {
                    this.canTranscode = !!this.currentMaster;
                    // If a job is already running for this movie, start polling immediately.
                    this.pollStatus(true);
                },

                onDrop(e) {
                    this.dragActive = false;
                    const file = e.dataTransfer?.files?.[0];
                    if (file) this.setFile(file);
                },

                onFileSelected(e) {
                    const file = e.target.files?.[0];
                    if (file) this.setFile(file);
                },

                setFile(file) {
                    this.selectedFile = file;
                    this.uploadProgress = 0;
                    this.uploadStatusText = 'Ready to upload.';
                    this.errorMessage = '';
                },

                async startUpload() {
                    if (!this.selectedFile || this.uploading) return;

                    this.uploading = true;
                    this.errorMessage = '';
                    this.uploadProgress = 0;
                    this.uploadStatusText = 'Uploading…';

                    try {
                        // When GCS/S3 is configured, upload straight to the
                        // bucket via a presigned PUT — the file never transits
                        // the PHP server (essential for large files on shared
                        // hosting). Otherwise fall back to server-proxied upload.
                        if (this.directUpload) {
                            await this.uploadDirectToGcs(this.selectedFile);
                        } else if (this.selectedFile.size > 50 * 1024 * 1024) {
                            // Files larger than ~50MB → chunked upload. Smaller
                            // files go in one shot for lower latency.
                            await this.uploadChunked(this.selectedFile);
                        } else {
                            await this.uploadSingleShot(this.selectedFile);
                        }

                        this.uploadStatusText = 'Upload complete.';
                        this.uploadedNow = true;
                        this.canTranscode = true;
                    } catch (e) {
                        this.errorMessage = e.message || 'Upload failed.';
                        this.uploadStatusText = 'Upload failed.';
                    } finally {
                        this.uploading = false;
                    }
                },

                uploadSingleShot(file) {
                    return new Promise((resolve, reject) => {
                        const fd = new FormData();
                        fd.append('file', file);
                        fd.append('_token', this.csrf);

                        const xhr = new XMLHttpRequest();
                        xhr.open('POST', this.uploadUrl);
                        xhr.setRequestHeader('X-CSRF-TOKEN', this.csrf);
                        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                        xhr.setRequestHeader('Accept', 'application/json');

                        xhr.upload.onprogress = (ev) => {
                            if (ev.lengthComputable) {
                                this.uploadProgress = Math.round((ev.loaded / ev.total) * 100);
                            }
                        };

                        xhr.onload = () => {
                            if (xhr.status >= 200 && xhr.status < 300) {
                                this.uploadProgress = 100;
                                resolve(JSON.parse(xhr.responseText || '{}'));
                            } else {
                                let body;
                                try { body = JSON.parse(xhr.responseText || '{}'); } catch { body = {}; }
                                reject(new Error(body.message || ('HTTP ' + xhr.status)));
                            }
                        };
                        xhr.onerror = () => reject(new Error('Network error.'));
                        xhr.send(fd);
                    });
                },

                async uploadChunked(file) {
                    const total = Math.ceil(file.size / this.chunkSize);
                    const uploadId = this.randomId(16);

                    for (let i = 0; i < total; i++) {
                        const start = i * this.chunkSize;
                        const end = Math.min(file.size, start + this.chunkSize);
                        const chunk = file.slice(start, end);

                        await this.uploadOneChunk(chunk, i, total, uploadId, file.name);

                        // Bump progress based on chunks completed.
                        this.uploadProgress = Math.round(((i + 1) / total) * 100);
                        this.uploadStatusText = `Uploading chunk ${i + 1} / ${total}…`;
                    }
                },

                uploadOneChunk(chunk, index, total, uploadId, filename) {
                    return new Promise((resolve, reject) => {
                        const fd = new FormData();
                        fd.append('file', chunk, filename);
                        fd.append('chunk_index', String(index));
                        fd.append('chunk_count', String(total));
                        fd.append('upload_id', uploadId);
                        fd.append('_token', this.csrf);

                        const xhr = new XMLHttpRequest();
                        xhr.open('POST', this.uploadUrl);
                        xhr.setRequestHeader('X-CSRF-TOKEN', this.csrf);
                        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                        xhr.setRequestHeader('Accept', 'application/json');

                        xhr.onload = () => {
                            if (xhr.status >= 200 && xhr.status < 300) {
                                resolve(JSON.parse(xhr.responseText || '{}'));
                            } else {
                                let body;
                                try { body = JSON.parse(xhr.responseText || '{}'); } catch { body = {}; }
                                reject(new Error(body.message || ('HTTP ' + xhr.status)));
                            }
                        };
                        xhr.onerror = () => reject(new Error('Network error during chunk upload.'));
                        xhr.send(fd);
                    });
                },

                // ── Direct browser → GCS / S3 (presigned PUT) ──────────────
                // 1) ask our server to sign a PUT URL, 2) PUT the file straight
                // to the bucket, 3) tell our server the object has landed.
                async uploadDirectToGcs(file) {
                    this.uploadStatusText = 'Meminta URL upload…';
                    const signRes = await fetch(this.signUploadUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': this.csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({ filename: file.name }),
                    });
                    const sign = await signRes.json().catch(() => ({}));
                    if (!signRes.ok || !sign.ok) {
                        throw new Error(sign.message || ('Gagal membuat URL upload (HTTP ' + signRes.status + ').'));
                    }

                    this.uploadStatusText = 'Mengupload ke Google Cloud…';
                    await this.putToBucket(sign.url, sign.headers || {}, file);

                    this.uploadStatusText = 'Menyelesaikan…';
                    const finRes = await fetch(this.finalizeUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': this.csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({ key: sign.key }),
                    });
                    const fin = await finRes.json().catch(() => ({}));
                    if (!finRes.ok || !fin.ok) {
                        throw new Error(fin.message || ('Gagal finalize (HTTP ' + finRes.status + ').'));
                    }
                },

                putToBucket(url, headers, file) {
                    return new Promise((resolve, reject) => {
                        const xhr = new XMLHttpRequest();
                        xhr.open('PUT', url);
                        // Apply exactly the headers the presign signed (if any).
                        Object.entries(headers).forEach(([k, v]) => {
                            try { xhr.setRequestHeader(k, v); } catch (e) { /* forbidden header — skip */ }
                        });
                        xhr.upload.onprogress = (ev) => {
                            if (ev.lengthComputable) {
                                this.uploadProgress = Math.round((ev.loaded / ev.total) * 100);
                            }
                        };
                        xhr.onload = () => {
                            if (xhr.status >= 200 && xhr.status < 300) {
                                this.uploadProgress = 100;
                                resolve();
                            } else {
                                reject(new Error('Upload ke storage gagal: HTTP ' + xhr.status));
                            }
                        };
                        xhr.onerror = () => reject(new Error('Network error saat upload ke storage (cek CORS bucket).'));
                        xhr.send(file);
                    });
                },

                async startTranscode() {
                    if (!this.canTranscode || this.transcoding) return;

                    this.transcoding = true;
                    this.errorMessage = '';

                    try {
                        const res = await fetch(this.transcodeUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': this.csrf,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        });
                        const body = await res.json().catch(() => ({}));
                        if (!res.ok) throw new Error(body.message || ('HTTP ' + res.status));

                        // Start polling immediately so the UI updates.
                        this.encodingJob = {
                            job_id: body.job_id,
                            status: body.status,
                            progress_percent: 0,
                        };
                        this.startPolling();
                    } catch (e) {
                        this.errorMessage = e.message || 'Failed to start transcoding.';
                    } finally {
                        this.transcoding = false;
                    }
                },

                startPolling() {
                    if (this.pollTimer) return;
                    this.pollTimer = window.setInterval(() => this.pollStatus(false), 5000);
                },

                stopPolling() {
                    if (this.pollTimer) {
                        window.clearInterval(this.pollTimer);
                        this.pollTimer = null;
                    }
                },

                async pollStatus(initial) {
                    try {
                        const res = await fetch(this.statusUrl, {
                            credentials: 'same-origin',
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        });

                        if (res.status === 404) {
                            // No job yet — only meaningful on initial mount.
                            if (initial) this.encodingJob = null;
                            return;
                        }

                        if (!res.ok) return;

                        const body = await res.json();
                        this.encodingJob = body;

                        if (initial && body.status && ['queued', 'transcoding', 'encrypting', 'uploading'].includes(body.status)) {
                            this.startPolling();
                        }

                        if (['completed', 'failed'].includes(body.status)) {
                            this.stopPolling();
                        }
                    } catch (e) {
                        // Swallow — the next poll will retry.
                    }
                },

                statusBadgeClass(status) {
                    return {
                        'badge-blue':  status === 'queued' || status === 'transcoding' || status === 'encrypting' || status === 'uploading',
                        'badge-green': status === 'completed',
                        'badge-gold':  status === 'failed',
                    };
                },

                formatBytes(b) {
                    if (b === 0) return '0 B';
                    const k = 1024;
                    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
                    const i = Math.floor(Math.log(b) / Math.log(k));
                    return (b / Math.pow(k, i)).toFixed(2) + ' ' + sizes[i];
                },

                formatTime(iso) {
                    try {
                        return new Date(iso).toLocaleTimeString();
                    } catch { return iso; }
                },

                basename(path) {
                    if (!path) return '';
                    const parts = String(path).split(/[\\/]/);
                    return parts[parts.length - 1];
                },

                randomId(len) {
                    const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
                    let out = '';
                    for (let i = 0; i < len; i++) out += chars[Math.floor(Math.random() * chars.length)];
                    return out;
                },
            };
        }
    </script>

</x-admin.layout>
