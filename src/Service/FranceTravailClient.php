<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client de l'API Offres d'emploi France Travail (ex Pôle Emploi).
 *
 * Nécessite un compte gratuit sur https://francetravail.io pour obtenir
 * client_id / client_secret, à renseigner dans le .env local.
 */
class FranceTravailClient
{
    private const TOKEN_URL = 'https://entreprise.francetravail.fr/connexion/oauth2/access_token?realm=/partenaire';

    private const BASE_URL = 'https://api.francetravail.io';

    private const SEARCH_URL = self::BASE_URL . '/partenaire/offresdemploi/v2/offres/search';

    private const GEO_URL = self::BASE_URL . '/partenaire/geolocalisation/v2/communes';

    private ?string $cachedToken = null;

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(default::FRANCE_TRAVAIL_CLIENT_ID)%')]
        private ?string $clientId = null,
        #[Autowire('%env(default::FRANCE_TRAVAIL_CLIENT_SECRET)%')]
        private ?string $clientSecret = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->clientId) !== '' && trim((string) $this->clientSecret) !== '';
    }

    /**
     * Recherche d'offres locales autour d'une commune.
     *
     * @return array<int, array<string, mixed>> offres normalisées
     */
    public function search(string $motsCles, ?string $codeInsee = null, int $rayon = 30, int $limit = 12): array
    {
        $params = [
            'motsCles' => $motsCles,
            'range' => '0-' . max(0, $limit - 1),
            'sort' => '2',
        ];

        if ($codeInsee !== null && $codeInsee !== '') {
            $params['commune'] = $codeInsee;
            $params['distance'] = max(1, min(100, $rayon));
        }

        $response = $this->httpClient->request('GET', self::SEARCH_URL, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token(),
                'Accept' => 'application/json',
            ],
            'query' => $params,
            'timeout' => 15,
            'max_duration' => 20,
        ]);

        if ($response->getStatusCode() !== 200) {
            return [];
        }

        $data = $response->toArray(false);
        $resultats = $data['resultats'] ?? [];

        $offres = [];
        foreach ($resultats as $offre) {
            if (!is_array($offre)) {
                continue;
            }

            $lieu = $offre['lieuTravail'] ?? [];
            $contact = $offre['contact'] ?? [];
            $url = $offre['urlOrigin'] ?? $contact['url'] ?? '#';

            $offres[] = [
                'title' => (string) ($offre['intitule'] ?? 'Offre sans intitulé'),
                'company' => (string) ($offre['entreprise']['nom'] ?? ''),
                'url' => (string) $url,
                'location' => (string) ($lieu['libelle'] ?? ''),
                'salary' => (string) ($offre['salaire']['libelle'] ?? ''),
                'type' => (string) ($offre['typeContratLibelle'] ?? ''),
                'date' => (string) ($offre['dateCreation'] ?? ''),
                'description' => (string) ($offre['description'] ?? ''),
                'source' => 'France Travail',
            ];
        }

        return $offres;
    }

    /**
     * Retourne le code INSEE d'une commune à partir de son nom.
     */
    public function geocode(string $ville): ?string
    {
        $ville = trim($ville);
        if ($ville === '') {
            return null;
        }

        $response = $this->httpClient->request('GET', self::GEO_URL, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token(),
                'Accept' => 'application/json',
            ],
            'query' => [
                'nom' => $ville,
            ],
            'timeout' => 10,
            'max_duration' => 15,
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $data = $response->toArray(false);

        foreach ($data as $candidat) {
            if (!is_array($candidat)) {
                continue;
            }

            foreach (['codeInseeLabel', 'codeInsee', 'code'] as $cle) {
                if (isset($candidat[$cle])) {
                    return (string) $candidat[$cle];
                }
            }
        }

        return null;
    }

    private function token(): string
    {
        if ($this->cachedToken !== null) {
            return $this->cachedToken;
        }

        if (!$this->isConfigured()) {
            throw new \RuntimeException(
                'L’API France Travail n’est pas configurée (client_id / client_secret manquants).'
            );
        }

        $response = $this->httpClient->request('POST', self::TOKEN_URL, [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => 'api_offresdemploiv2 o2dsoffre',
            ],
            'timeout' => 15,
            'max_duration' => 20,
        ]);

        $data = $response->toArray(false);
        $token = $data['access_token'] ?? null;

        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('Impossible d’obtenir un jeton France Travail.');
        }

        $this->cachedToken = $token;

        return $token;
    }
}