<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

function sv_forge_recipe_defaults(): array
{
    return [
        'version' => 1,
        'defaults' => [
            'steps_min'    => 10,
            'steps_max'    => 60,
            'cfg_min'      => 3,
            'cfg_max'      => 12,
            'size_default' => [1024, 1024],
            'size_grid'    => 64,
        ],
        'model_families' => [
            'sdxl' => [
                'default_preset' => 'sdxl_default',
                'presets' => [
                    'sdxl_default' => [
                        'sampler'          => 'DPM++ 2M Karras',
                        'steps'            => 28,
                        'cfg'              => 6.5,
                        'size'             => [1024, 1024],
                        'negative_default' => 'universal_sdxl',
                    ],
                    'sdxl_fast' => [
                        'sampler'          => 'DPM++ 2M Karras',
                        'steps'            => 22,
                        'cfg'              => 6.0,
                        'size'             => [1024, 1024],
                        'negative_default' => 'universal_sdxl',
                    ],
                ],
                'sampler_compat_map' => [
                    'Euler' => 'Euler a',
                    'EulerA' => 'Euler a',
                    'euler' => 'Euler a',
                ],
            ],
            'flux' => [
                'default_preset' => 'flux_default',
                'presets' => [
                    'flux_default' => [
                        'sampler'          => 'DPM++ 2M Karras',
                        'steps'            => 24,
                        'cfg'              => 5.0,
                        'size'             => [1024, 1024],
                        'negative_default' => '',
                    ],
                ],
                'sampler_compat_map' => [],
            ],
        ],
        'negatives' => [
            'universal_sdxl' => 'worst quality, low quality, lowres, jpeg artifacts, blurry, bad anatomy, extra limbs, extra fingers, deformed, watermark, text',
        ],
        'recipes' => [
            'repair' => [
                'fast' => ['mode_hint' => 'i2i_if_image_else_t2i', 'i2i_denoise' => 0.25, 'variants' => 1],
                'normal' => ['mode_hint' => 'i2i_if_image_else_t2i', 'i2i_denoise' => 0.40, 'variants' => 1],
                'strong' => ['mode_hint' => 'i2i_if_image_else_t2i', 'i2i_denoise' => 0.60, 'variants' => 2],
            ],
            'rebuild' => [
                'fast' => ['mode_hint' => 't2i', 'variants' => 1],
                'normal' => ['mode_hint' => 't2i', 'variants' => 2],
                'strong' => ['mode_hint' => 't2i', 'variants' => 4],
            ],
            'vary' => [
                'fast' => ['mode_hint' => 't2i_or_i2i', 'seed_policy' => 'new', 'variants' => 1],
                'normal' => ['mode_hint' => 't2i_or_i2i', 'seed_policy' => 'new', 'variants' => 2],
                'strong' => ['mode_hint' => 't2i_or_i2i', 'seed_policy' => 'new', 'variants' => 4],
            ],
        ],
        'tech_fixes' => [
            'none' => [],
            'sampler_compat' => ['apply_sampler_map' => true],
            'black_reset' => ['force_steps' => 28, 'force_cfg' => 6.5, 'force_sampler' => 'DPM++ 2M Karras'],
            'universal_negative' => ['force_negative_key' => 'universal_sdxl'],
            'normalize_size' => ['force_size_default' => true],
        ],
    ];
}

