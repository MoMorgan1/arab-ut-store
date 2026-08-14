<?php

namespace App\ValueObjects\Pricing;

use DomainException;

final readonly class SbcCompletionPricing
{
    /** @var list<array{completions:int,multiplierBps:int,totalMinor:int}> */
    private array $tiers;

    /**
     * @param  list<array{completions:int,multiplierBps:int,totalMinor:int}>  $tiers
     */
    private function __construct(
        private bool $repeatable,
        private ?int $maximum,
        array $tiers,
    ) {
        $this->tiers = $tiers;
    }

    /** @param array<string, mixed> $configuration */
    public static function fromConfiguration(
        array $configuration,
        int $fallbackMinor,
        bool $requireDeclared,
    ): self {
        if ($fallbackMinor <= 0) {
            throw new DomainException('The SBC completion price must be positive.');
        }

        $configured = $configuration['completionPricing'] ?? null;

        if ($configured === null) {
            if ($requireDeclared) {
                throw new DomainException('The SBC completion pricing must be declared.');
            }

            return new self(false, 1, [[
                'completions' => 1,
                'multiplierBps' => 10_000,
                'totalMinor' => $fallbackMinor,
            ]]);
        }

        if (! is_array($configured)) {
            throw new DomainException('The SBC completion pricing has an invalid shape.');
        }

        self::requireExactKeys($configured, ['version', 'repeatable', 'maximum', 'tiers']);

        if (($configured['version'] ?? null) !== 1 || ! is_bool($configured['repeatable'] ?? null)) {
            throw new DomainException('The SBC completion pricing identity is malformed.');
        }

        $repeatable = $configured['repeatable'];
        $maximum = self::maximum($configured['maximum'] ?? null, $repeatable);
        $expectedTiers = self::expectedTiers($repeatable, $maximum);
        $tiers = self::parseTiers($configured['tiers'] ?? null, $expectedTiers);

        if ($tiers[0]['totalMinor'] !== $fallbackMinor) {
            throw new DomainException('The first SBC completion tier must match the variant price.');
        }

        return new self($repeatable, $maximum, $tiers);
    }

    /** @return list<int> */
    public function completionCounts(): array
    {
        return array_column($this->tiers, 'completions');
    }

    public function tierTotal(int $completions): ?int
    {
        foreach ($this->tiers as $tier) {
            if ($tier['completions'] === $completions) {
                return $tier['totalMinor'];
            }
        }

        return null;
    }

    /** @return list<array{completions:int,multiplierBps:int,totalMinor:int}> */
    public function tiers(): array
    {
        return $this->tiers;
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'version' => 1,
            'repeatable' => $this->repeatable,
            'maximum' => $this->maximum,
            'tiers' => $this->tiers,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function maximum(mixed $configured, bool $repeatable): ?int
    {
        if (! $repeatable) {
            if ($configured !== 1) {
                throw new DomainException('A nonrepeatable SBC must have a maximum of one.');
            }

            return 1;
        }

        if ($configured === null) {
            return null;
        }

        if (! is_int($configured) || $configured < 2) {
            throw new DomainException('A repeatable SBC maximum must be null or at least two.');
        }

        return $configured;
    }

    /**
     * @return list<array{completions:int,multiplierBps:int}>
     */
    private static function expectedTiers(bool $repeatable, ?int $maximum): array
    {
        if (! $repeatable) {
            return [['completions' => 1, 'multiplierBps' => 10_000]];
        }

        if ($maximum !== null && $maximum < 5) {
            $tiers = [];

            for ($count = 1; $count <= $maximum; $count++) {
                $tiers[] = ['completions' => $count, 'multiplierBps' => 10_000];
            }

            return $tiers;
        }

        $standard = [
            ['completions' => 5, 'multiplierBps' => 10_000],
            ['completions' => 10, 'multiplierBps' => 9_500],
            ['completions' => 15, 'multiplierBps' => 9_200],
            ['completions' => 20, 'multiplierBps' => 9_000],
            ['completions' => 30, 'multiplierBps' => 8_700],
            ['completions' => 40, 'multiplierBps' => 8_500],
            ['completions' => 50, 'multiplierBps' => 8_200],
            ['completions' => 75, 'multiplierBps' => 7_800],
            ['completions' => 100, 'multiplierBps' => 7_600],
        ];

        if ($maximum === null || $maximum >= 100) {
            return $standard;
        }

        $tiers = array_values(array_filter(
            $standard,
            fn (array $tier): bool => $tier['completions'] <= $maximum,
        ));
        $last = $tiers[array_key_last($tiers)];

        if ($last['completions'] !== $maximum) {
            $tiers[] = [
                'completions' => $maximum,
                'multiplierBps' => max(7_000, $last['multiplierBps'] - 200),
            ];
        }

        return $tiers;
    }

    /**
     * @param  list<array{completions:int,multiplierBps:int}>  $expected
     * @return list<array{completions:int,multiplierBps:int,totalMinor:int}>
     */
    private static function parseTiers(mixed $configured, array $expected): array
    {
        if (! is_array($configured) || ! array_is_list($configured) || count($configured) !== count($expected)) {
            throw new DomainException('The SBC completion tiers have an invalid shape.');
        }

        $tiers = [];

        foreach ($configured as $index => $tier) {
            if (! is_array($tier)) {
                throw new DomainException('An SBC completion tier has an invalid shape.');
            }

            self::requireExactKeys($tier, ['completions', 'multiplierBps', 'totalMinor']);

            if (($tier['completions'] ?? null) !== $expected[$index]['completions']
                || ($tier['multiplierBps'] ?? null) !== $expected[$index]['multiplierBps']
                || ! is_int($tier['totalMinor'] ?? null)
                || $tier['totalMinor'] <= 0) {
                throw new DomainException('An SBC completion tier is outside the supported policy.');
            }

            $tiers[] = [
                'completions' => $tier['completions'],
                'multiplierBps' => $tier['multiplierBps'],
                'totalMinor' => $tier['totalMinor'],
            ];
        }

        return $tiers;
    }

    /**
     * @param  array<string, mixed>  $configured
     * @param  list<string>  $expected
     */
    private static function requireExactKeys(array $configured, array $expected): void
    {
        $actualKeys = array_keys($configured);
        sort($actualKeys);
        sort($expected);

        if ($actualKeys !== $expected) {
            throw new DomainException('The SBC completion pricing contains unsupported fields.');
        }
    }
}
