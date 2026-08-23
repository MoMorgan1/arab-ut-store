<?php

namespace App\ValueObjects\AI;

use InvalidArgumentException;

final readonly class SupportKnowledgeTopic
{
    /**
     * @param  list<string>  $keywordsAr
     * @param  list<string>  $keywordsEn
     */
    public function __construct(
        public string $id,
        public string $url,
        public bool $faq,
        public array $keywordsAr,
        public array $keywordsEn,
        public string $titleAr,
        public string $bodyAr,
        public string $titleEn,
        public string $bodyEn,
    ) {}

    /** @param array<string, mixed> $topic */
    public static function fromArray(array $topic): self
    {
        foreach (['id', 'url', 'title_ar', 'body_ar', 'title_en', 'body_en'] as $key) {
            if (! isset($topic[$key]) || ! is_string($topic[$key]) || trim($topic[$key]) === '') {
                throw new InvalidArgumentException("A knowledge topic is missing {$key}.");
            }
        }

        if (! is_bool($topic['faq'] ?? null)) {
            throw new InvalidArgumentException("Knowledge topic {$topic['id']} must declare faq visibility.");
        }

        return new self(
            id: $topic['id'],
            url: $topic['url'],
            faq: $topic['faq'],
            keywordsAr: self::strings($topic['keywords_ar'] ?? [], $topic['id']),
            keywordsEn: self::strings($topic['keywords_en'] ?? [], $topic['id']),
            titleAr: $topic['title_ar'],
            bodyAr: $topic['body_ar'],
            titleEn: $topic['title_en'],
            bodyEn: $topic['body_en'],
        );
    }

    /** The title in the conversation locale; Arabic is the authoritative source. */
    public function title(string $locale): string
    {
        return $locale === 'en' ? $this->titleEn : $this->titleAr;
    }

    public function body(string $locale): string
    {
        return $locale === 'en' ? $this->bodyEn : $this->bodyAr;
    }

    /** @return list<string> */
    public function keywords(): array
    {
        return [...$this->keywordsAr, ...$this->keywordsEn];
    }

    /**
     * @param  mixed  $keywords
     * @return list<string>
     */
    private static function strings($keywords, string $id): array
    {
        if (! is_array($keywords) || $keywords === []) {
            throw new InvalidArgumentException("Knowledge topic {$id} needs keywords in both locales.");
        }

        return array_values(array_map(static function ($keyword) use ($id): string {
            if (! is_string($keyword) || trim($keyword) === '') {
                throw new InvalidArgumentException("Knowledge topic {$id} has an empty keyword.");
            }

            return $keyword;
        }, $keywords));
    }
}
