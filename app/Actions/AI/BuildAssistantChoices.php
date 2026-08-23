<?php

namespace App\Actions\AI;

/**
 * The one question the assistant should ask next, as tappable chips.
 *
 * A customer who writes "الأسعار" gets every platform and every speed read out
 * at them and still has to decide. Instead the reply carries the single next
 * choice, and tapping a chip sends it as an ordinary customer message so the
 * server re-derives everything from scratch.
 *
 * Like the service cards, chips are derived from the customer's own message and
 * never authored by the model: the storefront can only ever offer something it
 * actually sells, and a prompt-injected turn cannot invent an option.
 */
final readonly class BuildAssistantChoices
{
    /** Topics whose card key is a service the customer can configure. */
    private const SERVICE_TOPICS = [
        'coins-service' => 'coins',
        'coins-speeds' => 'coins',
        'rivals' => 'rivals',
        'fut-champions' => 'fut_champions',
        'sbc' => 'sbc',
    ];

    /**
     * Topics that are about buying in general rather than one service. These
     * earn the "which service?" question instead of a wall of every price.
     */
    private const BROAD_TOPICS = ['pricing-policy', 'payment-methods'];

    /** The coin sizes the configurator sells, smallest first. */
    private const COIN_QUANTITIES = [100_000, 500_000, 1_000_000, 2_000_000, 5_000_000];

    public function __construct(
        private SelectSupportKnowledge $selectKnowledge,
        private SelectServiceOptions $selectOptions,
    ) {}

    /**
     * @return array{version: string, prompt: string, items: list<array{id: string, label: string, message: string}>}|null
     */
    public function execute(string $customerText, string $locale): ?array
    {
        if (trim($customerText) === '') {
            return null;
        }

        $topics = $this->selectKnowledge->execute($customerText, 1);
        $topic = $topics[0] ?? null;

        if ($topic === null) {
            return null;
        }

        $group = self::SERVICE_TOPICS[$topic->id] ?? null;

        if ($group === null) {
            return in_array($topic->id, self::BROAD_TOPICS, true)
                ? $this->serviceQuestion($locale)
                : null;
        }

        return match ($group) {
            'coins' => $this->coinsQuestion($customerText, $locale),
            'fut_champions' => $this->championsQuestion($customerText, $locale),
            default => null,
        };
    }

    /**
     * @return array{version: string, prompt: string, items: list<array{id: string, label: string, message: string}>}
     */
    private function serviceQuestion(string $locale): array
    {
        return $this->chips('service', 'chat.choices.service.prompt', [
            ['id' => 'coins', 'key' => 'chat.choices.service.coins'],
            ['id' => 'rivals', 'key' => 'chat.choices.service.rivals'],
            ['id' => 'fut_champions', 'key' => 'chat.choices.service.fut_champions'],
            ['id' => 'sbc', 'key' => 'chat.choices.service.sbc'],
        ], $locale);
    }

    /**
     * @return array{version: string, prompt: string, items: list<array{id: string, label: string, message: string}>}|null
     */
    private function coinsQuestion(string $customerText, string $locale): ?array
    {
        $options = $this->selectOptions->partial($customerText, 'coins');

        if (! isset($options['platform'])) {
            return $this->chips('coins-platform', 'chat.choices.coins.platform_prompt', [
                ['id' => 'playstation', 'key' => 'chat.choices.coins.playstation'],
                ['id' => 'pc', 'key' => 'chat.choices.coins.pc'],
            ], $locale);
        }

        if (! isset($options['quantity'])) {
            return $this->chips('coins-quantity', 'chat.choices.coins.quantity_prompt', array_map(
                fn (int $quantity): array => [
                    'id' => (string) $quantity,
                    'key' => 'chat.choices.coins.quantities.'.$quantity,
                ],
                self::COIN_QUANTITIES,
            ), $locale);
        }

        // PC has a single delivery speed, so there is nothing left to ask.
        if ($options['platform'] === 'pc' || isset($options['delivery'])) {
            return null;
        }

        return $this->chips('coins-delivery', 'chat.choices.coins.delivery_prompt', [
            ['id' => 'normal', 'key' => 'chat.choices.coins.normal'],
            ['id' => 'fast', 'key' => 'chat.choices.coins.fast'],
        ], $locale);
    }

    /**
     * @return array{version: string, prompt: string, items: list<array{id: string, label: string, message: string}>}|null
     */
    private function championsQuestion(string $customerText, string $locale): ?array
    {
        $options = $this->selectOptions->partial($customerText, 'fut_champions');

        if (! isset($options['rank'])) {
            return $this->chips('champions-rank', 'chat.choices.fut_champions.rank_prompt', array_map(
                fn (int $rank): array => [
                    'id' => (string) $rank,
                    'key' => 'chat.choices.fut_champions.ranks.'.$rank,
                ],
                [6, 5, 4, 3, 2, 1],
            ), $locale);
        }

        if (isset($options['urgent'])) {
            return null;
        }

        return $this->chips('champions-urgency', 'chat.choices.fut_champions.urgency_prompt', [
            ['id' => 'normal', 'key' => 'chat.choices.fut_champions.normal'],
            ['id' => 'urgent', 'key' => 'chat.choices.fut_champions.urgent'],
        ], $locale);
    }

    /**
     * A chip's message is the sentence the customer would have typed, so the
     * next turn re-derives the answer from ordinary text with no hidden state.
     *
     * @param  list<array{id: string, key: string}>  $items
     * @return array{version: string, prompt: string, items: list<array{id: string, label: string, message: string}>}
     */
    private function chips(string $group, string $promptKey, array $items, string $locale): array
    {
        return [
            'version' => 'choices.v1',
            'prompt' => (string) trans($promptKey, [], $locale),
            'items' => array_map(
                fn (array $item): array => [
                    'id' => $group.':'.$item['id'],
                    'label' => (string) trans($item['key'].'.label', [], $locale),
                    'message' => (string) trans($item['key'].'.message', [], $locale),
                ],
                $items,
            ),
        ];
    }
}
