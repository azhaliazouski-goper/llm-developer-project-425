<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/telegram_poller.php';

function failTest(string $message): never
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        failTest($message . '\nExpected: ' . var_export($expected, true)
            . '\nActual: ' . var_export($actual, true));
    }
}

function assertContainsText(string $needle, string $haystack, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        failTest($message . "\nMissing: $needle");
    }
}

if (!function_exists('buildAgentPayload')) {
    failTest('buildAgentPayload() is missing; the poller cannot build an MCP-enabled request');
}

$payload = buildAgentPayload(
    'gpt://folder/yandexgpt',
    'telegram-user-42',
    'Создай заявку: не работает принтер',
    'https://gateway.example.test/sse'
);

assertSameValue('gpt://folder/yandexgpt', $payload['model'] ?? null, 'The selected model must be preserved');
assertSameValue(
    [['role' => 'user', 'content' => 'Создай заявку: не работает принтер']],
    $payload['input'] ?? null,
    'The user message must stay separate from trusted instructions'
);
assertContainsText(
    'telegram-user-42',
    (string)($payload['instructions'] ?? ''),
    'The trusted instructions must provide the Telegram user_id to MCP tools'
);
assertSameValue(
    [[
        'type' => 'mcp',
        'server_label' => 'ydb-tickets',
        'server_url' => 'https://gateway.example.test/sse',
        'require_approval' => 'never',
    ]],
    $payload['tools'] ?? null,
    'The request must expose the YDB MCP gateway without an approval round trip'
);

fwrite(STDOUT, "PASS: telegram poller builds an MCP-enabled Responses API payload\n");
