<?php

use App\Enums\UserRole;
use App\Models\StaffAuditLog;
use App\Models\StorePage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

uses(RefreshDatabase::class);

function adminStorePagesActor(UserRole $role = UserRole::Admin): User
{
    $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
    $user = User::factory()->create(['role' => $role, 'password' => 'SecurePassword!12']);
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}

test('unauthenticated users cannot view store pages list or editor', function (): void {
    $this->get('/admin/marketing/pages')->assertRedirect('/en/login');
    $this->get('/admin/marketing/pages/privacy')->assertRedirect('/en/login');
    $this->putJson('/admin/api/marketing/pages/privacy', [])->assertUnauthorized();
});

test('users without marketing.view permission are forbidden from viewing pages', function (): void {
    $user = adminStorePagesActor(UserRole::Staff);
    // UserRole::Staff has only catalog/orders by default, not marketing

    $this->actingAs($user)
        ->withSession(['auth.mfa_passed' => true])
        ->get('/admin/marketing/pages')
        ->assertForbidden();

    $this->actingAs($user)
        ->withSession(['auth.mfa_passed' => true])
        ->get('/admin/marketing/pages/privacy')
        ->assertForbidden();
});

test('users without marketing.manage permission cannot update pages', function (): void {
    $user = adminStorePagesActor(UserRole::Staff);

    $this->actingAs($user)
        ->withSession(['auth.mfa_passed' => true])
        ->putJson('/admin/api/marketing/pages/privacy', [
            'ar' => [
                'title' => 'عنوان جديد',
                'updatedLabel' => '٢ سبتمبر ٢٠٢٦',
                'blocks' => [['type' => 'paragraph', 'text' => 'محتوى']],
            ],
            'en' => [
                'title' => 'New Title',
                'updatedLabel' => '2 September 2026',
                'blocks' => [['type' => 'paragraph', 'text' => 'Content']],
            ],
        ])
        ->assertForbidden();
});

test('GET /marketing/pages renders list of five policy pages in fixed order', function (): void {
    $admin = adminStorePagesActor(UserRole::Admin);

    $this->actingAs($admin)
        ->withSession(['auth.mfa_passed' => true])
        ->get('/admin/marketing/pages')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/marketing/pages')
            ->has('pages', 5)
            ->where('pages.0.key', 'privacy')
            ->where('pages.0.address', '/privacy')
            ->where('pages.1.key', 'returns')
            ->where('pages.1.address', '/returns')
            ->where('pages.2.key', 'warranty')
            ->where('pages.2.address', '/warranty')
            ->where('pages.3.key', 'ea_backup_codes')
            ->where('pages.3.address', '/ea-backup-codes')
            ->where('pages.4.key', 'terms')
            ->where('pages.4.address', '/terms')
            ->where('canManage', true));
});

test('GET /marketing/pages/{key} returns editor props with content converted to markers', function (): void {
    $admin = adminStorePagesActor(UserRole::Admin);

    $this->actingAs($admin)
        ->withSession(['auth.mfa_passed' => true])
        ->get('/admin/marketing/pages/privacy')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/marketing/page-editor')
            ->where('pageKey', 'privacy')
            ->where('storeUrl', '/en/privacy')
            ->where('content.ar.title', 'سياسة الخصوصية')
            ->where('content.en.title', 'Privacy Policy')
            ->has('content.ar.blocks')
            ->has('content.en.blocks')
            ->where('canManage', true));

    // Unknown page key returns 404
    $this->actingAs($admin)
        ->withSession(['auth.mfa_passed' => true])
        ->get('/admin/marketing/pages/non_existent_page')
        ->assertNotFound();
});

test('PUT /api/marketing/pages/{key} updates both locales and logs staff audit with previous blocks', function (): void {
    $admin = adminStorePagesActor(UserRole::Admin);

    $page = StorePage::query()->where('key', 'privacy')->firstOrFail();
    $oldBlocksAr = $page->blocks_ar;
    $oldBlocksEn = $page->blocks_en;

    $payload = [
        'ar' => [
            'title' => 'سياسة الخصوصية المحدثة',
            'subtitle' => 'وصف فرعي محدث',
            'updatedLabel' => '٣ سبتمبر ٢٠٢٦',
            'blocks' => [
                ['type' => 'heading', 'level' => 2, 'text' => 'مقدمة رئيسية'],
                ['type' => 'paragraph', 'text' => 'نص تجريبي مع رابط إلى [الدعم](https://help.ea.com) الرسمي.'],
                ['type' => 'notice', 'tone' => 'shield', 'text' => 'تنبيه أمان'],
                ['type' => 'divider'],
            ],
        ],
        'en' => [
            'title' => 'Updated Privacy Policy',
            'subtitle' => 'Updated Subtitle',
            'updatedLabel' => '3 September 2026',
            'blocks' => [
                ['type' => 'heading', 'level' => 2, 'text' => 'Main Heading'],
                ['type' => 'paragraph', 'text' => 'Test paragraph with [EA Support](https://help.ea.com) link.'],
                ['type' => 'notice', 'tone' => 'shield', 'text' => 'Security notice'],
                ['type' => 'divider'],
            ],
        ],
    ];

    $response = $this->actingAs($admin)
        ->withSession(['auth.mfa_passed' => true])
        ->putJson('/admin/api/marketing/pages/privacy', $payload);

    $response->assertOk()
        ->assertJson(['page' => 'privacy']);

    $page->refresh();
    expect($page->title_ar)->toBe('سياسة الخصوصية المحدثة')
        ->and($page->title_en)->toBe('Updated Privacy Policy')
        ->and($page->subtitle_ar)->toBe('وصف فرعي محدث')
        ->and($page->subtitle_en)->toBe('Updated Subtitle')
        ->and($page->updated_label_ar)->toBe('٣ سبتمبر ٢٠٢٦')
        ->and($page->updated_label_en)->toBe('3 September 2026')
        ->and($page->blocks_ar)->toHaveCount(4)
        ->and($page->blocks_ar[0]['type'])->toBe('heading')
        ->and($page->blocks_ar[0]['text'])->toBe('مقدمة رئيسية')
        ->and($page->blocks_ar[1]['content'][1]['url'])->toBe('https://help.ea.com')
        ->and($page->blocks_ar[1]['content'][1]['text'])->toBe('الدعم');

    // Audit log verification
    $audit = StaffAuditLog::query()
        ->where('action', 'store_pages.updated')
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->actor_user_id)->toBe($admin->id)
        ->and($audit->auditable_id)->toBe($page->id)
        ->and($audit->metadata)->toBe([
            'previous_blocks_ar' => $oldBlocksAr,
            'previous_blocks_en' => $oldBlocksEn,
        ]);
});

