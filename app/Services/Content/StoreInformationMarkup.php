<?php

namespace App\Services\Content;

use InvalidArgumentException;

final class StoreInformationMarkup
{
    /**
     * Convert an inline content parts array to a marker string.
     * Markers: **bold** and [label](url).
     * Literal '*' and '[' are escaped as '\*' and '\['.
     *
     * @param  list<array<string, mixed>>  $parts
     */
    public static function toMarkers(array $parts): string
    {
        $result = '';

        foreach ($parts as $part) {
            $text = (string) ($part['text'] ?? '');
            $isStrong = ! empty($part['strong']);
            $hasUrl = array_key_exists('url', $part) && $part['url'] !== null && $part['url'] !== '';

            if ($isStrong && $hasUrl) {
                throw new InvalidArgumentException('A part cannot be both bold and a link.');
            }

            $escaped = str_replace(['*', '['], ['\\*', '\\['], $text);

            if ($isStrong) {
                $result .= "**{$escaped}**";
            } elseif ($hasUrl) {
                $result .= "[{$escaped}]({$part['url']})";
            } else {
                $result .= $escaped;
            }
        }

        return $result;
    }

    /**
     * Convert a marker string into an array of inline content parts.
     *
     * @return list<array<string, mixed>>
     */
    public static function toParts(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $parts = [];
        $currentPlain = '';

        $flushPlain = function () use (&$parts, &$currentPlain): void {
            /** @var string $current */
            $current = $currentPlain;
            if ($current !== '') {
                $parts[] = ['text' => $current];
                $currentPlain = '';
            }
        };

        $len = strlen($text);
        $i = 0;

        while ($i < $len) {
            // Check for escapes: \* or \[
            if ($text[$i] === '\\' && $i + 1 < $len && ($text[$i + 1] === '*' || $text[$i + 1] === '[')) {
                $currentPlain .= $text[$i + 1];
                $i += 2;

                continue;
            }

            // Check for bold: **...**
            if ($text[$i] === '*' && $i + 1 < $len && $text[$i + 1] === '*') {
                $closePos = false;
                $j = $i + 2;
                while ($j < $len) {
                    if ($text[$j] === '\\' && $j + 1 < $len && ($text[$j + 1] === '*' || $text[$j + 1] === '[')) {
                        $j += 2;

                        continue;
                    }
                    if ($text[$j] === '*' && $j + 1 < $len && $text[$j + 1] === '*') {
                        $closePos = $j;
                        break;
                    }
                    $j++;
                }

                if ($closePos !== false) {
                    $rawInside = substr($text, $i + 2, $closePos - ($i + 2));

                    if (self::containsLink($rawInside)) {
                        throw new InvalidArgumentException('A part cannot be both bold and a link.');
                    }

                    $flushPlain();
                    $unescaped = self::unescape($rawInside);
                    if ($unescaped !== '') {
                        $parts[] = [
                            'text' => $unescaped,
                            'strong' => true,
                        ];
                    }
                    $i = $closePos + 2;

                    continue;
                }
            }

            // Check for link: [label](url)
            if ($text[$i] === '[') {
                $closeBracket = false;
                $j = $i + 1;
                while ($j < $len) {
                    if ($text[$j] === '\\' && $j + 1 < $len && ($text[$j + 1] === '*' || $text[$j + 1] === '[')) {
                        $j += 2;

                        continue;
                    }
                    if ($text[$j] === ']') {
                        $closeBracket = $j;
                        break;
                    }
                    $j++;
                }

                if ($closeBracket !== false && $closeBracket + 1 < $len && $text[$closeBracket + 1] === '(') {
                    $closeParen = false;
                    $k = $closeBracket + 2;
                    while ($k < $len) {
                        if ($text[$k] === ')') {
                            $closeParen = $k;
                            break;
                        }
                        $k++;
                    }

                    if ($closeParen !== false) {
                        $rawLabel = substr($text, $i + 1, $closeBracket - ($i + 1));
                        $url = substr($text, $closeBracket + 2, $closeParen - ($closeBracket + 2));

                        if (self::containsBold($rawLabel)) {
                            throw new InvalidArgumentException('A part cannot be both bold and a link.');
                        }

                        $flushPlain();
                        $unescapedLabel = self::unescape($rawLabel);
                        $parts[] = [
                            'text' => $unescapedLabel,
                            'url' => $url,
                        ];
                        $i = $closeParen + 1;

                        continue;
                    }
                }
            }

            $currentPlain .= $text[$i];
            $i++;
        }

        $flushPlain();

        return $parts;
    }

