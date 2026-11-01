<?php

namespace App\Service;

use App\Service\JobOffer\CompanyDirectoryProvider;
use App\Service\JobOffer\GeoCommuneResolver;
use App\Service\JobOffer\JobOfferProviderInterface;
use App\Service\JobOffer\SearchQuery;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Orchestre la recherche d'offres et d'entreprises.
 *
 * Le service ne connaît aucun fournisseur en particulier : il interroge tous
 * ceux qui sont configurés (Adzuna, Jooble…), fusionne leurs résultats et
 * interroge l'annuaire des entreprises. Chaque source est isolée : une panne
 * n'empêche pas les autres de s'afficher.
 */
class JobOfferSearch
{
    private const SEARCH_LINKS = [
        'Indeed' => 'https://fr.indeed.com/jobs?q={q}&l={ville}',
        'LinkedIn Jobs' => 'https://www.linkedin.com/jobs/search/?keywords={q}&location={ville}',
        'Welcome to the Jungle' => 'https://www.welcometothejungle.com/fr/jobs?query={q}&location={ville}',
        'HelloWork' => 'https://www.hellowork.com/fr-fr/emploi/recherche.html?k={q}&l={ville}',
        'Google Jobs' => 'https://www.google.com/search?ibp=htl;jobs&q={q} {ville}',
        'France Travail' => 'https://candidat.francetravail.fr/offres/recherche?motsCles={q}&lieux={ville}',
    ];

    /** Durée de mise en cache d'un résultat complet. */
    private const CACHE_TTL = 1800;

    /** Durée réduite quand une source est en erreur (on retente plus vite). */
    private const CACHE_TTL_ERROR = 300;

    private Filesystem $filesystem;

