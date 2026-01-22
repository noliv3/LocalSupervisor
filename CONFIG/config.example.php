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
        ],
        'queue_max_per_media'        => 2,
    ],
    'ollama' => [
        'base_url' => 'http://127.0.0.1:11434',
        'model' => [
            'default' => 'llava:latest',
            'vision'  => 'llava:latest',
            'text'    => 'llama3:latest',
        ],
        'timeout_ms' => 20000,
        'max_image_bytes' => 4194304,
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