    /**
     * Convert internal blocks to editor blocks.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    public static function blocksToEditor(array $blocks): array
    {
        $editorBlocks = [];

        foreach ($blocks as $block) {
            $type = (string) ($block['type'] ?? '');

            $editorBlocks[] = match ($type) {
                'heading' => [
                    'type' => 'heading',
                    'level' => (int) ($block['level'] ?? 2),
                    'text' => (string) ($block['text'] ?? ''),
                ],
                'paragraph' => [
                    'type' => 'paragraph',
                    'text' => self::toMarkers(array_values((array) ($block['content'] ?? []))),
                ],
                'list' => [
                    'type' => 'list',
                    'ordered' => (bool) ($block['ordered'] ?? false),
                    'text' => implode("\n", array_map(
                        fn ($item): string => self::toMarkers(array_values((array) $item)),
                        array_values((array) ($block['items'] ?? []))
                    )),
                ],
                'notice' => [
                    'type' => 'notice',
                    'tone' => (string) ($block['tone'] ?? 'info'),
                    'text' => self::toMarkers(array_values((array) ($block['content'] ?? []))),
                ],
                'divider' => [
                    'type' => 'divider',
                ],
                default => throw new InvalidArgumentException("Unsupported block type: {$type}"),
            };
        }

        return $editorBlocks;
    }

    /**
     * Convert editor blocks back to internal blocks.
     *
     * @param  list<array<string, mixed>>  $editorBlocks
     * @return list<array<string, mixed>>
     */
    public static function blocksFromEditor(array $editorBlocks): array
    {
        $blocks = [];

        foreach ($editorBlocks as $index => $block) {
            $type = (string) ($block['type'] ?? '');

            $blocks[] = match ($type) {
                'heading' => self::headingFromEditor($block, $index),
                'paragraph' => self::paragraphFromEditor($block, $index),
                'list' => self::listFromEditor($block, $index),
                'notice' => self::noticeFromEditor($block, $index),
                'divider' => ['type' => 'divider'],
                default => throw new InvalidArgumentException('Block #'.($index + 1).": unsupported block type [{$type}]."),
            };
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private static function headingFromEditor(array $block, int $index): array
    {
        $text = isset($block['text']) ? (string) $block['text'] : '';
        if (trim($text) === '') {
            throw new InvalidArgumentException('Block #'.($index + 1).' (heading) cannot be empty.');
        }

        $level = isset($block['level']) ? (int) $block['level'] : 2;
        if (! in_array($level, [2, 3], true)) {
            throw new InvalidArgumentException('Block #'.($index + 1).' heading level must be 2 or 3.');
        }

        return [
            'type' => 'heading',
            'level' => $level,
            'text' => $text,
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private static function paragraphFromEditor(array $block, int $index): array
    {
        $text = isset($block['text']) ? (string) $block['text'] : '';
        if (trim($text) === '') {
            throw new InvalidArgumentException('Block #'.($index + 1).' (paragraph) cannot be empty.');
        }

        $content = self::toParts($text);
        if ($content === []) {
            throw new InvalidArgumentException('Block #'.($index + 1).' (paragraph) cannot be empty.');
        }

        return [
            'type' => 'paragraph',
            'content' => $content,
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private static function listFromEditor(array $block, int $index): array
    {
        $text = isset($block['text']) ? (string) $block['text'] : '';
        if (trim($text) === '') {
            throw new InvalidArgumentException('Block #'.($index + 1).' (list) cannot be empty.');
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $normalized);

        $items = [];
        foreach ($lines as $lineIndex => $line) {
            if (trim($line) === '') {
                throw new InvalidArgumentException('Block #'.($index + 1).' list item #'.($lineIndex + 1).' cannot be empty.');
            }
            $itemParts = self::toParts($line);
            if ($itemParts === []) {
                throw new InvalidArgumentException('Block #'.($index + 1).' list item #'.($lineIndex + 1).' cannot be empty.');
            }
            $items[] = $itemParts;
        }

        return [
            'type' => 'list',
            'ordered' => ! empty($block['ordered']),
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private static function noticeFromEditor(array $block, int $index): array
    {
        $text = isset($block['text']) ? (string) $block['text'] : '';
        if (trim($text) === '') {
            throw new InvalidArgumentException('Block #'.($index + 1).' (notice) cannot be empty.');
        }

        $tone = (string) ($block['tone'] ?? 'info');
        if (! in_array($tone, ['info', 'shield', 'warning'], true)) {
            throw new InvalidArgumentException('Block #'.($index + 1).' notice tone must be info, shield, or warning.');
        }

        $content = self::toParts($text);
        if ($content === []) {
            throw new InvalidArgumentException('Block #'.($index + 1).' (notice) cannot be empty.');
        }

        return [
            'type' => 'notice',
            'tone' => $tone,
            'content' => $content,
        ];
    }

    private static function containsLink(string $str): bool
    {
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            if ($str[$i] === '\\' && $i + 1 < $len) {
                $i++;

                continue;
            }
            if ($str[$i] === '[') {
                $close = strpos($str, '](', $i);
                if ($close !== false && strpos($str, ')', $close + 2) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function containsBold(string $str): bool
    {
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            if ($str[$i] === '\\' && $i + 1 < $len) {
                $i++;

                continue;
            }
            if ($str[$i] === '*' && $i + 1 < $len && $str[$i + 1] === '*') {
                $close = strpos($str, '**', $i + 2);
                if ($close !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function unescape(string $str): string
    {
        $len = strlen($str);
        $result = '';
        for ($i = 0; $i < $len; $i++) {
            if ($str[$i] === '\\' && $i + 1 < $len && ($str[$i + 1] === '*' || $str[$i + 1] === '[')) {
                $result .= $str[$i + 1];
                $i++;

                continue;
            }
            $result .= $str[$i];
        }

        return $result;
    }
}
