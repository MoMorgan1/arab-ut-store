<?php

namespace App\Actions\Pricing;

use App\Enums\ServiceType;
use App\Exceptions\CoinsPricingEventReplay;
use App\Exceptions\CoinsPricingRunReplay;
use App\Models\PriceRule;
use App\Models\PriceRun;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

final class ApplyCoinsPricingRun
{
    /** @var list<string> */
    private const GROUPS = ['console_normal', 'console_fast', 'pc'];

    /** @param array<string, mixed> $payload */
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

    /** @param array<string, mixed> $payload */
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
                array_flip(['source', 'ratioEuroUsd', 'cyclePSUsdPerM', 'cyclePCUsdPerM']),
            );
        }

        return $safe;
    }
}
