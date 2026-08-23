<?php

namespace App\Actions\AI;

/**
 * Turns the knowledge topics a customer asked about into clickable service
 * cards on the assistant's reply.
 *
 * The model never authors a card: it only answers. Cards are derived here from
 * the customer's own message, so a reply can never show a service, a link, or a
 * claim the store does not actually offer. Prices are deliberately absent —
 * they are live data and belong on the product page.
 */
final readonly class BuildAssistantCards
{
    /**
     * Topics that correspond to something a customer can actually order, mapped
     * to the storefront route that sells it. Troubleshooting and policy topics
     * are intentionally absent: a card is an invitation to buy.
     *
     * @var array<string, array{path: string, key: string, image: string}>
     */
    private const CARDS = [
        'coins-service' => ['path' => '/#coins', 'key' => 'coins', 'image' => '/images/store/coins/ut-coin-240.webp'],
        'coins-speeds' => ['path' => '/#coins', 'key' => 'coins', 'image' => '/images/store/coins/ut-coin-240.webp'],
        'sbc' => ['path' => '/sbc', 'key' => 'sbc', 'image' => '/images/store/services/sbc.webp'],
        'rivals' => ['path' => '/rivals', 'key' => 'rivals', 'image' => '/images/store/services/rivals.webp'],
        'fut-champions' => ['path' => '/fut-champions', 'key' => 'fut_champions', 'image' => '/images/store/services/fut-champions.webp'],
    ];

    public function __construct(
        private SelectSupportKnowledge $selectKnowledge,
        private SelectServiceOptions $selectOptions,
    ) {}

    /**
     * @return list<array{
     *     id: string,
     *     title: string,
     *     subtitle: string,
     *     cta: string,
     *     url: string,
     *     image: string,
     *     options: list<array{label: string, value: string}>
     * }>
     */
    public function execute(string $customerText, string $locale, int $limit = 1): array
    {
        if ($limit < 1 || trim($customerText) === '') {
            return [];
        }

        $cards = [];

        // Only the topic the message is *primarily* about earns a card. A
        // warranty question also brushes the delivery-speed topic, and turning
        // that into a buy button would be an upsell on a support answer.
        foreach ($this->selectKnowledge->execute($customerText, $limit) as $topic) {
            $card = self::CARDS[$topic->id] ?? null;

            if ($card === null || isset($cards[$card['key']])) {
                continue;
            }

            $detectedOptions = $this->selectOptions->execute($customerText, $card['key']);
            $formattedOptions = $this->formatOptions($card['key'], $detectedOptions, $locale);

            $cards[$card['key']] = [
                'id' => $card['key'],
                'title' => (string) trans("chat.cards.{$card['key']}.title", [], $locale),
                'subtitle' => (string) trans("chat.cards.{$card['key']}.subtitle", [], $locale),
                'cta' => (string) trans('chat.cards.cta', [], $locale),
                'url' => self::buildUrl($card['path'], $locale, $detectedOptions),
                'image' => $card['image'],
                'options' => $formattedOptions,
            ];
        }

        return array_values($cards);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<array{label: string, value: string}>
     */
    private function formatOptions(string $key, array $options, string $locale): array
    {
        if ($options === []) {
            return [];
        }

        return match ($key) {
            'coins' => $this->formatCoinsOptions($options, $locale),
            'rivals' => $this->formatRivalsOptions($options, $locale),
            'fut_champions' => $this->formatFutChampionsOptions($options, $locale),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<array{label: string, value: string}>
     */
    private function formatCoinsOptions(array $options, string $locale): array
    {
        $items = [];

        if (isset($options['platform']) && is_string($options['platform'])) {
            $items[] = [
                'label' => (string) trans('chat.cards.coins.options.platform', [], $locale),
                'value' => (string) trans("chat.cards.coins.platforms.{$options['platform']}", [], $locale),
            ];
        }

        if (isset($options['delivery']) && is_string($options['delivery'])) {
            $items[] = [
                'label' => (string) trans('chat.cards.coins.options.delivery', [], $locale),
                'value' => (string) trans("chat.cards.coins.deliveries.{$options['delivery']}", [], $locale),
            ];
        }

        if (isset($options['quantity']) && (is_int($options['quantity']) || is_numeric($options['quantity']))) {
            $items[] = [
                'label' => (string) trans('chat.cards.coins.options.quantity', [], $locale),
                'value' => (string) trans('chat.cards.coins.quantity_value', ['count' => number_format((int) $options['quantity'])], $locale),
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<array{label: string, value: string}>
     */
    private function formatRivalsOptions(array $options, string $locale): array
    {
        $items = [];

        if (isset($options['currentDivision']) && is_string($options['currentDivision'])) {
            $div = $options['currentDivision'];
            $value = $div === 'elite'
                ? (string) trans('chat.cards.rivals.elite', [], $locale)
                : (string) trans('chat.cards.rivals.division_value', ['division' => $div], $locale);

            $items[] = [
                'label' => (string) trans('chat.cards.rivals.options.current_division', [], $locale),
                'value' => $value,
            ];
        }

        if (isset($options['targetDivision']) && is_string($options['targetDivision'])) {
            $div = $options['targetDivision'];
            $value = $div === 'elite'
                ? (string) trans('chat.cards.rivals.elite', [], $locale)
                : (string) trans('chat.cards.rivals.division_value', ['division' => $div], $locale);

            $items[] = [
                'label' => (string) trans('chat.cards.rivals.options.target_division', [], $locale),
                'value' => $value,
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<array{label: string, value: string}>
     */
    private function formatFutChampionsOptions(array $options, string $locale): array
    {
        $items = [];

        if (isset($options['rank']) && (is_int($options['rank']) || is_numeric($options['rank']))) {
            $items[] = [
                'label' => (string) trans('chat.cards.fut_champions.options.rank', [], $locale),
                'value' => (string) trans('chat.cards.fut_champions.rank_value', ['rank' => (int) $options['rank']], $locale),
            ];
        }

        if (isset($options['urgent']) && is_bool($options['urgent'])) {
            $items[] = [
                'label' => (string) trans('chat.cards.fut_champions.options.urgent', [], $locale),
                'value' => $options['urgent']
                    ? (string) trans('chat.cards.fut_champions.urgent_value', [], $locale)
                    : (string) trans('chat.cards.fut_champions.normal_value', [], $locale),
            ];
        }

        return $items;
    }

    /**
     * Builds the localized, same-origin relative path including any preselected options.
     *
     * @param  array<string, mixed>  $options
     */
    private static function buildUrl(string $path, string $locale, array $options): string
    {
        $localizedPath = self::localizedPath($path, $locale);

        if ($options === []) {
            return $localizedPath;
        }

        $queryParams = array_map(
            static fn (mixed $value): mixed => is_bool($value) ? ($value ? 1 : 0) : $value,
            $options,
        );

        $queryString = http_build_query($queryParams);

        if ($queryString === '') {
            return $localizedPath;
        }

        if (str_contains($localizedPath, '#')) {
            [$base, $fragment] = explode('#', $localizedPath, 2);
            $separator = str_contains($base, '?') ? '&' : '?';

            return "{$base}{$separator}{$queryString}#{$fragment}";
        }

        $separator = str_contains($localizedPath, '?') ? '&' : '?';

        return "{$localizedPath}{$separator}{$queryString}";
    }

    /** English browses the store under an /en prefix; Arabic is the bare path. */
    private static function localizedPath(string $path, string $locale): string
    {
        if ($locale !== 'en') {
            return $path;
        }

        return $path === '/#coins' ? '/en#coins' : '/en'.$path;
    }
}
