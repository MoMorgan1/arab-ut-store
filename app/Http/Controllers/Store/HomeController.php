<?php

namespace App\Http\Controllers\Store;

use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Catalog\CoinsCatalogReader;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;
use ValueError;

class HomeController extends Controller
{
    public function __invoke(Request $request, CoinsCatalogReader $catalog): Response
    {
        $product = null;

        try {
            $product = $catalog->assertHomepageAvailable();
        } catch (DomainException|ValueError) {
            // The public homepage stays available while pricing fails closed.
        }

        return Inertia::render('store/home', [
            'status' => $product instanceof Product ? 'available' : 'unavailable',
            'product' => $product instanceof Product ? [
                'publicId' => $product->public_id,
                'name' => $product->getAttribute('name_'.app()->getLocale()),
                'imageUrl' => config('coins.product_image_url'),
            ] : null,
            'quoteUrl' => $request->route('locale') === 'en'
                ? route('localized.coins.quote', ['locale' => 'en'], absolute: false)
                : route('coins.quote', absolute: false),
            'amount' => [
                'minimum' => config('coins.quantity.minimum'),
                'increment' => config('coins.quantity.increment'),
                'presets' => config('coins.quantity.presets'),
            ],
            'platforms' => $this->platforms(),
            'store' => trans('store'),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function platforms(): array
    {
        $platforms = [];

        foreach ([Platform::PlayStation, Platform::Pc] as $platform) {
            $platforms[] = [
                'value' => $platform->value,
                'label' => $this->translation("store.platform.descriptions.{$platform->value}"),
                'iconUrls' => Config::array("coins.platforms.{$platform->value}.icon_urls"),
                'maximum' => Config::integer("coins.platforms.{$platform->value}.maximum"),
                'deliveries' => $this->deliveries($platform),
            ];
        }

        return $platforms;
    }

    /** @return list<array{value: string, label: string, maximum: int, minutesPerMillion: int}> */
    private function deliveries(Platform $platform): array
    {
        $configuredDeliveries = Config::array("coins.platforms.{$platform->value}.deliveries");
        $deliveries = [];

        foreach (array_keys($configuredDeliveries) as $delivery) {
            if (! is_string($delivery)) {
                throw new LogicException('A configured Coins delivery key must be a string.');
            }

            $prefix = "coins.platforms.{$platform->value}.deliveries.{$delivery}";
            $deliveries[] = [
                'value' => $delivery,
                'label' => $this->translation("store.delivery.options.{$delivery}"),
                'maximum' => Config::integer("{$prefix}.maximum"),
                'minutesPerMillion' => Config::integer("{$prefix}.minutes_per_million"),
            ];
        }

        return $deliveries;
    }

    private function translation(string $key): string
    {
        $translation = trans($key);

        if (! is_string($translation)) {
            throw new LogicException("The store translation [{$key}] must be a string.");
        }

        return $translation;
    }
}
