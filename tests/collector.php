<?php

// A stand-in OTLP collector for the tests (php -S): stores each POST body, answers like Alloy.
if ($_SERVER['REQUEST_URI'] !== '/v1/traces') {
    http_response_code(404);
    exit;
}
file_put_contents(getenv('COLLECTOR_OUT'), file_get_contents('php://input'));
header('Content-Type: application/json');
echo '{"partialSuccess":{}}';
