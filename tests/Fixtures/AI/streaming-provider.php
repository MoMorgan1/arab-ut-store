<?php

// Standalone loopback server script for testing real StreamHandler transport
$requestUri = $_SERVER['REQUEST_URI'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_contains($requestUri, '/responses')) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    if (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();

    echo "data: {\"type\":\"response.output_text.delta\",\"delta\":\"Hello \"}\n\n";
    flush();
    usleep(25000);

    echo "data: {\"type\":\"response.output_text.delta\",\"delta\":\"World\"}\n\n";
    flush();
    usleep(25000);

    echo "data: {\"type\":\"response.completed\",\"response\":{\"id\":\"resp_loopback_01\",\"usage\":{\"input_tokens\":100,\"input_tokens_details\":{\"cached_tokens\":0,\"cache_write_tokens\":0},\"output_tokens\":50,\"output_tokens_details\":{\"reasoning_tokens\":10},\"total_tokens\":150}}}\n\n";
    flush();

    exit(0);
}

http_response_code(404);
echo json_encode(['error' => 'not found']);
