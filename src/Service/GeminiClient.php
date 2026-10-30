<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeminiClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(GEMINI_API_KEY)%')]
        private string $apiKey,
        #[Autowire('%env(default:gemini_model:GEMINI_MODEL)%')]
        private string $model = 'gemini-3.6-flash',
    ) {
    }

    public function generate(string $prompt, bool $json = true, int $maxTokens = 1800): string
    {
        if (trim($this->apiKey) === '') {
            throw new \RuntimeException('La clé API Gemini est absente.');
        }

        $model = trim($this->model) !== '' ? trim($this->model) : 'gemini-3.6-flash';

        $generationConfig = [
            'maxOutputTokens' => $maxTokens,
            'thinkingConfig' => [
                'thinkingLevel' => 'low',
            ],
        ];

        if ($json) {
            $generationConfig['responseMimeType'] = 'application/json';
        }

        $response = $this->httpClient->request(
            'POST',
            sprintf(
                'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
                $model
            ),
            [
                'headers' => [
                    'x-goog-api-key' => $this->apiKey,
                ],
                'json' => [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt],
                            ],
                        ],
                    ],
                    'generationConfig' => $generationConfig,
                ],
                'timeout' => 120,
                'max_duration' => 180,
            ]
        );

        $status = $response->getStatusCode();
        $data = $response->toArray(false);

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(
                'La requête Gemini a échoué (HTTP '
                . $status . '). Vérifie la clé API et les quotas.'
            );
        }

        $text = $data['candidates'][0]['content']['parts'][0]['text']
            ?? null;

        if (!is_string($text) || trim($text) === '') {
            throw new \RuntimeException(
                'Gemini n’a retourné aucun résultat exploitable.'
            );
        }

        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    public function generateJson(string $prompt, int $maxTokens = 1800): array
    {
        $raw = $this->generate($prompt, true, $maxTokens);
        $raw = $this->stripCodeFences($raw);

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(
                'La réponse de l’IA n’est pas un JSON valide.'
            );
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException(
                'La réponse de l’IA n’a pas le format attendu.'
            );
        }

        return $decoded;
    }

    private function stripCodeFences(string $text): string
    {
        $text = trim($text);

        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-zA-Z]*\s*/', '', $text) ?? $text;
            $text = preg_replace('/```\s*$/', '', $text) ?? $text;
        }

        return trim($text);
    }
}
