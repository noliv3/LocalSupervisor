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

    return [
        'prompt_id' => $mode,
        'prompt' => $prompt,
        'template' => $templatePrompt,
        'output_key' => $definitions[$mode]['output_key'],
    ];
}
