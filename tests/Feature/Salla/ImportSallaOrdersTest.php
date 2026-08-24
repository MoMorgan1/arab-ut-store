<?php

namespace Tests\Feature\Salla;

use App\Admin\Actions\TransitionAdminOrder;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Imports\Salla\ImportSallaOrders;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\ExternalRef;
use App\Models\ImportBatch;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ImportSallaOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_orders_with_multi_row_items_and_stores_them_in_sar(): void
    {
        $customer = User::factory()->create([
            'phone' => '+966550924984',
        ]);

        // The customer pass runs first in production and records the link. The
        // orders pass now requires it, so that a Salla customer the first pass
        // REFUSED to identify cannot be matched on a bare phone number and have
        // a stranger's orders filed against them.
        ExternalRef::query()->create([
            'source' => 'salla',
            'entity' => 'customer',
            'external_id' => '2026234260',
            'internal_id' => $customer->id,
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
        // Owner rule: cancelled orders are not imported at all, so of the two
        // orders in this file only the completed one comes across.
        $this->assertSame(2, $report1['total_orders']);
        $this->assertSame(1, $report1['created']);
        $this->assertSame(1, $report1['skipped']);
        $this->assertSame(1, $report1['skipped_not_completed']);
        $this->assertSame(0, $report1['unmatched_customer']);

        // Check order 1001
        $order1 = Order::query()->where('order_number', '1001')->with('items')->first();
        $this->assertNotNull($order1);
        $this->assertSame($customer->id, $order1->user_id);
        $this->assertSame('salla_import', $order1->channel);
        $this->assertSame(OrderStatus::Completed, $order1->status);
        // No KWD rate is seeded here, so the total falls back to the item
        // prices - which Salla already quotes in SAR. The order is stored in
        // SAR either way; an order left in a foreign currency would count as
        // nothing toward lifetime spend and loyalty tiers.
        $this->assertSame('SAR', $order1->currency);
        $this->assertSame(1600, $order1->total_halalah);
        $this->assertSame('item_prices', $order1->import_metadata['basis']);
        $this->assertSame('KWD', $order1->import_metadata['original_currency']);
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

        // Order 1002 is cancelled in the export, so it must not exist here at
        // all - importing it as cancelled would put a failed sale in the history.
        $this->assertNull(Order::query()->where('order_number', '1002')->first());

        // External refs created
        $this->assertDatabaseHas('external_refs', [
            'source' => 'salla',
            'entity' => 'order',
            'external_id' => '1001',
            'internal_id' => $order1->id,
        ]);
        // A skipped order gets no external ref either, so a later corrected
        // export could still bring it in if the owner wanted it.
        $this->assertDatabaseMissing('external_refs', [
            'source' => 'salla',
            'entity' => 'order',
            'external_id' => '1002',
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

    public function test_orders_are_not_filed_against_a_customer_the_first_pass_refused_to_identify(): void
    {
        // A real person who happens to hold the mobile number. The customer pass
        // hit an email/phone conflict for this Salla customer and refused to
        // link anyone, so no external_ref exists.
        User::factory()->create(['phone' => '+966550924984']);

        $headers = implode(',', [
            'رقم الطلب', 'حالة الطلب', 'اسم العميل', 'رقم الجوال', 'طريقة الدفع', 'حالة الدفع',
            'تاريخ الطلب', 'رمز الكوبون', 'مجموع السلة (على مستوى الطلب)', 'الضريبة', 'العملة',
            'اسم المنتج', 'SKU', 'الكمية', 'سعر المنتج', 'سعر المنتج قبل الخصم', 'سعر المنتج بعد الخصم',
            'الخصم (على مستوى المنتج)', 'رقم الفاتورة',
        ]);

        $rows = [
            $headers,
            '4001,تم التنفيذ,شخص آخر,+966550924984,visa,paid,2026-01-26 10:00:00,,50.00,0,SAR,منتج,SKU1,1,50.00,50.00,50.00,0,INV-4001',
        ];

        $tempFile = tempnam(sys_get_temp_dir(), 'salla_orders_');
        file_put_contents($tempFile, implode("\n", $rows));

        $report = app(ImportSallaOrders::class)->execute($tempFile, dryRun: false);

        // Matching on the bare phone would file a stranger's order against this
        // account, and their order history would then be visible to the wrong
        // person. Skipping is the only safe outcome.
        $this->assertSame(0, $report['created']);
        $this->assertSame(1, $report['unmatched_customer']);
        $this->assertDatabaseMissing('orders', ['order_number' => '4001']);

        @unlink($tempFile);
    }

    public function test_orders_never_attach_to_a_staff_account_sharing_the_number(): void
    {
        $staff = User::factory()->create([
            'phone' => '+966551112223',
            'role' => UserRole::Staff,
        ]);

        // Even with a link present, a non-customer account must never receive
        // imported order history.
        ExternalRef::query()->create([
            'source' => 'salla',
            'entity' => 'customer',
            'external_id' => '7777',
            'internal_id' => $staff->id,
        ]);

        $headers = implode(',', [
            'رقم الطلب', 'حالة الطلب', 'اسم العميل', 'رقم الجوال', 'طريقة الدفع', 'حالة الدفع',
            'تاريخ الطلب', 'رمز الكوبون', 'مجموع السلة (على مستوى الطلب)', 'الضريبة', 'العملة',
            'اسم المنتج', 'SKU', 'الكمية', 'سعر المنتج', 'سعر المنتج قبل الخصم', 'سعر المنتج بعد الخصم',
            'الخصم (على مستوى المنتج)', 'رقم الفاتورة',
        ]);

        $rows = [
            $headers,
            '4002,تم التنفيذ,موظف,+966551112223,visa,paid,2026-01-26 10:00:00,,50.00,0,SAR,منتج,SKU1,1,50.00,50.00,50.00,0,INV-4002',
        ];

        $tempFile = tempnam(sys_get_temp_dir(), 'salla_orders_');
        file_put_contents($tempFile, implode("\n", $rows));

        $report = app(ImportSallaOrders::class)->execute($tempFile, dryRun: false);

        $this->assertSame(0, $report['created']);
        $this->assertDatabaseMissing('orders', ['order_number' => '4002']);

        @unlink($tempFile);
    }

    public function test_a_non_sar_order_totals_from_its_sar_item_prices_and_keeps_its_provenance(): void
    {
        $customer = User::factory()->create(['phone' => '+966550924984']);
        ExternalRef::query()->create([
            'source' => 'salla', 'entity' => 'customer',
            'external_id' => '5001', 'internal_id' => $customer->id,
        ]);

        // Quoted as foreign units per one SAR, matching the app's own table.
        ExchangeRate::query()->create([
            'public_id' => (string) Str::ulid(),
            'base_currency' => 'SAR',
            'quote_currency' => 'KWD',
            'rate' => '0.08190000',
            'source' => 'test',
            'fetched_at' => now(),
        ]);

        $headers = implode(',', [
            'رقم الطلب', 'حالة الطلب', 'اسم العميل', 'رقم الجوال', 'طريقة الدفع', 'حالة الدفع',
            'تاريخ الطلب', 'رمز الكوبون', 'مجموع السلة (على مستوى الطلب)', 'الضريبة', 'العملة',
            'اسم المنتج', 'SKU', 'الكمية', 'سعر المنتج', 'سعر المنتج قبل الخصم', 'سعر المنتج بعد الخصم',
            'الخصم (على مستوى المنتج)', 'رقم الفاتورة',
        ]);

        $rows = [
            $headers,
            '5100,تم التنفيذ,عميل,+966550924984,visa,paid,2026-01-26 10:00:00,,8.37,0,KWD,منتج,SKU1,1,102,102,102,0,INV-5100',
        ];

        $tempFile = tempnam(sys_get_temp_dir(), 'salla_orders_');
        file_put_contents($tempFile, implode("\n", $rows));

        app(ImportSallaOrders::class)->execute($tempFile, dryRun: false);

        $order = Order::query()->where('order_number', '5100')->sole();

        // The line is priced at 102, and Salla quotes item prices in SAR, so
        // that IS the SAR total - no rate needed. Left in KWD this order
        // would contribute nothing to lifetime spend, because every tier
        // query filters on SAR.
        $this->assertSame('SAR', $order->currency);
        $this->assertSame(10200, $order->total_halalah);

        // Conversion is lossy and one-way, so what it was must survive.
        $this->assertSame('KWD', $order->import_metadata['original_currency']);
        $this->assertSame(837, $order->import_metadata['original_total_minor']);
        $this->assertSame('item_prices', $order->import_metadata['basis']);
        // No rate was involved, so none is recorded.
        $this->assertNull($order->import_metadata['rate_foreign_per_sar']);

        @unlink($tempFile);
    }

    public function test_line_items_are_left_alone_because_salla_already_prices_them_in_sar(): void
    {
        $customer = User::factory()->create(['phone' => '+966550924984']);
        ExternalRef::query()->create([
            'source' => 'salla', 'entity' => 'customer',
            'external_id' => '5003', 'internal_id' => $customer->id,
        ]);

        ExchangeRate::query()->create([
            'public_id' => (string) Str::ulid(),
            'base_currency' => 'SAR',
            'quote_currency' => 'KWD',
            'rate' => '0.08190000',
            'source' => 'test',
            'fetched_at' => now(),
        ]);

        $headers = implode(',', [
            'رقم الطلب', 'حالة الطلب', 'اسم العميل', 'رقم الجوال', 'طريقة الدفع', 'حالة الدفع',
            'تاريخ الطلب', 'رمز الكوبون', 'مجموع السلة (على مستوى الطلب)', 'الضريبة', 'العملة',
            'اسم المنتج', 'SKU', 'الكمية', 'سعر المنتج', 'سعر المنتج قبل الخصم', 'سعر المنتج بعد الخصم',
            'الخصم (على مستوى المنتج)', 'رقم الفاتورة',
        ]);

        // 16.74 KWD cart total, two units listed at 102 - and 102 SAR is 8.35
        // KWD, so the item price is SAR while only the cart total is KWD.
        $rows = [
            $headers,
            '5300,تم التنفيذ,عميل,+966550924984,visa,paid,2026-01-26 10:00:00,,16.74,0,KWD,منتج,SKU1,2,102,102,102,0,INV-5300',
        ];

        $tempFile = tempnam(sys_get_temp_dir(), 'salla_orders_');
        file_put_contents($tempFile, implode('
', $rows));

        app(ImportSallaOrders::class)->execute($tempFile, dryRun: false);

        $order = Order::query()->where('order_number', '5300')->sole();
        $item = $order->items()->sole();

        // Converting the item would bill this line at 1,245.42.
        $this->assertSame(10200, $item->unit_price_halalah);
        $this->assertSame(20400, $item->subtotal_halalah);

        // Order-level subtotal and discount are summed FROM the items, so they
        // are already SAR too and must not be converted a second time.
        $this->assertSame(20400, $order->subtotal_halalah);
        $this->assertSame(0, $order->discount_halalah);

        // The order total is the item sum, so the two always agree.
        $this->assertSame('SAR', $order->currency);
        $this->assertSame(20400, $order->total_halalah);

        @unlink($tempFile);
    }

    public function test_an_order_with_unpriced_lines_falls_back_to_converting_the_cart_total(): void
    {
        $customer = User::factory()->create(['phone' => '+966550924984']);
        ExternalRef::query()->create([
            'source' => 'salla', 'entity' => 'customer',
            'external_id' => '5004', 'internal_id' => $customer->id,
        ]);

        ExchangeRate::query()->create([
            'public_id' => (string) Str::ulid(),
            'base_currency' => 'SAR',
            'quote_currency' => 'KWD',
            'rate' => '0.08190000',
            'source' => 'test',
            'fetched_at' => now(),
        ]);

        $headers = implode(',', [
            'رقم الطلب', 'حالة الطلب', 'اسم العميل', 'رقم الجوال', 'طريقة الدفع', 'حالة الدفع',
            'تاريخ الطلب', 'رمز الكوبون', 'مجموع السلة (على مستوى الطلب)', 'الضريبة', 'العملة',
            'اسم المنتج', 'SKU', 'الكمية', 'سعر المنتج', 'سعر المنتج قبل الخصم', 'سعر المنتج بعد الخصم',
            'الخصم (على مستوى المنتج)', 'رقم الفاتورة',
        ]);

        // The line carries no price, so the cart total is the only figure
        // there is - and that one really is in KWD.
        $rows = [
            $headers,
            '5400,تم التنفيذ,عميل,+966550924984,visa,paid,2026-01-26 10:00:00,,8.37,0,KWD,منتج,SKU1,1,0,0,0,0,INV-5400',
        ];

        $tempFile = tempnam(sys_get_temp_dir(), 'salla_orders_');
        file_put_contents($tempFile, implode('
', $rows));

        app(ImportSallaOrders::class)->execute($tempFile, dryRun: false);

        $order = Order::query()->where('order_number', '5400')->sole();

        // 8.37 / 0.0819 = 102.20.
        $this->assertSame('SAR', $order->currency);
        $this->assertSame(10220, $order->total_halalah);
        $this->assertSame('exchange_rate', $order->import_metadata['basis']);
        $this->assertSame(837, $order->import_metadata['original_total_minor']);

        @unlink($tempFile);
    }

    public function test_a_currency_with_no_rate_falls_back_to_the_sar_item_prices(): void
    {
        $customer = User::factory()->create(['phone' => '+966550924984']);
        ExternalRef::query()->create([
            'source' => 'salla', 'entity' => 'customer',
            'external_id' => '5002', 'internal_id' => $customer->id,
        ]);

        $headers = implode(',', [
            'رقم الطلب', 'حالة الطلب', 'اسم العميل', 'رقم الجوال', 'طريقة الدفع', 'حالة الدفع',
            'تاريخ الطلب', 'رمز الكوبون', 'مجموع السلة (على مستوى الطلب)', 'الضريبة', 'العملة',
            'اسم المنتج', 'SKU', 'الكمية', 'سعر المنتج', 'سعر المنتج قبل الخصم', 'سعر المنتج بعد الخصم',
            'الخصم (على مستوى المنتج)', 'رقم الفاتورة',
        ]);

        $rows = [
            $headers,
            '5200,تم التنفيذ,عميل,+966550924984,visa,paid,2026-01-26 10:00:00,,1833.08,0,EGP,منتج,SKU1,1,120,120,120,0,INV-5200',
        ];

        $tempFile = tempnam(sys_get_temp_dir(), 'salla_orders_');
        file_put_contents($tempFile, implode("\n", $rows));

        app(ImportSallaOrders::class)->execute($tempFile, dryRun: false);

        $order = Order::query()->where('order_number', '5200')->sole();

        // No EGP rate exists, and inventing one for a currency that has moved
        // by half since 2024 would be worse than using what we already have:
        // the item price, which Salla quotes in SAR.
        $this->assertSame('SAR', $order->currency);
        $this->assertSame(12000, $order->total_halalah);
        $this->assertSame('item_prices', $order->import_metadata['basis']);
        $this->assertSame('EGP', $order->import_metadata['original_currency']);
        $this->assertSame(183308, $order->import_metadata['original_total_minor']);
        $this->assertNull($order->import_metadata['rate_foreign_per_sar']);

        @unlink($tempFile);
    }

    public function test_dry_run_writes_nothing_to_database(): void
    {
        $customer = User::factory()->create(['phone' => '+966550924984']);

        // The orders pass only links customers the customer pass identified.
        ExternalRef::query()->create([
            'source' => 'salla',
            'entity' => 'customer',
            'external_id' => '9001',
            'internal_id' => $customer->id,
        ]);

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
