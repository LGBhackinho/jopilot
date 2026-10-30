<?php

namespace App\Service\Ai\Provider;

use App\Service\Ai\AiClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAiCompatibleClient implements AiClientInterface
{
    private string $baseUrlResolved;
    private string $apiKeyResolved;
    private string $modelResolved;
    private string $nameResolved;

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(OPENAI_API_KEY)%')]
        string $apiKey = '',
        #[Autowire('%env(OPENAI_BASE_URL)%')]
        string $baseUrl = '',
        #[Autowire('%env(OPENAI_MODEL)%')]
        string $model = '',
        #[Autowire('%env(OPENAI_NAME)%')]
        string $name = '',
    ) {
        $this->apiKeyResolved = trim($apiKey) !== '' ? trim($apiKey) : trim((string) ($_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?? ''));
        $this->baseUrlResolved = trim($baseUrl) !== '' ? trim($baseUrl) : trim((string) ($_ENV['OPENAI_BASE_URL'] ?? getenv('OPENAI_BASE_URL') ?? ''));
        if ($this->baseUrlResolved === '') {
            $this->baseUrlResolved = 'https://api.openai.com/v1';
        }
        $this->modelResolved = trim($model) !== '' ? trim($model) : trim((string) ($_ENV['OPENAI_MODEL'] ?? getenv('OPENAI_MODEL') ?? ''));
        if ($this->modelResolved === '') {
            $this->modelResolved = 'gpt-4o-mini';
        }
        $this->nameResolved = trim($name) !== '' ? strtolower(trim($name)) : strtolower(trim((string) ($_ENV['OPENAI_NAME'] ?? getenv('OPENAI_NAME') ?? 'openai')));
        if ($this->nameResolved === '') {
            $this->nameResolved = 'openai';
        }
    }

    public function getName(): string
    {
        return $this->nameResolved;
    }

    public function supportsJson(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        $base = strtolower($this->baseUrlResolved);
        if (str_contains($base, '11434')) {
            return true;
        }
        return trim($this->apiKeyResolved) !== '';
    }

    public function generateText(string $prompt, array $options = []): string
    {
        $base = strtolower($this->baseUrlResolved);
        if (trim($this->apiKeyResolved) === '' && !str_contains($base, '11434')) {
            throw new \RuntimeException('Clé API OpenAI-compatible absente.');
        }

        $model = $this->resolveModel($options);
        $maxTokens = (int) ($options['maxTokens'] ?? ($options['max_tokens'] ?? 1800));
        $temperature = (float) ($options['temperature'] ?? 0.2);
        $json = (bool) ($options['json'] ?? false);

        $body = [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
        ];

        if ($json) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $headers = [
            'Content-Type' => 'application/json',
        ];
        if (trim($this->apiKeyResolved) !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKeyResolved;
        }

        $response = $this->httpClient->request('POST', rtrim($this->baseUrlResolved, '/') . '/chat/completions', [
            'headers' => $headers,
            'json' => $body,
            'timeout' => 120,
            'max_duration' => 180,
        ]);

        $status = $response->getStatusCode();
        $data = $response->toArray(false);

        if ($status < 200 || $status >= 300) {
            $msg = $data['error']['message'] ?? ($data['message'] ?? ('HTTP ' . $status));
            throw new \RuntimeException('Requête ' . $this->getName() . ' échouée : ' . $msg);
        }

        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new \RuntimeException($this->getName() . ' n\'a retourné aucun contenu exploitable.');
        }

        return $content;
    }

    public function generateJson(string $prompt, array $options = []): array
    {
        $options['json'] = true;
        $raw = $this->generateText($prompt, $options);
        $raw = $this->stripCodeFences($raw);

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('La réponse ' . $this->getName() . ' n\'est pas un JSON valide.');
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('La réponse ' . $this->getName() . ' n\'a pas le format attendu.');
        }

        return $decoded;
    }

    private function resolveModel(array $options): string
    {
        if (isset($options['model']) && is_string($options['model']) && trim($options['model']) !== '') {
            return trim($options['model']);
        }
        return $this->modelResolved !== '' ? $this->modelResolved : 'gpt-4o-mini';
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