function sv_validate_forge_recipes(array $recipes): bool
{
    if (($recipes['version'] ?? null) !== 1) {
        return false;
    }
    $defaults = $recipes['defaults'] ?? null;
    if (!is_array($defaults)) {
        return false;
    }
    foreach (['steps_min', 'steps_max', 'cfg_min', 'cfg_max', 'size_grid'] as $key) {
        if (!isset($defaults[$key]) || !is_numeric($defaults[$key])) {
            return false;
        }
    }
    $sizeDefault = $defaults['size_default'] ?? null;
    if (!is_array($sizeDefault) || count($sizeDefault) !== 2) {
        return false;
    }
    foreach ($sizeDefault as $val) {
        if (!is_numeric($val)) {
            return false;
        }
    }
    $families = $recipes['model_families'] ?? null;
    if (!is_array($families) || !isset($families['sdxl']) || !isset($families['flux'])) {
        return false;
    }
    foreach ($families as $family) {
        if (!is_array($family)) {
            return false;
        }
        if (empty($family['default_preset']) || !is_string($family['default_preset'])) {
            return false;
        }
        $presets = $family['presets'] ?? null;
        if (!is_array($presets) || $presets === []) {
            return false;
        }
        foreach ($presets as $preset) {
            if (!is_array($preset)) {
                return false;
            }
            foreach (['sampler', 'steps', 'cfg', 'size', 'negative_default'] as $key) {
                if (!array_key_exists($key, $preset)) {
                    return false;
                }
            }
        }
        if (!isset($family['sampler_compat_map']) || !is_array($family['sampler_compat_map'])) {
            return false;
        }
    }
    if (!isset($recipes['recipes']) || !is_array($recipes['recipes'])) {
        return false;
    }
    if (!isset($recipes['tech_fixes']) || !is_array($recipes['tech_fixes'])) {
        return false;
    }

    return true;
}

function sv_load_forge_recipes(?array $config = null, ?callable $logger = null): array
{
    $defaults = sv_forge_recipe_defaults();
    $path = sv_base_dir() . '/CONFIG/forge_recipes.json';
    if (isset($config['forge']['recipes_path']) && is_string($config['forge']['recipes_path'])) {
        $candidate = trim((string)$config['forge']['recipes_path']);
        if ($candidate !== '') {
            $path = $candidate;
        }
    }

    if (!is_file($path)) {
        if ($logger) {
            $logger('Forge-Rezepte nicht gefunden, nutze Defaults.');
        }
        return $defaults;
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        if ($logger) {
            $logger('Forge-Rezepte konnten nicht gelesen werden, nutze Defaults.');
        }
        return $defaults;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !sv_validate_forge_recipes($decoded)) {
        if ($logger) {
            $logger('Forge-Rezepte ungültig, nutze Defaults.');
        }
        return $defaults;
    }

    return $decoded;
}

function sv_forge_detect_model_family(?string $modelName): string
{
    $name = strtolower(trim((string)$modelName));
    if ($name === '') {
        return 'sdxl';
    }

    $fluxMarkers = ['flux', 'flux1', 'flux.'];
    foreach ($fluxMarkers as $marker) {
        if (str_contains($name, $marker)) {
            return 'flux';
        }
    }

    return 'sdxl';
}

function sv_forge_build_tag_prompt(array $tags, int $maxTags = 8, int $maxLen = 200): string
{
    $names = [];
    foreach ($tags as $tag) {
        if (!is_array($tag) || !isset($tag['name']) || !is_string($tag['name'])) {
            continue;
        }
        $label = trim($tag['name']);
        if ($label !== '') {
            $names[] = $label;
        }
    }
    $names = array_values(array_unique($names));
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    if ($names === []) {
        return '';
    }

    $limited = array_slice($names, 0, max(1, $maxTags));
    $prompt = implode(', ', $limited);
    $promptLen = function_exists('mb_strlen') ? mb_strlen($prompt) : strlen($prompt);
    if ($promptLen > $maxLen) {
        $prompt = function_exists('mb_substr') ? mb_substr($prompt, 0, $maxLen) : substr($prompt, 0, $maxLen);
        $prompt = rtrim($prompt, ", \t\n\r\0\x0B");
    }

    return $prompt;
}

