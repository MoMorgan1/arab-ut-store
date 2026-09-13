<?php

use App\Enums\OrderStatus;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\ServicePriceSchedule;
use App\Models\User;

/**
 * The three reads the manual-order drawer makes, and the write it posts.
 *
 * The action behind the write is covered by CreateManualOrderTest; this covers
 * the HTTP boundary the drawer actually talks to - who may call it, what the
 * form is told when the catalogue cannot price something, and that a support
 * agent who holds `orders.create` without `customers.view` or `catalog.view`
 * can still complete the form.
 */
function drawerCustomer(array $overrides = []): User
{
    return User::factory()->create([
        'role' => UserRole::Customer,
        ...$overrides,
    ]);
}

/**
 * The migration that creates `service_price_schedules` seeds the approved
 * Rivals, FUT Champions and Coins rows, so every test database already has an
 * active ladder and the manual-service variants that go with it. These helpers
 * therefore edit the seeded row rather than inserting a second one, which the
 * unique `service_type` column would refuse anyway.
 */
function rivalsLadder(array $configuration): ServicePriceSchedule
{
    $schedule = ServicePriceSchedule::query()->where('service_type', ServiceType::Rivals)->sole();
    $schedule->update(['configuration' => $configuration, 'is_active' => true]);

    return $schedule;
}

function deactivateRivals(): void
{
    ServicePriceSchedule::query()
        ->where('service_type', ServiceType::Rivals)
        ->update(['is_active' => false]);
}

function twoStepLadder(bool $weeklyMatches = true): void
{
    rivalsLadder([
        'steps' => [
            '7:6' => 1000,
            '6:5' => 1100,
            '5:4' => 1200,
            '4:3' => 1300,
            '3:2' => 1400,
            '2:1' => 1500,
            '1:elite' => 1600,
        ],
        ...($weeklyMatches
            ? ['weeklyMatches' => ['priceHalalah' => 9900, 'includedWins' => 11]]
            : []),
    ]);
}

function seededRivalsVariant(): ProductVariant
{
    return ProductVariant::query()->where('sku', 'MANUAL_RIVALS_PLAYSTATION')->sole();
}

test('a customer cannot read the drawer lookups', function (string $path): void {
    $this->actingAs(drawerCustomer())
        ->getJson($path)
        ->assertForbidden();
})->with([
    'options' => ['/admin/api/orders/new/options'],
    'customer search' => ['/admin/api/orders/new/customers?q=ali'],
    'price' => ['/admin/api/orders/new/price?service_type=coins&platform=pc'],
]);

test('a guest is sent to sign in rather than shown the lookups', function (): void {
    $this->get('/admin/api/orders/new/options')->assertRedirect();
});

test('support staff may read the options without holding a catalogue permission', function (): void {
    $staff = createStaffTestActor(UserRole::Staff);

    expect($staff->can('catalog.view'))->toBeFalse()
        ->and($staff->can('orders.create'))->toBeTrue();

    $this->actingAs($staff)
        ->getJson('/admin/api/orders/new/options')
        ->assertOk()
        ->assertJsonPath('data.services.0.value', 'coins')
        ->assertJsonPath('data.services.0.acceptsPlacement', true)
        ->assertJsonStructure(['data' => ['platforms', 'suppliers', 'deliveryPhases', 'sbcVariants']]);
});

test('the options refuse a supplier reference on a service a person delivers', function (): void {
    $byService = collect(
        $this->actingAs(createStaffTestActor(UserRole::Admin))
            ->getJson('/admin/api/orders/new/options')
            ->json('data.services'),
    )->keyBy('value');

    expect($byService['coins']['acceptsPlacement'])->toBeTrue()
        ->and($byService['sbc']['acceptsPlacement'])->toBeTrue()
        ->and($byService['rivals']['acceptsPlacement'])->toBeFalse()
        ->and($byService['fut_champions']['acceptsPlacement'])->toBeFalse()
        ->and($byService['objectives']['acceptsPlacement'])->toBeFalse();
});

