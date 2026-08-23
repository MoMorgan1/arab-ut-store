<?php

namespace App\Actions\AI;

use App\Actions\Pricing\ReadManualServicePricing;
use App\ValueObjects\AI\SupportKnowledgeTopic;
use Throwable;

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

    /** The Rivals ladder a customer can start from, lowest division first. */
    private const DIVISIONS = ['7', '6', '5', '4', '3', '2', '1'];

    public function __construct(
        private SelectSupportKnowledge $selectKnowledge,
        private SelectServiceOptions $selectOptions,
        private ReadManualServicePricing $manualPricing,
    ) {}

    /**
     * @return array{version: string, prompt: string, items: list<array{id: string, label: string, message: string}>}|null
     */
    public function execute(string $customerText, string $locale): ?array
    {
        if (trim($customerText) === '') {
            return null;
        }

        // Three, not one: "كم سعر ديفيجن ١؟" scores the generic pricing topic
        // above Rivals, because it carries two pricing keywords and one service
        // keyword. A question of the form "how much is X" is a question about
        // X, so when the winner is the broad topic the runner-up service takes
        // it. Anywhere else the top topic still decides alone.
        $topics = $this->selectKnowledge->execute($customerText, 3);
        $topic = $topics[0] ?? null;

        if ($topic === null) {
            return null;
        }

        $group = self::SERVICE_TOPICS[$topic->id] ?? null;

        if ($group === null) {
            if (! in_array($topic->id, self::BROAD_TOPICS, true)) {
                return null;
            }

            $group = $this->namedService($topics);

            if ($group === null) {
                return $this->serviceQuestion($locale);
            }
        }

        return match ($group) {
            'coins' => $this->coinsQuestion($customerText, $locale),
            'fut_champions' => $this->championsQuestion($customerText, $locale),
            'rivals' => $this->rivalsQuestion($customerText, $locale),
            default => null,
        };
    }

    /**
     * The first service any of these topics names, or null when none does.
     *
     * @param  list<SupportKnowledgeTopic>  $topics
     */
    private function namedService(array $topics): ?string
    {
        foreach ($topics as $topic) {
            $group = self::SERVICE_TOPICS[$topic->id] ?? null;

            if ($group !== null) {
                return $group;
            }
        }

        return null;
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
        $platform = $options['platform'] ?? null;

        if (! is_string($platform)) {
            return $this->chips('coins-platform', 'chat.choices.coins.platform_prompt', [
                ['id' => 'playstation', 'key' => 'chat.choices.coins.playstation'],
                ['id' => 'pc', 'key' => 'chat.choices.coins.pc'],
            ], $locale);
        }

        // Every later chip restates the platform, because the next turn reads
        // its message and nothing else.
        $platformName = (string) trans("chat.cards.coins.platforms.{$platform}", [], $locale);
        $quantity = $options['quantity'] ?? null;

        if (! is_int($quantity)) {
            return $this->chips('coins-quantity', 'chat.choices.coins.quantity_prompt', array_map(
                fn (int $size): array => [
                    'id' => (string) $size,
                    'key' => 'chat.choices.coins.quantities.'.$size,
                    'replace' => ['platform' => $platformName],
                ],
                self::COIN_QUANTITIES,
            ), $locale);
        }

        // PC has a single delivery speed, so there is nothing left to ask.
        if ($platform === 'pc' || isset($options['delivery'])) {
            return null;
        }

        $replace = [
            'amount' => (string) trans('chat.choices.coins.quantities.'.$quantity.'.amount', [], $locale),
            'platform' => $platformName,
        ];

        return $this->chips('coins-delivery', 'chat.choices.coins.delivery_prompt', [
            ['id' => 'normal', 'key' => 'chat.choices.coins.normal', 'replace' => $replace],
            ['id' => 'fast', 'key' => 'chat.choices.coins.fast', 'replace' => $replace],
        ], $locale);
    }

    /**
     * Rivals is priced by route, so the question is asked in two steps: where
     * the customer is now, then where they want to reach.
     *
     * The target chips carry the whole route in their message — "from division
     * 5 to Elite" — because each turn re-derives everything from the latest
     * message alone. A chip saying only "Elite" would arrive with no start.
     *
     * @return array{version: string, prompt: string, items: list<array{id: string, label: string, message: string}>}|null
     */
    private function rivalsQuestion(string $customerText, string $locale): ?array
    {
        $options = $this->selectOptions->partial($customerText, 'rivals');
        $current = $options['currentDivision'] ?? null;

        if (! is_string($current)) {
            return $this->chips('rivals-current', 'chat.choices.rivals.current_prompt', array_map(
                fn (string $division): array => [
                    'id' => $division,
                    'key' => 'chat.choices.rivals.current.'.$division,
                ],
                self::DIVISIONS,
            ), $locale);
        }

        if (isset($options['targetDivision'])) {
            return null;
        }

        $targets = $this->rivalsTargets($current);

        if ($targets === []) {
            return null;
        }

        return $this->chips('rivals-target', 'chat.choices.rivals.target_prompt', array_map(
            fn (string $target): array => [
                'id' => $target,
                'key' => 'chat.choices.rivals.target.'.($target === 'elite' ? 'elite' : 'division'),
                'replace' => ['from' => $current, 'to' => $target],
            ],
            $targets,
        ), $locale);
    }

    /**
     * The divisions the store will actually boost this customer to, read from
     * live pricing so a chip can never offer an unpriced route.
     *
     * @return list<string>
     */
    private function rivalsTargets(string $current): array
    {
        try {
            return $this->manualPricing->rivals()['pricing']->availableTargets($current);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array{version: string, prompt: string, items: list<array{id: string, label: string, message: string}>}|null
     */
    private function championsQuestion(string $customerText, string $locale): ?array
    {
        $options = $this->selectOptions->partial($customerText, 'fut_champions');
        $rank = $options['rank'] ?? null;

        if (! is_int($rank)) {
            return $this->chips('champions-rank', 'chat.choices.fut_champions.rank_prompt', array_map(
                fn (int $option): array => [
                    'id' => (string) $option,
                    'key' => 'chat.choices.fut_champions.ranks.'.$option,
                ],
                [6, 5, 4, 3, 2, 1],
            ), $locale);
        }

        if (isset($options['urgent'])) {
            return null;
        }

        $replace = ['rank' => (string) $rank];

        return $this->chips('champions-urgency', 'chat.choices.fut_champions.urgency_prompt', [
            ['id' => 'normal', 'key' => 'chat.choices.fut_champions.normal', 'replace' => $replace],
            ['id' => 'urgent', 'key' => 'chat.choices.fut_champions.urgent', 'replace' => $replace],
        ], $locale);
    }

    /**
     * A chip's message is the sentence the customer would have typed, so the
     * next turn re-derives the answer from ordinary text with no hidden state.
     *
     * @param  list<array{id: string, key: string, replace?: array<string, string>}>  $items
     * @return array{version: string, prompt: string, items: list<array{id: string, label: string, message: string}>}
     */
    private function chips(string $group, string $promptKey, array $items, string $locale): array
    {
        return [
            'version' => 'choices.v1',
            'prompt' => (string) trans($promptKey, [], $locale),
            'items' => array_map(
                function (array $item) use ($group, $locale): array {
                    $replace = $item['replace'] ?? [];

                    return [
                        'id' => $group.':'.$item['id'],
                        'label' => (string) trans($item['key'].'.label', $replace, $locale),
                        'message' => (string) trans($item['key'].'.message', $replace, $locale),
                    ];
                },
                $items,
            ),
        ];
    }
}
