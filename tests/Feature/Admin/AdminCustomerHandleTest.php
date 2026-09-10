<?php

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\User;
use App\Support\PublicHandle\CustomerHandle;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

test('a legacy ULID admin customer URL is permanently redirected to the customer number', function (string $prefix): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $customer = User::factory()->create(['role' => UserRole::Customer]);

    $this->actingAs($admin)
        ->get("{$prefix}/customers/{$customer->public_id}")
        ->assertStatus(301)
        ->assertRedirect("{$prefix}/customers/{$customer->customer_number}");
})->with([
    'default prefix' => ['/admin'],
    'localized prefix' => ['/en/admin'],
]);

test('the handle helper falls back to the internal public id when a customer has no number', function (): void {
    $publicId = (string) Str::ulid();

    expect(CustomerHandle::handleForValues(null, $publicId))->toBe($publicId)
        ->and(CustomerHandle::handleForValues('', $publicId))->toBe($publicId)
        ->and(CustomerHandle::handleForValues('CUS-AB12CD', $publicId))->toBe('CUS-AB12CD');
});

test('the admin customer detail resolves by the customer number', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $customer = User::factory()->create(['role' => UserRole::Customer]);

    $this->actingAs($admin)
        ->get('/admin/customers/'.$customer->customer_number)
        ->assertOk();
});

test('an unknown customer handle returns 404', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);

    $this->actingAs($admin)
        ->get('/admin/customers/CUS-ZZZZZZ')
        ->assertNotFound();
});

test('a lowercase customer number still resolves the admin detail', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $customer = User::factory()->create(['role' => UserRole::Customer]);

    $this->actingAs($admin)
        ->get('/admin/customers/'.mb_strtolower((string) $customer->customer_number))
        ->assertOk();
});

test('a legacy ULID admin customer URL keeps its query string through the redirect', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $customer = User::factory()->create(['role' => UserRole::Customer]);

    $this->actingAs($admin)
        ->get("/admin/customers/{$customer->public_id}?tab=wallet")
        ->assertStatus(301)
        ->assertRedirect('/admin/customers/'.$customer->customer_number.'?tab=wallet');
});

test('an admin status change resolves a legacy ULID without a canonicalizing redirect', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $customer = User::factory()->create([
        'role' => UserRole::Customer,
        'is_active' => true,
    ]);

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$customer->public_id}/status", [
            'action' => 'suspend',
            'reason_code' => 'abuse',
            'expected_active' => true,
        ])
        ->assertOk();

    expect($customer->fresh()->is_active)->toBeFalse();
});

test('a legacy ULID for a non-customer account returns 404 from the customer detail', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $staff = createStaffTestActor(UserRole::Staff);

    $this->actingAs($admin)
        ->get("/admin/customers/{$staff->public_id}")
        ->assertNotFound();
});

test('status, contact, and wallet mutations resolve the customer number without a redirect', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $customer = User::factory()->create([
        'role' => UserRole::Customer,
        'phone' => null,
    ]);

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$customer->customer_number}/status", [
            'action' => 'suspend',
            'reason_code' => 'abuse',
            'expected_active' => true,
        ])
        ->assertSuccessful();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$customer->customer_number}/contact", [
            'first_name' => 'Updated',
            'last_name' => $customer->last_name,
            'email' => 'updated-'.$customer->id.'@example.test',
            'phone' => null,
            'expected' => [
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name,
                'email' => $customer->email,
                'phone' => null,
            ],
        ])
        ->assertSuccessful();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$customer->customer_number}/wallet/adjust", [
            'amount_halalah' => 1000,
            'reason' => 'Goodwill compensation for delay',
        ])
        ->assertSuccessful();
});

test('an unknown well-formed ULID returns 404 from the detail and every mutation endpoint', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $unknown = (string) Str::ulid();

    $this->actingAs($admin)
        ->get("/admin/customers/{$unknown}")
        ->assertNotFound();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$unknown}/status", [
            'action' => 'suspend',
            'reason_code' => 'abuse',
            'expected_active' => true,
        ])
        ->assertNotFound();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$unknown}/contact", [
            'first_name' => 'Ghost',
            'last_name' => 'Account',
            'email' => 'ghost@example.test',
            'phone' => null,
            'expected' => [
                'first_name' => 'Ghost',
                'last_name' => 'Account',
                'email' => 'ghost@example.test',
                'phone' => null,
            ],
        ])
        ->assertNotFound();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/customers/{$unknown}/wallet/adjust", [
            'amount_halalah' => 1000,
            'reason' => 'Goodwill compensation for delay',
        ])
        ->assertNotFound();
});

test('admin customer surfaces never emit an internal ULID customer href', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $customer = User::factory()->create(['role' => UserRole::Customer]);

    $order = Order::factory()->create([
        'user_id' => $customer->id,
        'status' => OrderStatus::Completed,
        'paid_at' => now(),
    ]);

    $coupon = Coupon::query()->create([
        'public_id' => (string) Str::ulid(),
        'code' => 'HANDLEGUARD1',
        'discount_type' => 'percent',
        'value' => 20,
        'minimum_order_halalah' => 0,
        'is_active' => true,
    ]);

    CouponRedemption::create([
        'coupon_id' => $coupon->id,
        'user_id' => $customer->id,
        'order_id' => $order->id,
        'redeemed_at' => now(),
    ]);

    OrderDiscount::create([
        'order_id' => $order->id,
        'coupon_id' => $coupon->id,
        'type' => 'percent',
        'label_ar' => 'خصم',
        'label_en' => 'Discount',
        'amount_halalah' => 1000,
    ]);

    $responses = [
        $this->actingAs($admin)->get('/admin/customers')->assertOk(),
        $this->actingAs($admin)->get("/admin/customers/{$customer->customer_number}")->assertOk(),
        $this->actingAs($admin)->get('/admin/orders')->assertOk(),
        $this->actingAs($admin)->get("/admin/orders/{$order->order_number}")->assertOk(),
        $this->actingAs($admin)->get("/admin/marketing/coupons/{$coupon->code}")->assertOk(),
    ];

    foreach ($responses as $response) {
        assertNoInternalCustomerUlidHrefs(adminCustomerHandleProps($response));
    }
});

/**
 * @return array<string, mixed>
 */
function adminCustomerHandleProps(TestResponse $response): array
{
    /** @var array{props?: array<string, mixed>} $page */
    $page = $response->viewData('page');

    return $page['props'] ?? [];
}

function assertNoInternalCustomerUlidHrefs(mixed $value): void
{
    if (is_string($value)) {
        expect($value)->not->toMatch('#/customers/[0-9A-HJKMNP-TV-Z]{26}#i');

        return;
    }

    if (is_array($value)) {
        foreach ($value as $item) {
            assertNoInternalCustomerUlidHrefs($item);
        }
    }
}
