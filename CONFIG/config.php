<?php
// Globale Konfiguration für SuperVisOr

return [

    'db' => [
        // SQLite-DB im DB-Ordner
        'dsn'      => 'sqlite:' . __DIR__ . '/../DB/supervisor.sqlite',
        'user'     => null,
        'password' => null,
        'options'  => [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ],
    ],

    'paths' => [
        'base'          => 'I:/SuperVisOr',

        // Zielordner nach Klassifikation
        'images_sfw'    => 'I:/Bilder',
        'videos_sfw'    => 'I:/Videos',
        'images_nsfw'   => 'I:/FSK18/Bilder',
        'videos_nsfw'   => 'I:/FSK18/Videos',

        'logs'          => 'I:/SuperVisOr/LOGS',
        'tmp'           => 'I:/SuperVisOr/TMP',
    ],

    'tools' => [
        // Optional, wenn du ffmpeg/exiftool lokal im Projekt mitliefert
        'ffmpeg'   => 'I:/SuperVisOr/TOOLS/ffmpeg/bin/ffmpeg.exe',
        'exiftool' => 'I:/SuperVisOr/TOOLS/exiftool/exiftool.exe',
    ],

    // PixAI Sensible Scanner API (scanner_api.py / Port 8000)
    // /token?email=... liefert Token, das im Header "Authorization" gesendet wird 
    'scanner' => [
        'enabled'        => true,
        'base_url'       => 'http://127.0.0.1:8000',
        // HIER deinen echten Token eintragen (Antwort von /token)
        'token'          => 'f0f4dfb4f986acd4f533fa36879305b1',
        'timeout'        => 100,
        // ab welchem Risk-Level FSK18 (0.0–1.0)
        'nsfw_threshold' => 0.7,
    ],

    'security' => [
        'api_key_internal' => 'CHANGE_ME_INTERNAL_API_KEY',
        'allowed_ips'      => ['127.0.0.1'],
    ],
];
