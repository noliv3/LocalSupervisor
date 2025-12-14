<?php
declare(strict_types=1);

function sv_empty_normalized_prompt(): array
{
    return [
        'prompt'           => null,
        'negative_prompt'  => null,
        'model'            => null,
        'sampler'          => null,
        'steps'            => null,
        'cfg_scale'        => null,
        'seed'             => null,
        'width'            => null,
        'height'           => null,
        'scheduler'        => null,
        'sampler_settings' => null,
        'loras'            => null,
        'controlnet'       => null,
        'source_metadata'  => null,
    ];
}

function sv_merge_normalized_prompt(array $base, array $additional): array
{
    foreach ($base as $k => $v) {
        if ($v === null && array_key_exists($k, $additional) && $additional[$k] !== null) {
            $base[$k] = $additional[$k];
        }
    }

    return $base;
}

function sv_normalized_prompt_has_data(array $normalized): bool
{
    foreach ($normalized as $v) {
        if ($v !== null) {
            return true;
        }
    }

    return false;
}

function sv_select_raw_block(array $candidates): ?string
{
    $longest = null;
    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || trim($candidate) === '') {
            continue;
        }
        if ($longest === null || strlen($candidate) > strlen($longest)) {
            $longest = $candidate;
        }
    }

    return $longest;
}

