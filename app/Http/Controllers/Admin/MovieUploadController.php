<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\TranscodeMovie;
use App\Models\EncodingJob;
use App\Models\Movie;
use App\Services\Security\FileUploadValidator;
use App\Services\Security\VirusScanner;
use App\Services\Storage\S3StorageService;
use App\Support\SafeFilename;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Admin upload + encoding endpoints for the master video file.
 *
 *   1. uploadMaster()    — accepts a multipart MP4/MOV upload and writes it
 *                          to the configured master disk.
 *   2. startTranscode()  — dispatches the TranscodeMovie job which kicks off
 *                          the ffmpeg → encrypt → upload pipeline.
 *   3. encodingStatus()  — JSON polling endpoint for the upload UI.
 *
 * Chunked uploads are supported transparently: when `chunk_index` and
 * `chunk_count` are present, each request appends to a temporary file and
 * the final chunk renames the assembled file into its permanent location.
 * For simple single-shot uploads, omit those fields and pass `file`.
 */
class MovieUploadController extends Controller
{
    /**
     * Render the chunked-upload + transcode UI for a movie.
     *
     * Backs `resources/views/admin/movies/upload.blade.php` (existing view
     * referenced by route admin.movies.upload-master/start-transcode/
     * encoding-status). Per docs/audit/04-drm-playback.md FIX #2 §6 the
     * legacy AdminController::storeMovie/updateMovie video upload path was
     * removed in favour of redirecting admins here for any video work.
     */
    public function showUploadPage(Movie $movie): View
    {
        return view('admin.movies.upload', [
            'movie' => $movie,
            // Direct presigned PUT only works against the S3 disk. Master videos
            // now live on the native-GCS 'gcs_master' disk (UBLA bucket, no S3
            // ACL/presign compatibility), so we force the server-proxied chunked
            // path which streams to config('filesystems.master'). Gate on an env
            // flag so an S3-proper deployment can re-enable direct uploads.
            'directUpload' => env('MASTER_DIRECT_UPLOAD', false) && S3StorageService::enabled(),
        ]);
    }

