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
     * @var array<string, array{path: string, key: string}>
     */
    private const CARDS = [
        'coins-service' => ['path' => '/#coins', 'key' => 'coins'],
        'coins-speeds' => ['path' => '/#coins', 'key' => 'coins'],
        'sbc' => ['path' => '/sbc', 'key' => 'sbc'],
        'rivals' => ['path' => '/rivals', 'key' => 'rivals'],
        'fut-champions' => ['path' => '/fut-champions', 'key' => 'fut_champions'],
    ];

    public function __construct(private SelectSupportKnowledge $selectKnowledge) {}

    /**
     * @return list<array{id: string, title: string, subtitle: string, cta: string, url: string}>
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

            $cards[$card['key']] = [
                'id' => $card['key'],
                'title' => (string) trans("chat.cards.{$card['key']}.title", [], $locale),
                'subtitle' => (string) trans("chat.cards.{$card['key']}.subtitle", [], $locale),
                'cta' => (string) trans('chat.cards.cta', [], $locale),
                'url' => self::localizedPath($card['path'], $locale),
            ];
        }

        return array_values($cards);
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
