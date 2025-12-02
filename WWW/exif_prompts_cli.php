<?php
declare(strict_types=1);

/**
 * SuperVisOr - EXIF / Prompt / Meta-Extractor
 *
 * Liest für alle media-Einträge (type=image, is_missing=0) die
 * Stable-Diffusion-Metadaten per exiftool aus und schreibt:
 *
 *  - prompts.prompt
 *  - prompts.negative_prompt
 *  - prompts.model
 *  - prompts.sampler
 *  - prompts.cfg_scale
 *  - prompts.steps
 *  - prompts.seed
 *  - prompts.width
 *  - prompts.height
 *  - prompts.scheduler
 *  - prompts.sampler_settings
 *  - prompts.loras
 *  - prompts.controlnet
 *  - prompts.source_metadata (kompletter JSON-Dump exiftool + Raw-String)
 *
 * Existiert bereits ein prompts-Eintrag für media_id, wird er nur ergänzt,
 * d.h. vorhandene Felder bleiben erhalten, leere Felder werden gefüllt.
 */

$root = dirname(__DIR__);
$config = require $root . DIRECTORY_SEPARATOR . 'CONFIG' . DIRECTORY_SEPARATOR . 'config.php';

$dsn      = $config['db']['dsn'] ?? null;
$user     = $config['db']['user'] ?? null;
$password = $config['db']['password'] ?? null;
$options  = $config['db']['options'] ?? [];

$exiftoolPath = $config['tools']['exiftool'] ?? 'exiftool';

if (!is_string($dsn) || $dsn === '') {
    fwrite(STDERR, "DB-Fehler: DSN fehlt in config.\n");
    exit(1);
}

echo "SuperVisOr EXIF/Prompt-Scan\n";
echo "===========================\n\n";

try {
    $pdo = new PDO($dsn, $user, $password, $options);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    fwrite(STDERR, "DB-Fehler: " . $e->getMessage() . "\n");
    exit(1);
}

// Alle relevanten Medien holen
$sqlMedia = "
    SELECT id, path
    FROM media
    WHERE type = 'image'
      AND status = 'active'
";
$mediaRows = $pdo->query($sqlMedia)->fetchAll(PDO::FETCH_ASSOC);

$total = count($mediaRows);
echo "Zu prüfende Medien: {$total}\n\n";

$processed = 0;
$updated   = 0;
$inserted  = 0;
$skipped   = 0;
$errors    = 0;

// Hilfsfunktionen

/**
 * exiftool aufrufen und JSON zurückgeben
 */
function sv_exiftool_read(string $file, string $exiftoolPath): ?array
{
    if (!is_file($file)) {
        return null;
    }

    $cmd = escapeshellarg($exiftoolPath)
        . ' -j -s -s -s'
        . ' -UserComment -Comment -ImageDescription -Parameters'
        . ' -ImageWidth -ImageHeight'
        . ' ' . escapeshellarg($file);

    $output = shell_exec($cmd);
    if ($output === null || trim($output) === '') {
        return null;
    }

    $data = json_decode($output, true);
    if (!is_array($data) || empty($data) || !is_array($data[0])) {
        return null;
    }

    return $data[0];
}

/**
 * Wählt den wahrscheinlichsten SD-Parameter-String aus den EXIF-Feldern
 */
function sv_pick_parameters_string(array $meta): ?string
{
    $candidates = [
        'Parameters',
        'UserComment',
        'Comment',
        'ImageDescription',
    ];

    foreach ($candidates as $key) {
        if (!empty($meta[$key]) && is_string($meta[$key])) {
            $val = trim((string)$meta[$key]);
            if ($val !== '') {
                return $val;
            }
        }
    }

    return null;
}

/**
 * Parsed den SD-Parameterblock (A1111/Comfy PNG-Info).
 * Liefert ein Array mit allen Spalten der Tabelle prompts.
 */
