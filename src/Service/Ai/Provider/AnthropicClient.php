<?php

namespace App\Service\Ai\Provider;

use App\Service\Ai\AiClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AnthropicClient implements AiClientInterface
{
    private string $baseUrlResolved;
    private string $apiKeyResolved;
    private string $modelResolved;

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(ANTHROPIC_API_KEY)%')]
        string $apiKey = '',
        #[Autowire('%env(ANTHROPIC_BASE_URL)%')]
        string $baseUrl = '',
        #[Autowire('%env(ANTHROPIC_MODEL)%')]
        string $model = '',
    ) {
        $this->apiKeyResolved = trim($apiKey) !== '' ? trim($apiKey) : trim((string) ($_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY') ?? ''));
        $this->baseUrlResolved = trim($baseUrl) !== '' ? trim($baseUrl) : trim((string) ($_ENV['ANTHROPIC_BASE_URL'] ?? getenv('ANTHROPIC_BASE_URL') ?? ''));
        if ($this->baseUrlResolved === '') {
            $this->baseUrlResolved = 'https://api.anthropic.com/v1';
        }
        $this->modelResolved = trim($model) !== '' ? trim($model) : trim((string) ($_ENV['ANTHROPIC_MODEL'] ?? getenv('ANTHROPIC_MODEL') ?? ''));
        if ($this->modelResolved === '') {
            $this->modelResolved = 'claude-sonnet-4-5-20250929';
        }
    }

    public function getName(): string
    {
        return 'claude';
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
            throw new \RuntimeException('Clé API Anthropic absente (ANTHROPIC_API_KEY).');
        }

        $model = $this->resolveModel($options);
        $maxTokens = (int) ($options['maxTokens'] ?? ($options['max_tokens'] ?? 1800));
        $temperature = (float) ($options['temperature'] ?? 0.2);
        $json = (bool) ($options['json'] ?? false);

        $system = null;
        if ($json) {
            $system = 'Tu dois obligatoirement répondre en JSON valide, sans texte hors JSON, sans markdown.';
        }

        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        if ($system !== null) {
            $body['system'] = $system;
        }

        $response = $this->httpClient->request('POST', rtrim($this->baseUrlResolved, '/') . '/messages', [
            'headers' => [
                'x-api-key' => $this->apiKeyResolved,
                'anthropic-version' => '2023-06-13',
                'Content-Type' => 'application/json',
            ],
            'json' => $body,
            'timeout' => 120,
            'max_duration' => 180,
        ]);

        $status = $response->getStatusCode();
        $data = $response->toArray(false);

        if ($status < 200 || $status >= 300) {
            $msg = $data['error']['message'] ?? ('HTTP ' . $status);
            throw new \RuntimeException('Requête Claude échouée : ' . $msg);
        }

        $content = $data['content'][0]['text'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new \RuntimeException('Claude n\'a retourné aucun contenu exploitable.');
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
            throw new \RuntimeException('La réponse Claude n\'est pas un JSON valide.');
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('La réponse Claude n\'a pas le format attendu.');
        }

        return $decoded;
    }

    private function resolveModel(array $options): string
    {
        if (isset($options['model']) && is_string($options['model']) && trim($options['model']) !== '') {
            return trim($options['model']);
        }
        return $this->modelResolved !== '' ? $this->modelResolved : 'claude-sonnet-4-5-20250929';
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
