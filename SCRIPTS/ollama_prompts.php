<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/ollama_client.php';

function sv_ollama_prompt_definitions(array $config): array
{
    $ollamaCfg = sv_ollama_config($config);

    return [
        'caption' => [
            'prompt' => $ollamaCfg['caption_prompt_template'],
            'output_key' => 'caption',
        ],
        'title' => [
            'prompt' => $ollamaCfg['title_prompt_template'],
            'output_key' => 'title',
        ],
        'prompt_eval' => [
            'prompt' => $ollamaCfg['prompt_eval_template'],
            'output_key' => 'score',
        ],
        'tags_normalize' => [
            'prompt' => $ollamaCfg['tags_normalize_template'],
            'output_key' => 'tags_normalized',
        ],
        'quality' => [
            'prompt' => $ollamaCfg['quality_template'],
            'output_key' => 'quality_score',
        ],
    ];
}

function sv_ollama_build_prompt(string $mode, array $config, array $payload = []): array
{
    $mode = trim($mode);
    $definitions = sv_ollama_prompt_definitions($config);

    if (!isset($definitions[$mode])) {
        throw new InvalidArgumentException('Unbekannter Ollama-Modus: ' . $mode);
    }

    $prompt = $definitions[$mode]['prompt'];
    $templatePrompt = $prompt;

    if ($mode === 'prompt_eval') {
        $sourcePrompt = isset($payload['prompt']) && is_string($payload['prompt'])
            ? trim($payload['prompt'])
            : '';
        if ($sourcePrompt === '') {
            throw new InvalidArgumentException('Prompt-Evaluierung benötigt ein Prompt.');
        }
        $prompt = str_replace('{{prompt}}', $sourcePrompt, $prompt);
        $prompt = str_replace('{prompt}', $sourcePrompt, $prompt);
    }
    if ($mode === 'tags_normalize') {
        $tags = $payload['tags'] ?? null;
        if (is_array($tags)) {
            $tags = array_values(array_filter(array_map('strval', $tags), static fn ($v) => trim($v) !== ''));
            $tags = implode(', ', $tags);
        } elseif (is_string($tags)) {
            $tags = trim($tags);
        } else {
            $tags = '';
        }

        if ($tags === '') {
            throw new InvalidArgumentException('Tags-Normalisierung benötigt Roh-Tags.');
        }

        $context = isset($payload['context']) && is_string($payload['context']) ? trim($payload['context']) : '';
        $prompt = str_replace('{{tags}}', $tags, $prompt);
        $prompt = str_replace('{tags}', $tags, $prompt);
        $prompt = str_replace('{{context}}', $context, $prompt);
        $prompt = str_replace('{context}', $context, $prompt);
    }

    return [
        'prompt_id' => $mode,
        'prompt' => $prompt,
        'template' => $templatePrompt,
        'output_key' => $definitions[$mode]['output_key'],
    ];
}
