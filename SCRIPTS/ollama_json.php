<?php
declare(strict_types=1);

function sv_ollama_extract_first_json_object(string $text): ?string
{
    $length = strlen($text);
    $depth = 0;
    $start = null;
    $inString = false;
    $escape = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $text[$i];
        if ($inString) {
            if ($escape) {
                $escape = false;
                continue;
            }
            if ($char === '\\') {
                $escape = true;
                continue;
            }
            if ($char === '"') {
                $inString = false;
            }
            continue;
        }

        if ($char === '"') {
            $inString = true;
            continue;
        }

        if ($char === '{') {
            if ($depth === 0) {
                $start = $i;
            }
            $depth++;
            continue;
        }

        if ($char === '}' && $depth > 0) {
            $depth--;
            if ($depth === 0 && $start !== null) {
                return substr($text, $start, $i - $start + 1);
            }
        }
    }

    return null;
}
