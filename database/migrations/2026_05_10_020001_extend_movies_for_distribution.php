<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 distribution columns for movies.
 *
 * Adds encoding pipeline state, master file pointers, DRM strategy,
 * HLS/DASH manifest paths, CDN backing disk, and geo-allow list.
 *
 * NOTE: `video_disk` already exists (added in 2026_03_07_200001) and is
 * the storage disk of the original uploaded mp4 — this migration adds
 * `cdn_disk` for the publicly-served HLS/DASH packaged output (e.g. Bunny).
 *
 * Idempotent — safe to run multiple times.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            if (!Schema::hasColumn('movies', 'encoding_status')) {
                $table->enum('encoding_status', ['pending', 'processing', 'ready', 'failed'])
                    ->default('pending')
                    ->after('video_path');
            }

            if (!Schema::hasColumn('movies', 'encoding_renditions')) {
                $table->json('encoding_renditions')->nullable()->after('encoding_status')
                    ->comment('Array of {height, bitrate, path} produced by ffmpeg');
            }

            if (!Schema::hasColumn('movies', 'master_file_path')) {
                $table->string('master_file_path')->nullable()->after('encoding_renditions');
            }

            if (!Schema::hasColumn('movies', 'master_file_disk')) {
                $table->string('master_file_disk', 20)->default('s3')->after('master_file_path');
            }

            if (!Schema::hasColumn('movies', 'duration_seconds')) {
                $table->unsignedInteger('duration_seconds')->nullable()->after('master_file_disk');
            }

            if (!Schema::hasColumn('movies', 'drm_strategy')) {
                $table->enum('drm_strategy', ['none', 'diy_aes128', 'ezdrm'])
                    ->default('diy_aes128')
                    ->after('duration_seconds');
            }

            if (!Schema::hasColumn('movies', 'drm_config')) {
                $table->json('drm_config')->nullable()->after('drm_strategy')
                    ->comment('Key id refs, encrypted content keys, license server URLs');
            }

            if (!Schema::hasColumn('movies', 'hls_manifest_path')) {
                $table->string('hls_manifest_path')->nullable()->after('drm_config');
            }

            if (!Schema::hasColumn('movies', 'dash_manifest_path')) {
                $table->string('dash_manifest_path')->nullable()->after('hls_manifest_path');
            }

            if (!Schema::hasColumn('movies', 'cdn_disk')) {
                $table->string('cdn_disk', 20)->default('bunny')->after('dash_manifest_path');
            }

            if (!Schema::hasColumn('movies', 'geo_allow')) {
                $table->json('geo_allow')->nullable()->after('cdn_disk')
                    ->comment('Array of ISO-3166-1 alpha-2 country codes; null = worldwide');
            }
        });

        // Add index on encoding_status (separate closure to allow hasIndex check
        // via a try/catch — Laravel has no portable hasIndex helper).
        Schema::table('movies', function (Blueprint $table) {
            try {
                $table->index('encoding_status', 'movies_encoding_status_index');
            } catch (\Throwable $e) {
                // Index already exists — safe to ignore for idempotency.
            }
        });
    }

    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            try {
                $table->dropIndex('movies_encoding_status_index');
            } catch (\Throwable $e) {
                // Index missing — ignore.
            }

            $columns = [
                'geo_allow',
                'cdn_disk',
                'dash_manifest_path',
                'hls_manifest_path',
                'drm_config',
                'drm_strategy',
                'duration_seconds',
                'master_file_disk',
                'master_file_path',
                'encoding_renditions',
                'encoding_status',
            ];

            foreach ($columns as $col) {
                if (Schema::hasColumn('movies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
