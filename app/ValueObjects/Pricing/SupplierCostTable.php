<?php

namespace App\ValueObjects\Pricing;

use App\Enums\DeliveryMode;
use App\Enums\Platform;
use DomainException;

/**
 * What the suppliers were last seen selling coins for, and the ceiling a
 * placement may spend against it.
 *
 * `Fulfillment v14` read this table from the `ArabUT Price Settings` sheet:
 * a USD-per-million figure per platform, one for slow delivery and six by
 * quantity tier for fast, converted to the EUR-per-100K figure both suppliers
 * take as a maximum price. The sheet was filled by the same hourly supplier
 * probe that now publishes pricing runs to the store, and the run already
 * carries the figures under `observations.tierCosts` - the store just threw
 * them away. Owner decision, 2026-09-14: the budget is that observed cost as
 * it is, no margin on top, exactly as v14 took the sheet's number as it was.
 *
 * Units are the workflow's: `rawUsdPerM` is US dollars per million coins,
 * `ratioEuroUsd` is dollars per euro, and the result is euros per 100K.
 */
final readonly class SupplierCostTable
{
    /** The tier v14 priced a challenge's coins at when nothing else decided it. */
    private const int CHALLENGE_TIER_K = 2_000;

    /**
     * @param  list<array{targetK: int, rawUsdPerM: float, source: string}>  $consoleFast
     * @param  list<array{targetK: int, rawUsdPerM: float, source: string}>  $pc
     */
    private function __construct(
        public int $pricingVersion,
        public float $ratioEuroUsd,
        public ?float $cyclePsUsdPerM,
        public array $consoleFast,
        public array $pc,
    ) {}

    /**
     * @param  array<string, mixed>  $observations  the `observations` block of an applied pricing run
     *
     * @throws DomainException when the run carries no usable cost table
     */
    public static function fromObservations(int $pricingVersion, array $observations): self
    {
        $ratio = $observations['ratioEuroUsd'] ?? null;

        if (! is_numeric($ratio) || (float) $ratio <= 0) {
            throw new DomainException('The pricing run carries no EUR/USD ratio.');
        }

        $tiers = $observations['tierCosts'] ?? null;

        if (! is_array($tiers)) {
            throw new DomainException('The pricing run carries no supplier cost tiers.');
        }

        $cycle = $observations['cyclePSUsdPerM'] ?? null;

        return new self(
            pricingVersion: $pricingVersion,
            ratioEuroUsd: (float) $ratio,
            cyclePsUsdPerM: is_numeric($cycle) && (float) $cycle > 0 ? (float) $cycle : null,
            consoleFast: self::rows($tiers['console_fast'] ?? null, 'console_fast'),
            pc: self::rows($tiers['pc'] ?? null, 'pc'),
        );
    }

    /**
     * The ceiling for a coins shipment: by quantity tier on fast and PC
     * delivery, the cycle cost on slow console delivery, the way the sheet's
     * `slow_AnyQty` and `fast_Tier1..6` rows were picked.
     *
     * @return array{max_eur_per_100k: float, basis: string}
     *
     * @throws DomainException when the platform is not one a supplier serves,
     *                         or the row the delivery needs is missing
     */
    public function forCoins(Platform $platform, ?DeliveryMode $delivery, int $coins): array
    {
        if ($platform === Platform::Pc) {
            return $this->fromTier($this->pc, 'pc', $coins);
        }

        if ($platform !== Platform::PlayStation) {
            throw new DomainException("No supplier sells coins on {$platform->value}.");
        }

        if ($delivery === DeliveryMode::Normal) {
            if ($this->cyclePsUsdPerM === null) {
                throw new DomainException('The pricing run carries no console cycle cost.');
            }

            return [
                'max_eur_per_100k' => $this->eurPer100k($this->cyclePsUsdPerM),
                'basis' => 'cycle:ps',
            ];
        }

        return $this->fromTier($this->consoleFast, 'console_fast', $coins);
    }

    /**
     * The ceiling for the coins a challenge costs. The supplier prices the
     * challenge itself at solve time, so the quantity is unknown here and v14
     * used its second tier for every challenge.
     *
     * @return array{max_eur_per_100k: float, basis: string}
     *
     * @throws DomainException
     */
    public function forChallenge(Platform $platform): array
    {
        return match ($platform) {
            Platform::Pc => $this->fromTier($this->pc, 'pc', self::CHALLENGE_TIER_K * 1_000),
            Platform::PlayStation => $this->fromTier($this->consoleFast, 'console_fast', self::CHALLENGE_TIER_K * 1_000),
            default => throw new DomainException("No supplier solves challenges on {$platform->value}."),
        };
    }

    /**
     * @param  list<array{targetK: int, rawUsdPerM: float, source: string}>  $rows
     * @return array{max_eur_per_100k: float, basis: string}
     */
    private function fromTier(array $rows, string $group, int $coins): array
    {
        $amountK = (int) ceil($coins / 1_000);
        $picked = null;

        // The first tier whose cap reaches the quantity; past the last cap the
        // last tier holds, which is v14's `fast_Tier6` for anything over 15M.
        foreach ($rows as $row) {
            $picked = $row;

            if ($row['targetK'] >= $amountK) {
                break;
            }
        }

        if ($picked === null) {
            throw new DomainException("The pricing run carries no {$group} cost tiers.");
        }

        return [
            'max_eur_per_100k' => $this->eurPer100k($picked['rawUsdPerM']),
            'basis' => "{$group}:{$picked['targetK']}K",
        ];
    }

    /**
     * v14: `round((usdPerM * usdToEur / 10) * 100) / 100`, with `usdToEur`
     * the sheet's own rate. The run publishes the inverse ratio.
     */
    private function eurPer100k(float $usdPerM): float
    {
        return round($usdPerM / $this->ratioEuroUsd / 10, 2);
    }

    /**
     * A group with no rows is a platform nobody is selling on, not a broken
     * run. It stays empty here and `fromTier` raises when a shipment on that
     * platform actually asks for a ceiling - one refused shipment rather than
     * every shipment, which is what an eager throw here cost us when FC27
     * opened with an empty PC market.
     *
     * @return list<array{targetK: int, rawUsdPerM: float, source: string}>
     *
     * @throws DomainException
     */
    private static function rows(mixed $rows, string $group): array
    {
        if ($rows === null || $rows === []) {
            return [];
        }

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new DomainException("The pricing run carries no {$group} cost tiers.");
        }

        $clean = [];
        $previousK = 0;

        foreach ($rows as $row) {
            $targetK = is_array($row) ? ($row['targetK'] ?? null) : null;
            $usdPerM = is_array($row) ? ($row['rawUsdPerM'] ?? null) : null;

            if (! is_int($targetK) || $targetK <= $previousK || ! is_numeric($usdPerM) || (float) $usdPerM <= 0) {
                throw new DomainException("The {$group} cost tiers are malformed.");
            }

            $source = $row['source'] ?? ($row['selectedSource'] ?? null);

            $clean[] = [
                'targetK' => $targetK,
                'rawUsdPerM' => (float) $usdPerM,
                'source' => is_string($source) ? $source : 'unknown',
            ];
            $previousK = $targetK;
        }

        return $clean;
    }
}
