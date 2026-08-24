<?php

namespace Tests\Feature\Salla;

use App\Admin\Actions\TransitionAdminOrder;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Imports\Salla\ImportSallaOrders;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ImportSallaOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_orders_with_multi_row_items_and_retains_non_sar_currency(): void
    {
        $customer = User::factory()->create([
            'phone' => '+966550924984',
        ]);

        $category = Category::query()->create([
            'slug' => 'fc-coins',
            'name_ar' => 'كوينز',
            'name_en' => 'Coins',
        ]);

        $product = Product::query()->create([
            'category_id' => $category->id,
            'slug' => 'fc-coins-ps5',
            'name_ar' => 'كوينز فيفا بلايستيشن',
            'name_en' => 'FIFA Coins PlayStation',
            'service_type' => ServiceType::Coins,
            'is_active' => true,
        ]);

        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'FC-100K-PS',
            'service_type' => ServiceType::Coins,
            'platform' => Platform::PlayStation,
            'price_halalah' => 5000,
            'is_active' => true,
        ]);

        $headers = implode(',', [
            'رقم الطلب',
            'حالة الطلب',
            'اسم العميل',
            'رقم الجوال',
            'طريقة الدفع',
            'حالة الدفع',
            'تاريخ الطلب',
            'رمز الكوبون',
            'مجموع السلة (على مستوى الطلب)',
            'الضريبة',
            'العملة',
            'اسم المنتج',
            'SKU',
            'الكمية',
            'سعر المنتج',
            'سعر المنتج قبل الخصم',
            'سعر المنتج بعد الخصم',
            'الخصم (على مستوى المنتج)',
            'رقم الفاتورة',
        ]);

        $rows = [
            $headers,
            // Multi-row order 1001 with 2 items in KWD currency
            '1001,تم التنفيذ,سعود,+966550924984,knet,paid,2026-01-26 10:00:00,,16.00,0,KWD,كوينز فيفا بلايستيشن,FC-100K-PS,2,8.00,8.00,8.00,0,INV-1001',
            '1001,تم التنفيذ,سعود,+966550924984,knet,paid,2026-01-26 10:00:00,,16.00,0,KWD,منتج غير موجود بالمتجر,UNKNOWN-SKU,1,0.00,0.00,0.00,0,INV-1001',
            // Order 1002 in SAR
            '1002,ملغي,سعود,+966550924984,mada,failed,2026-01-27 12:00:00,,39.20,0,SAR,كوينز فيفا بلايستيشن,FC-100K-PS,1,39.20,39.20,39.20,0,INV-1002',
        ];

        $tempFile = tempnam(sys_get_temp_dir(), 'salla_orders_');
        file_put_contents($tempFile, implode("\n", $rows));

        $action = app(ImportSallaOrders::class);

        // First run
        $report1 = $action->execute($tempFile, dryRun: false);

        $this->assertSame(3, $report1['total_rows']);
        $this->assertSame(2, $report1['total_orders']);
        $this->assertSame(2, $report1['created']);
        $this->assertSame(0, $report1['skipped']);
        $this->assertSame(0, $report1['unmatched_customer']);

        // Check order 1001
        $order1 = Order::query()->where('order_number', '1001')->with('items')->first();
        $this->assertNotNull($order1);
        $this->assertSame($customer->id, $order1->user_id);
        $this->assertSame('salla_import', $order1->channel);
        $this->assertSame(OrderStatus::Completed, $order1->status);
        $this->assertSame('KWD', $order1->currency);
        $this->assertSame(1600, $order1->total_halalah);
        $this->assertCount(2, $order1->items);

        // First item (matched live variant)
        $item1 = $order1->items[0];
        $this->assertSame($variant->id, $item1->product_variant_id);
        $this->assertSame('FC-100K-PS', $item1->sku);
        $this->assertSame(2, $item1->quantity);
        $this->assertSame(800, $item1->unit_price_halalah);
        $this->assertSame(1600, $item1->total_halalah);

        // Second item (unmatched variant, fallback snapshot)
        $item2 = $order1->items[1];
        $this->assertNull($item2->product_variant_id);
        $this->assertSame('UNKNOWN-SKU', $item2->sku);
        $this->assertSame('منتج غير موجود بالمتجر', $item2->name_ar);

        // Check order 1002 (cancelled, SAR, exact 39.2 -> 3920 halalah)
        $order2 = Order::query()->where('order_number', '1002')->first();
        $this->assertNotNull($order2);
        $this->assertSame(OrderStatus::Cancelled, $order2->status);
        $this->assertSame('SAR', $order2->currency);
        $this->assertSame(3920, $order2->total_halalah);

        // External refs created
        $this->assertDatabaseHas('external_refs', [
            'source' => 'salla',
            'entity' => 'order',
            'external_id' => '1001',
            'internal_id' => $order1->id,
        ]);
        $this->assertDatabaseHas('external_refs', [
            'source' => 'salla',
            'entity' => 'order',
            'external_id' => '1002',
            'internal_id' => $order2->id,
        ]);

        // Re-run must create nothing new (idempotent)
        $report2 = $action->execute($tempFile, dryRun: false);
        $this->assertSame(0, $report2['created']);
        $this->assertSame(2, $report2['skipped']);

        @unlink($tempFile);
    }

    public function test_skips_orders_with_unmatched_mobile(): void
    {
        $headers = implode(',', [
            'رقم الطلب', 'حالة الطلب', 'اسم العميل', 'رقم الجوال', 'طريقة الدفع', 'حالة الدفع',
            'تاريخ الطلب', 'رمز الكوبون', 'مجموع السلة (على مستوى الطلب)', 'الضريبة', 'العملة',
            'اسم المنتج', 'SKU', 'الكمية', 'سعر المنتج', 'سعر المنتج قبل الخصم', 'سعر المنتج بعد الخصم',
            'الخصم (على مستوى المنتج)', 'رقم الفاتورة',
        ]);

        $rows = [
            $headers,
            '2001,تم التنفيذ,شخص مجهول,+966559999999,visa,paid,2026-01-26 10:00:00,,50.00,0,SAR,منتج,SKU1,1,50.00,50.00,50.00,0,INV-2001',
        ];

        $tempFile = tempnam(sys_get_temp_dir(), 'salla_orders_');
        file_put_contents($tempFile, implode("\n", $rows));

        $action = app(ImportSallaOrders::class);
        $report = $action->execute($tempFile, dryRun: false);

        $this->assertSame(1, $report['total_orders']);
        $this->assertSame(0, $report['created']);
        $this->assertSame(1, $report['skipped']);
        $this->assertSame(1, $report['unmatched_customer']);

        $this->assertDatabaseMissing('orders', ['order_number' => '2001']);
        $this->assertDatabaseMissing('external_refs', ['external_id' => '2001']);

        @unlink($tempFile);
    }

    public function test_imported_orders_cannot_be_transitioned_in_admin(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        $customer = User::factory()->create(['phone' => '+966550924984']);

        $order = Order::factory()->for($customer)->create([
            'status' => OrderStatus::Received,
            'channel' => 'salla_import',
        ]);

        $this->expectException(ValidationException::class);

        app(TransitionAdminOrder::class)->execute(
            $admin,
            (string) $order->public_id,
            OrderStatus::InProgress,
            OrderStatus::Received,
        );
    }

    public function test_dry_run_writes_nothing_to_database(): void
    {
        User::factory()->create(['phone' => '+966550924984']);

        $headers = implode(',', [
            'رقم الطلب', 'حالة الطلب', 'اسم العميل', 'رقم الجوال', 'طريقة الدفع', 'حالة الدفع',
            'تاريخ الطلب', 'رمز الكوبون', 'مجموع السلة (على مستوى الطلب)', 'الضريبة', 'العملة',
            'اسم المنتج', 'SKU', 'الكمية', 'سعر المنتج', 'سعر المنتج قبل الخصم', 'سعر المنتج بعد الخصم',
            'الخصم (على مستوى المنتج)', 'رقم الفاتورة',
        ]);

        $rows = [
            $headers,
            '3001,تم التنفيذ,سعود,+966550924984,visa,paid,2026-01-26 10:00:00,,50.00,0,SAR,منتج,SKU1,1,50.00,50.00,50.00,0,INV-3001',
        ];

        $tempFile = tempnam(sys_get_temp_dir(), 'salla_orders_');
        file_put_contents($tempFile, implode("\n", $rows));

        $action = app(ImportSallaOrders::class);
        $report = $action->execute($tempFile, dryRun: true);

        $this->assertTrue($report['dry_run']);
        $this->assertSame(1, $report['created']);
        $this->assertNull($report['batch_id']);

        $this->assertDatabaseMissing('orders', ['order_number' => '3001']);
        $this->assertDatabaseMissing('external_refs', ['external_id' => '3001']);
        $this->assertSame(0, ImportBatch::query()->count());

        @unlink($tempFile);
    }
}
