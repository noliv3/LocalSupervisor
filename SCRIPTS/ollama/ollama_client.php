<?php
declare(strict_types=1);

const SV_OLLAMA_BASE_URL_DEFAULT = 'http://127.0.0.1:11434';

function sv_ollama_client_generate(
    string $model,
    string $prompt,
    array $images,
    int $timeoutMs = 20000,
    ?string $baseUrl = null
): string {
    $baseUrl = $baseUrl !== null && trim($baseUrl) !== ''
        ? rtrim(trim($baseUrl), '/')
        : SV_OLLAMA_BASE_URL_DEFAULT;
    $url = $baseUrl . '/api/generate';

    $payload = [
        'model' => $model,
        'prompt' => $prompt,
        'images' => $images,
        'stream' => false,
    ];

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($jsonPayload === false) {
        throw new RuntimeException('Ollama-Payload konnte nicht serialisiert werden.');
    }

    $timeoutSec = max(1, (int)ceil($timeoutMs / 1000));

    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $jsonPayload,
            'timeout' => $timeoutSec,
        ],
    ]);

    $responseBody = @file_get_contents($url, false, $context);

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
        $code = $httpCode ?? 0;
        throw new RuntimeException('Ollama-Request fehlgeschlagen.', $code);
    }

    if ($httpCode === null || $httpCode < 200 || $httpCode >= 300) {
        $code = $httpCode ?? 0;
        throw new RuntimeException('Ollama-Request HTTP-Fehler.', $code);
    }

    return $responseBody;
}
