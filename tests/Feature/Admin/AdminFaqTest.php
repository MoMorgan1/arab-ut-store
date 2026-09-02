<?php

use App\Enums\AdminPermission;
use App\Enums\UserRole;
use App\Models\FaqEntry;
use App\Models\StaffAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    DB::table('faq_entries')->delete();
});

function adminFaqActor(UserRole $role = UserRole::Admin): User
{
    $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
    $user = User::factory()->create(['role' => $role, 'password' => 'SecurePassword!12']);
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}

function adminTestFaqEntry(array $attributes = []): FaqEntry
{
    return FaqEntry::query()->create([
        'question_ar' => 'سؤال تجريبي؟',
        'question_en' => 'Test question?',
        'answer_ar' => 'إجابة تجريبية.',
        'answer_en' => 'Test answer.',
        'sort_order' => 10,
        'is_visible' => true,
        ...$attributes,
    ]);
}

it('runs the seed migration on an empty table and does nothing when populated', function (): void {
    DB::table('faq_entries')->delete();
    expect(DB::table('faq_entries')->count())->toBe(0);

    $migration = require database_path('migrations/2026_09_02_000002_seed_faq_entries.php');
    $migration->up();

    expect(DB::table('faq_entries')->count())->toBe(4);

    $entries = DB::table('faq_entries')->orderBy('sort_order')->get();
    expect($entries[0]->sort_order)->toBe(10)
        ->and($entries[0]->is_visible)->toBe(1)
        ->and($entries[1]->sort_order)->toBe(20)
        ->and($entries[2]->sort_order)->toBe(30)
        ->and($entries[3]->sort_order)->toBe(40);

    // Running again does not insert duplicates
    $migration->up();
    expect(DB::table('faq_entries')->count())->toBe(4);
});

it('lists FAQ entries ordered by sort_order and id with props and URL templates', function (): void {
    $actor = adminFaqActor();
    adminTestFaqEntry(['question_ar' => 'سؤال 2', 'sort_order' => 20]);
    adminTestFaqEntry(['question_ar' => 'سؤال 1', 'sort_order' => 10]);

    test()->actingAs($actor)
        ->get('/admin/marketing/faq')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/marketing/faq')
            ->has('entries', 2)
            ->where('entries.0.questionAr', 'سؤال 1')
            ->where('entries.1.questionAr', 'سؤال 2')
            ->where('canManage', true)
            ->where('createUrl', '/admin/api/marketing/faq')
            ->where('updateUrlTemplate', '/admin/api/marketing/faq/__ID__')
            ->where('visibilityUrlTemplate', '/admin/api/marketing/faq/__ID__/visibility')
            ->where('moveUrlTemplate', '/admin/api/marketing/faq/__ID__/move')
            ->where('deleteUrlTemplate', '/admin/api/marketing/faq/__ID__'));
});

it('refuses the FAQ list to an actor without marketing.view', function (): void {
    $staff = adminFaqActor(UserRole::Staff);

    test()->actingAs($staff)->get('/admin/marketing/faq')->assertForbidden();
});

it('creates a new FAQ entry with sort_order max + 10 and writes an audit log', function (): void {
    $actor = adminFaqActor();
    adminTestFaqEntry(['sort_order' => 20]);

    $response = test()->actingAs($actor)
        ->postJson('/admin/api/marketing/faq', [
            'question_ar' => 'سؤال جديد؟',
            'question_en' => 'New question?',
            'answer_ar' => 'إجابة جديدة.',
            'answer_en' => 'New answer.',
        ])
        ->assertCreated()
        ->assertJsonStructure(['faq', 'sort_order']);

    $publicId = $response->json('faq');
    expect($response->json('sort_order'))->toBe(30);

    $entry = FaqEntry::query()->where('public_id', $publicId)->first();
    expect($entry)->not->toBeNull()
        ->and($entry->question_ar)->toBe('سؤال جديد؟')
        ->and($entry->question_en)->toBe('New question?')
        ->and($entry->is_visible)->toBeTrue();

    $audit = StaffAuditLog::query()
        ->where('action', 'faq_entries.created')
        ->where('auditable_id', $entry->id)
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->actor_user_id)->toBe($actor->id)
        ->and($audit->metadata['question_ar'])->toBe('سؤال جديد؟')
        ->and($audit->metadata['sort_order'])->toBe(30);
});

