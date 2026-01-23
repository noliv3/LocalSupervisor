<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/security.php';

const SV_OLLAMA_DEFAULT_BASE_URL = 'http://127.0.0.1:11434';

function sv_ollama_config(array $config): array
{
    $ollama = $config['ollama'] ?? [];
    if (!is_array($ollama)) {
        $ollama = [];
    }

    $enabled = !array_key_exists('enabled', $ollama) || (bool)$ollama['enabled'] === true;

    $model = $ollama['model'] ?? [];
    if (!is_array($model)) {
        $model = [];
    }

    $modelDefault = isset($ollama['model_default']) && is_string($ollama['model_default']) && trim($ollama['model_default']) !== ''
        ? trim($ollama['model_default'])
        : (isset($model['default']) && is_string($model['default']) && trim($model['default']) !== ''
            ? trim($model['default'])
            : 'llava:latest');

    $retry = $ollama['retry'] ?? [];
    if (!is_array($retry)) {
        $retry = [];
    }

    $deterministic = $ollama['deterministic'] ?? [];
    if (!is_array($deterministic)) {
        $deterministic = [];
    }

    $worker = $ollama['worker'] ?? [];
    if (!is_array($worker)) {
        $worker = [];
    }

    return [
        'enabled' => $enabled,
        'base_url' => isset($ollama['base_url']) && is_string($ollama['base_url']) && trim($ollama['base_url']) !== ''
            ? trim($ollama['base_url'])
            : SV_OLLAMA_DEFAULT_BASE_URL,
        'model_default' => $modelDefault,
        'model' => [
            'default' => isset($model['default']) && is_string($model['default']) && trim($model['default']) !== ''
                ? trim($model['default'])
                : $modelDefault,
            'vision' => isset($model['vision']) && is_string($model['vision']) && trim($model['vision']) !== ''
                ? trim($model['vision'])
                : $modelDefault,
            'text' => isset($model['text']) && is_string($model['text']) && trim($model['text']) !== ''
                ? trim($model['text'])
                : 'llama3:latest',
        ],
        'caption_prompt_template' => isset($ollama['caption_prompt_template']) && is_string($ollama['caption_prompt_template']) && trim($ollama['caption_prompt_template']) !== ''
            ? trim($ollama['caption_prompt_template'])
            : "Beschreibe das Bild in 1-3 Sätzen. Antworte ausschließlich als JSON.\nFormat: {\"caption\":\"...\",\"contradictions\":[],\"missing\":[],\"rationale\":\"...\"}",
        'title_prompt_template' => isset($ollama['title_prompt_template']) && is_string($ollama['title_prompt_template']) && trim($ollama['title_prompt_template']) !== ''
            ? trim($ollama['title_prompt_template'])
            : "Erzeuge einen kurzen, prägnanten Titel (max 80 Zeichen). Antworte ausschließlich als JSON.\nFormat: {\"title\":\"...\",\"rationale\":\"...\"}",
        'prompt_eval_template' => isset($ollama['prompt_eval_template']) && is_string($ollama['prompt_eval_template']) && trim($ollama['prompt_eval_template']) !== ''
            ? trim($ollama['prompt_eval_template'])
            : "Bewerte, wie gut der folgende Prompt das Bild beschreibt (0-100). Nenne Widersprüche, fehlende Elemente und eine kurze Begründung. Antworte ausschließlich als JSON.\nFormat: {\"score\":0,\"contradictions\":[],\"missing\":[],\"rationale\":\"...\"}\nPrompt: {{prompt}}",
        'tags_normalize_template' => isset($ollama['tags_normalize_template']) && is_string($ollama['tags_normalize_template']) && trim($ollama['tags_normalize_template']) !== ''
            ? trim($ollama['tags_normalize_template'])
            : "Normalisiere die folgenden Roh-Tags in kanonische, einheitliche Tags. Antworte ausschließlich als JSON.\nFormat: {\"tags_normalized\":[],\"tags_map\":[{\"raw\":\"\",\"normalized\":\"\",\"confidence\":0.0,\"type\":\"\"}],\"rationale\":\"...\"}\nTags: {{tags}}\nKontext: {{context}}",
        'quality_template' => isset($ollama['quality_template']) && is_string($ollama['quality_template']) && trim($ollama['quality_template']) !== ''
            ? trim($ollama['quality_template'])
            : "Bewerte die technische Bildqualität (0-100) und klassifiziere die Domäne. Antworte ausschließlich als JSON.\nFormat: {\"quality_score\":0,\"quality_flags\":[],\"domain_type\":\"other\",\"domain_confidence\":0.0,\"rationale\":\"...\"}",
        'prompt_recon_template' => isset($ollama['prompt_recon_template']) && is_string($ollama['prompt_recon_template']) && trim($ollama['prompt_recon_template']) !== ''
            ? trim($ollama['prompt_recon_template'])
            : "Rekonstruiere den wahrscheinlichsten Prompt aus den Metadaten. Antworte ausschließlich als JSON.\nFormat: {\"prompt\":\"...\",\"negative_prompt\":\"...\",\"confidence\":0.0,\"style_tokens\":[],\"subject_tokens\":[],\"rationale\":\"...\"}\nCaption: {{caption}}\nTitle: {{title}}\nTags: {{tags_normalized}}\nDomain: {{domain_type}}\nQuality flags: {{quality_flags}}\nOriginal prompt: {{original_prompt}}",
        'timeout_ms' => isset($ollama['timeout_ms']) ? max(1000, (int)$ollama['timeout_ms']) : 20000,
        'max_image_bytes' => isset($ollama['max_image_bytes']) ? max(0, (int)$ollama['max_image_bytes']) : 4194304,
        'retry' => [
            'max_attempts' => isset($retry['max_attempts']) ? max(1, (int)$retry['max_attempts']) : 3,
            'backoff_ms' => isset($retry['backoff_ms']) ? max(0, (int)$retry['backoff_ms']) : 500,
        ],
        'deterministic' => [
            'enabled' => isset($deterministic['enabled']) ? (bool)$deterministic['enabled'] : true,
            'temperature' => isset($deterministic['temperature']) ? (float)$deterministic['temperature'] : 0.0,
            'top_p' => isset($deterministic['top_p']) ? (float)$deterministic['top_p'] : 1.0,
            'seed' => isset($deterministic['seed']) ? $deterministic['seed'] : 42,
        ],
        'worker' => [
            'batch_size' => isset($worker['batch_size']) ? max(1, (int)$worker['batch_size']) : 5,
            'max_retries' => isset($worker['max_retries']) ? max(0, (int)$worker['max_retries']) : 2,
        ],
        'prompt_eval_fallback' => isset($ollama['prompt_eval_fallback']) && is_string($ollama['prompt_eval_fallback'])
            ? strtolower(trim($ollama['prompt_eval_fallback']))
            : 'tags',
        'prompt_eval_fallback_separator' => isset($ollama['prompt_eval_fallback_separator']) && is_string($ollama['prompt_eval_fallback_separator'])
            ? (string)$ollama['prompt_eval_fallback_separator']
            : ', ',
    ];
}

