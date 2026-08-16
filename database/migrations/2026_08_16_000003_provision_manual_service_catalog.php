<?php

use App\Enums\Market;
use App\Enums\Platform;
use App\Enums\ProductAuthority;
use App\Enums\ServiceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach ($this->services() as $service) {
                $productId = $this->provisionProduct($service);

                foreach ($service['variants'] as $variant) {
                    $this->provisionVariant($service, $variant, $productId);
                }
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::table('product_variants')->whereIn('public_id', [
                '01K2HZ00000000000000000011',
                '01K2HZ00000000000000000012',
                '01K2HZ00000000000000000021',
                '01K2HZ00000000000000000022',
            ])->delete();

            DB::table('products')->whereIn('public_id', [
                '01K2HZ00000000000000000001',
                '01K2HZ00000000000000000002',
            ])->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $service
     */
    private function provisionProduct(array $service): int
    {
        $existing = DB::table('products')->where('slug', $service['slug'])->first();

        if ($existing !== null) {
            if ($existing->authority !== ProductAuthority::Manual->value
                || $existing->service_type !== $service['service_type']) {
                throw new RuntimeException("The manual-service product slug [{$service['slug']}] is already owned by another catalog authority.");
            }

            return (int) $existing->id;
        }

        $this->rejectPublicIdConflict('products', $service['public_id']);

        return (int) DB::table('products')->insertGetId([
            'public_id' => $service['public_id'],
            'category_id' => null,
            'source_id' => null,
            'external_id' => null,
            'slug' => $service['slug'],
            'service_type' => $service['service_type'],
            'authority' => ProductAuthority::Manual->value,
            'name_ar' => $service['name_ar'],
            'name_en' => $service['name_en'],
            'description_ar' => $service['description_ar'],
            'description_en' => $service['description_en'],
            'is_visible' => true,
            'sort_order' => $service['sort_order'],
            'archived_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $service
     * @param  array<string, string>  $variant
     */
    private function provisionVariant(array $service, array $variant, int $productId): void
    {
        $existing = DB::table('product_variants')->where('sku', $variant['sku'])->first();

        if ($existing !== null) {
            if ((int) $existing->product_id !== $productId
                || $existing->authority !== ProductAuthority::Manual->value
                || $existing->service_type !== $service['service_type']
                || $existing->platform !== $variant['platform']) {
                throw new RuntimeException("The manual-service variant SKU [{$variant['sku']}] conflicts with another catalog record.");
            }

            return;
        }

        $this->rejectPublicIdConflict('product_variants', $variant['public_id']);

        DB::table('product_variants')->insert([
            'public_id' => $variant['public_id'],
            'product_id' => $productId,
            'source_id' => null,
            'external_id' => null,
            'sku' => $variant['sku'],
            'service_type' => $service['service_type'],
            'platform' => $variant['platform'],
            'market' => $variant['platform'] === Platform::Pc->value
                ? Market::Pc->value
                : Market::Console->value,
            'authority' => ProductAuthority::Manual->value,
            'name_ar' => $variant['name_ar'],
            'name_en' => $variant['name_en'],
            'quantity_k' => null,
            'price_halalah' => 0,
            'sale_price_halalah' => null,
            'price_version' => 1,
            'configuration' => json_encode(['manual_service' => true], JSON_THROW_ON_ERROR),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function rejectPublicIdConflict(string $table, string $publicId): void
    {
        if (DB::table($table)->where('public_id', $publicId)->exists()) {
            throw new RuntimeException("The reserved manual-service public ID [{$publicId}] is already in use.");
        }
    }

    /** @return list<array<string, mixed>> */
    private function services(): array
    {
        return [
            [
                'public_id' => '01K2HZ00000000000000000001',
                'slug' => 'fut-champions',
                'service_type' => ServiceType::FutChampions->value,
                'name_ar' => 'خدمة لعب الفوت',
                'name_en' => 'FUT Champions service',
                'description_ar' => 'نوصل حسابك للرانك المطلوب في الفوت الحالي.',
                'description_en' => 'Reach your requested rank in the current FUT Champions event.',
                'sort_order' => 30,
                'variants' => [
                    [
                        'public_id' => '01K2HZ00000000000000000011',
                        'sku' => 'MANUAL_FUT_CHAMPIONS_PLAYSTATION',
                        'platform' => Platform::PlayStation->value,
                        'name_ar' => 'بلايستيشن',
                        'name_en' => 'PlayStation',
                    ],
                    [
                        'public_id' => '01K2HZ00000000000000000012',
                        'sku' => 'MANUAL_FUT_CHAMPIONS_PC',
                        'platform' => Platform::Pc->value,
                        'name_ar' => 'بي سي',
                        'name_en' => 'PC',
                    ],
                ],
            ],
            [
                'public_id' => '01K2HZ00000000000000000002',
                'slug' => 'division-rivals',
                'service_type' => ServiceType::Rivals->value,
                'name_ar' => 'خدمة الرايفلز',
                'name_en' => 'Division Rivals service',
                'description_ar' => 'نرفع حسابك من الديفجن الحالي إلى الديفجن المطلوب.',
                'description_en' => 'Move from your current Division Rivals division to your chosen target.',
                'sort_order' => 40,
                'variants' => [
                    [
                        'public_id' => '01K2HZ00000000000000000021',
                        'sku' => 'MANUAL_RIVALS_PLAYSTATION',
                        'platform' => Platform::PlayStation->value,
                        'name_ar' => 'بلايستيشن',
                        'name_en' => 'PlayStation',
                    ],
                    [
                        'public_id' => '01K2HZ00000000000000000022',
                        'sku' => 'MANUAL_RIVALS_PC',
                        'platform' => Platform::Pc->value,
                        'name_ar' => 'بي سي',
                        'name_en' => 'PC',
                    ],
                ],
            ],
        ];
    }
};
