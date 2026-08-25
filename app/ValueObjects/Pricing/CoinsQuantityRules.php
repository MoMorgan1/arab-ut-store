<?php

namespace App\ValueObjects\Pricing;

use DomainException;

/**
 * The quantities a customer may buy, and the step the slider moves in.
 *
 * A single step across the whole range does not work here: the range runs from
 * thousands to twenty million, so a step fine enough at the bottom leaves the
 * slider crawling in tiny jumps at three million, and a step coarse enough at
 * the top skips every small order. The step therefore grows in bands.
 *
 * Every band must divide evenly, because the quote schedule prices each legal
 * quantity ahead of time and a band that does not land exactly on its own
 * ceiling would leave a gap the storefront could not price.
 */
final readonly class CoinsQuantityRules
{
    /**
     * @param  non-empty-list<array{upTo:int,step:int}>  $tiers
     * @param  list<int>  $presets
     */
    private function __construct(
        private int $minimum,
        private array $tiers,
        private array $presets,
    ) {}

    /** @param array<string, mixed> $configuration */
    public static function fromConfiguration(array $configuration): self
    {
        $minimum = self::positiveInteger($configuration['minimum'] ?? null, 'minimum');
        $tiers = self::parseTiers($configuration['tiers'] ?? null, $minimum);
        $presets = self::parsePresets($configuration['presets'] ?? null, $minimum, $tiers);

        return new self($minimum, $tiers, $presets);
    }

    public function minimum(): int
    {
        return $this->minimum;
    }

    public function maximum(): int
    {
        return $this->tiers[array_key_last($this->tiers)]['upTo'];
    }

    /** @return non-empty-list<array{upTo:int,step:int}> */
    public function tiers(): array
    {
        return $this->tiers;
    }

    /** @return list<int> */
    public function presets(): array
    {
        return $this->presets;
    }

    /**
     * Every quantity the storefront may quote, in order.
     *
     * @return non-empty-list<int>
     */
    public function legalQuantities(): array
    {
        $quantities = [$this->minimum];
        $current = $this->minimum;

        foreach ($this->tiers as $tier) {
            while ($current < $tier['upTo']) {
                $current += $tier['step'];
                $quantities[] = $current;
            }
        }

        return $quantities;
    }

    public function accepts(int $quantity): bool
    {
        if ($quantity < $this->minimum || $quantity > $this->maximum()) {
            return false;
        }

        $floor = $this->minimum;

        foreach ($this->tiers as $tier) {
            if ($quantity <= $tier['upTo']) {
                return ($quantity - $floor) % $tier['step'] === 0;
            }

            $floor = $tier['upTo'];
        }

        return false;
    }

    /**
     * The finest step in the bands.
     *
     * Every coarser band is a multiple of it, so every quantity this object
     * accepts also satisfies the single-increment contract the pricing run
     * still speaks. That keeps the n8n handshake valid while the storefront
     * quotes the narrower, banded set.
     */
    public function finestStep(): int
    {
        return min(array_column($this->tiers, 'step'));
    }

    /** The step in force at a quantity, so the slider can widen as it climbs. */
    public function stepAt(int $quantity): int
    {
        foreach ($this->tiers as $tier) {
            if ($quantity < $tier['upTo']) {
                return $tier['step'];
            }
        }

        return $this->tiers[array_key_last($this->tiers)]['step'];
    }

    /**
     * @return non-empty-list<array{upTo:int,step:int}>
     */
    private static function parseTiers(mixed $configured, int $minimum): array
    {
        if (! is_array($configured) || ! array_is_list($configured) || $configured === []) {
            throw new DomainException('The Coins quantity tiers must be a non-empty list.');
        }

        $tiers = [];
        $floor = $minimum;

        foreach ($configured as $tier) {
            if (! is_array($tier) || array_diff(array_keys($tier), ['upTo', 'step']) !== []) {
                throw new DomainException('A Coins quantity tier must declare exactly upTo and step.');
            }

            $upTo = self::positiveInteger($tier['upTo'] ?? null, 'tier ceiling');
            $step = self::positiveInteger($tier['step'] ?? null, 'tier step');

            if ($upTo <= $floor) {
                throw new DomainException('The Coins quantity tiers must ascend without overlapping.');
            }

            if (($upTo - $floor) % $step !== 0) {
                throw new DomainException('A Coins quantity tier must be divisible by its own step.');
            }

            $tiers[] = ['upTo' => $upTo, 'step' => $step];
            $floor = $upTo;
        }

        return $tiers;
    }

    /**
     * @param  non-empty-list<array{upTo:int,step:int}>  $tiers
     * @return list<int>
     */
    private static function parsePresets(mixed $configured, int $minimum, array $tiers): array
    {
        if (! is_array($configured) || ! array_is_list($configured)) {
            throw new DomainException('The Coins quantity presets must be a list.');
        }

        $rules = new self($minimum, $tiers, []);
        $presets = [];

        foreach ($configured as $preset) {
            $value = self::positiveInteger($preset, 'preset');

            if (! $rules->accepts($value)) {
                throw new DomainException('A Coins quantity preset must be a quantity a customer can actually buy.');
            }

            $presets[] = $value;
        }

        return $presets;
    }

    private static function positiveInteger(mixed $value, string $field): int
    {
        if (! is_int($value) || $value <= 0) {
            throw new DomainException("The Coins quantity {$field} must be a positive integer.");
        }

        return $value;
    }
}
