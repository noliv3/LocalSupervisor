<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/ollama_client.php';

function sv_ollama_prompt_template(string $mode, array $config): array
{
    $mode = trim($mode);
    $map = [
        'caption' => ['file' => 'caption.txt', 'legacy_key' => 'caption_prompt_template'],
        'title' => ['file' => 'title.txt', 'legacy_key' => 'title_prompt_template'],
        'prompt_eval' => ['file' => 'prompt_eval.txt', 'legacy_key' => 'prompt_eval_template'],
        'tags_normalize' => ['file' => 'tags_normalize.txt', 'legacy_key' => 'tags_normalize_template'],
        'quality' => ['file' => 'quality.txt', 'legacy_key' => 'quality_template'],
        'prompt_recon' => ['file' => 'prompt_recon.txt', 'legacy_key' => 'prompt_recon_template'],
        'nsfw_classify' => ['file' => 'nsfw_classify.txt', 'legacy_key' => 'nsfw_classify_template'],
    ];

    if (!isset($map[$mode])) {
        throw new InvalidArgumentException('Unbekannter Ollama-Modus: ' . $mode);
    }

    $ollamaCfg = sv_ollama_config($config);
    $promptDir = $ollamaCfg['prompts_dir'] ?? null;
    if (!is_string($promptDir) || trim($promptDir) === '') {
        $promptDir = sv_base_dir() . DIRECTORY_SEPARATOR . 'PROMPTS' . DIRECTORY_SEPARATOR . 'ollama';
    }
    $promptDir = sv_normalize_directory($promptDir);

    $filePath = $promptDir . DIRECTORY_SEPARATOR . $map[$mode]['file'];
    $filePrompt = null;
    if (is_file($filePath)) {
        $content = file_get_contents($filePath);
        if (is_string($content) && trim($content) !== '') {
            $filePrompt = trim($content);
        }
    }

    $legacyKey = $map[$mode]['legacy_key'];
    $legacyPrompt = isset($ollamaCfg[$legacyKey]) && is_string($ollamaCfg[$legacyKey]) && trim($ollamaCfg[$legacyKey]) !== ''
        ? trim($ollamaCfg[$legacyKey])
        : null;

    if ($legacyPrompt !== null) {
        return [
            'prompt_id' => $mode,
            'prompt' => $legacyPrompt,
            'template_source' => 'config_legacy',
        ];
    }

    if ($filePrompt !== null) {
        return [
            'prompt_id' => $mode,
            'prompt' => $filePrompt,
            'template_source' => 'file:' . $filePath,
        ];
    }

    throw new RuntimeException('Prompt-Datei fehlt: ' . $filePath);
}
