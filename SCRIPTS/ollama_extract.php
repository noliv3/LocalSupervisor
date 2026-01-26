<?php
declare(strict_types=1);

require_once __DIR__ . '/ollama_json.php';

function sv_ollama_strip_noise_text(string $text): string
{
    $text = trim($text);
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    $text = preg_replace('~```(?:json)?~i', '', $text);
    $text = str_replace('```', '', $text);
    $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text);
    $text = trim($text);

    return $text;
}

function sv_ollama_try_extract_json_object(string $text): ?array
{
    $cleaned = sv_ollama_strip_noise_text($text);
    if ($cleaned === '') {
        return null;
    }

    $jsonChunk = sv_ollama_extract_first_json_object($cleaned);
    if (!is_string($jsonChunk) || trim($jsonChunk) === '') {
        return null;
    }

    $decoded = json_decode($jsonChunk, true);
    return is_array($decoded) ? $decoded : null;
}

function sv_ollama_extract_title_fallback(string $text): ?string
{
    $cleaned = sv_ollama_strip_noise_text($text);
    if ($cleaned === '') {
        return null;
    }

    $lines = preg_split('/\R/', $cleaned) ?: [];
    foreach ($lines as $line) {
        $candidate = trim((string)$line);
        if ($candidate === '') {
            continue;
        }
        $candidate = preg_replace('/^(titel|title)\s*:\s*/iu', '', $candidate);
        $candidate = trim($candidate, " \t\n\r\0\x0B\"'`");
        if ($candidate === '') {
            continue;
        }
        if (mb_strlen($candidate, 'UTF-8') > 80) {
            $candidate = mb_substr($candidate, 0, 80, 'UTF-8');
        }
        return $candidate;
    }

    return null;
}

function sv_ollama_extract_caption_fallback(string $text): ?string
{
    $cleaned = sv_ollama_strip_noise_text($text);
    if ($cleaned === '') {
        return null;
    }

    $cleaned = preg_replace('/^(caption|beschreibung|description)\s*:\s*/iu', '', $cleaned);
    $sentences = preg_split('/(?<=[.!?])\s+/u', $cleaned) ?: [];
    $sentences = array_values(array_filter($sentences, static fn ($s) => trim((string)$s) !== ''));
    if ($sentences === []) {
        return null;
    }

    $maxSentences = 3;
    $selected = array_slice($sentences, 0, $maxSentences);
    $caption = trim(implode(' ', $selected));

    $maxLen = 520;
    if (mb_strlen($caption, 'UTF-8') > $maxLen) {
        $caption = mb_substr($caption, 0, $maxLen, 'UTF-8');
    }

    return $caption !== '' ? $caption : null;
}

function sv_ollama_extract_score_fallback(string $text): ?int
{
    $cleaned = sv_ollama_strip_noise_text($text);
    if ($cleaned === '') {
        return null;
    }

    if (preg_match('/\b([0-9]{1,3})\b/', $cleaned, $matches)) {
        $value = (int)$matches[1];
        if ($value >= 0 && $value <= 100) {
            return $value;
        }
    }

    return null;
}
