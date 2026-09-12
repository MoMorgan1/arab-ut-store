<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fulfillment_jobs', function (Blueprint $table): void {
            $table->string('delivery_phase')->nullable()->after('completed_at');
            $table->string('observed_state')->nullable()->after('delivery_phase');
            $table->json('observation')->nullable()->after('observed_state');
            $table->timestamp('observed_at')->nullable()->after('observation');
            $table->boolean('observation_supported')->default(true)->after('observed_at');
            $table->unsignedBigInteger('coins_delivered')->nullable()->after('observation_supported');
            $table->unsignedBigInteger('coins_ordered')->nullable()->after('coins_delivered');
            $table->unsignedInteger('challenges_solved')->nullable()->after('coins_ordered');
            $table->unsignedInteger('challenges_requested')->nullable()->after('challenges_solved');
            $table->string('hold_reason')->nullable()->after('challenges_requested');
            $table->json('allowed_actions')->nullable()->after('hold_reason');
            $table->timestamp('last_viewed_at')->nullable()->after('allowed_actions');
            $table->string('lease_token', 64)->nullable()->after('last_viewed_at');
            $table->timestamp('leased_until')->nullable()->after('lease_token');
            $table->unsignedInteger('poll_failure_count')->default(0)->after('leased_until');
            $table->index(['status', 'last_viewed_at']);
            $table->index('leased_until');
        });
    }

    public function down(): void
    {
        Schema::table('fulfillment_jobs', function (Blueprint $table): void {
            $table->dropIndex(['status', 'last_viewed_at']);
            $table->dropIndex(['leased_until']);
            $table->dropColumn([
                'poll_failure_count',
                'leased_until',
                'lease_token',
                'last_viewed_at',
                'allowed_actions',
                'hold_reason',
                'challenges_requested',
                'challenges_solved',
                'coins_ordered',
                'coins_delivered',
                'observation_supported',
                'observed_at',
                'observation',
                'observed_state',
                'delivery_phase',
            ]);
        });
    }
};
