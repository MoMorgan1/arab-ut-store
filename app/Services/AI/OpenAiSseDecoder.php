<?php

namespace App\Services\AI;

use Generator;

final class OpenAiSseDecoder
{
    private string $buffer = '';

    /**
     * @return Generator<int, array<string, mixed>, mixed, void>
     */
    public function push(string $chunk): Generator
    {
        $this->buffer .= $chunk;

        while (true) {
            $matched = preg_match('/(?:\r\n\r\n|\n\n|\r\r)/', $this->buffer, $matches, PREG_OFFSET_CAPTURE);
            if ($matched !== 1) {
                break;
            }

            $delimiterPos = (int) $matches[0][1];
            $delimiterLength = strlen($matches[0][0]);

            $eventBlock = substr($this->buffer, 0, $delimiterPos);
            $this->buffer = substr($this->buffer, $delimiterPos + $delimiterLength);

            $event = $this->parseEventBlock($eventBlock);
            if ($event !== null) {
                yield $event;
            }
        }
    }

    public function reset(): void
    {
        $this->buffer = '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseEventBlock(string $eventBlock): ?array
    {
        $lines = preg_split('/\r\n|\r|\n/', $eventBlock);
        if ($lines === false) {
            return null;
        }

        $dataLines = [];
        $eventType = null;

        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, ':')) {
                continue;
            }

            if (str_starts_with($line, 'event:')) {
                $eventType = trim(substr($line, 6));

                continue;
            }

            if (str_starts_with($line, 'data:')) {
                $val = substr($line, 5);
                if (str_starts_with($val, ' ')) {
                    $val = substr($val, 1);
                }
                $dataLines[] = $val;
            }
        }

        if ($dataLines === []) {
            return null;
        }

        $data = implode("\n", $dataLines);
        $trimmedData = trim($data);

        if ($trimmedData === '' || $trimmedData === '[DONE]') {
            return null;
        }

        $decoded = json_decode($data, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return ['__malformed__' => true];
        }

        if ($eventType !== null && ! isset($decoded['type'])) {
            $decoded['type'] = $eventType;
        }

        return $decoded;
    }
}
