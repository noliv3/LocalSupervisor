<?php
declare(strict_types=1);

if (!defined('SV_WEB_CONTEXT')) {
    define('SV_WEB_CONTEXT', true);
}

require_once __DIR__ . '/../SCRIPTS/security.php';

function sv_ui_header(string $title, string $activeNav, ?string $headerActionsHtml = null): void
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $isDashboard = $activeNav === 'dashboard';
    $isMedia = $activeNav === 'medien';
    $isOllama = $activeNav === 'ollama';
    ?>
    <!doctype html>
    <html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= $safeTitle ?></title>
        <link rel="stylesheet" href="app.css">
    </head>
    <body class="app-body">
    <header class="app-header">
        <div class="app-header__inner">
            <div class="app-header__title">
                <span class="app-title">SuperVisOr</span>
                <span class="app-page-title"><?= $safeTitle ?></span>
            </div>
            <nav class="app-nav" aria-label="Hauptnavigation">
                <a class="app-nav__link<?= $isDashboard ? ' is-active' : '' ?>" href="index.php">Dashboard</a>
                <a class="app-nav__link<?= $isOllama ? ' is-active' : '' ?>" href="dashboard_ollama.php">Ollama</a>
                <a class="app-nav__link<?= $isMedia ? ' is-active' : '' ?>" href="mediadb.php">Medien</a>
            </nav>
            <?php if ($headerActionsHtml): ?>
                <div class="app-header__actions">
                    <?= $headerActionsHtml ?>
                </div>
            <?php endif; ?>
        </div>
    </header>
    <main class="app-main">
    <?php
}

function sv_ui_footer(): void
{
    $csrfToken = sv_csrf_token();
    ?>
    </main>
    <script>
        window.SuperVisor = window.SuperVisor || {};
        window.SuperVisor.csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script src="app.js"></script>
    </body>
    </html>
    <?php
}
