<?php
declare(strict_types=1);

function sv_ollama_normalize_result(string $rawJson): array
{
    $candidate = $rawJson;

    $decoded = json_decode($rawJson, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        if (isset($decoded['response']) && is_string($decoded['response'])) {
            $candidate = $decoded['response'];
        } elseif (isset($decoded['message']) && is_string($decoded['message'])) {
            $candidate = $decoded['message'];
        }
    }

    $cleaned = sv_ollama_strip_noise($candidate);
    $jsonText = sv_ollama_extract_json($cleaned);

    $data = json_decode($jsonText, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        $data = [];
    }

    $title = isset($data['title']) && is_string($data['title']) ? trim($data['title']) : '';
    $description = isset($data['description']) && is_string($data['description']) ? trim($data['description']) : '';
    $qualityRaw = $data['quality_score'] ?? null;

    if (is_numeric($qualityRaw)) {
        $qualityScore = (int)round((float)$qualityRaw);
    } else {
        $qualityScore = 0;
    }

    if ($qualityScore < 0) {
        $qualityScore = 0;
    }
    if ($qualityScore > 100) {
        $qualityScore = 100;
    }

    return [
        'title' => $title,
        'description' => $description,
        'quality_score' => $qualityScore,
    ];
}

function sv_ollama_strip_noise(string $text): string
{
    $text = trim($text);
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    $text = preg_replace('~```(?:json)?~i', '', $text);
    $text = str_replace('```', '', $text);
    $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text);
    $text = trim($text);

    return $text;
}

function sv_ollama_extract_json(string $text): string
{
    $start = strpos($text, '{');
    $end = strrpos($text, '}');

    if ($start !== false && $end !== false && $end > $start) {
        $text = substr($text, $start, $end - $start + 1);
    }

    return trim($text);
}