function sv_ollama_truncate_for_log(?string $value, int $maxLen = 240): string
{
    if (!is_string($value)) {
        return '';
    }

    $value = preg_replace('~data:[^;]+;base64,[A-Za-z0-9+/=]+~', '<base64>', $value);
    $value = preg_replace('/[A-Za-z0-9+\/=]{120,}/', '<base64>', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    $value = trim((string)$value);

    if ($maxLen > 0 && mb_strlen($value, 'UTF-8') > $maxLen) {
        return mb_substr($value, 0, $maxLen, 'UTF-8') . '…';
    }

    return $value;
}

function sv_ollama_health(array $config): array
{
    $cfg = sv_ollama_config($config);
    $baseUrl = rtrim($cfg['base_url'], '/');
    $url = $baseUrl . '/api/version';
    $timeoutMs = (int)$cfg['timeout_ms'];

    $start = microtime(true);
    $context = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => "Accept: application/json\r\n",
            'timeout' => $timeoutMs / 1000,
        ],
    ]);

    $responseBody = @file_get_contents($url, false, $context);
    $latencyMs = (int)round((microtime(true) - $start) * 1000);

    $httpCode = null;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match('~^HTTP/[^ ]+ ([0-9]{3})~', (string)$headerLine, $matches)) {
                $httpCode = (int)$matches[1];
                break;
            }
        }
    }

    if ($responseBody !== false && $httpCode !== null && $httpCode >= 200 && $httpCode < 300) {
        return [
            'ok' => true,
            'latency_ms' => $latencyMs,
            'message' => null,
        ];
    }

    $message = 'Ollama Healthcheck fehlgeschlagen';
    if ($httpCode !== null) {
        $message .= ' (HTTP ' . $httpCode . ')';
    }

    return [
        'ok' => false,
        'latency_ms' => $latencyMs,
        'message' => $message,
    ];
}

function sv_ollama_generate_text(array $config, string $prompt, array $options): array
{
    return sv_ollama_request($config, [
        'prompt' => $prompt,
        'images' => null,
    ], $options);
}

function sv_ollama_analyze_image(array $config, string $imageBase64, string $prompt, array $options): array
{
    return sv_ollama_request($config, [
        'prompt' => $prompt,
        'images' => [$imageBase64],
    ], $options);
}

