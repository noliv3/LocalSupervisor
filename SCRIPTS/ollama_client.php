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

    $model = $ollama['model'] ?? [];
    if (!is_array($model)) {
        $model = [];
    }

    $retry = $ollama['retry'] ?? [];
    if (!is_array($retry)) {
        $retry = [];
    }

    $deterministic = $ollama['deterministic'] ?? [];
    if (!is_array($deterministic)) {
        $deterministic = [];
    }

    return [
        'base_url' => isset($ollama['base_url']) && is_string($ollama['base_url']) && trim($ollama['base_url']) !== ''
            ? trim($ollama['base_url'])
            : SV_OLLAMA_DEFAULT_BASE_URL,
        'model' => [
            'default' => isset($model['default']) && is_string($model['default']) && trim($model['default']) !== ''
                ? trim($model['default'])
                : 'llava:latest',
            'vision' => isset($model['vision']) && is_string($model['vision']) && trim($model['vision']) !== ''
                ? trim($model['vision'])
                : 'llava:latest',
            'text' => isset($model['text']) && is_string($model['text']) && trim($model['text']) !== ''
                ? trim($model['text'])
                : 'llama3:latest',
        ],
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
                'usage' => null,
                'error' => sv_sanitize_error_message((string)$decoded['error'], 200),
                'latency_ms' => $latencyMs,
            ];
        }

        $responseJson = $decoded['response'] ?? null;
        if (is_string($responseJson)) {
            $parsed = json_decode($responseJson, true);
            if (is_array($parsed)) {
                $responseJson = $parsed;
            }
        }

        if (!is_array($responseJson)) {
            return [
                'ok' => false,
                'model' => $model,
                'response_json' => null,
                'usage' => null,
                'error' => 'Antwort ist kein JSON-Objekt',
                'latency_ms' => $latencyMs,
            ];
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
