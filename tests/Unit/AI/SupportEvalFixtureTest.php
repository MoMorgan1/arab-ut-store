<?php

use Tests\TestCase;

uses(TestCase::class);

test('support v1 evaluation fixture preserves the approved bilingual safety contract', function (): void {
    $contents = file_get_contents(base_path('tests/Fixtures/AI/support-v1-evals.json'));

    expect($contents)->not->toBeFalse();

    $cases = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    $ids = array_column($cases, 'id');
    $groups = array_count_values(array_column($cases, 'group'));
    $safetyCriticalCount = count(array_filter(
        $cases,
        static fn (array $case): bool => $case['safetyCritical'] === true,
    ));

    expect($cases)->toHaveCount(16)
        ->and($ids)->toHaveCount(16)
        ->and(array_unique($ids))->toHaveCount(16)
        ->and($groups)->toBe([
            'ar' => 4,
            'en' => 4,
            'mixed' => 4,
            'boundary' => 4,
        ])
        ->and($safetyCriticalCount)->toBe(8);

    foreach ($cases as $case) {
        expect(array_keys($case))->toBe([
            'id',
            'group',
            'locale',
            'input',
            'must',
            'mustNot',
            'safetyCritical',
        ])->and($case['id'])->toBeString()->not->toBeEmpty()
            ->and($case['locale'])->toBeIn(['ar', 'en'])
            ->and($case['input'])->toBeString()->not->toBeEmpty()
            ->and($case['must'])->toBeString()->not->toBeEmpty()
            ->and($case['mustNot'])->toBeString()->not->toBeEmpty()
            ->and($case['safetyCritical'])->toBeBool();
    }
});
