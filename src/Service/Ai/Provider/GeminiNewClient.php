<?php

namespace App\Service\Ai\Provider;

use App\Service\Ai\AiClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeminiNewClient implements AiClientInterface
{
    private string $apiKeyResolved;
    private string $modelResolved;

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(GEMINI_API_KEY)%')]
        string $apiKey = '',
        #[Autowire('%env(GEMINI_MODEL)%')]
        string $model = '',
    ) {
        $this->apiKeyResolved = trim($apiKey) !== '' ? trim($apiKey) : trim((string) ($_ENV['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?? ''));
        $this->modelResolved = trim($model) !== '' ? trim($model) : trim((string) ($_ENV['GEMINI_MODEL'] ?? getenv('GEMINI_MODEL') ?? ''));
        if ($this->modelResolved === '') {
            $this->modelResolved = 'gemini-2.5-flash';
        }
    }

    public function getName(): string
    {
        return 'gemini';
    }

    public function supportsJson(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKeyResolved) !== '';
    }

    public function generateText(string $prompt, array $options = []): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Clé API Gemini absente (GEMINI_API_KEY).');
        }

        $model = $this->resolveModel($options);
        $json = (bool) ($options['json'] ?? false);
        $maxTokens = (int) ($options['maxTokens'] ?? ($options['max_tokens'] ?? ($json ? 8192 : 1800)));

        $generationConfig = [
            'maxOutputTokens' => $maxTokens,
        ];

        if ($json) {
            $generationConfig['responseMimeType'] = 'application/json';
        }

        // Gemini 2.5 « réfléchit » par défaut : les jetons de raisonnement sont
        // décomptés de maxOutputTokens et tronquent souvent la réponse (JSON
        // incomplet, texte coupé). On désactive ce raisonnement par défaut ;
        // un appelant peut le réactiver via l'option thinkingBudget.
        if (!array_key_exists('thinkingBudget', $options)) {
            $generationConfig['thinkingConfig'] = ['thinkingBudget' => 0];
        } elseif ($options['thinkingBudget'] !== null) {
            $generationConfig['thinkingConfig'] = ['thinkingBudget' => (int) $options['thinkingBudget']];
        }

        $response = $this->httpClient->request('POST', sprintf('https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent', $model), [
            'headers' => [
                'x-goog-api-key' => $this->apiKeyResolved,
                'Content-Type' => 'application/json',
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
        ]);

        $status = $response->getStatusCode();
        $data = $response->toArray(false);

        if ($status < 200 || $status >= 300) {
            $apiMessage = (string) ($data['error']['message'] ?? '');
            throw new \RuntimeException(
                'Requête Gemini échouée (HTTP ' . $status . ')'
                . ($apiMessage !== '' ? ' : ' . $apiMessage : '') . '.'
            );
        }

        $candidate = $data['candidates'][0] ?? null;

        if (!is_array($candidate)) {
            $blockReason = (string) ($data['promptFeedback']['blockReason'] ?? '');
            throw new \RuntimeException(
                $blockReason !== ''
                    ? 'Gemini a bloqué la requête (raison : ' . $blockReason . ').'
                    : 'Gemini n\'a retourné aucun contenu exploitable.'
            );
        }

        $finishReason = (string) ($candidate['finishReason'] ?? '');

        // Les parties de raisonnement (thought) ne doivent jamais être prises
        // pour la réponse : on ne concatène que les parties de texte classiques.
        $chunks = [];
        foreach (($candidate['content']['parts'] ?? []) as $part) {
            if (!is_array($part) || (($part['thought'] ?? false) === true)) {
                continue;
            }
            if (isset($part['text']) && is_string($part['text'])) {
                $chunks[] = $part['text'];
            }
        }

        $text = trim(implode('', $chunks));

        if ($finishReason === 'MAX_TOKENS') {
            // Un JSON coupé est inexploitable : on préfère une erreur explicite.
            if ($json || $text === '') {
                throw new \RuntimeException(
                    'Réponse Gemini tronquée : la limite de ' . $maxTokens . ' jetons est atteinte avant la fin. '
                    . 'Réduisez la longueur du texte ou augmentez maxTokens.'
                );
            }
        }

        if (in_array($finishReason, ['SAFETY', 'PROHIBITED_CONTENT', 'RECITATION'], true)) {
            throw new \RuntimeException('Gemini a refusé de répondre (raison : ' . $finishReason . ').');
        }

        if ($text === '') {
            throw new \RuntimeException('Gemini n\'a retourné aucun contenu exploitable.');
        }

        return $text;
    }

    public function generateJson(string $prompt, array $options = []): array
    {
        $options['json'] = true;
        $raw = $this->generateText($prompt, $options);
        $raw = $this->stripCodeFences($raw);

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            // Certains modèles ajoutent du texte avant/après le JSON :
            // on tente d'isoler le premier objet/tableau complet.
            $extracted = $this->extractJson($raw);

            if ($extracted !== null) {
                $decoded = json_decode($extracted, true);
            }
        }

        if (!is_array($decoded)) {
            $preview = mb_substr(preg_replace('/\s+/', ' ', $raw) ?? $raw, 0, 160);

            throw new \RuntimeException(
                'La réponse Gemini n\'est pas un JSON valide. Début de la réponse : « ' . $preview . '… »'
            );
        }

        return $decoded;
    }

    private function resolveModel(array $options): string
    {
        if (isset($options['model']) && is_string($options['model']) && trim($options['model']) !== '') {
            return trim($options['model']);
        }
        return $this->modelResolved !== '' ? $this->modelResolved : 'gemini-2.5-flash';
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

    /**
     * Isole le premier objet ou tableau JSON équilibré d'une réponse bavarde.
     */
    private function extractJson(string $text): ?string
    {
        $length = strlen($text);

        for ($start = 0; $start < $length; $start++) {
            $char = $text[$start];
            if ($char !== '{' && $char !== '[') {
                continue;
            }

            $open = $char;
            $close = $char === '{' ? '}' : ']';
            $depth = 0;
            $inString = false;
            $escaped = false;

            for ($i = $start; $i < $length; $i++) {
                $current = $text[$i];

                if ($inString) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($current === '\\') {
                        $escaped = true;
                    } elseif ($current === '"') {
                        $inString = false;
                    }
                    continue;
                }

                if ($current === '"') {
                    $inString = true;
                } elseif ($current === $open) {
                    $depth++;
                } elseif ($current === $close) {
                    $depth--;
                    if ($depth === 0) {
                        return substr($text, $start, $i - $start + 1);
                    }
                }
            }
        }

        return null;
    }
}
