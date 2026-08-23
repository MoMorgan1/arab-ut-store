<?php

namespace App\Actions\AI;

use App\Support\AI\SupportKnowledge;
use App\ValueObjects\AI\SupportKnowledgeTopic;

/**
 * Picks the few knowledge topics a customer's message is actually about.
 *
 * The corpus is small and staff-authored, so this is deliberately lexical: no
 * embedding provider, no vector store, no extra failure mode. Arabic is
 * normalized first because customers type the same word with different alef,
 * yaa, and ta marbuta forms, and often mix Arabic and English in one sentence.
 */
final readonly class SelectSupportKnowledge
{
    public function __construct(private SupportKnowledge $knowledge) {}

    /** @return list<SupportKnowledgeTopic> */
    public function execute(string $text, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $normalized = self::normalize($text);
        $tokens = self::tokens($text);

        if ($tokens === []) {
            return [];
        }

        $scored = [];

        foreach ($this->knowledge->topics() as $index => $topic) {
            $score = $this->score($topic, $tokens, $normalized);

            if ($score > 0) {
                // The index keeps the order stable when two topics tie, so the
                // same question always produces the same citations.
                $scored[] = ['score' => $score, 'index' => $index, 'topic' => $topic];
            }
        }

        usort($scored, static fn (array $a, array $b): int => [$b['score'], $a['index']] <=> [$a['score'], $b['index']]);

        return array_map(
            static fn (array $entry): SupportKnowledgeTopic => $entry['topic'],
            array_slice($scored, 0, $limit),
        );
    }

    /** @param list<string> $tokens */
    private function score(SupportKnowledgeTopic $topic, array $tokens, string $normalized): int
    {
        $keywords = array_map(self::normalize(...), $topic->keywords());
        // A multi-word keyword only counts when the customer wrote the whole
        // phrase. Matching one of its words would let "كيف" (how) pull in
        // every topic whose keywords happen to start with it.
        $phrases = array_values(array_filter($keywords, static fn (string $keyword): bool => str_contains($keyword, ' ')));
        $words = array_values(array_filter($keywords, static fn (string $keyword): bool => ! str_contains($keyword, ' ')));
        $titles = self::normalize($topic->titleAr.' '.$topic->titleEn);
        $bodies = self::normalize($topic->bodyAr.' '.$topic->bodyEn);
        $score = 0;
        $anchored = false;

        foreach ($phrases as $phrase) {
            if (str_contains($normalized, $phrase)) {
                $score += 6;
                $anchored = true;
            }
        }

        foreach (array_unique($tokens) as $token) {
            if ($this->matchesKeyword($token, $words)) {
                $score += 6;
                $anchored = true;

                continue;
            }

            if (str_contains($titles, $token)) {
                $score += 2;
                $anchored = true;

                continue;
            }

            if (str_contains($bodies, $token)) {
                $score += 1;
            }
        }

        // A topic only qualifies on a keyword or title hit. Body words alone
        // are noise: "كيف حالك" shares words with half the corpus.
        return $anchored && $score >= 4 ? $score : 0;
    }

    /** @param list<string> $keywords */
    private function matchesKeyword(string $token, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if ($token === $keyword) {
                return true;
            }

            // Only the customer's word may carry extra letters: "الضمان"
            // matches the keyword "ضمان". The reverse would match a fragment
            // inside a word — "وين" (where) sits inside "كوينز" (coins).
            if (mb_strlen($keyword) > 2 && str_contains($token, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function tokens(string $text): array
    {
        $normalized = self::normalize($text);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === false) {
            return [];
        }

        $tokens = [];

        foreach ($parts as $part) {
            // Two-letter tokens are Arabic particles and English filler.
            if (mb_strlen($part) > 2) {
                $tokens[] = $part;
            }

            // Arabic glues the article to the noun, so index both forms.
            if (str_starts_with($part, 'ال') && mb_strlen($part) > 4) {
                $tokens[] = mb_substr($part, 2);
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Normalizes Arabic and English text for consistent lexical matching.
     *
     * Strips Arabic diacritics and tatweel, normalizes alef/yaa/ta marbuta
     * letterforms, converts Arabic-Indic digits to ASCII, and lowercases.
     */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text) ?? $text;

        return strtr($text, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }
}
