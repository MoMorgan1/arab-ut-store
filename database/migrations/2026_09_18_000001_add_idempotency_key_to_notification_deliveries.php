<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The durable delivery claim for customer notifications.
     *
     * The same item, the same template and the same transition is one row:
     * `customer-notify:{subject}:{template}:{historyId}`. A second attempt to
     * insert it is a replay rather than a duplicate, and the unique index is
     * what makes that true rather than hoped for. A genuine recurrence after
     * recovery writes a new history row, so it earns a new key and a new
     * message.
     */
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->unique()->after('template_key');
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