it('validates lengths and strips control characters on create', function (): void {
    $actor = adminFaqActor();

    // Required fields
    test()->actingAs($actor)
        ->postJson('/admin/api/marketing/faq', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['question_ar', 'question_en', 'answer_ar', 'answer_en']);

    // Max length validation (question > 200, answer > 2000)
    test()->actingAs($actor)
        ->postJson('/admin/api/marketing/faq', [
            'question_ar' => str_repeat('أ', 201),
            'question_en' => str_repeat('a', 201),
            'answer_ar' => str_repeat('أ', 2001),
            'answer_en' => str_repeat('a', 2001),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['question_ar', 'question_en', 'answer_ar', 'answer_en']);

    // Control characters stripped (except \n)
    $response = test()->actingAs($actor)
        ->postJson('/admin/api/marketing/faq', [
            'question_ar' => "سؤال\x00 مع\x07 نص؟",
            'question_en' => "Question\x00 with\x07 text?",
            'answer_ar' => "إجابة\nسطر ثانٍ.",
            'answer_en' => "Answer\nsecond line.",
        ])
        ->assertCreated();

    $entry = FaqEntry::query()->where('public_id', $response->json('faq'))->first();
    expect($entry->question_ar)->toBe('سؤال مع نص؟')
        ->and($entry->question_en)->toBe('Question with text?')
        ->and($entry->answer_ar)->toBe("إجابة\nسطر ثانٍ.");
});

it('updates an existing FAQ entry and writes an audit log', function (): void {
    $actor = adminFaqActor();
    $entry = adminTestFaqEntry();

    test()->actingAs($actor)
        ->putJson("/admin/api/marketing/faq/{$entry->public_id}", [
            'question_ar' => 'سؤال معدل؟',
            'question_en' => 'Updated question?',
            'answer_ar' => 'إجابة معدلة.',
            'answer_en' => 'Updated answer.',
        ])
        ->assertOk()
        ->assertJson(['faq' => $entry->public_id]);

    $entry->refresh();
    expect($entry->question_ar)->toBe('سؤال معدل؟')
        ->and($entry->question_en)->toBe('Updated question?');

    $audit = StaffAuditLog::query()
        ->where('action', 'faq_entries.updated')
        ->where('auditable_id', $entry->id)
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->metadata['question_ar'])->toBe('سؤال معدل؟');
});

it('toggles visibility and writes an audit log with previous and new states', function (): void {
    $actor = adminFaqActor();
    $entry = adminTestFaqEntry(['is_visible' => true]);

    test()->actingAs($actor)
        ->postJson("/admin/api/marketing/faq/{$entry->public_id}/visibility", [
            'visible' => false,
            'expectedVisible' => true,
        ])
        ->assertOk()
        ->assertJson(['faq' => $entry->public_id, 'visible' => false]);

    $entry->refresh();
    expect($entry->is_visible)->toBeFalse();

    $audit = StaffAuditLog::query()
        ->where('action', 'faq_entries.visibility_changed')
        ->where('auditable_id', $entry->id)
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->metadata['previous_visible'])->toBeTrue()
        ->and($audit->metadata['new_visible'])->toBeFalse();
});

it('returns 409 when visibility has changed underneath the caller', function (): void {
    $actor = adminFaqActor();
    $entry = adminTestFaqEntry(['is_visible' => false]);

    test()->actingAs($actor)
        ->postJson("/admin/api/marketing/faq/{$entry->public_id}/visibility", [
            'visible' => false,
            'expectedVisible' => true,
        ])
        ->assertStatus(409)
        ->assertJson(['faq' => $entry->public_id, 'current' => ['visible' => false]]);
});

it('rejects unknown fields in the visibility payload', function (): void {
    $actor = adminFaqActor();
    $entry = adminTestFaqEntry();

    test()->actingAs($actor)
        ->postJson("/admin/api/marketing/faq/{$entry->public_id}/visibility", [
            'visible' => false,
            'expectedVisible' => true,
            'extra' => 'not-allowed',
        ])
        ->assertStatus(422);
});