function sv_parse_sd_parameters(string $raw, array $context = []): array
{
    $result = [
        'prompt'           => null,
        'negative_prompt'  => null,
        'model'            => null,
        'sampler'          => null,
        'cfg_scale'        => null,
        'steps'            => null,
        'seed'             => null,
        'width'            => null,
        'height'           => null,
        'scheduler'        => null,
        'sampler_settings' => null,
        'loras'            => null,
        'controlnet'       => null,
        'source_metadata'  => null,
    ];

    $text = str_replace("\r\n", "\n", trim($raw));
    if ($text === '') {
        return $result;
    }

    $full = $text;
    $metaText = '';
    $positive = '';
    $negative = null;

    $negPos = stripos($full, 'Negative prompt:');
    if ($negPos !== false) {
        $positive = trim(substr($full, 0, $negPos));

        $rest   = trim(substr($full, $negPos));
        $rest   = preg_replace('/^Negative prompt:\s*/i', '', $rest) ?? $rest;
        $parts  = preg_split('/\n/', $rest, 2);
        $negative = isset($parts[0]) ? trim($parts[0]) : null;
        $metaText = isset($parts[1]) ? trim($parts[1]) : '';
    } else {
        $positive = $full;
        $metaText = '';
    }

    $result['prompt']          = $positive !== '' ? $positive : null;
    $result['negative_prompt'] = $negative !== '' ? $negative : null;

    if ($metaText === '' && preg_match('/\n(Steps:\s.*)$/is', $full, $m)) {
        $metaText = trim($m[1]);
    }

    if ($metaText !== '') {
        if (preg_match('/\bSteps:\s*(\d+)/i', $metaText, $m)) {
            $result['steps'] = (int)$m[1];
        }

        if (preg_match('/\bSampler:\s*([^,\n]+)/i', $metaText, $m)) {
            $result['sampler'] = trim($m[1]);
        }

        if (preg_match('/\bCFG\s*scale:\s*([0-9.]+)/i', $metaText, $m)) {
            $result['cfg_scale'] = (float)$m[1];
        }

        if (preg_match('/\bSeed:\s*(-?\d+)/i', $metaText, $m)) {
            $result['seed'] = (int)$m[1];
        }

        if (preg_match('/\bSize:\s*(\d+)\s*x\s*(\d+)/i', $metaText, $m)) {
            $result['width']  = (int)$m[1];
            $result['height'] = (int)$m[2];
        }

        if (preg_match('/\bModel:\s*([^,\n]+)/i', $metaText, $m)) {
            $result['model'] = trim($m[1]);
        }

        if (preg_match('/\bScheduler:\s*([^,\n]+)/i', $metaText, $m)) {
            $result['scheduler'] = trim($m[1]);
        }

        if (preg_match_all('/\bLo?RA:\s*([^;\n]+)/i', $metaText, $m)) {
            $loras = array_map('trim', $m[1]);
            $result['loras'] = $loras ? implode('; ', $loras) : null;
        }

        if (preg_match_all('/\bControlNet:\s*([^;\n]+)/i', $metaText, $m)) {
            $cns = array_map('trim', $m[1]);
            $result['controlnet'] = $cns ? implode('; ', $cns) : null;
        }

        $knownPatterns = [
            '/\bSteps:\s*\d+/i',
            '/\bSampler:\s*[^,\n]+/i',
            '/\bCFG\s*scale:\s*[0-9.]+/i',
            '/\bSeed:\s*-?\d+/i',
            '/\bSize:\s*\d+\s*x\s*\d+/i',
            '/\bModel:\s*[^,\n]+/i',
            '/\bScheduler:\s*[^,\n]+/i',
            '/\bLo?RA:\s*[^;\n]+/i',
            '/\bControlNet:\s*[^;\n]+/i',
        ];
        $rest = $metaText;
        foreach ($knownPatterns as $pat) {
            $rest = preg_replace($pat, '', $rest) ?? $rest;
        }
        $rest = trim(preg_replace('/\s+,/', '', $rest) ?? $rest);
        $rest = trim(preg_replace('/,+\s*/', ', ', $rest) ?? $rest, " ,\n\t\r");
        $result['sampler_settings'] = $rest !== '' ? $rest : null;
    }

    $metaPayload = [
        'raw_parameters' => $text,
    ];
    if ($context) {
        $metaPayload['context'] = $context;
    }
    $result['source_metadata'] = json_encode(
        $metaPayload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    return $result;
}

function sv_normalize_prompt_block(string $raw, array $context = []): array
{
    $normalized = sv_empty_normalized_prompt();
    $rawText    = str_replace("\r\n", "\n", trim($raw));

    if ($rawText === '') {
        return $normalized;
    }

    $normalized = sv_merge_normalized_prompt($normalized, sv_parse_sd_parameters($rawText, $context));

    $json = json_decode($rawText, true);
    if (is_array($json)) {
        $payload = $json;
        if (isset($json['sd-metadata']) && is_array($json['sd-metadata'])) {
            $payload = $json['sd-metadata'];
        } elseif (isset($json['metadata']) && is_array($json['metadata'])) {
            $payload = $json['metadata'];
        }

        $candidate = [
            'prompt'           => $payload['prompt'] ?? ($payload['positive_prompt'] ?? ($payload['positive'] ?? null)),
            'negative_prompt'  => $payload['negative_prompt'] ?? ($payload['negative'] ?? ($payload['Negative prompt'] ?? null)),
            'model'            => $payload['model'] ?? ($payload['model_hash'] ?? ($payload['modelName'] ?? null)),
            'sampler'          => $payload['sampler'] ?? ($payload['sampler_name'] ?? null),
            'steps'            => isset($payload['steps']) && is_numeric($payload['steps']) ? (int)$payload['steps'] : null,
            'cfg_scale'        => isset($payload['cfg_scale']) && is_numeric($payload['cfg_scale']) ? (float)$payload['cfg_scale'] : null,
            'seed'             => isset($payload['seed']) ? (string)$payload['seed'] : null,
            'width'            => isset($payload['width']) && is_numeric($payload['width']) ? (int)$payload['width'] : null,
            'height'           => isset($payload['height']) && is_numeric($payload['height']) ? (int)$payload['height'] : null,
            'scheduler'        => $payload['scheduler'] ?? ($payload['schedule'] ?? null),
            'sampler_settings' => null,
            'loras'            => null,
            'controlnet'       => null,
            'source_metadata'  => json_encode([
                'raw_parameters' => $rawText,
                'json'           => $json,
                'context'        => $context,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        if (isset($payload['sampler_settings'])) {
            $samplerSettings = $payload['sampler_settings'];
            if (is_array($samplerSettings) || is_object($samplerSettings)) {
                $candidate['sampler_settings'] = json_encode($samplerSettings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_string($samplerSettings)) {
                $candidate['sampler_settings'] = $samplerSettings;
            }
        } elseif (isset($payload['settings']) && (is_array($payload['settings']) || is_object($payload['settings']))) {
            $candidate['sampler_settings'] = json_encode($payload['settings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (isset($payload['loras'])) {
            $loras = $payload['loras'];
            if (is_array($loras) || is_object($loras)) {
                $candidate['loras'] = json_encode($loras, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_string($loras)) {
                $candidate['loras'] = $loras;
            }
        }

        if (isset($payload['controlnet'])) {
            $controlnet = $payload['controlnet'];
            if (is_array($controlnet) || is_object($controlnet)) {
                $candidate['controlnet'] = json_encode($controlnet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_string($controlnet)) {
                $candidate['controlnet'] = $controlnet;
            }
        }

        $normalized = sv_merge_normalized_prompt($normalized, $candidate);
        if ($candidate['source_metadata'] !== null) {
            $normalized['source_metadata'] = $candidate['source_metadata'];
        }
    }

    if (isset($context['positive']) && ($normalized['prompt'] === null || $normalized['prompt'] === '')) {
        $normalized['prompt'] = $context['positive'];
    }
    if (isset($context['negative']) && ($normalized['negative_prompt'] === null || $normalized['negative_prompt'] === '')) {
        $normalized['negative_prompt'] = $context['negative'];
    }

    if ($normalized['source_metadata'] === null) {
        $metaPayload = ['raw_parameters' => $rawText];
        if ($context) {
            $metaPayload['context'] = $context;
        }
        $normalized['source_metadata'] = json_encode($metaPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    if ($normalized['prompt'] === null && $rawText !== '') {
        $normalized['prompt'] = $rawText;
    }

    return $normalized;
}

function sv_collect_prompt_candidates(array $meta): array
{
    $candidates   = [];
    $rawTextPool  = [];
    $positiveText = null;
    $negativeText = null;

    foreach ($meta as $pair) {
        if (!is_array($pair)) {
            continue;
        }
        $value = $pair['value'] ?? null;
        if ($value === null) {
            continue;
        }
        if (!is_string($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (!is_string($value) || trim($value) === '') {
            continue;
        }

        $rawTextPool[] = $value;

        $source       = (string)($pair['source'] ?? '');
        $key          = (string)($pair['key'] ?? '');
        $keyLower     = strtolower($key);
        $shortKey     = strtolower(strrchr($keyLower, '.') ?: $keyLower);
        $normalizedKey = preg_replace('/[\s_-]+/', ' ', $shortKey) ?? $shortKey;

        $parameterKeys = ['usercomment', 'image description', 'imagedescription', 'xpcomment', 'comment', 'parameters', 'parameter', 'generation parameters', 'generation data'];
        if (in_array($normalizedKey, $parameterKeys, true)) {
            $candidates[] = [
                'source'    => $source,
                'key'       => $key,
                'short_key' => $shortKey,
                'text'      => $value,
            ];
        }

        $jsonKeys = ['sd-metadata', 'sd metadata', 'sd_metadata', 'workflow', 'workflow json', 'workflow_json', 'workflow-json', 'workflowjson', 'metadata'];
        if (in_array($normalizedKey, $jsonKeys, true)) {
            $candidates[] = [
                'source'    => $source,
                'key'       => $key,
                'short_key' => $shortKey,
                'text'      => $value,
            ];
        }

        if (in_array($normalizedKey, ['prompt', 'positive prompt', 'positive_prompt', 'positive'], true) || (strpos($normalizedKey, 'prompt') !== false && strpos($normalizedKey, 'negative') === false && strpos($normalizedKey, 'seed') === false)) {
            $positiveText = $value;
        }

        if (in_array($normalizedKey, ['negative prompt', 'negative_prompt', 'negative'], true) || strpos($normalizedKey, 'negative prompt') !== false) {
            $negativeText = $value;
        }
    }

    if ($positiveText !== null || $negativeText !== null) {
        $combined = '';
        if ($positiveText !== null) {
            $combined .= trim($positiveText);
        }
        if ($negativeText !== null) {
            $combined .= "\nNegative prompt: " . trim($negativeText);
        }
        $candidates[] = [
            'source'    => 'combined',
            'key'       => 'positive+negative',
            'short_key' => 'positive+negative',
            'text'      => trim($combined),
            'type'      => 'combined_prompt',
            'positive'  => $positiveText,
            'negative'  => $negativeText,
        ];
    }

    $fallback = sv_select_raw_block($rawTextPool);
    if ($fallback !== null) {
        $candidates[] = [
            'source'    => 'fallback',
            'key'       => 'raw_block',
            'short_key' => 'raw_block',
            'text'      => $fallback,
            'type'      => 'fallback',
        ];
    }

    return $candidates;
}

function sv_select_prompt_candidate(array $candidates): ?array
{
    if (!$candidates) {
        return null;
    }

    $priority = [
        'parameters',
        'sd-metadata',
        'sd_metadata',
        'sd metadata',
        'workflow',
        'workflow_json',
        'workflow-json',
        'workflowjson',
        'positive+negative',
        'prompt',
        'positive prompt',
        'negative prompt',
        'usercomment',
        'imagedescription',
        'xpcomment',
        'comment',
        'raw_block',
    ];

    foreach ($priority as $key) {
        foreach ($candidates as $candidate) {
            $shortKey = strtolower((string)($candidate['short_key'] ?? ($candidate['key'] ?? '')));
            $type     = (string)($candidate['type'] ?? '');
            $text     = isset($candidate['text']) ? trim((string)$candidate['text']) : '';
            if ($text === '') {
                continue;
            }
            if ($key === 'positive+negative' && $type === 'combined_prompt') {
                return $candidate;
            }
            if ($shortKey === $key) {
                return $candidate;
            }
        }
    }

    $best = null;
    foreach ($candidates as $candidate) {
        $text = (string)($candidate['text'] ?? '');
        if (trim($text) === '') {
            continue;
        }
        if ($best === null || strlen($text) > strlen((string)($best['text'] ?? ''))) {
            $best = $candidate;
        }
    }

    return $best;
}
