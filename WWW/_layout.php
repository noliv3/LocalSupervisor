<?php
declare(strict_types=1);

function sv_ui_header(string $title, string $activeNav): void
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
        </div>
    </header>
    <main class="app-main">
    <?php
}

function sv_ui_footer(): void
{
    ?>
    </main>
    <script src="app.js"></script>
    </body>
    </html>
    <?php
}