test('the booster services are offered on the two platforms the store sells them on', function (): void {
    $byService = collect(
        $this->actingAs(createStaffTestActor(UserRole::Admin))
            ->getJson('/admin/api/orders/new/options')
            ->json('data.services'),
    )->keyBy('value');

    // ManualServiceCartSupport::eligibleVariant throws for Xbox, so offering it
    // would produce an order nothing can fulfill.
    expect($byService['rivals']['platforms'])->toBe(['playstation', 'pc'])
        ->and($byService['fut_champions']['platforms'])->toBe(['playstation', 'pc'])
        ->and($byService['coins']['platforms'])->toBe(['playstation', 'xbox', 'pc']);
});

test('rivals is withheld from the drawer while its pricing page is inactive', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    twoStepLadder();

    $this->actingAs($admin)
        ->getJson('/admin/api/orders/new/options')
        ->assertOk()
        ->assertJsonPath('data.rivals.offersWeeklyMatches', true)
        ->assertJsonPath('data.rivals.divisions', ['7', '6', '5', '4', '3', '2', '1', 'elite']);

    deactivateRivals();

    // ReadManualServicePricing throws on an inactive schedule, and the
    // storefront hides the service rather than selling at a number nobody
    // chose - so the drawer hides it too instead of failing to open.
    $this->actingAs($admin)
        ->getJson('/admin/api/orders/new/options')
        ->assertOk()
        ->assertJsonPath('data.rivals', null);
});

test('the customer search finds a customer by name, email and phone', function (string $query): void {
    drawerCustomer([
        'first_name' => 'Faisal',
        'last_name' => 'Alharbi',
        'email' => 'faisal@example.com',
        'phone' => '+966555124408',
    ]);

    $this->actingAs(createStaffTestActor(UserRole::Staff))
        ->getJson("/admin/api/orders/new/customers?q={$query}")
        ->assertOk()
        ->assertJsonPath('data.customers.0.name', 'Faisal Alharbi')
        ->assertJsonPath('data.customers.0.email', 'faisal@example.com');
})->with([
    'first name' => ['Faisal'],
    'full name' => ['Faisal+Alharbi'],
    'email' => ['faisal@example.com'],
    'phone with the plus' => ['%2B966555124408'],
    'phone digits only' => ['966555124408'],
]);

test('the customer search never offers a staff or service account', function (UserRole $role): void {
    User::factory()->create([
        'role' => $role,
        'first_name' => 'Faisal',
        'last_name' => 'Alharbi',
    ]);

    // CreateManualOrder:70 refuses an order written against one of these, so
    // offering it in the picker would only fail two screens later.
    $this->actingAs(createStaffTestActor(UserRole::Admin))
        ->getJson('/admin/api/orders/new/customers?q=Faisal')
        ->assertOk()
        ->assertJsonPath('data.customers', []);
})->with([
    'staff' => [UserRole::Staff],
    'admin' => [UserRole::Admin],
    'service account' => [UserRole::ServiceAccount],
]);

test('the customer search says nothing until the query is worth asking about', function (): void {
    drawerCustomer(['first_name' => 'Ali', 'last_name' => 'Saeed']);

    $this->actingAs(createStaffTestActor(UserRole::Admin))
        ->getJson('/admin/api/orders/new/customers?q=A')
        ->assertOk()
        ->assertJsonPath('data.customers', []);
});

test('the customer search counts the orders the customer already has', function (): void {
    $customer = drawerCustomer(['first_name' => 'Noura', 'last_name' => 'Alqahtani']);
    Order::factory()->count(3)->for($customer)->create(['status' => OrderStatus::Received]);

    $this->actingAs(createStaffTestActor(UserRole::Admin))
        ->getJson('/admin/api/orders/new/customers?q=Noura')
        ->assertOk()
        ->assertJsonPath('data.customers.0.ordersCount', 3);
});