it('moves FAQ entries up and down by swapping sort_order', function (): void {
    $actor = adminFaqActor();
    $first = adminTestFaqEntry(['question_ar' => 'أول', 'sort_order' => 10]);
    $second = adminTestFaqEntry(['question_ar' => 'ثانٍ', 'sort_order' => 20]);
    $third = adminTestFaqEntry(['question_ar' => 'ثالث', 'sort_order' => 30]);

    // Move second up -> should swap with first
    test()->actingAs($actor)
        ->postJson("/admin/api/marketing/faq/{$second->public_id}/move", [
            'direction' => 'up',
        ])
        ->assertOk();

    expect($second->fresh()->sort_order)->toBe(10)
        ->and($first->fresh()->sort_order)->toBe(20);

    $audit = StaffAuditLog::query()
        ->where('action', 'faq_entries.moved')
        ->where('auditable_id', $second->id)
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->metadata['direction'])->toBe('up')
        ->and($audit->metadata['previous_sort_order'])->toBe(20)
        ->and($audit->metadata['new_sort_order'])->toBe(10);

    // Move second down -> should swap back
    test()->actingAs($actor)
        ->postJson("/admin/api/marketing/faq/{$second->public_id}/move", [
            'direction' => 'down',
        ])
        ->assertOk();

    expect($second->fresh()->sort_order)->toBe(20)
        ->and($first->fresh()->sort_order)->toBe(10);
});

it('returns 422 when trying to move the first entry up or the last entry down', function (): void {
    $actor = adminFaqActor();
    $first = adminTestFaqEntry(['sort_order' => 10]);
    $second = adminTestFaqEntry(['sort_order' => 20]);

    // First cannot move up
    test()->actingAs($actor)
        ->postJson("/admin/api/marketing/faq/{$first->public_id}/move", [
            'direction' => 'up',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('direction');

    // Second (last) cannot move down
    test()->actingAs($actor)
        ->postJson("/admin/api/marketing/faq/{$second->public_id}/move", [
            'direction' => 'down',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('direction');
});

it('deletes an FAQ entry and writes an audit log with all four text fields', function (): void {
    $actor = adminFaqActor();
    $entry = adminTestFaqEntry([
        'question_ar' => 'سؤال للحذف؟',
        'question_en' => 'Question to delete?',
        'answer_ar' => 'إجابة للحذف.',
        'answer_en' => 'Answer to delete.',
    ]);

    $entryId = $entry->id;
    $publicId = $entry->public_id;

    test()->actingAs($actor)
        ->deleteJson("/admin/api/marketing/faq/{$publicId}")
        ->assertOk()
        ->assertJson(['deleted' => true, 'id' => $publicId]);

    expect(FaqEntry::query()->where('id', $entryId)->exists())->toBeFalse();

    $audit = StaffAuditLog::query()
        ->where('action', 'faq_entries.deleted')
        ->where('auditable_id', $entryId)
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->actor_user_id)->toBe($actor->id)
        ->and($audit->metadata['question_ar'])->toBe('سؤال للحذف؟')
        ->and($audit->metadata['question_en'])->toBe('Question to delete?')
        ->and($audit->metadata['answer_ar'])->toBe('إجابة للحذف.')
        ->and($audit->metadata['answer_en'])->toBe('Answer to delete.');
});

it('refuses write operations to an actor with marketing.view only', function (): void {
    $actor = adminFaqActor();
    $entry = adminTestFaqEntry();
    Gate::define(AdminPermission::MarketingManage->value, fn (): bool => false);

    // List is allowed
    test()->actingAs($actor)->get('/admin/marketing/faq')->assertOk();

    // Create refused
    test()->actingAs($actor)->postJson('/admin/api/marketing/faq', [
        'question_ar' => 'سؤال؟',
        'question_en' => 'Question?',
        'answer_ar' => 'إجابة.',
        'answer_en' => 'Answer.',
    ])->assertForbidden();

    // Update refused
    test()->actingAs($actor)->putJson("/admin/api/marketing/faq/{$entry->public_id}", [
        'question_ar' => 'سؤال؟',
        'question_en' => 'Question?',
        'answer_ar' => 'إجابة.',
        'answer_en' => 'Answer.',
    ])->assertForbidden();

    // Visibility refused
    test()->actingAs($actor)->postJson("/admin/api/marketing/faq/{$entry->public_id}/visibility", [
        'visible' => false,
        'expectedVisible' => true,
    ])->assertForbidden();

    // Move refused
    test()->actingAs($actor)->postJson("/admin/api/marketing/faq/{$entry->public_id}/move", [
        'direction' => 'down',
    ])->assertForbidden();

    // Delete refused
    test()->actingAs($actor)->deleteJson("/admin/api/marketing/faq/{$entry->public_id}")->assertForbidden();
});
