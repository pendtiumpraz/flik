<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\TranscodeMovie;
use App\Models\EncodingJob;
use App\Models\Movie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
     * Upload (or append a chunk of) the master file for a movie.
     *
     * Single-shot:    POST { file: <binary> }
     * Chunked:        POST { file: <chunk>, chunk_index: 0..N-1, chunk_count: N, upload_id: <stable id> }
     */
    public function uploadMaster(Request $request, Movie $movie): JsonResponse
    {
        $validated = $request->validate([
            'file'        => 'required|file',
            'chunk_index' => 'nullable|integer|min:0',
            'chunk_count' => 'nullable|integer|min:1',
            'upload_id'   => 'nullable|string|max:128',
        ]);

        $file = $request->file('file');
        $disk = (string) ($movie->master_file_disk ?: config('filesystems.default', 'local'));

        // Chunked path: we accumulate into a temporary upload-id file, then
        // promote it to the canonical master path on the last chunk. Using
        // the local disk for accumulation avoids paying egress per chunk to
        // S3/Bunny when the admin is on a slow link.
        $isChunked = $validated['chunk_index'] !== null && $validated['chunk_count'] !== null;

        if ($isChunked) {
            return $this->handleChunkedUpload($movie, $file->getRealPath(), $validated, $file->getClientOriginalName());
        }

        // Single-shot path. Stream the upload directly to the target disk.
        try {
            $extension = strtolower($file->getClientOriginalExtension() ?: 'mp4');
            $filename = sprintf('movies/%d/master_%s.%s', $movie->id, Str::random(8), $extension);

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
                'encoding_status'  => 'pending',
            ])->save();

            return response()->json([
                'ok'   => true,
                'path' => $filename,
                'disk' => $disk,
                'size' => $file->getSize(),
            ]);
        } catch (Throwable $e) {
            Log::error('uploadMaster failed', [
                'movie_id' => $movie->id,
                'error'    => $e->getMessage(),
            ]);

            return response()->json([
                'ok'    => false,
                'error' => 'upload_failed',
                'message' => $e->getMessage(),
            ], 500);
        }
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
                'ok'      => false,
                'error'   => 'no_master_file',
                'message' => 'Upload a master file before starting transcoding.',
            ], 422);
        }

        // Pre-create the EncodingJob row so the polling endpoint has something
        // to return immediately (otherwise we'd race the job constructor).
        $job = EncodingJob::create([
            'movie_id'         => $movie->id,
            'status'           => EncodingJob::STATUS_QUEUED,
            'progress_percent' => 0,
        ]);

        $movie->forceFill(['encoding_status' => 'processing'])->save();

        TranscodeMovie::dispatch($movie->id);

        return response()->json([
            'ok'     => true,
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
                'ok'    => false,
                'error' => 'no_job',
                'movie_status' => $movie->encoding_status ?? 'pending',
            ], 404);
        }

        return response()->json([
            'ok'               => true,
            'job_id'           => $job->id,
            'status'           => $job->status,
            'progress_percent' => (int) $job->progress_percent,
            'error_message'    => $job->error_message,
            'started_at'       => $job->started_at?->toIso8601String(),
            'completed_at'     => $job->completed_at?->toIso8601String(),
            'movie_status'     => $movie->encoding_status ?? 'pending',
        ]);
    }

    /**
     * Append a chunk to a temporary upload file. On the final chunk, promote
     * the assembled file to the configured master disk.
     *
     * @param  array{chunk_index:int|null, chunk_count:int|null, upload_id:string|null}  $payload
     */
    protected function handleChunkedUpload(Movie $movie, string $chunkPath, array $payload, string $originalName): JsonResponse
    {
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

        $assemblyPath = $tmpDir . DIRECTORY_SEPARATOR . $uploadId . '.part';

        // Append-mode write so concurrent chunks (rare but possible) don't
        // overwrite each other's bytes. Front-end SHOULD send chunks serially.
        $in = fopen($chunkPath, 'rb');
        $out = fopen($assemblyPath, 'ab');

        if ($in === false || $out === false) {
            if (is_resource($in)) fclose($in);
            if (is_resource($out)) fclose($out);

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
                'ok'        => true,
                'upload_id' => $uploadId,
                'received'  => $index + 1,
                'expected'  => $total,
                'final'     => false,
            ]);
        }

        // Final chunk — promote to permanent location.
        $disk = (string) ($movie->master_file_disk ?: config('filesystems.default', 'local'));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION) ?: 'mp4');
        $filename = sprintf('movies/%d/master_%s.%s', $movie->id, Str::random(8), $extension);

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
                'encoding_status'  => 'pending',
            ])->save();

            return response()->json([
                'ok'    => true,
                'path'  => $filename,
                'disk'  => $disk,
                'size'  => $size,
                'final' => true,
            ]);
        } catch (Throwable $e) {
            Log::error('Chunked upload promotion failed', [
                'movie_id' => $movie->id,
                'error'    => $e->getMessage(),
            ]);

            return response()->json([
                'ok'      => false,
                'error'   => 'promote_failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
