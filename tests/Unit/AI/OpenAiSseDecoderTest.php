<?php

use App\Services\AI\OpenAiSseDecoder;

test('decoder decodes single complete event in one chunk', function () {
    $decoder = new OpenAiSseDecoder;
    $chunk = "data: {\"type\":\"response.output_text.delta\",\"delta\":\"Hello\"}\n\n";

    $events = iterator_to_array($decoder->push($chunk));

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBe([
            'type' => 'response.output_text.delta',
            'delta' => 'Hello',
        ]);
});

test('decoder handles events split across multiple chunks', function () {
    $decoder = new OpenAiSseDecoder;

    $events1 = iterator_to_array($decoder->push('data: {"type":"response.out'));
    expect($events1)->toBeEmpty();

    $events2 = iterator_to_array($decoder->push("put_text.delta\",\"delta\":\"World\"}\n\n"));
    expect($events2)->toHaveCount(1)
        ->and($events2[0])->toBe([
            'type' => 'response.output_text.delta',
            'delta' => 'World',
        ]);
});

test('decoder parses multiple events in a single chunk', function () {
    $decoder = new OpenAiSseDecoder;
    $chunk = "data: {\"type\":\"response.output_text.delta\",\"delta\":\"A\"}\n\n".
        "data: {\"type\":\"response.output_text.delta\",\"delta\":\"B\"}\n\n";

    $events = iterator_to_array($decoder->push($chunk));

    expect($events)->toHaveCount(2)
        ->and($events[0]['delta'])->toBe('A')
        ->and($events[1]['delta'])->toBe('B');
});

test('decoder handles CRLF and CR line endings', function () {
    $decoder = new OpenAiSseDecoder;
    $chunk = "data: {\"type\":\"response.output_text.delta\",\"delta\":\"CRLF\"}\r\n\r\n".
        "data: {\"type\":\"response.output_text.delta\",\"delta\":\"CR\"}\r\r";

    $events = iterator_to_array($decoder->push($chunk));

    expect($events)->toHaveCount(2)
        ->and($events[0]['delta'])->toBe('CRLF')
        ->and($events[1]['delta'])->toBe('CR');
});

test('decoder ignores comment lines and blank lines', function () {
    $decoder = new OpenAiSseDecoder;
    $chunk = ": this is a comment\n\n".
        ": another comment\n".
        "data: {\"type\":\"response.output_text.delta\",\"delta\":\"Valid\"}\n\n";

    $events = iterator_to_array($decoder->push($chunk));

    expect($events)->toHaveCount(1)
        ->and($events[0]['delta'])->toBe('Valid');
});

test('decoder ignores done marker', function () {
    $decoder = new OpenAiSseDecoder;
    $chunk = "data: [DONE]\n\n";

    $events = iterator_to_array($decoder->push($chunk));

    expect($events)->toBeEmpty();
});

test('decoder flags malformed json payload', function () {
    $decoder = new OpenAiSseDecoder;
    $chunk = "data: {invalid-json}\n\n";

    $events = iterator_to_array($decoder->push($chunk));

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBe(['__malformed__' => true]);
});

test('decoder joins multi-line data fields', function () {
    $decoder = new OpenAiSseDecoder;
    $chunk = "data: {\"type\":\"response.output_text.delta\",\n".
        "data: \"delta\":\"Multiline\"}\n\n";

    $events = iterator_to_array($decoder->push($chunk));

    expect($events)->toHaveCount(1)
        ->and($events[0]['delta'])->toBe('Multiline');
});

test('decoder resets buffer on reset call', function () {
    $decoder = new OpenAiSseDecoder;
    $decoder->push('data: {"type":"partial');
    $decoder->reset();

    $events = iterator_to_array($decoder->push("data: {\"type\":\"fresh\"}\n\n"));

    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('fresh');
});
