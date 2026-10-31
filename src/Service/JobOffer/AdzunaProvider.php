<?php

namespace App\Service\JobOffer;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Offres d'emploi françaises via l'API Adzuna.
 *
 * Adzuna agrège de nombreuses plateformes (dont Indeed et Monster) et
 * expose un flux JSON pour la France (code pays « fr »).
 * Clé gratuite sur https://developer.adzuna.com (250 appels/jour).
 *
 * Conditions d'utilisation : afficher le logo « Jobs by Adzuna » et
 * conserver le lien vers l'annonce d'origine.
 */
class AdzunaProvider implements JobOfferProviderInterface
{
    private const BASE_URL = 'https://api.adzuna.com/v1/api/jobs/fr/search/1';
    public const MAX_TENTATIVES = 5;

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(default::ADZUNA_APP_ID)%')]
        private ?string $appId = null,
        #[Autowire('%env(default::ADZUNA_APP_KEY)%')]
        private ?string $appKey = null,
    ) {
    }

    public function name(): string
    {
        return 'adzuna';
    }

    public function label(): string
    {
        return 'Adzuna';
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->appId) !== '' && trim((string) $this->appKey) !== '';
    }

    public function search(SearchQuery $query, int $limit): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException(
                'Adzuna n’est pas configuré : renseignez ADZUNA_APP_ID et ADZUNA_APP_KEY dans .env.local.'
            );
        }

        if ($query->isEmpty()) {
            return [];
        }

        // Adzuna ne renvoie que les annonces contenant TOUS les mots de `what`.
        // Une requête précise comme « Développeur Web Full Stack » donne donc
        // 0 résultat hors des grandes métropoles. On essaie du plus précis au
        // plus large et on garde le premier essai qui remonte des offres.
        foreach ($this->candidateQueries($query->motsCles) as $motsCles) {
            $offres = $this->request($motsCles, $query, $limit);

            if ($offres !== []) {
                return $offres;
            }
        }

        return [];
    }

    /**
     * Variantes de la requête, de la plus précise à la plus large.
     *
     * On conserve le début de la requête : `JobOfferSearch::buildQuery()`
     * place les intitulés de poste en premier, ce sont eux qui portent le sens.
     *
     * @return list<string>
     */
    private function candidateQueries(string $motsCles): array
    {
        $motsCles = trim($motsCles);
        $mots = preg_split('/\s+/u', $motsCles, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $candidats = [$motsCles];

        foreach ([3, 2] as $longueur) {
            if (count($mots) > $longueur) {
                $candidats[] = implode(' ', array_slice($mots, 0, $longueur));
            }
        }

        return array_slice(array_values(array_unique($candidats)), 0, self::MAX_TENTATIVES);
    }

    /**
     * @return list<array<string, string>>
     */
    private function request(string $motsCles, SearchQuery $query, int $limit): array
    {
        $params = [
            'app_id' => $this->appId,
            'app_key' => $this->appKey,
            'what' => $motsCles,
            'results_per_page' => max(1, min(50, $limit)),
            'sort_by' => 'date',
            'content-type' => 'application/json',
        ];

        $ville = $query->villePrincipale();

        if ($ville !== '') {
            $params['where'] = $ville;
            $params['distance'] = max(1, min(100, $query->rayon));
        }

        try {
            $response = $this->httpClient->request('GET', self::BASE_URL, [
                'query' => $params,
                'timeout' => 12,
                'max_duration' => 18,
            ]);
        } catch (HttpClientException $e) {
            throw new \RuntimeException('Adzuna est injoignable : ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();

        if ($status === 429) {
            throw new \RuntimeException('Adzuna : quota journalier atteint (HTTP 429). Réessayez demain.');
        }

        if ($status === 401 || $status === 403) {
            throw new \RuntimeException('Adzuna : clé API refusée (HTTP ' . $status . ').');
        }

        if ($status !== 200) {
            throw new \RuntimeException('Adzuna a répondu HTTP ' . $status . '.');
        }

        $data = $response->toArray(false);
        $results = $data['results'] ?? [];

        if (!is_array($results)) {
            return [];
        }

        $offres = [];

        foreach ($results as $item) {
            if (!is_array($item)) {
                continue;
            }

            $url = (string) ($item['redirect_url'] ?? '');

            if ($url === '') {
                continue;
            }

            $offres[] = [
                'title' => $this->text((string) ($item['title'] ?? 'Offre sans intitulé')),
                'company' => $this->text((string) ($item['company']['display_name'] ?? '')),
                'url' => $url,
                'location' => $this->text((string) ($item['location']['display_name'] ?? '')),
                'salary' => $this->salary($item),
                'type' => $this->contract($item),
                'date' => (string) ($item['created'] ?? ''),
                'description' => $this->truncate($this->text((string) ($item['description'] ?? ''))),
                'source' => $this->label(),
            ];
        }

        return $offres;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function salary(array $item): string
    {
        $min = $item['salary_min'] ?? null;
        $max = $item['salary_max'] ?? null;

        if (!is_numeric($min) && !is_numeric($max)) {
            return '';
        }

        $format = static fn (float $value): string => number_format($value, 0, ',', ' ');

        if (is_numeric($min) && is_numeric($max) && (float) $min !== (float) $max) {
            return $format((float) $min) . ' – ' . $format((float) $max) . ' € / an';
        }

        $value = is_numeric($min) ? (float) $min : (float) $max;

        return '≈ ' . $format($value) . ' € / an';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function contract(array $item): string
    {
        $parts = [];

        $time = (string) ($item['contract_time'] ?? '');
        $parts[] = match ($time) {
            'full_time' => 'Temps plein',
            'part_time' => 'Temps partiel',
            default => '',
        };

        $type = (string) ($item['contract_type'] ?? '');
        $parts[] = match ($type) {
            'permanent' => 'CDI',
            'contract' => 'CDD',
            default => '',
        };

        return implode(' · ', array_filter($parts));
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