function sv_forge_parse_prompt_edit(string $value): array
{
    $value = trim($value);
    if ($value === '') {
        return ['type' => 'none'];
    }

    if (preg_match('/^replace:\s*(.+?)\s*->\s*(.+)$/i', $value, $matches)) {
        return [
            'type' => 'replace',
            'from' => $matches[1],
            'to'   => $matches[2],
        ];
    }

    if (str_starts_with($value, '+ ')) {
        return [
            'type' => 'append',
            'text' => trim(substr($value, 2)),
        ];
    }

    return ['type' => 'none'];
}

function sv_forge_round_to_grid(int $value, int $grid): int
{
    if ($grid <= 0) {
        return $value;
    }

    return max($grid, (int)(round($value / $grid) * $grid));
}

function sv_resolve_forge_repair(array $mediaRow, array $opts, array $recipes): array
{
    $source = isset($opts['source']) && is_string($opts['source']) ? trim($opts['source']) : 'auto';
    $goal = isset($opts['goal']) && is_string($opts['goal']) ? trim($opts['goal']) : 'repair';
    $intensity = isset($opts['intensity']) && is_string($opts['intensity']) ? trim($opts['intensity']) : 'normal';
    $techFix = isset($opts['tech_fix']) && is_string($opts['tech_fix']) ? trim($opts['tech_fix']) : 'none';
    $promptEdit = isset($opts['prompt_edit']) && is_string($opts['prompt_edit']) ? trim($opts['prompt_edit']) : '';

    $source = in_array($source, ['auto', 'prompt', 'tags', 'minimal'], true) ? $source : 'auto';
    $goal = in_array($goal, ['repair', 'rebuild', 'vary'], true) ? $goal : 'repair';
    $intensity = in_array($intensity, ['fast', 'normal', 'strong'], true) ? $intensity : 'normal';
    $techFix = in_array($techFix, ['none', 'sampler_compat', 'black_reset', 'universal_negative', 'normalize_size'], true)
        ? $techFix
        : 'none';

    $promptExists = !empty($mediaRow['prompt']) && !empty($mediaRow['model']);
    $tags = isset($mediaRow['tags']) && is_array($mediaRow['tags']) ? $mediaRow['tags'] : [];
    $tagNames = array_filter(array_map(static function ($tag): string {
        return is_array($tag) && isset($tag['name']) ? trim((string)$tag['name']) : '';
    }, $tags));
    $tagsExist = $tagNames !== [];

    $sourceUsed = 'minimal';
    if ($source === 'auto') {
        if ($promptExists) {
            $sourceUsed = 'prompt';
        } elseif ($tagsExist) {
            $sourceUsed = 'tags';
        }
    } elseif ($source === 'prompt') {
        $sourceUsed = $promptExists ? 'prompt' : ($tagsExist ? 'tags' : 'minimal');
    } elseif ($source === 'tags') {
        $sourceUsed = $tagsExist ? 'tags' : 'minimal';
    } else {
        $sourceUsed = 'minimal';
    }

    $family = sv_forge_detect_model_family($mediaRow['model'] ?? null);
    $familyConfig = $recipes['model_families'][$family] ?? $recipes['model_families']['sdxl'];
    $presetId = $familyConfig['default_preset'] ?? array_key_first($familyConfig['presets']);
    $preset = $familyConfig['presets'][$presetId] ?? [];
    $defaults = $recipes['defaults'];
    $deltas = [];

    $sampler = $sourceUsed === 'prompt' ? (string)($mediaRow['sampler'] ?? '') : '';
    $steps = $sourceUsed === 'prompt' ? (int)($mediaRow['steps'] ?? 0) : 0;
    $cfg = $sourceUsed === 'prompt' ? (float)($mediaRow['cfg_scale'] ?? 0) : 0.0;
    $width = $sourceUsed === 'prompt' ? (int)($mediaRow['width'] ?? 0) : 0;
    $height = $sourceUsed === 'prompt' ? (int)($mediaRow['height'] ?? 0) : 0;
    $seed = $sourceUsed === 'prompt' ? (string)($mediaRow['seed'] ?? '') : '';
    $modelName = $sourceUsed === 'prompt' ? (string)($mediaRow['model'] ?? '') : '';
    $scheduler = $sourceUsed === 'prompt' && !empty($mediaRow['scheduler']) ? (string)$mediaRow['scheduler'] : null;

    if ($sampler === '' && isset($preset['sampler'])) {
        $sampler = (string)$preset['sampler'];
        $deltas[] = 'sampler:preset';
    }
    if ($steps <= 1 && isset($preset['steps'])) {
        $steps = (int)$preset['steps'];
        $deltas[] = 'steps:preset';
    }
    if ($cfg <= 0 && isset($preset['cfg'])) {
        $cfg = (float)$preset['cfg'];
        $deltas[] = 'cfg:preset';
    }
    if ($width <= 0 || $height <= 0) {
        if (isset($preset['size'][0], $preset['size'][1])) {
            $width = (int)$preset['size'][0];
            $height = (int)$preset['size'][1];
            $deltas[] = 'size:preset';
        }
    }

    $stepsMin = (int)$defaults['steps_min'];
    $stepsMax = (int)$defaults['steps_max'];
    $cfgMin = (float)$defaults['cfg_min'];
    $cfgMax = (float)$defaults['cfg_max'];

    $steps = max($stepsMin, min($stepsMax, $steps));
    $cfg = max($cfgMin, min($cfgMax, $cfg));

    if ($techFix === 'normalize_size') {
        $grid = (int)$defaults['size_grid'];
        $width = sv_forge_round_to_grid($width, $grid);
        $height = sv_forge_round_to_grid($height, $grid);
        $deltas[] = 'size:grid';
    }

    $positive = '';
    $negative = '';
    $promptMissing = false;
    $tagsLimited = false;

    if ($sourceUsed === 'prompt') {
        $positive = trim((string)($mediaRow['prompt'] ?? ''));
        $negative = trim((string)($mediaRow['negative_prompt'] ?? ''));
        $promptMissing = $positive === '';
    } elseif ($sourceUsed === 'tags') {
        $maxTags = defined('SV_FORGE_MAX_TAGS_PROMPT') ? SV_FORGE_MAX_TAGS_PROMPT : 8;
        $positive = sv_forge_build_tag_prompt($tags, $maxTags, 200);
        $negative = '';
        $tagsLimited = count($tagNames) > $maxTags;
        $promptMissing = $positive === '';
    } else {
        $positive = 'high quality, detailed';
        $negative = '';
        $promptMissing = false;
    }

    $edit = sv_forge_parse_prompt_edit($promptEdit);
    if ($edit['type'] === 'replace') {
        $positive = str_ireplace((string)$edit['from'], (string)$edit['to'], $positive);
        $deltas[] = 'prompt_edit:replace';
    } elseif ($edit['type'] === 'append' && $edit['text'] !== '') {
        $suffix = trim((string)$edit['text']);
        $positive = $positive === '' ? $suffix : ($positive . ', ' . $suffix);
        $deltas[] = 'prompt_edit:append';
    }

    $recipeSet = $recipes['recipes'][$goal][$intensity] ?? null;
    if (!is_array($recipeSet)) {
        $recipeSet = $recipes['recipes']['repair']['normal'];
    }

    $modeHint = (string)($recipeSet['mode_hint'] ?? 't2i');
    $variants = isset($recipeSet['variants']) ? (int)$recipeSet['variants'] : 1;
    $variants = max(1, $variants);
    $seedPolicy = isset($recipeSet['seed_policy']) && is_string($recipeSet['seed_policy'])
        ? $recipeSet['seed_policy']
        : 'keep';
    $i2iDenoise = isset($recipeSet['i2i_denoise']) ? (float)$recipeSet['i2i_denoise'] : null;

    $imageExists = !empty($mediaRow['_image_exists']);
    $mode = 'text2img';
    $decidedReason = 'mode_hint:' . $modeHint;
    if ($modeHint === 't2i') {
        $mode = 'text2img';
    } elseif ($modeHint === 'i2i_if_image_else_t2i') {
        $mode = $imageExists ? 'img2img' : 'text2img';
        $decidedReason = $imageExists ? 'img2img:source' : 't2i:no_image';
    } elseif ($modeHint === 't2i_or_i2i') {
        if ($goal === 'vary') {
            $mode = 'text2img';
            $decidedReason = 't2i:vary_default';
        } else {
            $mode = 'text2img';
        }
    }

    $negativeSource = 'stored';
    if ($negative === '') {
        $negativeSource = 'empty';
        $presetNegKey = $preset['negative_default'] ?? '';
        $hasUniversal = isset($recipes['negatives'][$presetNegKey]) && is_string($recipes['negatives'][$presetNegKey]);
        $forceUniversal = $techFix === 'universal_negative';
        if ($family === 'sdxl' && $hasUniversal && ($intensity !== 'fast' || $forceUniversal)) {
            $negative = (string)$recipes['negatives'][$presetNegKey];
            $negativeSource = 'fallback';
            $deltas[] = 'negative:fallback';
        }
    }

    if ($techFix === 'sampler_compat') {
        $samplerMap = $familyConfig['sampler_compat_map'] ?? [];
        if (is_array($samplerMap) && $sampler !== '') {
            $mapped = $samplerMap[$sampler] ?? ($samplerMap[strtolower($sampler)] ?? null);
            if (is_string($mapped) && $mapped !== '' && $mapped !== $sampler) {
                $sampler = $mapped;
                $deltas[] = 'sampler:compat';
            }
        }
    } elseif ($techFix === 'black_reset') {
        $tech = $recipes['tech_fixes']['black_reset'] ?? [];
        if (isset($tech['force_steps'])) {
            $steps = (int)$tech['force_steps'];
        }
        if (isset($tech['force_cfg'])) {
            $cfg = (float)$tech['force_cfg'];
        }
        if (!empty($tech['force_sampler'])) {
            $sampler = (string)$tech['force_sampler'];
        }
        $deltas[] = 'tech:black_reset';
    } elseif ($techFix === 'universal_negative') {
        $negKey = $recipes['tech_fixes']['universal_negative']['force_negative_key'] ?? '';
        if ($negKey && isset($recipes['negatives'][$negKey])) {
            $negative = (string)$recipes['negatives'][$negKey];
            $negativeSource = 'forced';
            $deltas[] = 'negative:forced';
        }
    }

    if ($negative === '') {
        $negativeLen = 0;
    } else {
        $negativeLen = function_exists('mb_strlen') ? mb_strlen($negative) : strlen($negative);
    }

    return [
        'prompt'        => $positive,
        'negative'      => $negative,
        'negative_len'  => $negativeLen,
        'negative_source' => $negativeSource,
        'source_used'   => $sourceUsed,
        'goal'          => $goal,
        'intensity'     => $intensity,
        'tech_fix'      => $techFix,
        'mode'          => $mode,
        'mode_hint'     => $modeHint,
        'mode_reason'   => $decidedReason,
        'denoise'       => $mode === 'img2img' ? $i2iDenoise : null,
        'variants'      => $variants,
        'seed_policy'   => $seedPolicy,
        'model_family'  => $family,
        'params' => [
            'sampler'   => $sampler,
            'steps'     => $steps,
            'cfg'       => $cfg,
            'width'     => $width,
            'height'    => $height,
            'seed'      => $seed,
            'model'     => $modelName,
            'scheduler' => $scheduler,
        ],
        'prompt_missing' => $promptMissing,
        'tags_used_count' => count($tagNames),
        'tags_limited'   => $tagsLimited,
        'recipe_id'     => $goal . '.' . $intensity,
        'applied_deltas' => implode('; ', array_values(array_unique($deltas))),
    ];
}
