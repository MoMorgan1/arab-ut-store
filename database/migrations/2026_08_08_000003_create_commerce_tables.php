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
        'cart_items' => ['unit_price_halalah', 'total_halalah'],
        'coupons' => ['value', 'minimum_order_halalah', 'maximum_discount_halalah'],
        'loyalty_tiers' => ['minimum_lifetime_spend_halalah'],
        'orders' => ['subtotal_halalah', 'discount_halalah', 'wallet_halalah', 'payment_halalah', 'total_halalah'],
        'order_items' => ['unit_price_halalah', 'subtotal_halalah', 'discount_halalah', 'total_halalah'],
        'order_discounts' => ['amount_halalah'],
        'payments' => ['amount_halalah', 'captured_halalah', 'refunded_halalah'],
        'refunds' => ['amount_halalah'],
        'wallet_accounts' => ['balance_halalah'],
        'wallet_entries' => ['amount_halalah', 'balance_after_halalah'],
        'receipts' => ['total_halalah'],
    ];

    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('session_key')->nullable()->unique();
            $table->string('status')->default('active')->index();
            $table->string('currency', 3)->default('SAR');
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $this->nonnegativeMoneyColumn($table, 'unit_price_halalah');
            $this->nonnegativeMoneyColumn($table, 'total_halalah');
            $table->json('configuration')->nullable();
            $table->timestamps();
            $table->index(['cart_id', 'product_variant_id']);
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code')->unique();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('discount_type');
            $this->nonnegativeMoneyColumn($table, 'value');
            $this->nonnegativeMoneyColumn($table, 'minimum_order_halalah')->default(0);
            $this->nonnegativeMoneyColumn($table, 'maximum_discount_halalah')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_user_limit')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('key')->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->unsignedInteger('rank')->unique();
            $this->nonnegativeMoneyColumn($table, 'minimum_lifetime_spend_halalah');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('order_number')->unique();
            $table->string('status')->index();
            $table->string('locale', 2)->default('ar');
            $table->string('currency', 3)->default('SAR');
            $this->nonnegativeMoneyColumn($table, 'subtotal_halalah');
            $this->nonnegativeMoneyColumn($table, 'discount_halalah')->default(0);
            $this->nonnegativeMoneyColumn($table, 'wallet_halalah')->default(0);
            $this->nonnegativeMoneyColumn($table, 'payment_halalah')->default(0);
            $this->nonnegativeMoneyColumn($table, 'total_halalah');
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index('user_id');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku');
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('service_type');
            $table->string('platform');
            $table->string('status');
            $table->unsignedInteger('quantity')->default(1);
            $this->nonnegativeMoneyColumn($table, 'unit_price_halalah');
            $this->nonnegativeMoneyColumn($table, 'subtotal_halalah');
            $this->nonnegativeMoneyColumn($table, 'discount_halalah')->default(0);
            $this->nonnegativeMoneyColumn($table, 'total_halalah');
            $table->json('configuration')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
        });

        Schema::create('order_discounts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('label_ar');
            $table->string('label_en');
            $this->nonnegativeMoneyColumn($table, 'amount_halalah');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('provider');
            $table->string('provider_payment_id')->nullable();
            $table->string('status')->index();
            $table->string('currency', 3)->default('SAR');
            $this->nonnegativeMoneyColumn($table, 'amount_halalah');
            $this->nonnegativeMoneyColumn($table, 'captured_halalah')->default(0);
            $this->nonnegativeMoneyColumn($table, 'refunded_halalah')->default(0);
            $table->string('idempotency_key')->unique();
            $table->json('provider_metadata')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_payment_id']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('method');
            $table->string('status')->index();
            $this->nonnegativeMoneyColumn($table, 'amount_halalah');
            $table->text('reason_ar')->nullable();
            $table->text('reason_en')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('wallet_accounts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $this->nonnegativeMoneyColumn($table, 'balance_halalah')->default(0);
            $table->timestamps();
        });

        Schema::create('wallet_entries', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('wallet_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('refund_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('type')->index();
            $this->nonnegativeMoneyColumn($table, 'amount_halalah');
            $this->nonnegativeMoneyColumn($table, 'balance_after_halalah');
            $table->string('reference')->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['wallet_account_id', 'created_at']);
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('coupon_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->timestamp('redeemed_at')->useCurrent();
            $table->unique(['coupon_id', 'order_id']);
            $table->index(['coupon_id', 'user_id']);
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->index();
            $table->text('note_ar')->nullable();
            $table->text('note_en')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['order_id', 'created_at']);
        });

        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->string('receipt_number')->unique();
            $table->string('currency', 3)->default('SAR');
            $this->nonnegativeMoneyColumn($table, 'total_halalah');
            $table->string('storage_path');
            $table->string('content_hash', 64);
            $table->timestamp('issued_at')->index();
            $table->timestamps();
        });

        $this->enforceNonnegativeMoney();
        $this->protectWalletLedger();
    }

    public function down(): void
    {
        foreach (['receipts', 'order_status_history', 'coupon_redemptions', 'wallet_entries', 'wallet_accounts', 'refunds', 'payments', 'order_discounts', 'order_items', 'orders', 'loyalty_tiers', 'coupons', 'cart_items', 'carts'] as $table) {
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

    private function protectWalletLedger(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement("CREATE TRIGGER wallet_entries_immutable_update BEFORE UPDATE ON wallet_entries BEGIN SELECT RAISE(ABORT, 'wallet entries are immutable'); END");
            DB::statement("CREATE TRIGGER wallet_entries_immutable_delete BEFORE DELETE ON wallet_entries BEGIN SELECT RAISE(ABORT, 'wallet entries are immutable'); END");
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared("CREATE TRIGGER wallet_entries_immutable_update BEFORE UPDATE ON wallet_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'wallet entries are immutable'");
            DB::unprepared("CREATE TRIGGER wallet_entries_immutable_delete BEFORE DELETE ON wallet_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'wallet entries are immutable'");
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
