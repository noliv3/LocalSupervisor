<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

const OLLAMA_CAPTION_V1 = 'OLLAMA_CAPTION_V1';
const OLLAMA_TITLE_V1 = 'OLLAMA_TITLE_V1';

function sv_ollama_prompt_definitions(): array
{
    return [
        OLLAMA_CAPTION_V1 => [
            'prompt' => "Du bist ein Bildbeschreiber. Antworte ausschließlich als JSON. Keine Zusatztexte.\nOutput-Format:\n{\"caption\":\"...\",\"confidence\":0.0}",
            'output_key' => 'caption',
        ],
        OLLAMA_TITLE_V1 => [
            'prompt' => "Erzeuge einen kurzen, präzisen Titel (max 80 Zeichen). Antworte ausschließlich als JSON.\nOutput-Format:\n{\"title\":\"...\",\"confidence\":0.0}",
            'output_key' => 'title',
        ],
    ];
}

function sv_ollama_build_prompt(string $jobType): array
{
    $jobType = trim($jobType);
    $definitions = sv_ollama_prompt_definitions();

    if ($jobType === 'ollama_caption') {
        return [
            'prompt_id' => OLLAMA_CAPTION_V1,
            'prompt' => $definitions[OLLAMA_CAPTION_V1]['prompt'],
            'output_key' => $definitions[OLLAMA_CAPTION_V1]['output_key'],
        ];
    }

    if ($jobType === 'ollama_title') {
        return [
            'prompt_id' => OLLAMA_TITLE_V1,
            'prompt' => $definitions[OLLAMA_TITLE_V1]['prompt'],
            'output_key' => $definitions[OLLAMA_TITLE_V1]['output_key'],
        ];
    }

    throw new InvalidArgumentException('Unbekannter Ollama-Jobtyp: ' . $jobType);
}
