<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/ollama_prompt_loader.php';

function sv_ollama_prompt_definitions(array $config): array
{
    $captionPrompt = sv_ollama_prompt_template('caption', $config);
    $titlePrompt = sv_ollama_prompt_template('title', $config);
    $promptEvalPrompt = sv_ollama_prompt_template('prompt_eval', $config);
    $tagsNormalizePrompt = sv_ollama_prompt_template('tags_normalize', $config);
    $qualityPrompt = sv_ollama_prompt_template('quality', $config);
    $promptReconPrompt = sv_ollama_prompt_template('prompt_recon', $config);
    $nsfwPrompt = sv_ollama_prompt_template('nsfw_classify', $config);

    return [
        'caption' => [
            'prompt' => $captionPrompt['prompt'],
            'template_source' => $captionPrompt['template_source'],
            'output_key' => 'caption',
        ],
        'title' => [
            'prompt' => $titlePrompt['prompt'],
            'template_source' => $titlePrompt['template_source'],
            'output_key' => 'title',
        ],
        'prompt_eval' => [
            'prompt' => $promptEvalPrompt['prompt'],
            'template_source' => $promptEvalPrompt['template_source'],
            'output_key' => 'score',
        ],
        'tags_normalize' => [
            'prompt' => $tagsNormalizePrompt['prompt'],
            'template_source' => $tagsNormalizePrompt['template_source'],
            'output_key' => 'tags_normalized',
        ],
        'quality' => [
            'prompt' => $qualityPrompt['prompt'],
            'template_source' => $qualityPrompt['template_source'],
            'output_key' => 'quality_score',
        ],
        'prompt_recon' => [
            'prompt' => $promptReconPrompt['prompt'],
            'template_source' => $promptReconPrompt['template_source'],
            'output_key' => 'prompt',
        ],
        'nsfw_classify' => [
            'prompt' => $nsfwPrompt['prompt'],
            'template_source' => $nsfwPrompt['template_source'],
            'output_key' => 'nsfw_score',
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
    if ($mode === 'prompt_recon') {
        $caption = isset($payload['caption']) ? trim((string)$payload['caption']) : '';
        $title = isset($payload['title']) ? trim((string)$payload['title']) : '';
        $tags = $payload['tags_normalized'] ?? null;
        if (is_array($tags)) {
            $tags = array_values(array_filter(array_map('strval', $tags), static fn ($v) => trim($v) !== ''));
            $tags = implode(', ', $tags);
        } elseif (is_string($tags)) {
            $tags = trim($tags);
        } else {
            $tags = '';
        }
        $domainType = isset($payload['domain_type']) ? trim((string)$payload['domain_type']) : '';
        $qualityFlags = $payload['quality_flags'] ?? null;
        if (is_array($qualityFlags)) {
            $qualityFlags = array_values(array_filter(array_map('strval', $qualityFlags), static fn ($v) => trim($v) !== ''));
            $qualityFlags = implode(', ', $qualityFlags);
        } elseif (is_string($qualityFlags)) {
            $qualityFlags = trim($qualityFlags);
        } else {
            $qualityFlags = '';
        }
        $originalPrompt = isset($payload['original_prompt']) ? trim((string)$payload['original_prompt']) : '';

        $prompt = str_replace('{{caption}}', $caption, $prompt);
        $prompt = str_replace('{caption}', $caption, $prompt);
        $prompt = str_replace('{{title}}', $title, $prompt);
        $prompt = str_replace('{title}', $title, $prompt);
        $prompt = str_replace('{{tags_normalized}}', $tags, $prompt);
        $prompt = str_replace('{tags_normalized}', $tags, $prompt);
        $prompt = str_replace('{{domain_type}}', $domainType, $prompt);
        $prompt = str_replace('{domain_type}', $domainType, $prompt);
        $prompt = str_replace('{{quality_flags}}', $qualityFlags, $prompt);
        $prompt = str_replace('{quality_flags}', $qualityFlags, $prompt);
        $prompt = str_replace('{{original_prompt}}', $originalPrompt, $prompt);
        $prompt = str_replace('{original_prompt}', $originalPrompt, $prompt);
    }

    return [
        'prompt_id' => $mode,
        'prompt' => $prompt,
        'template' => $templatePrompt,
        'template_source' => $definitions[$mode]['template_source'] ?? null,
        'output_key' => $definitions[$mode]['output_key'],
    ];
}
