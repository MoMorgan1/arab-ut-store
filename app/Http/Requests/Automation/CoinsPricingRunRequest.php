<?php

namespace App\Http\Requests\Automation;

use App\Services\Catalog\CoinsCatalogReader;
use App\ValueObjects\Pricing\CoinsPricingRule;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

final class CoinsPricingRunRequest extends FormRequest
{
    /** @var list<string> */
    private const TOP_LEVEL_KEYS = [
        'schemaVersion',
        'eventId',
        'runId',
        'generatedAt',
        'mode',
        'serviceType',
        'legalRanges',
        'rules',
        'observations',
    ];

    /** @var list<string> */
    private const GROUPS = ['console_normal', 'console_fast', 'pc'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'schemaVersion' => ['required', 'integer', 'in:1'],
            'eventId' => ['required', 'ulid'],
            'runId' => ['required', 'ulid'],
            'generatedAt' => ['required', 'date_format:Y-m-d\TH:i:s.u\Z'],
            'mode' => ['required', Rule::in(['dry_run', 'apply'])],
            'serviceType' => ['required', 'in:coins'],
            'legalRanges' => ['required', 'array:console_normal,console_fast,pc'],
            'legalRanges.*' => ['required', 'array:minimum,maximum,increment'],
            'legalRanges.*.minimum' => ['required', 'integer', 'min:1'],
            'legalRanges.*.maximum' => ['required', 'integer', 'min:1'],
            'legalRanges.*.increment' => ['required', 'integer', 'min:1'],
            'rules' => ['required', 'array:console_normal,console_fast,pc'],
            'rules.console_normal' => ['required', 'array'],
            'rules.console_fast' => ['required', 'array'],
            'rules.pc' => ['required', 'array'],
            'observations' => ['sometimes', 'array'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->validateTopLevelShape($validator);
                $this->validateSignedEvent($validator);
                $this->validateGeneratedAt($validator);
                $this->validateLegalRanges($validator);
                $this->validateRuleConfigurations($validator);
            },
        ];
    }

    private function validateTopLevelShape(Validator $validator): void
    {
        $unknownKeys = array_diff(array_keys($this->all()), self::TOP_LEVEL_KEYS);

        if ($unknownKeys !== []) {
            $validator->errors()->add('payload', 'The pricing snapshot contains undeclared fields.');
        }

        if ($this->input('serviceType') !== 'coins') {
            $validator->errors()->add('serviceType', 'The pricing snapshot must describe Coins.');
        }
    }

    private function validateSignedEvent(Validator $validator): void
    {
        if ($this->header('X-ArabUT-Event') !== $this->input('eventId')) {
            $validator->errors()->add('eventId', 'The signed event does not match the pricing snapshot event.');
        }
    }

    private function validateGeneratedAt(Validator $validator): void
    {
        $value = $this->input('generatedAt');

        if (! is_string($value)) {
            return;
        }

        $generatedAt = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s.u\Z',
            $value,
            new DateTimeZone('UTC'),
        );

        if ($generatedAt !== false && abs(now()->getTimestamp() - $generatedAt->getTimestamp()) > 300) {
            $validator->errors()->add('generatedAt', 'The pricing snapshot is outside the freshness window.');
        }
    }

    /**
     * The quantity range the storefront is actually selling, per group.
     *
     * @return array<string, array{minimum: int, maximum: int, increment: int}>
     */
    private function expectedRanges(): array
    {
        $rules = app(CoinsCatalogReader::class)->quantityRules();
        $minimum = $rules->minimum();
        $increment = $rules->finestStep();

        return [
            'console_normal' => [
                'minimum' => $minimum,
                'maximum' => Config::integer('coins.platforms.playstation.deliveries.normal.maximum'),
                'increment' => $increment,
            ],
            'console_fast' => [
                'minimum' => $minimum,
                'maximum' => Config::integer('coins.platforms.playstation.deliveries.fast.maximum'),
                'increment' => $increment,
            ],
            'pc' => [
                'minimum' => $minimum,
                'maximum' => Config::integer('coins.platforms.pc.maximum'),
                'increment' => $increment,
            ],
        ];
    }

    private function anchored(string $group): bool
    {
        $configuration = $this->input("rules.{$group}");

        return is_array($configuration)
            && array_key_exists('multiplier_anchors_basis_points', $configuration);
    }

    private function validateLegalRanges(Validator $validator): void
    {
        $expected = $this->expectedRanges();

        foreach (self::GROUPS as $group) {
            $range = $this->input("legalRanges.{$group}");

            if (! is_array($range)) {
                $validator->errors()->add(
                    "legalRanges.{$group}",
                    'The pricing snapshot declares no legal range for this group.',
                );

                continue;
            }

            // An anchor curve derives its floor from the live admin settings, so
            // the declared minimum is advisory - that is what stops an admin
            // change from needing a matching edit in n8n. A threshold map cannot
            // answer below its first entry, so its declared minimum must agree.
            $ignore = $this->anchored($group) ? ['minimum' => null] : [];
            $received = array_diff_key($range, $ignore);
            $against = array_diff_key($expected[$group], $ignore);

            if ($received !== $against) {
                $validator->errors()->add(
                    "legalRanges.{$group}",
                    sprintf(
                        'The pricing range does not match the active Coins quantity settings. Expected %s, received %s.',
                        json_encode($against),
                        json_encode($received),
                    ),
                );
            }
        }
    }

    private function validateRuleConfigurations(Validator $validator): void
    {
        $expected = $this->expectedRanges();

        foreach (self::GROUPS as $group) {
            $configuration = $this->input("rules.{$group}");

            if (! is_array($configuration)) {
                continue;
            }

            try {
                $rule = CoinsPricingRule::fromConfiguration($configuration, $group);
                $rule->multiplierBasisPoints((int) $this->input("legalRanges.{$group}.minimum"));
                $rule->multiplierBasisPoints((int) $this->input("legalRanges.{$group}.maximum"));
            } catch (Throwable $exception) {
                $validator->errors()->add(
                    "rules.{$group}",
                    'The Coins pricing rule is malformed or does not cover its legal quantity range.',
                );

                continue;
            }

            if ($rule->isAnchored()) {
                $this->validateAnchorCoverage($validator, $group, $rule, $expected[$group]);
            }
        }
    }

    /**
     * An anchor curve clamps outside its published range, so both ends need a
     * bound that the threshold shape got for free by simply throwing.
     *
     * @param  array{minimum: int, maximum: int, increment: int}  $range
     */
    private function validateAnchorCoverage(
        Validator $validator,
        string $group,
        CoinsPricingRule $rule,
        array $range,
    ): void {
        // Clamping at the top is never safe: a curve stopping at two million
        // would price a twenty million order at the two million rate.
        if ($rule->highestCoveredQuantity() < $range['maximum']) {
            $validator->errors()->add(
                "rules.{$group}",
                sprintf(
                    'The multiplier curve does not reach the store maximum. Highest anchor %d, maximum %d.',
                    $rule->highestCoveredQuantity(),
                    $range['maximum'],
                ),
            );
        }

        // Clamping at the bottom is safe only when there is nothing to clamp, or
        // when the first anchor is the dearest rate on the table. The commercial
        // curve dips at one million and climbs again, so being dearest is a
        // property to check rather than a shape to assume.
        if ($rule->lowestCoveredQuantity() > $range['minimum'] && ! $rule->firstAnchorIsDearest()) {
            $validator->errors()->add(
                "rules.{$group}",
                sprintf(
                    'The multiplier curve starts at %d, above the %d minimum, and its first anchor is not its dearest rate.',
                    $rule->lowestCoveredQuantity(),
                    $range['minimum'],
                ),
            );
        }
    }
}
