<?php
/**
 * 2.29.0 Mock AI providers for Integrations → AI (Align\Ai). Included by tests/mock-server.php with $path, $method and
 * $json in scope; answers only /ai/<provider>/... and /mock/ai*, and returns true once it has answered.
 *
 * Setting for the tests: ai_api_base = <mock>/ai (honoured only with allow_insecure_integrations). Each provider's
 * own path and answer shape:
 *  - /ai/claude/v1/messages (x-api-key, anthropic-version), /ai/openai/v1/chat/completions and
 *    /ai/grok/v1/chat/completions (Bearer), /ai/gemini/v1beta/models/<model>:generateContent (x-goog-api-key),
 *    /ai/ollama/api/chat (Bearer only when a key is saved).
 *  - The key must be "key-<provider>" (anything else: 401), and Ollama takes none or "key-ollama".
 *  - The answer is the state's "text" (default "OK"), with "input" and "output" tokens (default 12 and 3).
 *  - "fail": a status to answer with instead (404, 429, 500…), with a provider-style error message.
 *  - "queue" (2.30.0): a list of answer texts; each successful answer takes the next one, then "text" again once
 *    the queue is empty. A failed request (fail, a wrong key) leaves the queue as it was.
 * Every request is recorded (provider, path, the auth header's name and value, the body). Control: POST /mock/ai
 * (merge into the state), GET /mock/ai-state, /mock/ai-reset.
 */
declare(strict_types=1);

if (!str_starts_with($path, '/ai/') && !str_starts_with($path, '/mock/ai')) {
    return false;
}
$file = sys_get_temp_dir() . '/ai-mock.json';
$fresh = ['text' => 'OK', 'input' => 12, 'output' => 3, 'fail' => 0, 'queue' => [], 'calls' => []];
$state = json_decode((string) @file_get_contents($file), true) ?: $fresh;
$save = function () use (&$state, $file): void { // by reference: the state as changed below
    file_put_contents($file, json_encode($state), LOCK_EX);
};

if ($path === '/mock/ai-reset') {
    @unlink($file);
    $json(['ok' => true]);
    return true;
}
if ($path === '/mock/ai-state') {
    $json($state);
    return true;
}
if ($path === '/mock/ai') {
    $state = array_merge($state, json_decode((string) file_get_contents('php://input'), true) ?: []);
    $save();
    $json(['ok' => true]);
    return true;
}

if (!preg_match('#^/ai/(claude|openai|grok|gemini|ollama)(/.*)$#', $path, $m) || $method !== 'POST') {
    http_response_code(404);
    $json(['error' => ['message' => 'Not found']]);
    return true;
}
[, $provider, $rest] = $m;
$h = array_change_key_case(function_exists('getallheaders') ? getallheaders() : [], CASE_LOWER);
[$authName, $authValue] = match ($provider) {
    'claude' => ['x-api-key', $h['x-api-key'] ?? ''],
    'gemini' => ['x-goog-api-key', $h['x-goog-api-key'] ?? ''],
    default => ['authorization', $h['authorization'] ?? ''],
};
$key = preg_replace('/^Bearer /', '', $authValue);
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
$state['calls'][] = ['provider' => $provider, 'path' => $rest, 'auth' => $authName, 'key' => $key, 'version' => $h['anthropic-version'] ?? '', 'body' => $body];
$save();

$expected = ['claude' => '/v1/messages', 'openai' => '/v1/chat/completions', 'grok' => '/v1/chat/completions', 'ollama' => '/api/chat'][$provider] ?? null;
if (($expected !== null && $rest !== $expected) || ($provider === 'gemini' && !preg_match('#^/v1beta/models/[A-Za-z0-9._-]+:generateContent$#', $rest))) {
    http_response_code(404);
    $json(['error' => ['message' => 'Unknown path ' . $rest]]);
    return true;
}
if ($provider === 'ollama' ? !in_array($key, ['', 'key-ollama'], true) : $key !== "key-$provider") {
    http_response_code(401);
    $json(['error' => ['message' => 'Invalid API key']]);
    return true;
}
if ((int) $state['fail'] > 0) {
    http_response_code((int) $state['fail']);
    $json(['error' => ['message' => 'Mock failure ' . (int) $state['fail']]]);
    return true;
}
[$text, $in, $out] = [(string) $state['text'], (int) $state['input'], (int) $state['output']];
if (!empty($state['queue']) && is_array($state['queue'])) { // 2.30.0 the next queued answer
    $text = (string) array_shift($state['queue']);
    $save();
}
$json(match ($provider) {
    'claude' => ['id' => 'msg_mock', 'type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'text', 'text' => $text]], 'usage' => ['input_tokens' => $in, 'output_tokens' => $out]],
    'openai', 'grok' => ['id' => 'chatcmpl-mock', 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text]]], 'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out, 'total_tokens' => $in + $out]],
    'gemini' => ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $text]]]]], 'usageMetadata' => ['promptTokenCount' => $in, 'candidatesTokenCount' => $out]],
    'ollama' => ['model' => $body['model'] ?? '', 'message' => ['role' => 'assistant', 'content' => $text], 'done' => true, 'prompt_eval_count' => $in, 'eval_count' => $out],
});
return true;