    /**
     * Upload (or append a chunk of) the master file for a movie.
     *
     * Single-shot:    POST { file: <binary> }
     * Chunked:        POST { file: <chunk>, chunk_index: 0..N-1, chunk_count: N, upload_id: <stable id> }
     */
    public function uploadMaster(
        Request $request,
        Movie $movie,
        FileUploadValidator $uploads,
        VirusScanner $scanner,
    ): JsonResponse {
        $validated = $request->validate([
            'file' => 'required|file',
            'chunk_index' => 'nullable|integer|min:0',
            'chunk_count' => 'nullable|integer|min:1',
            'upload_id' => 'nullable|string|max:128',
        ]);

        $file = $request->file('file');
        // A new upload always targets the currently-configured master disk —
        // NOT $movie->master_file_disk. Honouring the old row value made a movie
        // whose first upload landed on a wrong/broken disk impossible to fix by
        // re-uploading (it kept resolving to the stale disk). The row is updated
        // to this disk once the upload succeeds.
        $disk = (string) config('filesystems.master', 'local');

        // Chunked path: we accumulate into a temporary upload-id file, then
        // promote it to the canonical master path on the last chunk. Using
        // the local disk for accumulation avoids paying egress per chunk to
        // S3/Bunny when the admin is on a slow link.
        $isChunked = $validated['chunk_index'] !== null && $validated['chunk_count'] !== null;

        if ($isChunked) {
            // Chunks are themselves arbitrary bytes — we can't magic-byte
            // sniff a single chunk. We DO still enforce filename safety
            // here, and run the full validator on the assembled file at
            // the final-chunk handoff (see handleChunkedUpload).
            if (! SafeFilename::isSafePath((string) $file->getClientOriginalName())) {
                return response()->json([
                    'ok' => false, 'error' => 'unsafe_filename',
                    'message' => 'Filename mengandung karakter terlarang.',
                ], 422);
            }

            return $this->handleChunkedUpload($movie, $file->getRealPath(), $validated, $file->getClientOriginalName(), $uploads, $scanner);
        }

        // Single-shot path. Validate the upload BEFORE we open any stream.
        $check = $uploads->validateVideo($file);
        if (! $check['ok']) {
            return response()->json([
                'ok' => false, 'error' => 'invalid_video', 'errors' => $check['errors'],
            ], 422);
        }

        if (! $scanner->scan($check['safe_path'] ?? $file->getRealPath())) {
            return response()->json([
                'ok' => false, 'error' => 'malware_detected',
                'message' => 'File ditolak oleh anti-malware scanner.',
            ], 422);
        }

        // Single-shot path. Stream the upload directly to the target disk.
        try {
            // Extension is derived from the SNIFFED MIME, not the client name —
            // a `.exe` renamed to `.mp4` would still be rejected above; this
            // guarantees the persisted name carries the correct ext.
            $safeName = SafeFilename::generate(
                $file->getClientOriginalName(),
                'master'
            );
            $filename = sprintf('movies/%d/%s', $movie->id, $safeName);

            $stream = fopen($file->getRealPath(), 'rb');
            if ($stream === false) {
                throw new \RuntimeException('Cannot open uploaded file stream.');
            }

            try {
                Storage::disk($disk)->put($filename, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $movie->forceFill([
                'master_file_path' => $filename,
                'master_file_disk' => $disk,
                'encoding_status' => 'pending',
            ])->save();

            return response()->json([
                'ok' => true,
                'path' => $filename,
                'disk' => $disk,
                'size' => $file->getSize(),
            ]);
        } catch (Throwable $e) {
            Log::error('uploadMaster failed', [
                'movie_id' => $movie->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'upload_failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Issue a presigned PUT URL so the browser can upload the master file
     * DIRECTLY to GCS / S3, bypassing the PHP server entirely.
     *
     * This is the shared-hosting-friendly path: a 2GB file never transits the
     * web server (no temp disk, no max_execution_time risk). Because we can't
     * magic-byte sniff or virus-scan a file that hasn't landed yet, this
     * endpoint gates on the extension only — acceptable since the route is
     * admin-only (can:movies.upload_master).
     *
     * POST { filename: "movie.mp4" }
     * → { ok, url, headers, key, disk }
     */
    public function signUpload(Request $request, Movie $movie, S3StorageService $s3): JsonResponse
    {
        $data = $request->validate([
            'filename' => 'required|string|max:255',
        ]);

        if (! S3StorageService::enabled()) {
            return response()->json([
                'ok' => false, 'error' => 'gcs_not_configured',
                'message' => 'Cloud storage (GCS/S3) belum dikonfigurasi.',
            ], 422);
        }

        if (! SafeFilename::isSafePath($data['filename'])) {
            return response()->json([
                'ok' => false, 'error' => 'unsafe_filename',
                'message' => 'Filename mengandung karakter terlarang.',
            ], 422);
        }

        // Name-based extension gate (mirrors FileUploadValidator's video set).
        $ext = SafeFilename::sanitiseExtension($data['filename']);
        $allowed = ['mp4', 'mov', 'mkv', 'webm'];
        if (! in_array($ext, $allowed, true)) {
            return response()->json([
                'ok' => false, 'error' => 'bad_extension',
                'message' => 'Ekstensi tidak diizinkan. Gunakan: '.implode(', ', $allowed).'.',
            ], 422);
        }

        $key = sprintf('movies/%d/%s', $movie->id, SafeFilename::generate($data['filename'], 'master'));

        try {
            $signed = $s3->presignedUploadUrl($key, 3600);
        } catch (Throwable $e) {
            Log::error('signUpload presign failed', [
                'movie_id' => $movie->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false, 'error' => 'presign_failed', 'message' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'url' => $signed['url'],
            'headers' => $signed['headers'],
            'key' => $key,
            'disk' => 's3',
        ]);
    }

    /**
     * Register a master file that the browser uploaded directly to GCS / S3.
     *
     * Called after the presigned PUT (signUpload) succeeds. We re-validate the
     * key is inside THIS movie's prefix (never trust the client) and confirm
     * the object actually exists before stamping it on the movie.
     *
     * POST { key: "movies/{id}/master_xxx.mp4" }
     */
    public function finalizeUpload(Request $request, Movie $movie, S3StorageService $s3): JsonResponse
    {
        $data = $request->validate([
            'key' => 'required|string|max:512',
        ]);

        $key = $data['key'];
        $prefix = sprintf('movies/%d/', $movie->id);

        if (! str_starts_with($key, $prefix) || str_contains($key, '..')) {
            return response()->json([
                'ok' => false, 'error' => 'invalid_key',
                'message' => 'Object key di luar folder movie ini.',
            ], 422);
        }

        if (! $s3->exists($key)) {
            return response()->json([
                'ok' => false, 'error' => 'not_found',
                'message' => 'File belum ada di storage — upload mungkin gagal atau CORS bucket belum diset.',
            ], 422);
        }

        $movie->forceFill([
            'master_file_path' => $key,
            'master_file_disk' => 's3',
            'encoding_status' => 'pending',
        ])->save();

        return response()->json([
            'ok' => true,
            'path' => $key,
            'disk' => 's3',
        ]);
    }

    /**
     * Dispatch the transcoding pipeline.
     *
     * Returns immediately with the job row id; the front-end then polls
     * encodingStatus() to drive the progress bar.
     */
    public function startTranscode(Movie $movie): JsonResponse
    {
        if (empty($movie->master_file_path)) {
            return response()->json([
                'ok' => false,
                'error' => 'no_master_file',
                'message' => 'Upload a master file before starting transcoding.',
            ], 422);
        }

        // Pre-create the EncodingJob row so the polling endpoint has something
        // to return immediately (otherwise we'd race the job constructor).
        // EncodingJob uses $guarded = ['*'] (mass-assignment audit, 2026-05-13).
        $job = EncodingJob::forceCreate([
            'movie_id' => $movie->id,
            'status' => EncodingJob::STATUS_QUEUED,
            'progress_percent' => 0,
        ]);

        $movie->forceFill(['encoding_status' => 'processing'])->save();

        TranscodeMovie::dispatch($movie->id);

        return response()->json([
            'ok' => true,
            'job_id' => $job->id,
            'status' => $job->status,
        ]);
    }

    /**
     * Latest encoding job status for the movie.
     *
     * Returns 200 with the latest row, or 404 if there are no jobs yet so
     * the front-end can distinguish "not started" from "in-flight".
     */
    public function encodingStatus(Movie $movie): JsonResponse
    {
        $job = EncodingJob::query()
            ->where('movie_id', $movie->id)
            ->latest('id')
            ->first();

        if ($job === null) {
            return response()->json([
                'ok' => false,
                'error' => 'no_job',
                'movie_status' => $movie->encoding_status ?? 'pending',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'job_id' => $job->id,
            'status' => $job->status,
            'progress_percent' => (int) $job->progress_percent,
            'error_message' => $job->error_message,
            'started_at' => $job->started_at?->toIso8601String(),
            'completed_at' => $job->completed_at?->toIso8601String(),
            'movie_status' => $movie->encoding_status ?? 'pending',
        ]);
    }

    /**
     * Append a chunk to a temporary upload file. On the final chunk, promote
     * the assembled file to the configured master disk.
     *
     * @param  array{chunk_index:int|null, chunk_count:int|null, upload_id:string|null}  $payload
     */
    protected function handleChunkedUpload(
        Movie $movie,
        string $chunkPath,
        array $payload,
        string $originalName,
        ?FileUploadValidator $uploads = null,
        ?VirusScanner $scanner = null,
    ): JsonResponse {
        $uploadId = $payload['upload_id'] ?: Str::random(16);
        $index = (int) $payload['chunk_index'];
        $total = (int) $payload['chunk_count'];

        // Local staging — chunks live on the default disk under uploads/tmp
        // until the final chunk arrives, then the assembled file is streamed
        // to the master disk in one shot.
        $tmpDir = storage_path("app/uploads/tmp/{$movie->id}");
        if (! is_dir($tmpDir) && ! mkdir($tmpDir, 0755, true) && ! is_dir($tmpDir)) {
            return response()->json(['ok' => false, 'error' => 'tmp_dir_unwritable'], 500);
        }

        $assemblyPath = $tmpDir.DIRECTORY_SEPARATOR.$uploadId.'.part';

        // Append-mode write so concurrent chunks (rare but possible) don't
        // overwrite each other's bytes. Front-end SHOULD send chunks serially.
        $in = fopen($chunkPath, 'rb');
        $out = fopen($assemblyPath, 'ab');

        if ($in === false || $out === false) {
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }

            return response()->json(['ok' => false, 'error' => 'chunk_io_failed'], 500);
        }

        try {
            stream_copy_to_stream($in, $out);
        } finally {
            fclose($in);
            fclose($out);
        }

        $isLastChunk = ($index + 1) >= $total;

        if (! $isLastChunk) {
            return response()->json([
                'ok' => true,
                'upload_id' => $uploadId,
                'received' => $index + 1,
                'expected' => $total,
                'final' => false,
            ]);
        }

        // Final chunk — promote to permanent location. We now have the
        // FULL assembled file on disk, so this is when we can do the
        // magic-byte sniff + virus scan that we couldn't do per-chunk.
        // Wrap the assembled tmp file in an UploadedFile so we can pass
        // it through the same validator the single-shot path uses.
        if ($uploads !== null) {
            $assembledUpload = new UploadedFile(
                $assemblyPath,
                $originalName,
                null, // let finfo sniff
                null,
                true  // test mode = treat $assemblyPath as already-moved
            );

            $check = $uploads->validateVideo($assembledUpload);
            if (! $check['ok']) {
                @unlink($assemblyPath);

                return response()->json([
                    'ok' => false, 'error' => 'invalid_video', 'errors' => $check['errors'],
                ], 422);
            }

            if ($scanner !== null && ! $scanner->scan($assemblyPath)) {
                @unlink($assemblyPath);

                return response()->json([
                    'ok' => false, 'error' => 'malware_detected',
                    'message' => 'File ditolak oleh anti-malware scanner.',
                ], 422);
            }
        }

        // A new upload always targets the currently-configured master disk —
        // NOT $movie->master_file_disk. Honouring the old row value made a movie
        // whose first upload landed on a wrong/broken disk impossible to fix by
        // re-uploading (it kept resolving to the stale disk). The row is updated
        // to this disk once the upload succeeds.
        $disk = (string) config('filesystems.master', 'local');
        $safeName = SafeFilename::generate($originalName, 'master');
        $filename = sprintf('movies/%d/%s', $movie->id, $safeName);

        try {
            $stream = fopen($assemblyPath, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('Cannot open assembled upload.');
            }

            try {
                Storage::disk($disk)->put($filename, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $size = filesize($assemblyPath) ?: 0;

            // Best-effort cleanup of the temp file.
            @unlink($assemblyPath);

            $movie->forceFill([
                'master_file_path' => $filename,
                'master_file_disk' => $disk,
                'encoding_status' => 'pending',
            ])->save();

            return response()->json([
                'ok' => true,
                'path' => $filename,
                'disk' => $disk,
                'size' => $size,
                'final' => true,
            ]);
        } catch (Throwable $e) {
            Log::error('Chunked upload promotion failed', [
                'movie_id' => $movie->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'promote_failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