    /**
     * @param iterable<JobOfferProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('app.job_offer_provider')]
        private iterable $providers,
        private CompanyDirectoryProvider $companies,
        private GeoCommuneResolver $geo,
        #[Autowire('%kernel.project_dir%/var/data/offers_cache.json')]
        private string $cachePath,
    ) {
        $this->filesystem = new Filesystem();
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
     * @return array<string, mixed>
     */
    public function search(string $query, string $ville = '', int $rayon = 30, int $limit = 12): array
    {
        $query = trim($query);
        $ville = trim($ville);
        $rayon = max(0, min(100, $rayon));

        if ($query === '') {
            return $this->emptyResult($query, $ville, $rayon);
        }

        $commune = $this->geo->resolve($ville);
        $searchQuery = new SearchQuery($query, $ville, $commune['code'] ?? null, $rayon);

        $cacheKey = $this->cacheKey($searchQuery, $limit);
        $cached = $this->readCache($cacheKey);

        if ($cached !== null) {
            $cached['cached'] = true;

            return $cached;
        }

        $sources = [];
        $erreurs = [];
        $offresParSource = [];
        $lignes = [];

        // Toutes les sources configurées sont interrogées, jamais l'une à la
        // place de l'autre : chaque fournisseur apporte sa propre couverture
        // (Adzuna agrège de grandes plateformes, Jooble d'autres sites).
        foreach ($this->providers as $provider) {
            $providerOffres = [];
            $status = $this->runProvider($provider, $searchQuery, $limit, $providerOffres);

            $sources[] = $status;

            if ($status['state'] === 'error') {
                $erreurs[] = $status['message'];
            }

            if ($status['state'] === 'ok' && $providerOffres !== []) {
                $offresParSource[] = [
                    'label' => $provider->label(),
                    'offres' => $providerOffres,
                ];
            }
        }

        $offres = $this->merge($offresParSource, $limit);
        $sourceOffres = $offresParSource === []
            ? null
            : implode(' + ', array_column($offresParSource, 'label'));

        $entreprises = [];
        $entrepriseSource = $this->companies->label();
        $entrepriseMessage = null;

        try {
            $entreprises = $this->companies->search(
                $searchQuery,
                $commune['departement'] ?? null,
                6
            );

            $entrepriseMessage = $this->companies->lastNotice();

            if ($entreprises === [] && $entrepriseMessage === null) {
                $entrepriseMessage = 'Aucune entreprise trouvée pour cette recherche.';
            }
        } catch (\Throwable $e) {
            $erreurs[] = $e->getMessage();
            $entrepriseMessage = $e->getMessage();
        }

        $payload = [
            'query' => $query,
            'ville' => $ville,
            'rayon' => $rayon,
            'code_insee' => $commune['code'] ?? null,
            'commune' => $commune['nom'] ?? null,
            'offres' => $offres,
            'source_offres' => $sourceOffres,
            'entreprises' => $entreprises,
            'entreprise_source' => $entrepriseSource,
            'entreprise_message' => $entrepriseMessage,
            'sources' => $sources,
            'erreurs' => $erreurs,
            'liens' => $this->searchLinks($query, $ville),
            'cached' => false,
            'generated_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ];

        $this->writeCache($cacheKey, $payload, $erreurs === [] ? self::CACHE_TTL : self::CACHE_TTL_ERROR);

        return $payload;
    }

    /**
     * Exécute un fournisseur et normalise son état pour l'interface.
     *
     * @param list<array<string, mixed>> $offres
     * @return array{name: string, label: string, state: string, count: int, message: string|null}
     */
    private function runProvider(
        JobOfferProviderInterface $provider,
        SearchQuery $query,
        int $limit,
        array &$offres,
    ): array {
        $status = [
            'name' => $provider->name(),
            'label' => $provider->label(),
            'state' => 'ok',
            'count' => 0,
            'message' => null,
        ];

        if (!$provider->isConfigured()) {
            $status['state'] = 'unconfigured';
            $status['message'] = $provider->label() . ' n’est pas configuré.';

            return $status;
        }

        try {
            $offres = $provider->search($query, $limit);
            $status['count'] = count($offres);
            $status['state'] = $offres === [] ? 'empty' : 'ok';

            return $status;
        } catch (\Throwable $e) {
            $status['state'] = 'error';
            $status['message'] = $e->getMessage();
            $offres = [];

            return $status;
        }
    }

    /**
     * Fusionne les résultats des fournisseurs en alternance.
     *
     * L'alternance (une offre Adzuna, une offre Jooble, etc.) garantit que
     * chaque source reste visible dans la liste, au lieu qu'une source
     * prolifique écrase les autres.
     *
     * @param list<array{label: string, offres: list<array<string, mixed>>}> $parSource
     * @return list<array<string, mixed>>
     */
    private function merge(array $parSource, int $limit): array
    {
        $vues = [];
        $fusion = [];
        $index = 0;
        $encore = true;

        while ($encore && count($fusion) < $limit) {
            $encore = false;

            foreach ($parSource as $source) {
                if (!isset($source['offres'][$index])) {
                    continue;
                }

                $encore = true;
                $offre = $source['offres'][$index];
                $url = (string) ($offre['url'] ?? '');
                $signature = $this->signature($offre);

                if ($url === '' || isset($vues[$url])) {
                    continue;
                }

                if ($signature !== '' && isset($vues[$signature])) {
                    continue;
                }

                $vues[$url] = true;

                if ($signature !== '') {
                    $vues[$signature] = true;
                }

                $fusion[] = $offre;

                if (count($fusion) >= $limit) {
                    break;
                }
            }

            ++$index;
        }

        return $fusion;
    }

    /**
     * Empreinte « intitulé + entreprise ».
     *
     * Deux plateformes relayent souvent la même annonce avec deux URL
     * distinctes : sans cette empreinte, la liste affiche des doublons.
     *
     * @param array<string, mixed> $offre
     */
    private function signature(array $offre): string
    {
        $titre = mb_strtolower(trim((string) ($offre['title'] ?? '')));
        $entreprise = mb_strtolower(trim((string) ($offre['company'] ?? '')));

        // « Développeur Angular (H/F) », « Développeur Angular F/H » et
        // « Développeur Angular (IT) » désignent la même annonce.
        $nettoyer = static function (string $valeur): string {
            $valeur = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $valeur) ?? $valeur;
            $valeur = preg_replace('/\b(f|h|hf|fh|it)\b/u', ' ', $valeur) ?? $valeur;

            return trim(preg_replace('/\s+/u', ' ', $valeur) ?? $valeur);
        };

        $titre = $nettoyer($titre);
        $entreprise = $nettoyer($entreprise);

        if ($titre === '') {
            return '';
        }

        return 'sig:' . $titre . '|' . $entreprise;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(string $query, string $ville, int $rayon): array
    {
        return [
            'query' => $query,
            'ville' => $ville,
            'rayon' => $rayon,
            'code_insee' => null,
            'commune' => null,
            'offres' => [],
            'source_offres' => null,
            'entreprises' => [],
            'entreprise_source' => null,
            'entreprise_message' => null,
            'sources' => [],
            'erreurs' => [],
            'liens' => [],
            'cached' => false,
            'generated_at' => null,
        ];
    }

    private function cacheKey(SearchQuery $query, int $limit): string
    {
        $etats = [];

        foreach ($this->providers as $provider) {
            $etats[] = $provider->name() . ':' . ($provider->isConfigured() ? '1' : '0');
        }

        return sha1(implode('|', [
            mb_strtolower($query->motsCles),
            mb_strtolower($query->ville),
            $query->codeInsee ?? '',
            $query->rayon,
            $limit,
            // L'état de configuration fait partie de la clé : dès qu'une clé API
            // est renseignée (ou retirée), les anciens résultats « non configuré »
            // mis en cache ne sont plus servis.
            implode(',', $etats),
        ]));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCache(string $key): ?array
    {
        $entries = $this->readCacheFile();
        $entry = $entries[$key] ?? null;

        if (!is_array($entry) || !isset($entry['expires_at'], $entry['payload'])) {
            return null;
        }

        if ((int) $entry['expires_at'] < time()) {
            return null;
        }

        return is_array($entry['payload']) ? $entry['payload'] : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeCache(string $key, array $payload, int $ttl): void
    {
        // On purge les entrées expirées à chaque écriture : le fichier
        // reste petit sans tâche de maintenance dédiée.
        $now = time();
        $entries = array_filter(
            $this->readCacheFile(),
            static fn (mixed $entry): bool => is_array($entry) && (int) ($entry['expires_at'] ?? 0) >= $now
        );

        $entries[$key] = [
            'expires_at' => $now + $ttl,
            'payload' => $payload,
        ];

        try {
            $this->filesystem->mkdir(\dirname($this->cachePath));
            file_put_contents(
                $this->cachePath,
                json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            );
        } catch (\Throwable) {
            // Le cache est un confort : son échec ne doit jamais casser la recherche.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readCacheFile(): array
    {
        if (!is_file($this->cachePath)) {
            return [];
        }

        $content = (string) file_get_contents($this->cachePath);

        if (trim($content) === '') {
            return [];
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : [];
    }

    private function cleanTerm(string $term): string
    {
        $term = trim($term);

        if (mb_strlen($term) > 1 && mb_strlen($term) <= 32) {
            return $term;
        }

        return '';
    }

    /**
     * @return array<int, string>
     */
    private function keywords(string $query): array
    {
        $stopwords = [
            'de', 'des', 'du', 'et', 'en', 'la', 'le', 'les', 'un', 'une',
            'the', 'and', 'for', 'with', 'senior', 'junior', 'développeur',
            'developpeur', 'developer', 'développeuse', 'freelance',
        ];

        $raw = preg_split('/[\s,;|()\/]+/u', mb_strtolower($query)) ?: [];

        $keywords = [];
        foreach ($raw as $token) {
            $token = trim($token, ".'\"");

            if ($token === '' || in_array($token, $stopwords, true)) {
                continue;
            }

            $keywords[] = $token;
        }

        return array_values(array_unique($keywords));
    }
}