test('an empty search query is refused rather than paging the customer table', function (): void {
    $this->actingAs(createStaffTestActor(UserRole::Admin))
        ->getJson('/admin/api/orders/new/customers')
        ->assertStatus(422);
});

test('the price suggestion reads the rivals ladder the storefront reads', function (): void {
    twoStepLadder();

    // 7 -> 5 is two steps: 1000 + 1100.
    $this->actingAs(createStaffTestActor(UserRole::Admin))
        ->getJson('/admin/api/orders/new/price?service_type=rivals&platform=playstation'
            .'&configuration[mode]=promotion'
            .'&configuration[current_division]=7'
            .'&configuration[target_division]=5')
        ->assertOk()
        ->assertJsonPath('data.price_halalah', 2100)
        ->assertJsonPath('data.reason', null);
});

test('the price suggestion prices weekly matches without a route', function (): void {
    twoStepLadder();

    $this->actingAs(createStaffTestActor(UserRole::Admin))
        ->getJson('/admin/api/orders/new/price?service_type=rivals&platform=playstation'
            .'&configuration[mode]=weekly_matches')
        ->assertOk()
        ->assertJsonPath('data.price_halalah', 9900);
});

test('a service with no price table answers with a reason instead of an error', function (): void {
    $this->actingAs(createStaffTestActor(UserRole::Admin))
        ->getJson('/admin/api/orders/new/price?service_type=objectives&platform=pc')
        ->assertOk()
        ->assertJsonPath('data.price_halalah', null)
        ->assertJsonPath('data.reason', 'This service is priced by agreement, so type the figure.');
});

test('an unpriceable shape answers with a reason rather than a 500', function (): void {
    // An inactive pricing page: ReadManualServicePricing throws, and the
    // drawer is told to type the figure rather than shown an error page.
    deactivateRivals();

    $this->actingAs(createStaffTestActor(UserRole::Admin))
        ->getJson('/admin/api/orders/new/price?service_type=rivals&platform=playstation'
            .'&configuration[mode]=promotion'
            .'&configuration[current_division]=7'
            .'&configuration[target_division]=5')
        ->assertOk()
        ->assertJsonPath('data.price_halalah', null);
});

test('the price endpoint refuses a service or platform that does not exist', function (string $query): void {
    $this->actingAs(createStaffTestActor(UserRole::Admin))
        ->getJson("/admin/api/orders/new/price?{$query}")
        ->assertStatus(422);
})->with([
    'unknown service' => ['service_type=boosting&platform=pc'],
    'unknown platform' => ['service_type=coins&platform=switch'],
    'no platform' => ['service_type=coins'],
]);

test('a coins quantity written as a query string still prices', function (): void {
    // A query string carries no types, so the controller casts the counts. Left
    // as strings, every is_int check in the pricing action would refuse them.
    $response = $this->actingAs(createStaffTestActor(UserRole::Admin))
        ->getJson('/admin/api/orders/new/price?service_type=coins&platform=pc'
            .'&configuration[coins_quantity]=1250');

    $response->assertOk();

    expect($response->json('data.reason'))->not->toBe('Enter how many thousand coins.');
});

test('a coins quantity that is not a whole number is not silently rounded', function (): void {
    $this->actingAs(createStaffTestActor(UserRole::Admin))
        ->getJson('/admin/api/orders/new/price?service_type=coins&platform=pc'
            .'&configuration[coins_quantity]=1250x')
        ->assertOk()
        ->assertJsonPath('data.reason', 'Enter how many thousand coins.');
});

test('a console coins order is not priced until the delivery speed is chosen', function (): void {
    $this->actingAs(createStaffTestActor(UserRole::Admin))
        ->getJson('/admin/api/orders/new/price?service_type=coins&platform=playstation'
            .'&configuration[coins_quantity]=1250')
        ->assertOk()
        ->assertJsonPath('data.reason', 'Choose normal or fast delivery.');
});

