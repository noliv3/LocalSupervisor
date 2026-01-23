<?php
declare(strict_types=1);

$baseDir = realpath(__DIR__ . '/..') ?: __DIR__ . '/..';

return [
    'db' => [
        'dsn'      => 'sqlite:' . $baseDir . '/DB/local.sqlite',
        'user'     => null,
        'password' => null,
        'options'  => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ],
        'sqlite' => [
            'busy_timeout_ms' => 5000,
            'journal_mode'    => 'WAL',
        ],
    ],
    'paths' => [
        'images_sfw'  => $baseDir . '/LIBRARY/images_sfw',
        'images_nsfw' => $baseDir . '/LIBRARY/images_nsfw',
        'videos_sfw'  => $baseDir . '/LIBRARY/videos_sfw',
        'videos_nsfw' => $baseDir . '/LIBRARY/videos_nsfw',
        'thumb_cache' => $baseDir . '/CACHE/thumbs',
        'previews'    => $baseDir . '/PREVIEWS',
    ],
    'scanner' => [
        'base_url'       => 'http://127.0.0.1:8000',
        'timeout'        => 15,
        'nsfw_threshold' => 0.7,
    ],
    'security' => [
        'internal_api_key'             => 'change-me',
        'ip_whitelist'                 => ['127.0.0.1', '::1'],
        'allow_insecure_internal_cookie' => false,
    ],
    'jobs' => [
        'queue_max_total'            => 2000,
        'queue_max_per_type_default' => 500,
        'queue_max_per_type'         => [
            'scan_path'          => 300,
            'rescan_media'       => 300,
            'scan_backfill_tags' => 2,
            'library_rename'     => 200,
            'forge_regen'        => 150,
            'ollama_caption'     => 200,
            'ollama_title'       => 200,
            'ollama_prompt_eval' => 200,
            'ollama_tags_normalize' => 200,
        ],
        'queue_max_per_media'        => 2,
    ],
    'ollama' => [
        'enabled' => true,
        'base_url' => 'http://127.0.0.1:11434',
        'model_default' => 'llava:latest',
        'model' => [
            'default' => 'llava:latest',
            'vision'  => 'llava:latest',
            'text'    => 'llama3:latest',
        ],
        'caption_prompt_template' => "Beschreibe das Bild in 1-3 Sätzen. Antworte ausschließlich als JSON.\nFormat: {\"caption\":\"...\",\"contradictions\":[],\"missing\":[],\"rationale\":\"...\"}",
        'title_prompt_template' => "Erzeuge einen kurzen, prägnanten Titel (max 80 Zeichen). Antworte ausschließlich als JSON.\nFormat: {\"title\":\"...\",\"rationale\":\"...\"}",
        'prompt_eval_template' => "Bewerte, wie gut der folgende Prompt das Bild beschreibt (0-100). Nenne Widersprüche, fehlende Elemente und eine kurze Begründung. Antworte ausschließlich als JSON.\nFormat: {\"score\":0,\"contradictions\":[],\"missing\":[],\"rationale\":\"...\"}\nPrompt: {{prompt}}",
        'tags_normalize_template' => "Normalisiere die folgenden Roh-Tags in kanonische, einheitliche Tags. Antworte ausschließlich als JSON.\nFormat: {\"tags_normalized\":[],\"tags_map\":[{\"raw\":\"\",\"normalized\":\"\",\"confidence\":0.0,\"type\":\"\"}],\"rationale\":\"...\"}\nTags: {{tags}}\nKontext: {{context}}",
        'timeout_ms' => 20000,
        'max_image_bytes' => 4194304,
        'worker' => [
            'batch_size' => 5,
            'max_retries' => 2,
        ],
        'prompt_eval_fallback' => 'tags',
        'prompt_eval_fallback_separator' => ', ',
        'retry' => [
            'max_attempts' => 3,
            'backoff_ms'   => 500,
        ],
        'deterministic' => [
            'enabled'     => true,
            'temperature' => 0.0,
            'top_p'       => 1.0,
            'seed'        => 42,
        ],
    ],
    'default_nsfw' => 0,
    'php_cli' => $baseDir . '/TOOLS/php/php.exe',
    'tools' => [
        'ffmpeg'  => 'ffmpeg',
        'ffprobe' => 'ffprobe',
    ],
];
