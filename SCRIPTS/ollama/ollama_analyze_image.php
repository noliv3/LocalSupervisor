<?php
declare(strict_types=1);

require_once __DIR__ . '/../paths.php';
require_once __DIR__ . '/ollama_client.php';
require_once __DIR__ . '/ollama_result_normalize.php';

const SV_OLLAMA_ANALYZE_PROMPT = "Erzeuge einen Titel, eine kurze Bildbeschreibung (max 3 Sätze)\n   und eine Qualitätswertung von 0–100.\n   Antworte ausschließlich als JSON mit Feldern:\n   title, description, quality_score.";

function sv_ollama_analyze_image(PDO $pdo, array $config, int $mediaId, ?string $modelOverride = null): array
{
    $pathsCfg = $config['paths'] ?? [];
    $ollamaCfg = $config['ollama'] ?? [];
    $modelCfg = $ollamaCfg['model'] ?? [];

    $model = $modelOverride !== null && trim($modelOverride) !== ''
        ? trim($modelOverride)
        : (is_array($modelCfg) && isset($modelCfg['vision']) && is_string($modelCfg['vision']) && trim($modelCfg['vision']) !== ''
            ? trim($modelCfg['vision'])
            : 'llava:latest');

    $baseUrl = isset($ollamaCfg['base_url']) && is_string($ollamaCfg['base_url']) && trim($ollamaCfg['base_url']) !== ''
        ? trim($ollamaCfg['base_url'])
        : SV_OLLAMA_BASE_URL_DEFAULT;

    $timeoutMs = isset($ollamaCfg['timeout_ms']) ? max(1000, (int)$ollamaCfg['timeout_ms']) : 20000;

    $pathStmt = $pdo->prepare('SELECT path FROM media WHERE id = :id LIMIT 1');
    $pathStmt->execute([':id' => $mediaId]);
    $path = $pathStmt->fetchColumn();

    if (!is_string($path) || trim($path) === '') {
        throw new RuntimeException('Media-Pfad nicht gefunden.');
    }

    $path = str_replace('\\', '/', trim($path));
    sv_assert_media_path_allowed($path, $pathsCfg, 'ollama_analyze');

    if (!is_file($path)) {
        throw new RuntimeException('Media-Datei fehlt.');
    }

    $imageData = @file_get_contents($path);
    if ($imageData === false) {
        throw new RuntimeException('Media-Datei konnte nicht gelesen werden.');
    }

    $imageBase64 = base64_encode($imageData);

    $rawJson = sv_ollama_client_generate(
        $model,
        SV_OLLAMA_ANALYZE_PROMPT,
        [$imageBase64],
        $timeoutMs,
        $baseUrl
    );

    $normalized = sv_ollama_normalize_result($rawJson);

    return [
        'model' => $model,
        'raw_json' => $rawJson,
        'normalized' => $normalized,
    ];
}
