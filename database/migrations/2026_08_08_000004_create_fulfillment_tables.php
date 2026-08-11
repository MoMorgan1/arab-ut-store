<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, list<string>> */
    private array $nonnegativeColumns = [
        'fulfillment_jobs' => ['actual_cost_halalah'],
        'fulfillment_attempts' => ['actual_cost_halalah'],
    ];

    public function up(): void
    {
        Schema::create('order_item_secrets', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->longText('encrypted_payload');
            $table->json('masked_summary')->nullable();
            $table->timestamp('retained_until')->nullable()->index();
            $table->timestamp('deleted_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('secret_access_logs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_item_secret_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('accessed_at')->useCurrent();
            $table->index(['order_item_secret_id', 'accessed_at']);
        });

        Schema::create('fulfillment_jobs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_item_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status')->index();
            $table->string('supplier')->nullable()->index();
            $table->string('supplier_order_id')->nullable();
            $table->string('idempotency_key')->unique();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('next_poll_at')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error')->nullable();
            $this->nonnegativeMoneyColumn($table, 'actual_cost_halalah')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_poll_at']);
            $table->unique(['supplier', 'supplier_order_id']);
        });

        Schema::create('fulfillment_attempts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('fulfillment_job_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('status')->index();
            $table->json('request_metadata')->nullable();
            $table->json('response_metadata')->nullable();
            $this->nonnegativeMoneyColumn($table, 'actual_cost_halalah')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['fulfillment_job_id', 'attempt_number']);
        });

        Schema::create('integration_events', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->ulid('event_id')->unique();
            $table->string('event_type')->index();
            $table->string('aggregate_type');
            $table->string('aggregate_id');
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->json('payload');
            $table->string('signature_hash', 64)->nullable();
            $table->string('status')->default('pending');
            $table->string('idempotency_key')->unique();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['aggregate_type', 'aggregate_id']);
            $table->index(['status', 'available_at']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('integration_event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel');
            $table->string('template_key');
            $table->string('locale', 2)->default('ar');
            $table->string('status')->default('queued');
            $table->string('recipient_masked');
            $table->string('provider_message_id')->nullable()->unique();
            $table->json('payload')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('key')->unique();
            $table->string('scope')->index();
            $table->string('request_hash', 64)->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('created_at')->useCurrent();
        });

        $this->enforceNonnegativeMoney();
    }

    public function down(): void
    {
        foreach (['idempotency_keys', 'notification_deliveries', 'integration_events', 'fulfillment_attempts', 'fulfillment_jobs', 'secret_access_logs', 'order_item_secrets'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function enforceNonnegativeMoney(): void
    {
        $driver = DB::connection()->getDriverName();

        foreach ($this->nonnegativeColumns as $table => $columns) {
            foreach ($columns as $column) {
                $name = "{$table}_{$column}_nonnegative";

                if (in_array($driver, ['mysql', 'mariadb'], true)) {
                    DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$column} BETWEEN 0 AND 9223372036854775807)");
                }
            }
        }
    }

    private function nonnegativeMoneyColumn(Blueprint $table, string $column): ColumnDefinition
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return $table->rawColumn($column, "integer check ({$column} between 0 and 9223372036854775807)");
        }

        return $table->bigInteger($column);
    }
};
