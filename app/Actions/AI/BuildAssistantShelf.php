<?php

namespace App\Actions\AI;

/**
 * A shelf of real products to pick from, when one price cannot answer the
 * question.
 *
 * Today only SBC qualifies: it is a catalogue where every challenge is priced
 * on its own, so "how much are the challenges?" has no single honest answer.
 * Coins, Rivals and Champions each have a price table the assistant can quote
 * directly, so they get a card and a question instead.
 *
 * Like the cards and the chips, the shelf is chosen from the customer's own
 * message, never by the model.
 */
final readonly class BuildAssistantShelf
{
    public function __construct(
        private SelectSupportKnowledge $selectKnowledge,
        private BuildSbcSuggestions $sbcSuggestions,
    ) {}

    /**
     * @return list<array{id: string, title: string, url: string, image: string}>
     */
    public function execute(string $customerText, string $locale): array
    {
        if (trim($customerText) === '') {
            return [];
        }

        $topics = $this->selectKnowledge->execute($customerText, 1);

        if (($topics[0]->id ?? null) !== 'sbc') {
            return [];
        }

        return $this->sbcSuggestions->execute($locale);
    }
}