function sv_parse_sd_parameters(string $raw): array
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

    // Negative prompt trennen
    $negPos = stripos($full, "Negative prompt:");
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

    // Metazeile sammeln (falls mehrere Zeilen Parameter)
    if ($metaText === '' && preg_match('/\n(Steps:\s.*)$/is', $full, $m)) {
        $metaText = trim($m[1]);
    }

    // Standard-Felder parsen
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

        // Lora-Blöcke (alles, was mit "Lora:" / "LoRA:" beginnt)
        if (preg_match_all('/\bLo?RA:\s*([^;\n]+)/i', $metaText, $m)) {
            $loras = array_map('trim', $m[1]);
            $result['loras'] = $loras ? implode('; ', $loras) : null;
        }

        // ControlNet
        if (preg_match_all('/\bControlNet:\s*([^;\n]+)/i', $metaText, $m)) {
            $cns = array_map('trim', $m[1]);
            $result['controlnet'] = $cns ? implode('; ', $cns) : null;
        }

        // Rest als sampler_settings (Fallback)
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
        $rest = trim(preg_replace('/\s+,/','', $rest) ?? $rest);
        $rest = trim(preg_replace('/,+\s*/', ', ', $rest) ?? $rest, " ,\n\t\r");
        $result['sampler_settings'] = $rest !== '' ? $rest : null;
    }

    $result['source_metadata'] = json_encode(
        [
            'raw_parameters' => $text,
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    return $result;
}

/**
 * Führt Insert oder Update in prompts aus.
 */
function sv_upsert_prompt(
    PDO $pdo,
    int $mediaId,
    array $parsed,
    ?array $existingRow
): bool {
    // Insert
    if ($existingRow === null) {
        $stmt = $pdo->prepare("
            INSERT INTO prompts (
                media_id,
                prompt,
                negative_prompt,
                model,
                sampler,
                cfg_scale,
                steps,
                seed,
                width,
                height,
                scheduler,
                sampler_settings,
                loras,
                controlnet,
                source_metadata
            ) VALUES (
                :media_id,
                :prompt,
                :negative_prompt,
                :model,
                :sampler,
                :cfg_scale,
                :steps,
                :seed,
                :width,
                :height,
                :scheduler,
                :sampler_settings,
                :loras,
                :controlnet,
                :source_metadata
            )
        ");

        return $stmt->execute([
            ':media_id'         => $mediaId,
            ':prompt'           => $parsed['prompt'],
            ':negative_prompt'  => $parsed['negative_prompt'],
            ':model'            => $parsed['model'],
            ':sampler'          => $parsed['sampler'],
            ':cfg_scale'        => $parsed['cfg_scale'],
            ':steps'            => $parsed['steps'],
            ':seed'             => $parsed['seed'],
            ':width'            => $parsed['width'],
            ':height'           => $parsed['height'],
            ':scheduler'        => $parsed['scheduler'],
            ':sampler_settings' => $parsed['sampler_settings'],
            ':loras'            => $parsed['loras'],
            ':controlnet'       => $parsed['controlnet'],
            ':source_metadata'  => $parsed['source_metadata'],
        ]);
    }

    // Update nur, wenn Feld bisher NULL ist
    $stmt = $pdo->prepare("
        UPDATE prompts
        SET
            prompt           = COALESCE(prompt, :prompt),
            negative_prompt  = COALESCE(negative_prompt, :negative_prompt),
            model            = COALESCE(model, :model),
            sampler          = COALESCE(sampler, :sampler),
            cfg_scale        = COALESCE(cfg_scale, :cfg_scale),
            steps            = COALESCE(steps, :steps),
            seed             = COALESCE(seed, :seed),
            width            = COALESCE(width, :width),
            height           = COALESCE(height, :height),
            scheduler        = COALESCE(scheduler, :scheduler),
            sampler_settings = COALESCE(sampler_settings, :sampler_settings),
            loras            = COALESCE(loras, :loras),
            controlnet       = COALESCE(controlnet, :controlnet),
            source_metadata  = COALESCE(source_metadata, :source_metadata)
        WHERE id = :id
    ");

    return $stmt->execute([
        ':id'               => $existingRow['id'],
        ':prompt'           => $parsed['prompt'],
        ':negative_prompt'  => $parsed['negative_prompt'],
        ':model'            => $parsed['model'],
        ':sampler'          => $parsed['sampler'],
        ':cfg_scale'        => $parsed['cfg_scale'],
        ':steps'            => $parsed['steps'],
        ':seed'             => $parsed['seed'],
        ':width'            => $parsed['width'],
        ':height'           => $parsed['height'],
        ':scheduler'        => $parsed['scheduler'],
        ':sampler_settings' => $parsed['sampler_settings'],
        ':loras'            => $parsed['loras'],
        ':controlnet'       => $parsed['controlnet'],
        ':source_metadata'  => $parsed['source_metadata'],
    ]);
}

// Hauptloop
$stmtPromptByMedia = $pdo->prepare("SELECT * FROM prompts WHERE media_id = :mid LIMIT 1");

foreach ($mediaRows as $row) {
    $mediaId = (int)$row['id'];
    $path    = (string)$row['path'];

    echo "Media ID {$mediaId}: {$path}\n";

    if (!is_file($path)) {
        echo "  -> Datei nicht gefunden, übersprungen.\n";
        $skipped++;
        continue;
    }

    try {
        $meta = sv_exiftool_read($path, $exiftoolPath);
        if ($meta === null) {
            echo "  -> Keine Metadaten von exiftool, übersprungen.\n";
            $skipped++;
            continue;
        }

        $paramStr = sv_pick_parameters_string($meta);
        if ($paramStr === null) {
            echo "  -> Kein Parameters/UserComment/Comment/ImageDescription, übersprungen.\n";
            $skipped++;
            continue;
        }

        $parsed = sv_parse_sd_parameters($paramStr);

        // source_metadata um exiftool-Rohdaten erweitern
        $baseSource = [
            'raw_parameters' => $paramStr,
            'exiftool'       => $meta,
        ];
        $parsed['source_metadata'] = json_encode(
            $baseSource,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        // Existiert schon prompts-Eintrag?
        $stmtPromptByMedia->execute([':mid' => $mediaId]);
        $existing = $stmtPromptByMedia->fetch(PDO::FETCH_ASSOC) ?: null;

        $ok = sv_upsert_prompt($pdo, $mediaId, $parsed, $existing);

        if ($ok) {
            $processed++;
            if ($existing === null) {
                $inserted++;
                echo "  -> Insert in prompts OK.\n";
            } else {
                $updated++;
                echo "  -> Update in prompts OK.\n";
            }
        } else {
            $errors++;
            echo "  -> Insert/Update fehlgeschlagen.\n";
        }
    } catch (Throwable $e) {
        $errors++;
        echo "  -> Fehler: " . $e->getMessage() . "\n";
    }
}

echo "\nFertig.\n";
echo "Gesamt:   {$total}\n";
echo "Processed:{$processed}\n";
echo "Inserted: {$inserted}\n";
echo "Updated:  {$updated}\n";
echo "Skipped:  {$skipped}\n";
echo "Errors:   {$errors}\n";
