<?php

namespace App\ValueObjects\Pricing;

use DomainException;

/**
 * The quantities a customer may buy, and where the slider stops.
 *
 * Two different things live here, and keeping them apart is the point.
 *
 * The rounding unit is what a customer may actually buy: any multiple of it
 * between the floor and the ceiling. Typing an exact amount is the normal way
 * to order, so the buyable set is deliberately dense.
 *
 * The bands are only where the slider stops. A single step across the whole
 * range does not work for dragging: the range runs from thousands to twenty
 * million, so a step fine enough at the bottom leaves the slider crawling at
 * three million, and a step coarse enough at the top skips every small order.
 * The slider therefore steps in bands while the customer stays free to type
 * anything on the unit.
 *
 * Every band step is a multiple of the unit, so every slider stop is itself
 * buyable, and every band must divide evenly by its own step so the slider
 * lands exactly on each band ceiling.
 */
final readonly class CoinsQuantityRules
{
    /**
     * Used when a stored configuration predates the rounding unit. It has to
     * divide the floor and every band step, which the validation below checks.
     */
    private const DEFAULT_ROUNDING_UNIT = 5_000;

    /**
     * @param  non-empty-list<array{upTo:int,step:int}>  $tiers
     * @param  list<int>  $presets
     */
    private function __construct(
        private int $minimum,
        private int $roundingUnit,
        private array $tiers,
        private array $presets,
    ) {}

    /** @param array<string, mixed> $configuration */
    public static function fromConfiguration(array $configuration): self
    {
        $minimum = self::positiveInteger($configuration['minimum'] ?? null, 'minimum');
        $roundingUnit = self::positiveInteger(
            $configuration['roundingUnit'] ?? self::DEFAULT_ROUNDING_UNIT,
            'rounding unit',
        );

        if ($minimum % $roundingUnit !== 0) {
            throw new DomainException('The Coins quantity minimum must be a multiple of the rounding unit.');
        }

        $tiers = self::parseTiers($configuration['tiers'] ?? null, $minimum, $roundingUnit);
        $presets = self::parsePresets($configuration['presets'] ?? null, $minimum, $roundingUnit, $tiers);

        return new self($minimum, $roundingUnit, $tiers, $presets);
    }

    public function minimum(): int
    {
        return $this->minimum;
    }

    public function maximum(): int
    {
        return $this->tiers[array_key_last($this->tiers)]['upTo'];
    }

    /** The grain a typed quantity is rounded to, and the only step that binds. */
    public function roundingUnit(): int
    {
        return $this->roundingUnit;
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
     * Where the slider stops, in order. Not the buyable set — see accepts().
     *
     * @return non-empty-list<int>
     */
    public function sliderStops(): array
    {
        $stops = [$this->minimum];
        $current = $this->minimum;

        foreach ($this->tiers as $tier) {
            while ($current < $tier['upTo']) {
                $current += $tier['step'];
                $stops[] = $current;
            }
        }

        return $stops;
    }

    public function accepts(int $quantity): bool
    {
        return $quantity >= $this->minimum
            && $quantity <= $this->maximum()
            && $quantity % $this->roundingUnit === 0;
    }

    /**
     * The buyable quantity closest to what the customer typed.
     *
     * A value exactly between two units rounds up, so the number never drops
     * below what someone deliberately asked for by more than they gain.
     */
    public function round(int $quantity): int
    {
        $clamped = max($this->minimum, min($this->maximum(), $quantity));
        $rounded = (int) (floor($clamped / $this->roundingUnit + 0.5) * $this->roundingUnit);

        return max($this->minimum, min($this->maximum(), $rounded));
    }

    /**
     * The single increment the pricing run speaks.
     *
     * n8n publishes one legal range per group as minimum/maximum/increment, and
     * this is the increment that has to match: every quantity accepted here is
     * a multiple of it, bands included.
     */
    public function finestStep(): int
    {
        return $this->roundingUnit;
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
    private static function parseTiers(mixed $configured, int $minimum, int $roundingUnit): array
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

            if ($step % $roundingUnit !== 0) {
                throw new DomainException('A Coins quantity tier step must be a multiple of the rounding unit.');
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
    private static function parsePresets(mixed $configured, int $minimum, int $roundingUnit, array $tiers): array
    {
        if (! is_array($configured) || ! array_is_list($configured)) {
            throw new DomainException('The Coins quantity presets must be a list.');
        }

        $rules = new self($minimum, $roundingUnit, $tiers, []);
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