test('PUT /api/marketing/pages/{key} rejects unapproved external link hosts with 422', function (): void {
    $admin = adminStorePagesActor(UserRole::Admin);

    $payload = [
        'ar' => [
            'title' => 'سياسة الخصوصية',
            'updatedLabel' => '٢ سبتمبر ٢٠٢٦',
            'blocks' => [
                ['type' => 'paragraph', 'text' => 'رابط خارجي غير مصرح به: [اضغط هنا](https://unapproved-malicious-site.com).'],
            ],
        ],
        'en' => [
            'title' => 'Privacy Policy',
            'updatedLabel' => '2 September 2026',
            'blocks' => [
                ['type' => 'paragraph', 'text' => 'Content'],
            ],
        ],
    ];

    $response = $this->actingAs($admin)
        ->withSession(['auth.mfa_passed' => true])
        ->putJson('/admin/api/marketing/pages/privacy', $payload);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('error');
});

test('PUT /api/marketing/pages/{key} rejects parts that are both bold and link', function (): void {
    $admin = adminStorePagesActor(UserRole::Admin);

    $payload = [
        'ar' => [
            'title' => 'سياسة الخصوصية',
            'updatedLabel' => '٢ سبتمبر ٢٠٢٦',
            'blocks' => [
                ['type' => 'paragraph', 'text' => 'غير صالح: **[رابط عريض](https://help.ea.com)**'],
            ],
        ],
        'en' => [
            'title' => 'Privacy Policy',
            'updatedLabel' => '2 September 2026',
            'blocks' => [
                ['type' => 'paragraph', 'text' => 'Content'],
            ],
        ],
    ];

    $response = $this->actingAs($admin)
        ->withSession(['auth.mfa_passed' => true])
        ->putJson('/admin/api/marketing/pages/privacy', $payload);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('error');
});

test('PUT /api/marketing/pages/{key} rejects invalid types, tones, and length limits', function (): void {
    $admin = adminStorePagesActor(UserRole::Admin);

    // Title exceeds 120 chars
    $this->actingAs($admin)
        ->withSession(['auth.mfa_passed' => true])
        ->putJson('/admin/api/marketing/pages/privacy', [
            'ar' => [
                'title' => str_repeat('أ', 121),
                'updatedLabel' => '٢ سبتمبر ٢٠٢٦',
                'blocks' => [['type' => 'paragraph', 'text' => 'نص']],
            ],
            'en' => [
                'title' => 'Valid Title',
                'updatedLabel' => '2 September 2026',
                'blocks' => [['type' => 'paragraph', 'text' => 'Content']],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('ar.title');

    // Invalid tone in notice
    $this->actingAs($admin)
        ->withSession(['auth.mfa_passed' => true])
        ->putJson('/admin/api/marketing/pages/privacy', [
            'ar' => [
                'title' => 'عنوان',
                'updatedLabel' => '٢ سبتمبر ٢٠٢٦',
                'blocks' => [['type' => 'notice', 'tone' => 'invalid_tone', 'text' => 'نص']],
            ],
            'en' => [
                'title' => 'Title',
                'updatedLabel' => '2 September 2026',
                'blocks' => [['type' => 'paragraph', 'text' => 'Content']],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('ar.blocks.0.tone');

    // Invalid heading level
    $this->actingAs($admin)
        ->withSession(['auth.mfa_passed' => true])
        ->putJson('/admin/api/marketing/pages/privacy', [
            'ar' => [
                'title' => 'عنوان',
                'updatedLabel' => '٢ سبتمبر ٢٠٢٦',
                'blocks' => [['type' => 'heading', 'level' => 4, 'text' => 'عنوان']],
            ],
            'en' => [
                'title' => 'Title',
                'updatedLabel' => '2 September 2026',
                'blocks' => [['type' => 'paragraph', 'text' => 'Content']],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('ar.blocks.0.level');
});
