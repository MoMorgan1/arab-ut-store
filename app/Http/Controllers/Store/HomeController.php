<?php

namespace App\Http\Controllers\Store;

use App\Actions\Pricing\BuildCoinsQuoteSchedule;
use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Validation\CoinsSelectionRules;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;
use ValueError;

class HomeController extends Controller
{
    public function __invoke(
        Request $request,
        CoinsSelectionRules $selectionRules,
        BuildCoinsQuoteSchedule $buildCoinsQuoteSchedule,
    ): Response {
        $status = 'unavailable';
        $quoteSchedules = null;

        try {
            $displayCurrency = (string) $request->session()->get('display_currency');
            $quoteSchedules = $buildCoinsQuoteSchedule->executeHomepage($displayCurrency);
            $status = 'available';
        } catch (DomainException|ValueError) {
            // The public homepage stays available while pricing fails closed.
        }

        return Inertia::render('store/home', [
            'status' => $status,
            ...($quoteSchedules === null ? [] : ['quoteSchedules' => $quoteSchedules]),
            'quoteUrl' => $request->route('locale') === 'en'
                ? route('localized.coins.quote', ['locale' => 'en'], absolute: false)
                : route('coins.quote', absolute: false),
            'coinsCart' => [
                'addUrl' => $this->storeRoute($request, 'cart.items.coins.store'),
                'initialSelection' => $this->initialSelection($request, $selectionRules),
            ],
            'amount' => [
                'minimum' => config('coins.quantity.minimum'),
                'increment' => config('coins.quantity.increment'),
                'presets' => config('coins.quantity.presets'),
            ],
            'platforms' => $this->platforms(),
            'store' => trans('store'),
        ]);
    }

    private function storeRoute(Request $request, string $route): string
    {
        return $request->route('locale') === 'en'
            ? route("localized.{$route}", ['locale' => 'en'], absolute: false)
            : route($route, absolute: false);
    }

    /** @return array{platform: string, delivery: string|null, quantity: int}|null */
    private function initialSelection(Request $request, CoinsSelectionRules $selectionRules): ?array
    {
        if (
            $request->user() === null
            || $request->query('step') !== 'credentials'
            || $this->queryContainsCredentials($request)
        ) {
            return null;
        }

        $selection = $request->only(['platform', 'delivery', 'quantity']);
        $validator = Validator::make(
            $selection,
            $selectionRules->for($selection['platform'] ?? null, $selection['delivery'] ?? null),
        );

        if ($validator->fails()) {
            return null;
        }

        return [
            'platform' => (string) $selection['platform'],
            'delivery' => isset($selection['delivery']) ? (string) $selection['delivery'] : null,
            'quantity' => (int) $selection['quantity'],
        ];
    }

    private function queryContainsCredentials(Request $request): bool
    {
        foreach (['credentials', 'ea_email', 'ea_password', 'backup_codes'] as $field) {
            if ($request->query->has($field)) {
                return true;
            }
        }

        return false;
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
