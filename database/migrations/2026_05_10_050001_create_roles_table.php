<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('roles')) {
            return;
        }

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            // Slug-style identifier, e.g. 'super_admin', 'content_editor'.
            $table->string('name', 60)->unique();
            // Human-readable label rendered in admin UI.
            $table->string('display_name', 120);
            $table->text('description')->nullable();
            // System roles are seeded by the app and cannot be deleted via
            // /admin/roles — guard enforced in the controller/model layer.
            $table->boolean('is_system')->default(false);
            // Lower number = higher priority; used to order role pickers and
            // to break ties when a user has multiple roles.
            $table->integer('priority')->default(100);
            $table->timestamps();

            $table->index(['is_system', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
