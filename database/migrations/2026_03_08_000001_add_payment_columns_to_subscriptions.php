<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('order_id')->nullable()->after('transaction_id');
            $table->decimal('amount', 12, 2)->default(0)->after('order_id');
            $table->timestamp('paid_at')->nullable()->after('amount');
            $table->integer('duration_days')->default(30)->after('paid_at');
        });

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->integer('duration_days')->default(30)->after('billing_cycle');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['order_id', 'amount', 'paid_at', 'duration_days']);
        });
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('duration_days');
        });
    }
};
