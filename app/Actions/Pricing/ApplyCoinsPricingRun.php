<?php

namespace App\Actions\Pricing;

use App\Enums\ServiceType;
use App\Exceptions\CoinsPricingEventReplay;
use App\Exceptions\CoinsPricingRunReplay;
use App\Models\PriceRule;
use App\Models\PriceRun;
use App\Models\ProductVariant;
use App\ValueObjects\Pricing\CoinsPricingRule;
use Illuminate\Support\Facades\DB;

final class ApplyCoinsPricingRun
{
    /** @var list<string> */
    private const GROUPS = ['console_normal', 'console_fast', 'pc'];

    /**
     * @param  array<string, mixed>  $payload
     * @return array{runId: string, status: string, pricingVersion: int, groups: list<string>}
     */
    public function execute(array $payload): array
    {
        $runId = (string) $payload['runId'];
        $eventId = (string) $payload['eventId'];

        if (PriceRun::query()->where('run_id', $runId)->exists()) {
            throw new CoinsPricingRunReplay;
        }

        if (PriceRun::query()->where('event_id', $eventId)->exists()) {
            throw new CoinsPricingEventReplay;
        }

        // Every entry that only repeats the one before it is dropped here, before
        // a single row is written. The run is already validated at this point and
        // the collapse is price-preserving, so this is purely a matter of not
        // storing the same number several thousand times per group - twice, once
        // in the run's own payload and once in the rule the storefront reads.
        foreach (self::GROUPS as $group) {
            $payload['rules'][$group] = CoinsPricingRule::withoutRedundantMultipliers(
                $payload['rules'][$group],
            );
        }

        return DB::transaction(function () use ($payload, $runId, $eventId): array {
            if (PriceRun::query()->where('run_id', $runId)->lockForUpdate()->exists()) {
                throw new CoinsPricingRunReplay;
            }

            if (PriceRun::query()->where('event_id', $eventId)->lockForUpdate()->exists()) {
                throw new CoinsPricingEventReplay;
            }

            $variants = ProductVariant::query()
                ->where('service_type', ServiceType::Coins->value)
                ->where('is_active', true)
                ->lockForUpdate()
                ->get();
            $pricingVersion = max(
                1,
                ((int) $variants->max('price_version')) + 1,
                ((int) PriceRun::query()->max('pricing_version')) + 1,
            );
            $mode = (string) $payload['mode'];
            $status = $mode === 'apply' ? 'applied' : 'proposed';

            $run = PriceRun::query()->create([
                'run_id' => $runId,
                'event_id' => $eventId,
                'status' => $status,
                'mode' => $mode,
                'pricing_version' => $pricingVersion,
                'payload' => $this->safePayload($payload),
                'started_at' => now(),
                'completed_at' => now(),
            ]);

            if ($mode === 'apply') {
                PriceRule::query()
                    ->where('service_type', ServiceType::Coins->value)
                    ->whereNull('product_variant_id')
                    ->whereNull('platform')
                    ->where('is_active', true)
                    ->update(['is_active' => false]);

                foreach (self::GROUPS as $group) {
                    PriceRule::query()->create([
                        'name' => "n8n Coins {$group} {$runId}",
                        'service_type' => ServiceType::Coins,
                        'platform' => null,
                        'configuration' => $payload['rules'][$group],
                        'is_active' => true,
                    ]);
                }

                foreach ($variants as $variant) {
                    $variant->increment('price_version');
                }
            }

            return [
                'runId' => $runId,
                'status' => $status,
                'pricingVersion' => $pricingVersion,
                'groups' => self::GROUPS,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function safePayload(array $payload): array
    {
        $safe = [
            'schemaVersion' => $payload['schemaVersion'],
            'eventId' => $payload['eventId'],
            'runId' => $payload['runId'],
            'generatedAt' => $payload['generatedAt'],
            'mode' => $payload['mode'],
            'serviceType' => $payload['serviceType'],
            'legalRanges' => $payload['legalRanges'],
            'rules' => $payload['rules'],
        ];

        if (is_array($payload['observations'] ?? null)) {
            $safe['observations'] = array_intersect_key(
                $payload['observations'],
                array_flip(['source', 'ratioEuroUsd', 'cyclePSUsdPerM', 'cyclePCUsdPerM', 'availableCoins']),
            );

            // The raw supplier cost per tier is what a placement may spend
            // (SupplierCostTable). Only the three figures the budget reads are
            // kept: the workflow's candidate lists carry per-provider detail
            // that nothing here needs to hold.
            if (is_array($payload['observations']['tierCosts'] ?? null)) {
                $safe['observations']['tierCosts'] = self::costTiers($payload['observations']['tierCosts']);
            }
        }

        return $safe;
    }

    /**
     * @param  array<string, mixed>  $tiers
     * @return array<string, list<array{targetK: int, rawUsdPerM: float, source: string}>>
     */
    private static function costTiers(array $tiers): array
    {
        $kept = [];

        // Both groups are always named, an empty list included: a platform with
        // no market this hour is a fact the budget should be able to read back,
        // and a key that is simply absent reads the same as an older run that
        // never carried the group at all.
        foreach (['console_fast', 'pc'] as $group) {
            $rows = $tiers[$group] ?? null;

            if (! is_array($rows)) {
                $kept[$group] = [];

                continue;
            }

            $kept[$group] = array_values(array_map(
                static fn (array $row): array => [
                    'targetK' => (int) $row['targetK'],
                    'rawUsdPerM' => (float) $row['rawUsdPerM'],
                    'source' => is_string($row['selectedSource'] ?? null) ? $row['selectedSource'] : 'unknown',
                ],
                array_filter($rows, 'is_array'),
            ));
        }

        return $kept;
    }
}
