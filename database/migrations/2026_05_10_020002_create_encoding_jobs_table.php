<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks ffmpeg/packaging job lifecycle for a movie's master file.
 *
 * Each row represents one distinct encoding attempt. Multiple rows per
 * movie are expected (re-encodes, format additions). The latest
 * `completed` row determines what `movies.encoding_renditions` reflects.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('encoding_jobs')) {
            return;
        }

        Schema::create('encoding_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')->constrained()->cascadeOnDelete();
            $table->enum('status', [
                'queued',
                'transcoding',
                'encrypting',
                'uploading',
                'completed',
                'failed',
            ])->default('queued');
            $table->json('rendition_specs')->nullable()
                ->comment('Requested ladder, e.g. [{height:480,bitrate:800k},...]');
            $table->json('output_paths')->nullable()
                ->comment('Resulting per-rendition file paths on cdn_disk');
            $table->text('error_message')->nullable();
            $table->unsignedInteger('progress_percent')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['movie_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encoding_jobs');
    }
};