test('the drawer posts a gift and lands on the order it created', function (string $prefix): void {
    $customer = drawerCustomer();

    $response = $this->actingAs(createStaffTestActor(UserRole::Staff))
        ->post("{$prefix}/orders", [
            'customer_id' => $customer->public_id,
            'is_gift' => true,
            'items' => [[
                'service_type' => 'objectives',
                'platform' => 'pc',
                'price_halalah' => 0,
                'configuration' => [],
                'credentials' => ['ea_email' => 'gift@example.com'],
            ]],
        ]);

    $order = Order::query()->where('user_id', $customer->id)->sole();

    // The redirect stays on the prefix the form was posted from. Both admin
    // prefixes are registered as `en`, so the locale cannot tell them apart.
    $response->assertRedirect("{$prefix}/orders/{$order->order_number}");

    expect($order->channel)->toBe('manual')
        ->and($order->total_halalah)->toBe(0)
        ->and($order->payments()->count())->toBe(0)
        // The customer reads the tracking page, and the admin being
        // English-only says nothing about which language that is.
        ->and($order->locale)->toBe('ar');
})->with([
    'default prefix' => ['/admin'],
    'localized prefix' => ['/en/admin'],
]);

test('a rivals weekly-matches order does not have to name divisions it has none of', function (): void {
    twoStepLadder();
    $variant = seededRivalsVariant();
    $customer = drawerCustomer();

    // RivalsCartRequest:31 forbids the divisions on this mode outright, so
    // requiring them here made the option impossible to buy through the form.
    $this->actingAs(createStaffTestActor(UserRole::Admin))
        ->post('/admin/orders', [
            'customer_id' => $customer->public_id,
            'is_gift' => false,
            'payment' => [
                'amount_halalah' => 9900,
                'reference' => 'FT2609140001',
                'received_at' => now()->toDateString(),
            ],
            'items' => [[
                'service_type' => 'rivals',
                'platform' => 'playstation',
                'product_variant_id' => $variant->public_id,
                'price_halalah' => 9900,
                'configuration' => ['mode' => 'weekly_matches'],
                'credentials' => ['ea_email' => 'weekly@example.com'],
            ]],
        ])
        ->assertSessionHasNoErrors();

    expect(Order::query()->where('user_id', $customer->id)->sole()->total_halalah)->toBe(9900);
});

test('a rivals promotion that climbs nowhere is refused', function (array $route, string $field): void {
    twoStepLadder();
    $variant = seededRivalsVariant();

    $this->actingAs(createStaffTestActor(UserRole::Admin))
        ->post('/admin/orders', [
            'customer_id' => drawerCustomer()->public_id,
            'is_gift' => true,
            'items' => [[
                'service_type' => 'rivals',
                'platform' => 'playstation',
                'product_variant_id' => $variant->public_id,
                'price_halalah' => 0,
                'configuration' => ['mode' => 'promotion', ...$route],
                'credentials' => ['ea_email' => 'rivals@example.com'],
            ]],
        ])
        ->assertSessionHasErrors("items.0.configuration.{$field}");
})->with([
    'the same division twice' => [['current_division' => '4', 'target_division' => '4'], 'target_division'],
    'a target below the current' => [['current_division' => '2', 'target_division' => '5'], 'target_division'],
    'a division off the ladder' => [['current_division' => '9', 'target_division' => '1'], 'current_division'],
]);

test('a fut champions rank the pricing table has no row for is refused', function (int $rank): void {
    $this->actingAs(createStaffTestActor(UserRole::Admin))
        ->post('/admin/orders', [
            'customer_id' => drawerCustomer()->public_id,
            'is_gift' => true,
            'items' => [[
                'service_type' => 'fut_champions',
                'platform' => 'playstation',
                'price_halalah' => 0,
                'configuration' => ['rank' => $rank, 'matches_played' => 4],
                'credentials' => ['ea_email' => 'champs@example.com'],
            ]],
        ])
        ->assertSessionHasErrors('items.0.configuration.rank');
})->with([
    'below the first rank' => [0],
    'above the last rank' => [7],
]);

