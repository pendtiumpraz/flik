<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('permissions')) {
            return;
        }

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            // Dot-namespaced verb, e.g. 'movies.create', 'security.audit_logs'.
            $table->string('name', 80)->unique();
            $table->string('display_name', 150);
            // Grouping label used by Permission::groupedByCategory() and the
            // admin role editor UI (one panel per category).
            $table->string('category', 40);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
