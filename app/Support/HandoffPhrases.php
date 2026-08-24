<?php

namespace App\Support;

/**
 * Does this message ask to be put through to a person?
 *
 * The bar is deliberately high. Every match opens a real support ticket a human
 * has to answer, and the owner's instruction was "do not suggest to the customer
 * unless the customer asks so you don't overload me". A false positive costs a
 * ticket nobody wanted; a false negative costs almost nothing, because the
 * always-available control in the widget header is one tap away.
 *
 * So a bare noun is never enough. Matching "agent" on its own fires on "what
 * does the agent do" and on "user agent" — and it did fire on the fixture text
 * "Original agent request" in an unrelated test, which is exactly how it would
 * have behaved against real messages.
 */
final class HandoffPhrases
{
    /**
     * Arabic phrases specific enough to stand alone.
     *
     * `الدعم` is deliberately absent: "متى يشتغل الدعم؟" asks about opening
     * hours, not for a person. It counts only alongside a request verb below.
     *
     * @var list<string>
     */
    private const ARABIC_PHRASES = [
        'موظف',
        'خدمة العملاء',
        'شخص حقيقي',
        'احد من الفريق',
        'واحد من الفريق',
        'بشري',
    ];

    /**
     * Request markers, not only verbs: "الدعم لو سمحت" is a request with no verb
     * in it at all, and politeness is the whole signal. Order does not matter —
     * the marker can sit either side of the noun.
     *
     * @var list<string>
     */
    private const ARABIC_VERBS = [
        'اكلم', 'كلم', 'اتواصل', 'تواصل', 'حولني', 'ودني', 'ابي', 'ابغى', 'اريد',
        'لو سمحت', 'من فضلك', 'ممكن',
    ];

    /** @var list<string> */
    private const ARABIC_WEAK_NOUNS = ['الدعم', 'انسان', 'شخص'];

    /**
     * Latin phrases specific enough to stand alone.
     *
     * @var list<string>
     */
    private const LATIN_PHRASES = [
        'real person',
        'real human',
        'live agent',
        'human agent',
        'customer service',
        'support team',
        'talk to someone',
        'speak to someone',
        'talk to a person',
        'speak to a person',
    ];

    /** @var list<string> */
    private const LATIN_VERBS = ['talk', 'speak', 'chat', 'connect', 'transfer', 'contact', 'reach', 'want', 'need', 'get'];

    /** @var list<string> */
    private const LATIN_WEAK_NOUNS = ['human', 'agent', 'person', 'representative', 'someone', 'somebody'];

    public static function matches(string $text): bool
    {
        $normalized = self::normalize($text);

        if ($normalized === '') {
            return false;
        }

        foreach (self::ARABIC_PHRASES as $phrase) {
            if (str_contains($normalized, self::normalize($phrase))) {
                return true;
            }
        }

        if (self::verbNearNoun($normalized, self::ARABIC_VERBS, self::ARABIC_WEAK_NOUNS, wordBoundary: false)) {
            return true;
        }

        foreach (self::LATIN_PHRASES as $phrase) {
            if (preg_match('/\b'.preg_quote($phrase, '/').'\b/iu', $normalized) === 1) {
                return true;
            }
        }

        return self::verbNearNoun($normalized, self::LATIN_VERBS, self::LATIN_WEAK_NOUNS, wordBoundary: true);
    }

    /**
     * A weak noun counts only when a request verb sits close in front of it.
     *
     * The 40-character window keeps "can I talk to a human" and
     * "ابي اكلم احد من الدعم" while rejecting a paragraph that happens to
     * contain both words far apart.
     *
     * @param  list<string>  $verbs
     * @param  list<string>  $nouns
     */
    private static function verbNearNoun(string $text, array $verbs, array $nouns, bool $wordBoundary): bool
    {
        $boundary = $wordBoundary ? '\b' : '';

        foreach ($verbs as $verb) {
            $quotedVerb = $boundary.preg_quote(self::normalize($verb), '/').$boundary;

            foreach ($nouns as $noun) {
                $quotedNoun = $boundary.preg_quote(self::normalize($noun), '/').$boundary;

                // Either order: "ابي اكلم الدعم" and "الدعم لو سمحت" are both
                // requests, and Arabic puts the polite marker last.
                foreach ([
                    '/'.$quotedVerb.'.{0,40}?'.$quotedNoun.'/iu',
                    '/'.$quotedNoun.'.{0,40}?'.$quotedVerb.'/iu',
                ] as $pattern) {
                    if (preg_match($pattern, $text) === 1) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[\x{0640}]/u', '', $text) ?? $text;
        $text = preg_replace('/[\x{064B}-\x{0652}]/u', '', $text) ?? $text;
        $text = preg_replace('/[أإآٱ]/u', 'ا', $text) ?? $text;
        $text = preg_replace('/[ة]/u', 'ه', $text) ?? $text;

        return $text;
    }
}
