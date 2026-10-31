<?php
// includes/ai.php   [M4]  LLM + embedding client. Optional: everything works without it.
//
// LLM_PROVIDER = openai     -> any OpenAI-compatible Chat Completions API (OpenAI, Gemini's
//                              OpenAI endpoint, Groq, OpenRouter...). Set LLM_BASE_URL for non-OpenAI.
//              = anthropic  -> Anthropic Messages API
// The model name always comes from LLM_MODEL. Keys come only from the environment.
// Every call is logged in ai_interactions. Any failure returns null and callers fall back.
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function cit_llm_available(): bool
{
    return cit_env('LLM_PROVIDER') !== null && cit_env('LLM_API_KEY') !== null && cit_env('LLM_MODEL') !== null
        && (bool)(cit_setting_value('ai_enabled') ?? true);
}

function cit_embed_available(): bool
{
    return cit_env('EMBED_MODEL') !== null && (cit_env('EMBED_API_KEY') ?? cit_env('LLM_API_KEY')) !== null;
}

function cit_setting_value(string $k): mixed
{
    try {
        $st = cit_db()->prepare('SELECT v FROM app_settings WHERE k = ?');
        $st->execute([$k]);
        $v = $st->fetchColumn();
        return $v === false ? null : json_decode($v, true);
    } catch (Throwable) {
        return null;
    }
}

function cit_http_json(string $url, array $headers, array $body, int $timeout = 20): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException("Network error: $err");
    $json = json_decode($raw, true);
    if ($code >= 400 || !is_array($json)) {
        throw new RuntimeException("Provider returned HTTP $code: " . mb_substr(is_string($raw) ? $raw : '', 0, 300));
    }
    return $json;
}

function cit_log_ai(?int $userId, string $feature, ?string $input, mixed $output, string $status, ?string $error, int $ms, ?int $tin = null, ?int $tout = null): ?int
{
    try {
        cit_db()->prepare('INSERT INTO ai_interactions (user_id, feature, provider, model, input_text, output_json, status, error_message, latency_ms, tokens_in, tokens_out)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$userId, $feature, cit_env('LLM_PROVIDER'), $feature === 'embed' ? cit_env('EMBED_MODEL') : cit_env('LLM_MODEL'),
                $input === null ? null : mb_substr($input, 0, 20000), $output === null ? null : cit_json($output), $status,
                $error === null ? null : mb_substr($error, 0, 500), $ms, $tin, $tout]);
        return (int)cit_db()->lastInsertId();
    } catch (Throwable) {
        return null;
    }
}

/** Raw chat call. $messages = [['role' => 'user'|'assistant', 'content' => '...'], ...]. */
function cit_llm_chat(string $system, array $messages, string $feature, ?int $userId, bool $json = false, int $maxTokens = 900, ?int &$logId = null): ?string
{
    if (!cit_llm_available()) return null;
    $t0 = microtime(true);
    $provider = cit_env('LLM_PROVIDER');
    $model = cit_env('LLM_MODEL');
    $key = cit_env('LLM_API_KEY');
    $input = $system . "\n---\n" . implode("\n", array_map(fn($m) => "{$m['role']}: {$m['content']}", $messages));
    try {
        if ($provider === 'anthropic') {
            $res = cit_http_json(cit_env('LLM_BASE_URL', 'https://api.anthropic.com/v1') . '/messages',
                ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
                ['model' => $model, 'max_tokens' => $maxTokens, 'system' => $system, 'messages' => $messages, 'temperature' => 0]);
            $text = implode('', array_map(fn($b) => $b['text'] ?? '', $res['content'] ?? []));
            $tin = $res['usage']['input_tokens'] ?? null; $tout = $res['usage']['output_tokens'] ?? null;
        } else {
            $body = ['model' => $model, 'temperature' => 0, 'max_tokens' => $maxTokens,
                     'messages' => array_merge([['role' => 'system', 'content' => $system]], $messages)];
            if ($json) $body['response_format'] = ['type' => 'json_object'];
            $res = cit_http_json(rtrim(cit_env('LLM_BASE_URL', 'https://api.openai.com/v1'), '/') . '/chat/completions',
                ['Authorization: Bearer ' . $key], $body);
            $text = $res['choices'][0]['message']['content'] ?? '';
            $tin = $res['usage']['prompt_tokens'] ?? null; $tout = $res['usage']['completion_tokens'] ?? null;
        }
        $logId = cit_log_ai($userId, $feature, $input, ['text' => $text], 'ok', null, (int)((microtime(true) - $t0) * 1000), $tin, $tout);
        return $text;
    } catch (Throwable $e) {
        $logId = cit_log_ai($userId, $feature, $input, null, 'error', $e->getMessage(), (int)((microtime(true) - $t0) * 1000));
        return null;
    }
}

/** Chat call that must return a JSON object; null if the provider fails or returns non-JSON. */
function cit_llm_json(string $system, string $user, string $feature, ?int $userId, ?int &$logId = null): ?array
{
    $text = cit_llm_chat($system . "\nReply with one JSON object only, no prose, no code fences.", [['role' => 'user', 'content' => $user]], $feature, $userId, true, 900, $logId);
    if ($text === null) return null;
    $text = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($text)));
    $j = json_decode($text, true);
    if (!is_array($j)) {
        if ($logId) cit_db()->prepare("UPDATE ai_interactions SET status = 'rejected_schema' WHERE id = ?")->execute([$logId]);
        return null;
    }
    return $j;
}

/** Embeddings via an OpenAI-compatible /embeddings endpoint. Returns float vectors or null. */
function cit_embed(array $texts, ?int $userId = null): ?array
{
    if (!cit_embed_available() || !$texts) return null;
    $t0 = microtime(true);
    try {
        $res = cit_http_json(rtrim(cit_env('EMBED_BASE_URL', cit_env('LLM_BASE_URL', 'https://api.openai.com/v1')), '/') . '/embeddings',
            ['Authorization: Bearer ' . (cit_env('EMBED_API_KEY') ?? cit_env('LLM_API_KEY'))],
            ['model' => cit_env('EMBED_MODEL'), 'input' => array_values($texts)], 30);
        $out = array_map(fn($d) => $d['embedding'], $res['data'] ?? []);
        if (count($out) !== count($texts)) throw new RuntimeException('Embedding count mismatch');
        cit_log_ai($userId, 'embed', count($texts) . ' texts', null, 'ok', null, (int)((microtime(true) - $t0) * 1000));
        return $out;
    } catch (Throwable $e) {
        cit_log_ai($userId, 'embed', count($texts) . ' texts', null, 'error', $e->getMessage(), (int)((microtime(true) - $t0) * 1000));
        return null;
    }
}