function sv_ollama_request(array $config, array $input, array $options): array
{
    $cfg = sv_ollama_config($config);
    $baseUrl = rtrim($cfg['base_url'], '/');
    $url = $baseUrl . '/api/generate';

    $prompt = isset($input['prompt']) ? (string)$input['prompt'] : '';
    $images = $input['images'] ?? null;

    $model = isset($options['model']) && is_string($options['model']) && trim($options['model']) !== ''
        ? trim($options['model'])
        : $cfg['model']['default'];

    $payloadOptions = [
        'model' => $model,
        'format' => 'json',
    ];

    if (isset($options['temperature'])) {
        $payloadOptions['temperature'] = (float)$options['temperature'];
    }
    if (isset($options['top_p'])) {
        $payloadOptions['top_p'] = (float)$options['top_p'];
    }
    if (array_key_exists('seed', $options)) {
        $payloadOptions['seed'] = $options['seed'];
    }

    if (($cfg['deterministic']['enabled'] ?? false) && (!isset($options['deterministic']) || $options['deterministic'] !== false)) {
        $payloadOptions['temperature'] = (float)($cfg['deterministic']['temperature'] ?? 0.0);
        $payloadOptions['top_p'] = (float)($cfg['deterministic']['top_p'] ?? 1.0);
        $payloadOptions['seed'] = $cfg['deterministic']['seed'] ?? null;
    }

    $timeoutMs = isset($options['timeout_ms']) ? max(1000, (int)$options['timeout_ms']) : (int)$cfg['timeout_ms'];
    $maxAttempts = max(1, (int)($cfg['retry']['max_attempts'] ?? 1));
    $backoffMs = max(0, (int)($cfg['retry']['backoff_ms'] ?? 0));

    $requestPayload = [
        'model' => $model,
        'prompt' => $prompt,
        'format' => 'json',
        'stream' => false,
        'options' => $payloadOptions,
    ];
    if (is_array($images) && $images !== []) {
        $requestPayload['images'] = $images;
    }

    $attempt = 0;
    $lastError = null;
    while ($attempt < $maxAttempts) {
        $attempt++;
        $start = microtime(true);
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => json_encode($requestPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'timeout' => $timeoutMs / 1000,
            ],
        ]);

        $lastError = null;
        set_error_handler(static function ($severity, $message) use (&$lastError): bool {
            $lastError = $message;
            return false;
        });
        try {
            $responseBody = @file_get_contents($url, false, $context);
        } catch (Throwable $e) {
            restore_error_handler();
            $lastError = $e->getMessage();
            $responseBody = false;
        }
        restore_error_handler();

        $latencyMs = (int)round((microtime(true) - $start) * 1000);

        $httpCode = null;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $headerLine) {
                if (preg_match('~^HTTP/[^ ]+ ([0-9]{3})~', (string)$headerLine, $matches)) {
                    $httpCode = (int)$matches[1];
                    break;
                }
            }
        }

        if ($responseBody === false) {
            $message = $lastError ? sv_sanitize_error_message((string)$lastError, 200) : 'Transportfehler';
            if ($attempt < $maxAttempts) {
                if ($backoffMs > 0) {
                    $sleepMs = (int)round($backoffMs * pow(2, $attempt - 1));
                    usleep($sleepMs * 1000);
                }
                continue;
            }

            return [
                'ok' => false,
                'model' => $model,
                'response_json' => null,
                'usage' => null,
                'error' => $message,
                'latency_ms' => $latencyMs,
            ];
        }

        if ($httpCode !== null && ($httpCode < 200 || $httpCode >= 300)) {
            return [
                'ok' => false,
                'model' => $model,
                'response_json' => null,
                'usage' => null,
                'error' => 'HTTP ' . $httpCode,
                'latency_ms' => $latencyMs,
            ];
        }

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'model' => $model,
                'response_json' => null,
                'response_text' => null,
                'raw_body' => $responseBody !== false ? (string)$responseBody : null,
                'parse_error' => true,
                'usage' => null,
                'error' => 'Ungültige JSON-Antwort',
                'latency_ms' => $latencyMs,
            ];
        }

        if (isset($decoded['error'])) {
            return [
                'ok' => false,
                'model' => $model,
                'response_json' => null,
                'response_text' => null,
                'raw_body' => $responseBody !== false ? (string)$responseBody : null,
                'parse_error' => true,
                'usage' => null,
                'error' => sv_sanitize_error_message((string)$decoded['error'], 200),
                'latency_ms' => $latencyMs,
            ];
        }

        $responseText = $decoded['response'] ?? null;
        $responseJson = null;
        $parseError = false;
        if (is_array($responseText)) {
            $responseJson = $responseText;
        } elseif (is_string($responseText)) {
            $parsed = json_decode($responseText, true);
            if (is_array($parsed)) {
                $responseJson = $parsed;
            } else {
                $parseError = true;
            }
        } elseif ($responseText !== null) {
            $parseError = true;
        }

        $usage = [];
        if (isset($decoded['prompt_eval_count'])) {
            $usage['input_tokens'] = (int)$decoded['prompt_eval_count'];
        }
        if (isset($decoded['eval_count'])) {
            $usage['output_tokens'] = (int)$decoded['eval_count'];
        }

        return [
            'ok' => true,
            'model' => $decoded['model'] ?? $model,
            'response_json' => $responseJson,
            'response_text' => is_string($responseText) ? $responseText : null,
            'raw_body' => $responseBody !== false ? (string)$responseBody : null,
            'parse_error' => $parseError,
            'usage' => $usage !== [] ? $usage : null,
            'error' => null,
            'latency_ms' => $latencyMs,
        ];
    }

    return [
        'ok' => false,
        'model' => $model,
        'response_json' => null,
        'usage' => null,
        'error' => 'Unbekannter Fehler',
        'latency_ms' => null,
    ];
}
