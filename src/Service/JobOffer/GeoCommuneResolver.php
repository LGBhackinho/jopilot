<?php

namespace App\Service\JobOffer;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Transforme un nom de ville en commune officielle (code INSEE, département).
 *
 * Utilise l'API publique « geo.api.gouv.fr » : gratuite, sans clé,
 * maintenue par l'État. Le résultat est mémorisé pour la durée de la requête
 * afin de ne jamais appeler deux fois le même nom de ville.
 */
class GeoCommuneResolver
{
    private const ENDPOINT = 'https://geo.api.gouv.fr/communes';

    /** @var array<string, array{code: string, nom: string, departement: string, code_postal: string}|null> */
    private array $cache = [];

    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    /**
     * @return array{code: string, nom: string, departement: string, code_postal: string}|null
     */
    public function resolve(string $ville): ?array
    {
        // « Lyon, France » ou « 69003 Lyon » : on garde le premier fragment utile.
        $nom = trim(preg_split('/[,;]/', $ville)[0] ?? '');
        $nom = preg_replace('/^\d{4,5}\s+/', '', $nom) ?? $nom;
        $nom = trim($nom);

        if ($nom === '') {
            return null;
        }

        $key = mb_strtolower($nom);

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        try {
            $response = $this->httpClient->request('GET', self::ENDPOINT, [
                'query' => [
                    'nom' => $nom,
                    'fields' => 'code,nom,departement,codesPostaux,population',
                    'boost' => 'population',
                    'limit' => 1,
                ],
                'timeout' => 6,
                'max_duration' => 10,
            ]);

            if ($response->getStatusCode() !== 200) {
                return $this->cache[$key] = null;
            }

            $data = $response->toArray(false);
        } catch (\Throwable) {
            return $this->cache[$key] = null;
        }

        $commune = is_array($data[0] ?? null) ? $data[0] : null;

        if ($commune === null || !isset($commune['code'])) {
            return $this->cache[$key] = null;
        }

        return $this->cache[$key] = [
            'code' => (string) $commune['code'],
            'nom' => (string) ($commune['nom'] ?? $nom),
            'departement' => (string) ($commune['departement']['code'] ?? ''),
            'code_postal' => (string) ($commune['codesPostaux'][0] ?? ''),
        ];
    }
}
