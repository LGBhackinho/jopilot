<?php

namespace App\Service\Ai\Provider;

use App\Service\Ai\AiClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AlbertClient implements AiClientInterface
{
    private string $baseUrlResolved;
    private string $apiKeyResolved;
    private string $modelResolved;
    private HttpClientInterface $httpClient;

    public function __construct(
        ?HttpClientInterface $httpClient = null,
        #[Autowire('%env(ALBERT_API_KEY)%')]
        string $apiKey = '',
        #[Autowire('%env(ALBERT_BASE_URL)%')]
        string $baseUrl = '',
        #[Autowire('%env(ALBERT_MODEL)%')]
        string $model = '',
    ) {
        $this->apiKeyResolved = trim($apiKey) !== '' ? trim($apiKey) : trim((string) ($_ENV['ALBERT_API_KEY'] ?? getenv('ALBERT_API_KEY') ?? ''));
        $this->baseUrlResolved = trim($baseUrl) !== '' ? trim($baseUrl) : trim((string) ($_ENV['ALBERT_BASE_URL'] ?? getenv('ALBERT_BASE_URL') ?? ''));
        if ($this->baseUrlResolved === '') {
            $this->baseUrlResolved = 'https://api.albert.etalab.gouv.fr';
        }
        $this->modelResolved = trim($model) !== '' ? trim($model) : trim((string) ($_ENV['ALBERT_MODEL'] ?? getenv('ALBERT_MODEL') ?? ''));
        if ($this->modelResolved === '') {
            $this->modelResolved = 'albert-large';
        }

        $this->httpClient = $httpClient ?? HttpClient::create([
            'verify_peer' => false,
            'verify_host' => false,
            'timeout' => 300,
            'max_duration' => 360,
        ]);
    }

    public function getName(): string
    {
        return 'albert';
    }

    public function supportsJson(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        // Une clé Albert réelle fait plusieurs dizaines de caractères. Cela évite
        // qu'une valeur tronquée ou un reste de configuration (ex. « sk ») fasse
        // échouer chaque requête avec une erreur 404 avant le provider réellement voulu.
        return strlen(trim($this->apiKeyResolved)) >= 20;
    }

    public function generateText(string $prompt, array $options = []): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Clé API Albert absente (ALBERT_API_KEY).');
        }

        $model = $this->resolveModel($options);
        $maxTokens = (int) ($options['maxTokens'] ?? ($options['max_tokens'] ?? 2048));
        $temperature = (float) ($options['temperature'] ?? 0.2);
        $json = (bool) ($options['json'] ?? false);

        $base = rtrim($this->baseUrlResolved, '/');
        if (str_ends_with($base, '/v1')) {
            $base = substr($base, 0, -3);
        }

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

        $endpoints = [
            $base . '/v1/chat/completions',
            $base . '/chat/completions',
            $base . '/api/chat/completions',
            $base . '/api/v1/chat/completions',
            'http://185.161.45.171/v1/chat/completions',
            'http://185.161.45.171/chat/completions',
            'http://185.161.45.171/api/chat/completions',
            'http://185.161.45.171/api/v1/chat/completions',
        ];

        $lastError = null;
        foreach ($endpoints as $url) {
            try {
                $headers = [
                    'Authorization' => 'Bearer ' . $this->apiKeyResolved,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => 'JobPilot/1.0',
                ];
                if (str_contains($url, '185.161.45.171')) {
                    $headers['Host'] = 'api.albert.etalab.gouv.fr';
                }

                $response = $this->httpClient->request('POST', $url, [
                    'headers' => $headers,
                    'json' => $body,
                    'timeout' => 300,
                    'max_duration' => 360,
                ]);

                $status = $response->getStatusCode();
                $raw = $response->getContent(false);
                try {
                    $data = json_decode($raw, true);
                } catch (\Throwable $e) {
                    $data = null;
                }

                if ($status < 200 || $status >= 300) {
                    $msg = is_array($data) ? ($data['error']['message'] ?? ($data['message'] ?? ('HTTP ' . $status))) : ('HTTP ' . $status);
                    throw new \RuntimeException('Requête Albert échouée : ' . $msg);
                }

                if (is_array($data)) {
                    $content = $data['choices'][0]['message']['content'] ?? ($data['response'] ?? ($data['message']['content'] ?? ($data['content'] ?? ($data['text'] ?? null))));
                    if (is_array($content)) {
                        $content = $content['text'] ?? ($content['content'] ?? json_encode($content));
                    }
                    if (is_string($content) && trim($content) !== '') {
                        return $content;
                    }
                }

                $content = trim($raw);
                if ($content === '') {
                    throw new \RuntimeException('Albert n\'a retourné aucun contenu exploitable.');
                }
                $content = $this->stripCodeFences($content);
                if ($content !== '') {
                    return $content;
                }
            } catch (\Throwable $e) {
                $lastError = $e;
                continue;
            }
        }

        if ($lastError instanceof \Throwable) {
            throw $lastError;
        }

        throw new \RuntimeException('Impossible de joindre Albert.');
    }

    public function generateJson(string $prompt, array $options = []): array
    {
        $options['json'] = true;
        $raw = $this->generateText($prompt, $options);
        $raw = $this->stripCodeFences($raw);

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('La réponse Albert n\'est pas un JSON valide.');
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('La réponse Albert n\'a pas le format attendu.');
        }

        return $decoded;
    }

    private function resolveModel(array $options): string
    {
        if (isset($options['model']) && is_string($options['model']) && trim($options['model']) !== '') {
            return trim($options['model']);
        }
        return $this->modelResolved !== '' ? $this->modelResolved : 'albert-large';
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
