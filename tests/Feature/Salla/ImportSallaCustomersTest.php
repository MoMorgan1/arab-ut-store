<?php

namespace Tests\Feature\Salla;

use App\Enums\UserRole;
use App\Imports\Salla\ImportSallaCustomers;
use App\Models\ImportBatch;
use App\Models\PhoneVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ImportSallaCustomersTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_customers_idempotently_and_creates_new_users(): void
    {
        $csvContent = implode("\n", [
            'ID,Full_Name,Mobile,Email,Created_At,Country,City,Gender,Birthday,Loyalty_Points,Order_Count,Total_Spent,Avg_Order_Value,Last_Purchase_Date,Cancelled_Orders,Wallet_Balance,Abandoned_Cart_Count',
            '101,سعود القحطاني,+966550924984,saud@example.test,2026-01-26 09:31:35,SA,Riyadh,male,1995-05-12,150,5,1500.00,300.00,2026-02-01 14:20:00,0,0,0',
            '102,خالد العتيبي,+966551122334,,2026-01-27 10:00:00,SA,Jeddah,male,1992-08-20,0,1,250.00,250.00,2026-01-27 10:00:00,0,0,0',
        ]);

        $tempFile = tempnam(sys_get_temp_dir(), 'salla_customers_');
        file_put_contents($tempFile, $csvContent);

        $action = app(ImportSallaCustomers::class);

        // First run
        $report1 = $action->execute($tempFile, dryRun: false);

        $this->assertSame(2, $report1['total_processed']);
        $this->assertSame(2, $report1['created']);
        $this->assertSame(0, $report1['updated']);
        $this->assertSame(0, $report1['skipped']);
        $this->assertSame(0, $report1['conflicts']);

        $user1 = User::query()->where('email', 'saud@example.test')->first();
        $this->assertNotNull($user1);
        $this->assertSame('سعود', $user1->first_name);
        $this->assertSame('القحطاني', $user1->last_name);
        $this->assertSame('+966550924984', $user1->phone);
        $this->assertNotNull($user1->phone_verified_at);

        // User without email
        $user2 = User::query()->where('phone', '+966551122334')->first();
        $this->assertNotNull($user2);
        $this->assertNull($user2->email);
        $this->assertSame('خالد', $user2->first_name);
        $this->assertSame('العتيبي', $user2->last_name);
        $this->assertNotNull($user2->phone_verified_at);

        // External refs created
        $this->assertDatabaseHas('external_refs', [
            'source' => 'salla',
            'entity' => 'customer',
            'external_id' => '101',
            'internal_id' => $user1->id,
        ]);
        $this->assertDatabaseHas('external_refs', [
            'source' => 'salla',
            'entity' => 'customer',
            'external_id' => '102',
            'internal_id' => $user2->id,
        ]);

        // Re-run must create nothing new (idempotent)
        $report2 = $action->execute($tempFile, dryRun: false);
        $this->assertSame(2, $report2['total_processed']);
        $this->assertSame(0, $report2['created']);
        $this->assertSame(0, $report2['updated']);
        $this->assertSame(2, $report2['skipped']);

        @unlink($tempFile);
    }

    public function test_links_existing_user_without_overwriting_attributes(): void
    {
        $existing = User::factory()->create([
            'first_name' => 'Original',
            'last_name' => 'Name',
            'email' => 'existing@example.test',
            'phone' => '+966559988776',
        ]);

        $csvContent = implode("\n", [
            'ID,Full_Name,Mobile,Email,Created_At,Country,City,Gender,Birthday,Loyalty_Points,Order_Count,Total_Spent,Avg_Order_Value,Last_Purchase_Date,Cancelled_Orders,Wallet_Balance,Abandoned_Cart_Count',
            '201,Different Name,+966559988776,existing@example.test,2026-01-26 09:31:35,SA,Riyadh,male,1995-05-12,0,0,0,0,2026-01-26,0,0,0',
        ]);

        $tempFile = tempnam(sys_get_temp_dir(), 'salla_customers_');
        file_put_contents($tempFile, $csvContent);

        $action = app(ImportSallaCustomers::class);
        $report = $action->execute($tempFile, dryRun: false);

        $this->assertSame(1, $report['updated']);
        $this->assertSame(0, $report['created']);

        $existing->refresh();
        $this->assertSame('Original', $existing->first_name);
        $this->assertSame('Name', $existing->last_name);

        $this->assertDatabaseHas('external_refs', [
            'source' => 'salla',
            'entity' => 'customer',
            'external_id' => '201',
            'internal_id' => $existing->id,
        ]);

        @unlink($tempFile);
    }

    public function test_detects_email_mobile_conflict_and_imports_neither(): void
    {
        $userA = User::factory()->create(['email' => 'conflict@example.test', 'phone' => '+966550000001']);
        $userB = User::factory()->create(['email' => 'other@example.test', 'phone' => '+966550000002']);

        $csvContent = implode("\n", [
            'ID,Full_Name,Mobile,Email,Created_At,Country,City,Gender,Birthday,Loyalty_Points,Order_Count,Total_Spent,Avg_Order_Value,Last_Purchase_Date,Cancelled_Orders,Wallet_Balance,Abandoned_Cart_Count',
            '301,Conflicted User,+966550000002,conflict@example.test,2026-01-26 09:31:35,SA,Riyadh,male,1995-05-12,0,0,0,0,2026-01-26,0,0,0',
        ]);

        $tempFile = tempnam(sys_get_temp_dir(), 'salla_customers_');
        file_put_contents($tempFile, $csvContent);

        $action = app(ImportSallaCustomers::class);
        $report = $action->execute($tempFile, dryRun: false);

        $this->assertSame(1, $report['conflicts']);
        $this->assertSame(0, $report['created']);
        $this->assertSame(0, $report['updated']);
        $this->assertCount(1, $report['conflict_details']);
        $this->assertSame('301', $report['conflict_details'][0]['salla_id']);

        $this->assertDatabaseMissing('external_refs', [
            'source' => 'salla',
            'entity' => 'customer',
            'external_id' => '301',
        ]);

        @unlink($tempFile);
    }

    public function test_a_row_whose_email_belongs_to_a_staff_account_is_skipped_not_created(): void
    {
        // The real export carries an owner's own address on a customer record.
        // Filtering the lookup to customers hid that account, so the row looked
        // new and the import died on the users.email unique index - which is
        // global, not role-scoped - taking the whole run down with it.
        $admin = User::factory()->create([
            'email' => 'owner@example.test',
            'phone' => '+966559999999',
            'role' => UserRole::Admin,
        ]);

        $csvContent = implode("\n", [
            'ID,Full_Name,Mobile,Email,Created_At,Country,City,Gender,Birthday,Loyalty_Points,Order_Count,Total_Spent,Avg_Order_Value,Last_Purchase_Date,Cancelled_Orders,Wallet_Balance,Abandoned_Cart_Count',
            '401,Salla Person,+201060848264,owner@example.test,2026-01-26 09:31:35,EG,Cairo,male,1995-05-12,0,0,0,0,2026-01-26,0,0,0',
        ]);

        $tempFile = tempnam(sys_get_temp_dir(), 'salla_customers_');
        file_put_contents($tempFile, $csvContent);

        $report = app(ImportSallaCustomers::class)->execute($tempFile, dryRun: false);

        $this->assertSame(1, $report['conflicts']);
        $this->assertSame(0, $report['created']);
        $this->assertSame(0, $report['updated']);
        $this->assertSame($admin->id, $report['conflict_details'][0]['staff_user_id']);

        // Never linked: a stranger's orders must not land in an admin's
        // history, and the admin account must not be renamed to theirs.
        $this->assertDatabaseMissing('external_refs', [
            'source' => 'salla',
            'entity' => 'customer',
            'external_id' => '401',
        ]);
        $this->assertSame('owner@example.test', $admin->refresh()->email);
        $this->assertSame('+966559999999', $admin->phone);
        $this->assertSame(UserRole::Admin, $admin->role);

        @unlink($tempFile);
    }

    public function test_a_row_whose_mobile_belongs_to_a_staff_account_is_skipped_not_created(): void
    {
        $staff = User::factory()->create([
            'email' => 'staff@example.test',
            'phone' => '+966558888888',
            'role' => UserRole::Staff,
        ]);

        $csvContent = implode("\n", [
            'ID,Full_Name,Mobile,Email,Created_At,Country,City,Gender,Birthday,Loyalty_Points,Order_Count,Total_Spent,Avg_Order_Value,Last_Purchase_Date,Cancelled_Orders,Wallet_Balance,Abandoned_Cart_Count',
            '402,Salla Person,+966558888888,someone@example.test,2026-01-26 09:31:35,SA,Riyadh,male,1995-05-12,0,0,0,0,2026-01-26,0,0,0',
        ]);

        $tempFile = tempnam(sys_get_temp_dir(), 'salla_customers_');
        file_put_contents($tempFile, $csvContent);

        $report = app(ImportSallaCustomers::class)->execute($tempFile, dryRun: false);

        $this->assertSame(1, $report['conflicts']);
        $this->assertSame(0, $report['created']);
        $this->assertSame($staff->id, $report['conflict_details'][0]['staff_user_id']);

        @unlink($tempFile);
    }

    public function test_dry_run_writes_nothing_to_database(): void
    {
        $csvContent = implode("\n", [
            'ID,Full_Name,Mobile,Email,Created_At,Country,City,Gender,Birthday,Loyalty_Points,Order_Count,Total_Spent,Avg_Order_Value,Last_Purchase_Date,Cancelled_Orders,Wallet_Balance,Abandoned_Cart_Count',
            '401,Test User,+966553344556,dryrun@example.test,2026-01-26 09:31:35,SA,Riyadh,male,1995-05-12,0,0,0,0,2026-01-26,0,0,0',
        ]);

        $tempFile = tempnam(sys_get_temp_dir(), 'salla_customers_');
        file_put_contents($tempFile, $csvContent);

        $action = app(ImportSallaCustomers::class);
        $report = $action->execute($tempFile, dryRun: true);

        $this->assertTrue($report['dry_run']);
        $this->assertSame(1, $report['created']);
        $this->assertNull($report['batch_id']);

        $this->assertDatabaseMissing('users', ['email' => 'dryrun@example.test']);
        $this->assertDatabaseMissing('external_refs', ['external_id' => '401']);
        $this->assertSame(0, ImportBatch::query()->count());

        @unlink($tempFile);
    }

    public function test_phone_only_user_can_login_via_whatsapp_otp(): void
    {
        $csvContent = implode("\n", [
            'ID,Full_Name,Mobile,Email,Created_At,Country,City,Gender,Birthday,Loyalty_Points,Order_Count,Total_Spent,Avg_Order_Value,Last_Purchase_Date,Cancelled_Orders,Wallet_Balance,Abandoned_Cart_Count',
            '501,واتساب فقط,+966558877665,,2026-01-26 09:31:35,SA,Riyadh,male,1995-05-12,0,0,0,0,2026-01-26,0,0,0',
        ]);

        $tempFile = tempnam(sys_get_temp_dir(), 'salla_customers_');
        file_put_contents($tempFile, $csvContent);

        app(ImportSallaCustomers::class)->execute($tempFile, dryRun: false);

        $user = User::query()->where('phone', '+966558877665')->sole();
        $this->assertNull($user->email);

        // Drive the real WhatsApp OTP flow: send creates a PhoneVerification and
        // WhatsApps a six-digit code, verify signs the matching user in. An
        // imported phone-only account must be able to get in this way, because it
        // has no email and therefore no password path.
        config()->set('services.whapi', [
            'base_url' => 'https://gate.whapi.test',
            'token' => 'synthetic-whapi-token',
        ]);

        $sentCode = null;
        Http::fake(function ($request) use (&$sentCode) {
            preg_match('/\b([0-9]{6})\b/', (string) $request['body'], $matches);
            $sentCode = $matches[1] ?? null;

            return Http::response(['sent' => true]);
        });

        $this->postJson(route('auth.whatsapp.send'), ['phone' => '+966558877665'])
            ->assertOk();

        $this->assertNotNull(PhoneVerification::query()->sole());
        $this->assertMatchesRegularExpression('/\A[0-9]{6}\z/', (string) $sentCode);

        $this->postJson(route('auth.whatsapp.verify'), [
            'phone' => '+966558877665',
            'code' => $sentCode,
        ])->assertOk();

        $this->assertAuthenticatedAs($user);

        @unlink($tempFile);
    }
}
