<?php

namespace App\Actions\Store;

use LogicException;

class ValidateStoreInformationPage
{
    private const BLOCK_TYPES = ['divider', 'heading', 'list', 'notice', 'paragraph'];

    private const META_KEYS = [
        'breadcrumb_label',
        'home',
        'support_action',
        'support_subtitle',
        'support_title',
        'updated_label',
        'updated_value',
    ];

    private const NOTICE_TONES = ['info', 'shield', 'warning'];

    /**
     * @return array<string, mixed>
     */
    public function validate(string $key, mixed $page, mixed $meta, mixed $supportUrl): array
    {
        $page = $this->page($page);
        $meta = $this->meta($meta);
        $supportUrl = $this->approvedUrl($supportUrl, ['wa.me']);

        return [
            'key' => $key,
            'title' => $page['title'],
            'subtitle' => $page['subtitle'] ?? null,
            'breadcrumb' => [
                'label' => $meta['breadcrumb_label'],
                'home' => $meta['home'],
                'current' => $page['title'],
            ],
            'updated' => [
                'label' => $meta['updated_label'],
                'value' => $meta['updated_value'],
            ],
            'blocks' => $page['blocks'],
            'support' => [
                'title' => $meta['support_title'],
                'subtitle' => $meta['support_subtitle'],
                'action' => $meta['support_action'],
                'url' => $supportUrl,
            ],
        ];
    }

    /** @return array{title: string, subtitle: ?string, blocks: list<array<string, mixed>>} */
    private function page(mixed $translation): array
    {
        $translation = $this->requireArray($translation, 'page');
        $this->requireExactKeys($translation, ['blocks', 'title'], ['subtitle']);
        $title = $this->nonEmptyString($translation['title'], 'page title');
        $subtitle = array_key_exists('subtitle', $translation)
            ? $this->nonEmptyString($translation['subtitle'], 'page subtitle')
            : null;
        $blocks = [];

        foreach ($this->nonEmptyList($translation['blocks'], 'page blocks') as $block) {
            $blocks[] = $this->block($block);
        }

        return ['title' => $title, 'subtitle' => $subtitle, 'blocks' => $blocks];
    }

    /** @return array<string, string> */
    private function meta(mixed $metadata): array
    {
        $metadata = $this->requireArray($metadata, 'metadata');
        $this->requireExactKeys($metadata, self::META_KEYS);

        foreach (self::META_KEYS as $key) {
            $this->nonEmptyString($metadata[$key], "metadata {$key}");
        }

        return $metadata;
    }

    /** @return array<string, mixed> */
    private function block(mixed $block): array
    {
        $block = $this->requireArray($block, 'page block');
        $type = $block['type'] ?? null;

        if (! is_string($type) || ! in_array($type, self::BLOCK_TYPES, true)) {
            throw new LogicException('The information page contains an unsupported block type.');
        }

        match ($type) {
            'divider' => $this->divider($block),
            'heading' => $this->heading($block),
            'list' => $this->list($block),
            'notice' => $this->notice($block),
            'paragraph' => $this->paragraph($block),
        };

        return $block;
    }

    /** @param array<string, mixed> $block */
    private function divider(array $block): void
    {
        $this->requireExactKeys($block, ['type']);
    }

    /** @param array<string, mixed> $block */
    private function heading(array $block): void
    {
        $this->requireExactKeys($block, ['level', 'text', 'type']);
        $this->nonEmptyString($block['text'], 'heading text');

        if (! in_array($block['level'], [2, 3], true)) {
            throw new LogicException('Information page headings must use level two or three.');
        }
    }

    /** @param array<string, mixed> $block */
    private function list(array $block): void
    {
        $this->requireExactKeys($block, ['items', 'ordered', 'type']);

        if (! is_bool($block['ordered'])) {
            throw new LogicException('Information page list ordering must be boolean.');
        }

        foreach ($this->nonEmptyList($block['items'], 'list items') as $item) {
            $this->inlineContent($item);
        }
    }

    /** @param array<string, mixed> $block */
    private function notice(array $block): void
    {
        $this->requireExactKeys($block, ['content', 'tone', 'type']);

        if (! is_string($block['tone']) || ! in_array($block['tone'], self::NOTICE_TONES, true)) {
            throw new LogicException('The information page contains an unsupported notice tone.');
        }

        $this->inlineContent($block['content']);
    }

    /** @param array<string, mixed> $block */
    private function paragraph(array $block): void
    {
        $this->requireExactKeys($block, ['content', 'type']);
        $this->inlineContent($block['content']);
    }

    private function inlineContent(mixed $content): void
    {
        foreach ($this->nonEmptyList($content, 'inline content') as $part) {
            $part = $this->requireArray($part, 'inline content part');
            $this->requireExactKeys($part, ['text'], ['strong', 'url']);
            $this->nonEmptyString($part['text'], 'inline text', false);

            if (array_key_exists('strong', $part) && ! is_bool($part['strong'])) {
                throw new LogicException('Inline emphasis must be boolean.');
            }

            if (array_key_exists('url', $part)) {
                $this->approvedUrl($part['url'], ['help.ea.com']);
            }
        }
    }

    /** @param list<string> $allowedHosts */
    private function approvedUrl(mixed $url, array $allowedHosts): string
    {
        $this->nonEmptyString($url, 'external URL');
        $parts = parse_url($url);

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! in_array(strtolower((string) ($parts['host'] ?? '')), $allowedHosts, true)) {
            throw new LogicException('The information page contains an unapproved external URL.');
        }

        return $url;
    }

    /** @return non-empty-list<mixed> */
    private function nonEmptyList(mixed $candidate, string $field): array
    {
        if (! is_array($candidate) || ! array_is_list($candidate) || $candidate === []) {
            throw new LogicException("The {$field} must be a non-empty list.");
        }

        return $candidate;
    }

    private function nonEmptyString(mixed $candidate, string $field, bool $trim = true): string
    {
        if (! is_string($candidate) || ($trim ? trim($candidate) === '' : $candidate === '')) {
            throw new LogicException("The {$field} must be a non-empty string.");
        }

        return $candidate;
    }

    /** @return array<string, mixed> */
    private function requireArray(mixed $candidate, string $field): array
    {
        if (! is_array($candidate)) {
            throw new LogicException("The {$field} must be an array.");
        }

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $required
     * @param  list<string>  $optional
     */
    private function requireExactKeys(array $fields, array $required, array $optional = []): void
    {
        $keys = array_keys($fields);
        $missing = array_diff($required, $keys);
        $unexpected = array_diff($keys, [...$required, ...$optional]);

        if ($missing !== [] || $unexpected !== []) {
            throw new LogicException('The information page contains missing or unexpected fields.');
        }
    }
}
