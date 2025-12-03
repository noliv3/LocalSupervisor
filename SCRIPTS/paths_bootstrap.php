<?php
declare(strict_types=1);

function sv_bootstrap_web_symlinks(array $pathsCfg, ?string $webRootDir = null): void
{
    $webRootDir = $webRootDir === null ? realpath(__DIR__ . '/../WWW') : realpath($webRootDir);
    if ($webRootDir === false) {
        return;
    }

    $map = [
        'images'    => 'bilder',
        'images_18' => 'fsk18',
        'videos'    => 'videos',
        'videos_18' => 'videos18',
    ];

    foreach ($map as $cfgKey => $webDirName) {
        $targetFs = $pathsCfg[$cfgKey] ?? '';
        if ($targetFs === '' || $targetFs === null) {
            continue;
        }

        $targetFs = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, (string)$targetFs);
        $targetFs = rtrim($targetFs, DIRECTORY_SEPARATOR);

        if ($targetFs === '' || !is_dir($targetFs)) {
            continue;
        }

        $link = $webRootDir . DIRECTORY_SEPARATOR . $webDirName;
        if (is_dir($link) || is_link($link)) {
            continue;
        }

        @symlink($targetFs, $link);
    }
}
