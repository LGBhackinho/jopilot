<?php

namespace App\Service\JobOffer;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Offres françaises via l'API Jooble (secours d'Adzuna).
 *
 * La clé doit être créée sur https://fr.jooble.org/api/about : une clé
 * prise sur jooble.org ne renvoie que des offres américaines.
 * L'offre gratuite est limitée à 500 requêtes pour la durée de vie de la clé,
 * d'où le cache de l'orchestrateur.
 */
class JoobleProvider implements JobOfferProviderInterface
{
    private const BASE_URL = 'https://fr.jooble.org/api/';

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(default::JOOBLE_API_KEY)%')]
        private ?string $apiKey = null,
    ) {
    }

    public function name(): string
    {
        return 'jooble';
    }

    public function label(): string
    {
        return 'Jooble';
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->apiKey) !== '';
    }

    public function search(SearchQuery $query, int $limit): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Jooble n’est pas configuré : renseignez JOOBLE_API_KEY dans .env.local.');
        }

        if ($query->isEmpty()) {
            return [];
        }

        $payload = [
            'keywords' => $query->motsCles,
            'location' => trim($query->ville),
            'page' => 1,
            'ResultOnPage' => max(1, min(50, $limit)),
        ];

        $radius = $this->radius($query->rayon);
        if ($radius !== null && trim($query->ville) !== '') {
            $payload['radius'] = $radius;
        }

        try {
            $response = $this->httpClient->request('POST', self::BASE_URL . trim((string) $this->apiKey), [
                'json' => $payload,
                'timeout' => 12,
                'max_duration' => 18,
            ]);
        } catch (HttpClientException $e) {
            throw new \RuntimeException('Jooble est injoignable : ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();

        if ($status === 403) {
            throw new \RuntimeException('Jooble : clé API refusée (HTTP 403).');
        }

        if ($status !== 200) {
            throw new \RuntimeException('Jooble a répondu HTTP ' . $status . '.');
        }

        $data = $response->toArray(false);
        $jobs = $data['jobs'] ?? [];

        if (!is_array($jobs)) {
            return [];
        }

        $offres = [];

        foreach ($jobs as $job) {
            if (!is_array($job)) {
                continue;
            }

            $url = (string) ($job['link'] ?? '');

            if ($url === '') {
                continue;
            }

            $offres[] = [
                'title' => $this->text((string) ($job['title'] ?? 'Offre sans intitulé')),
                'company' => $this->text((string) ($job['company'] ?? '')),
                'url' => $url,
                'location' => $this->text((string) ($job['location'] ?? '')),
                'salary' => $this->text((string) ($job['salary'] ?? '')),
                'type' => $this->text((string) ($job['type'] ?? '')),
                'date' => (string) ($job['updated'] ?? ''),
                'description' => $this->truncate($this->text((string) ($job['snippet'] ?? ''))),
                'source' => $this->label(),
            ];
        }

        return $offres;
    }

    /** Jooble n'accepte que des rayons prédéfinis. */
    private function radius(int $rayon): ?int
    {
        $allowed = [0, 4, 8, 16, 26, 40, 80];
        $closest = null;

        foreach ($allowed as $value) {
            if ($closest === null || abs($value - $rayon) < abs($closest - $rayon)) {
                $closest = $value;
            }
        }

        return $closest;
    }

    private function text(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function truncate(string $value, int $length = 220): string
    {
        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length) . '…';
    }
}
