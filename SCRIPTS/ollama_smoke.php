<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Nur CLI erlaubt.\n");
    exit(1);
}

require_once __DIR__ . '/ollama_jobs.php';

$mediaId = null;
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--media-id=') === 0) {
        $mediaId = (int)substr($arg, 11);
    } elseif ($mediaId === null && is_numeric($arg)) {
        $mediaId = (int)$arg;
    }
}

if ($mediaId === null || $mediaId <= 0) {
    fwrite(STDERR, "Usage: php SCRIPTS/ollama_smoke.php --media-id=123\n");
    exit(1);
}

try {
    $config = sv_load_config();
    $pdo = sv_open_pdo($config);
} catch (Throwable $e) {
    fwrite(STDERR, "Init-Fehler: " . $e->getMessage() . "\n");
    exit(1);
}

try {
    $ollamaCfg = sv_ollama_config($config);
    if (!$ollamaCfg['enabled']) {
        throw new RuntimeException('Ollama ist deaktiviert.');
    }

    $imageData = sv_ollama_load_image_source($pdo, $config, $mediaId, []);
    $imageBase64 = $imageData['base64'] ?? null;
    if (!is_string($imageBase64) || $imageBase64 === '') {
        throw new RuntimeException('Bilddaten fehlen.');
    }

    $options = [
        'model' => $ollamaCfg['model']['vision'] ?? $ollamaCfg['model_default'],
        'timeout_ms' => $ollamaCfg['timeout_ms'],
    ];

    $captionPrompt = sv_ollama_build_prompt('caption', $config, [])['prompt'];
    $titlePrompt = sv_ollama_build_prompt('title', $config, [])['prompt'];

    $captionResponse = sv_ollama_analyze_image($config, $imageBase64, $captionPrompt, $options);
    $titleResponse = sv_ollama_analyze_image($config, $imageBase64, $titlePrompt, $options);

    if (empty($captionResponse['ok']) || empty($titleResponse['ok'])) {
        $error = $captionResponse['error'] ?? $titleResponse['error'] ?? 'Ollama-Request fehlgeschlagen.';
        throw new RuntimeException((string)$error);
    }

    $output = [
        'media_id' => $mediaId,
        'caption' => [
            'model' => $captionResponse['model'] ?? null,
            'parse_error' => !empty($captionResponse['parse_error']),
            'response' => $captionResponse['response_json'] ?? null,
            'raw_text' => $captionResponse['response_text'] ?? null,
        ],
        'title' => [
            'model' => $titleResponse['model'] ?? null,
            'parse_error' => !empty($titleResponse['parse_error']),
            'response' => $titleResponse['response_json'] ?? null,
            'raw_text' => $titleResponse['response_text'] ?? null,
        ],
    ];

    fwrite(STDOUT, json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Smoke-Fehler: " . $e->getMessage() . "\n");
    exit(1);
}
