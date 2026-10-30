<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class JobOfferSearch
{
    private const STOPWORDS = [
        'de', 'des', 'du', 'et', 'en', 'la', 'le', 'les', 'un', 'une',
        'the', 'and', 'for', 'with', 'senior', 'junior', 'développeur',
        'developpeur', 'developer', 'développeuse', 'freelance',
    ];

    private const SEARCH_LINKS = [
        'Indeed' => 'https://fr.indeed.com/jobs?q={q}&l={ville}',
        'LinkedIn Jobs' => 'https://www.linkedin.com/jobs/search/?keywords={q}&location={ville}',
        'Welcome to the Jungle' => 'https://www.welcometothejungle.com/fr/jobs?query={q}&location={ville}',
        'Google Jobs' => 'https://www.google.com/search?ibp=htl;jobs&q={q} {ville}',
        'France Travail' => 'https://candidat.francetravail.fr/offres/recherche?motsCles={q}&lieux={ville}',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private FranceTravailClient $franceTravail,
    ) {
    }

    /**
     * Construit une requête de recherche ciblée à partir de l'analyse du CV.
     *
     * @param array<string, mixed> $analysis
     */
    public function buildQuery(array $analysis): string
    {
        $terms = [];
        $seen = [];

        $add = function (string $term) use (&$terms, &$seen): void {
            $term = $this->cleanTerm($term);

            if ($term === '') {
                return;
            }

            $words = $this->keywords($term);
            $hasNew = false;

            foreach ($words as $word) {
                if (!isset($seen[$word])) {
                    $hasNew = true;
                }
            }

            if (!$hasNew) {
                return;
            }

            foreach ($words as $word) {
                $seen[$word] = true;
            }

            $terms[] = $term;
        };

        $metiers = $analysis['metiers_suggeres'] ?? [];
        if (is_array($metiers)) {
            foreach (array_slice($metiers, 0, 2) as $metier) {
                $add((string) $metier);
            }
        }

        $competences = $analysis['competences'] ?? [];
        if (is_array($competences)) {
            foreach ($competences as $competence) {
                $add((string) $competence);
            }
        }

        return implode(' ', $terms);
    }

    /**
     * @return array<int, array{name: string, url: string}>
     */
    public function searchLinks(string $query, string $ville = ''): array
    {
        $q = rawurlencode($query);
        $v = rawurlencode(trim($ville));
        $links = [];

        foreach (self::SEARCH_LINKS as $name => $template) {
            $links[] = [
                'name' => $name,
                'url' => str_replace(['{q}', '{ville}'], [$q, $v], $template),
            ];
        }

        return $links;
    }

    /**
     * Cherche des offres réelles sur une API publique (sans clé) et les filtre.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOffers(string $query, int $limit = 12): array
    {
        $keywords = $this->keywords($query);

        if ($keywords === []) {
            return [];
        }

        try {
            $response = $this->httpClient->request('GET', 'https://remotive.com/api/remote-jobs', [
                'query' => [
                    'search' => implode(' ', array_slice($keywords, 0, 3)),
                    'limit' => 50,
                ],
                'timeout' => 12,
                'max_duration' => 15,
            ]);

            if ($response->getStatusCode() !== 200) {
                return [];
            }

            $data = $response->toArray(false);
            $jobs = $data['jobs'] ?? [];
        } catch (\Throwable $e) {
            return [];
        }

        $results = [];

        foreach ($jobs as $job) {
            if (!is_array($job)) {
                continue;
            }

            $title = $this->text((string) ($job['title'] ?? ''));
            $company = $this->text((string) ($job['company_name'] ?? ''));
            $tags = is_array($job['tags'] ?? null)
                ? array_map('strval', $job['tags'])
                : [];
            $haystack = strtolower($title . ' ' . $company . ' ' . implode(' ', $tags));

            if (!$this->matches($haystack, $keywords)) {
                continue;
            }

            $results[] = [
                'title' => $title,
                'company' => $company,
                'url' => (string) ($job['url'] ?? '#'),
                'location' => $this->text((string) ($job['candidate_required_location'] ?? '')),
                'salary' => $this->text((string) ($job['salary'] ?? '')),
                'type' => $this->text((string) ($job['job_type'] ?? '')),
                'date' => (string) ($job['publication_date'] ?? ''),
                'description' => $this->truncate($this->stripHtml((string) ($job['description'] ?? ''))),
                'source' => 'À distance',
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        usort($results, static function (array $a, array $b): int {
            return strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''));
        });

        return $results;
    }

    /**
     * Recherche complète : offres locales (France Travail), offres distantes
     * (API publique) et liens vers les plateformes d'emploi.
     *
     * @return array{
     *   query: string,
     *   ville: string,
     *   rayon: int,
     *   liens: array<int, array{name: string, url: string}>,
     *   offres: array<int, array<string, mixed>>,
     *   source_locale: string|null,
     *   erreur_locale: string|null
     * }
     */
    public function search(string $query, string $ville = '', int $rayon = 30, int $limit = 12): array
    {
        $query = trim($query);
        $ville = trim($ville);

        if ($query === '') {
            return [
                'query' => '',
                'ville' => $ville,
                'rayon' => $rayon,
                'liens' => [],
                'offres' => [],
                'source_locale' => null,
                'erreur_locale' => null,
            ];
        }

        $offres = [];
        $sourceLocale = null;
        $erreurLocale = null;

        if ($this->franceTravail->isConfigured()) {
            try {
                $codeInsee = $this->franceTravail->geocode($ville);
                $offres = $this->franceTravail->search($query, $codeInsee, $rayon, $limit);
                $sourceLocale = 'France Travail';
            } catch (\Throwable $e) {
                $erreurLocale = $e->getMessage();
                $offres = [];
            }
        }

        $distantes = $this->fetchOffers($query, $limit);

        foreach ($distantes as $offre) {
            $offres[] = $offre;
        }

        return [
            'query' => $query,
            'ville' => $ville,
            'rayon' => $rayon,
            'liens' => $this->searchLinks($query, $ville),
            'offres' => $offres,
            'source_locale' => $sourceLocale,
            'erreur_locale' => $erreurLocale,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function keywords(string $query): array
    {
        $raw = preg_split('/[\s,;|()\/]+/u', mb_strtolower($query)) ?: [];

        $keywords = [];
        foreach ($raw as $token) {
            $token = trim($token, ".'\"");

            if ($token === '' || in_array($token, self::STOPWORDS, true)) {
                continue;
            }

            $keywords[] = $token;
        }

        return array_values(array_unique($keywords));
    }

    /**
     * @param array<int, string> $keywords
     */
    private function matches(string $haystack, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function cleanTerm(string $term): string
    {
        $term = trim($term);

        // Restreint à 4.5 octets-ish : on garde les termes courts et significatifs.
        if (mb_strlen($term) > 1 && mb_strlen($term) <= 32) {
            return $term;
        }

        return '';
    }

    private function text(string $value): string
    {
        $value = strip_tags($value);

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function stripHtml(string $value): string
    {
        $value = preg_replace('/<br\s*\/?>/i', "\n", $value) ?? $value;
        $value = preg_replace('/<\/p>/i', "\n", $value) ?? $value;
        $value = strip_tags($value);

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