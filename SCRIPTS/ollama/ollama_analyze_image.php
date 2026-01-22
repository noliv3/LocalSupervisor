<?php
declare(strict_types=1);

require_once __DIR__ . '/../paths.php';
require_once __DIR__ . '/../ollama_client.php';
require_once __DIR__ . '/ollama_result_normalize.php';

const SV_OLLAMA_ANALYZE_PROMPT = "Erzeuge einen Titel, eine kurze Bildbeschreibung (max 3 Sätze)\nund eine Qualitätswertung von 0–100. Antworte ausschließlich als JSON mit Feldern:\ntitle, description, quality_score.";

function sv_ollama_analyze_image(PDO $pdo, array $config, int $mediaId, ?string $modelOverride = null): array
{
    $pathsCfg = $config['paths'] ?? [];
    $ollamaCfg = sv_ollama_config($config);

    $model = $modelOverride !== null && trim($modelOverride) !== ''
        ? trim($modelOverride)
        : ($ollamaCfg['model']['vision'] ?? $ollamaCfg['model_default']);

    $timeoutMs = (int)$ollamaCfg['timeout_ms'];

    $pathStmt = $pdo->prepare('SELECT path, type, filesize FROM media WHERE id = :id LIMIT 1');
    $pathStmt->execute([':id' => $mediaId]);
    $row = $pathStmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row) || !is_string($row['path'] ?? null) || trim((string)$row['path']) === '') {
        throw new RuntimeException('Media-Pfad nicht gefunden.');
    }
    if (($row['type'] ?? '') !== 'image') {
        throw new RuntimeException('Nur Bildmedien können analysiert werden.');
    }

    $path = (string)$row['path'];
    sv_assert_media_path_allowed($path, $pathsCfg, 'ollama_analyze');

    if (!is_file($path)) {
        throw new RuntimeException('Media-Datei fehlt.');
    }

    $fileSize = isset($row['filesize']) ? (int)$row['filesize'] : (int)@filesize($path);
    $maxBytes = (int)$ollamaCfg['max_image_bytes'];
    if ($maxBytes > 0 && $fileSize > $maxBytes) {
        throw new RuntimeException('Bildgröße zu groß (' . $fileSize . ' > ' . $maxBytes . ' Bytes).');
    }

    $imageData = @file_get_contents($path);
    if ($imageData === false) {
        throw new RuntimeException('Media-Datei konnte nicht gelesen werden.');
    }

    $imageBase64 = base64_encode($imageData);

    $response = sv_ollama_request($config, [
        'prompt' => SV_OLLAMA_ANALYZE_PROMPT,
        'images' => [$imageBase64],
    ], [
        'model' => $model,
        'timeout_ms' => $timeoutMs,
    ]);

    if (empty($response['ok'])) {
        $error = isset($response['error']) ? (string)$response['error'] : 'Ollama-Request fehlgeschlagen.';
        throw new RuntimeException($error);
    }

    $responseJson = is_array($response['response_json'] ?? null) ? $response['response_json'] : null;
    $normalized = $responseJson !== null ? $responseJson : sv_ollama_normalize_result((string)($response['response_text'] ?? ''));

    return [
        'model' => $response['model'] ?? $model,
        'raw_json' => $responseJson,
        'normalized' => $normalized,
        'parse_error' => !empty($response['parse_error']),
    ];
}