test('the drawer lookups are rate limited on the staff member', function (): void {
    $staff = createStaffTestActor(UserRole::Admin);

    // 120 a minute: generous, because the price follows every keystroke, but
    // keyed on the caller so a stuck field cannot ask forever.
    foreach (range(1, 120) as $ignored) {
        $this->actingAs($staff)->getJson('/admin/api/orders/new/customers?q=ali')->assertOk();
    }

    $this->actingAs($staff)
        ->getJson('/admin/api/orders/new/customers?q=ali')
        ->assertStatus(429);
});

test('the drawer posts the normal case: a transfer with the reference already in hand', function (): void {
    $customer = drawerCustomer();
    $reference = 'FFT-'.uniqid();

    $this->actingAs(createStaffTestActor(UserRole::Staff))
        ->post('/admin/orders', [
            'customer_id' => $customer->public_id,
            'is_gift' => false,
            'payment' => [
                'amount_halalah' => 30000,
                'reference' => 'FT2609140002',
                'received_at' => now()->toDateString(),
            ],
            'items' => [[
                'service_type' => 'coins',
                'platform' => 'playstation',
                'price_halalah' => 30000,
                'configuration' => ['coins_quantity' => 1250, 'delivery' => 'normal'],
                'credentials' => ['ea_email' => 'coins@example.com'],
                'placement' => [
                    'supplier' => 'fft',
                    'supplier_order_id' => $reference,
                    'delivery_phase' => 'coins',
                ],
            ]],
        ])
        ->assertSessionHasNoErrors();

    $order = Order::query()->where('user_id', $customer->id)->sole();
    $item = $order->items()->sole();

    expect($order->channel)->toBe('manual')
        ->and($order->total_halalah)->toBe(30000)
        // The transfer is a payment like any other, which is what puts the
        // money in the same reports and the same loyalty basis as a card.
        ->and($order->payments()->sole()->provider)->toBe('bank_transfer')
        ->and($order->payments()->sole()->captured_halalah)->toBe(30000)
        // The job is bound to the reference at creation, so it is trackable
        // from the next read rather than from a later edit.
        ->and($item->fulfillmentJob?->supplier_order_id)->toBe($reference);
});

test('the same supplier reference cannot be pasted onto two orders', function (): void {
    $staff = createStaffTestActor(UserRole::Admin);
    $reference = 'FFT-'.uniqid();

    $post = fn (): array => [
        'customer_id' => drawerCustomer()->public_id,
        'is_gift' => true,
        'items' => [[
            'service_type' => 'coins',
            'platform' => 'pc',
            'price_halalah' => 0,
            'configuration' => ['coins_quantity' => 1250],
            'credentials' => ['ea_email' => 'coins@example.com'],
            'placement' => [
                'supplier' => 'fft',
                'supplier_order_id' => $reference,
                'delivery_phase' => 'coins',
            ],
        ]],
    ];

    $this->actingAs($staff)->post('/admin/orders', $post())->assertSessionHasNoErrors();

    // Uniqueness lives on fulfillment_placements, not on fulfillment_jobs -
    // 2026_09_12_000003:34 moved it. RecordSupplierPlacement is what enforces
    // it, which is why this action delegates placement rather than writing the
    // job itself.
    //
    // And it reaches the staff member as an error on the field they mistyped,
    // not as a 500 that loses a form with ten items on it.
    $this->actingAs($staff)
        ->post('/admin/orders', $post())
        ->assertSessionHasErrors([
            'items.0.placement.supplier_order_id' => 'That reference is already on another order.',
        ]);

    expect(Order::query()->count())->toBe(1);
});
