<?php

namespace App\Service\JobOffer;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Entreprises françaises à cibler en candidature spontanée.
 *
 * Source : l'API officielle « Recherche d'entreprises »
 * (recherche-entreprises.api.gouv.fr), gratuite, sans clé, alimentée par
 * l'INSEE, l'INPI et la DILA.
 *
 * Elle ne dit pas si l'entreprise recrute — c'est le rôle de La Bonne Boîte,
 * dont l'accès API est soumis à une habilitation manuelle de France Travail.
 * En attendant cette habilitation, l'annuaire fournit au moins une liste
 * d'employeurs réels, filtrée par activité et par département.
 */
class CompanyDirectoryProvider
{
    private const ENDPOINT = 'https://recherche-entreprises.api.gouv.fr/search';

    /** Tranches d'effectif salarié INSEE. */
    private const EFFECTIFS = [
        'NN' => '0 salarié',
        '00' => '0 salarié',
        '01' => '1 à 2 salariés',
        '02' => '3 à 5 salariés',
        '03' => '6 à 9 salariés',
        '11' => '10 à 19 salariés',
        '12' => '20 à 49 salariés',
        '21' => '50 à 99 salariés',
        '22' => '100 à 199 salariés',
        '31' => '200 à 249 salariés',
        '32' => '250 à 499 salariés',
        '41' => '500 à 999 salariés',
        '42' => '1 000 à 1 999 salariés',
        '51' => '2 000 à 4 999 salariés',
        '52' => '5 000 à 9 999 salariés',
        '53' => '10 000 salariés et plus',
    ];

    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    public function label(): string
    {
        return 'Annuaire des entreprises';
    }

    /**
     * @return list<array{
     *     name: string,
     *     ville: string,
     *     secteur: string,
     *     effectif: string,
     *     categorie: string,
     *     siren: string,
     *     url: string
     * }>
     */
    public function search(SearchQuery $query, ?string $departement, int $limit = 6): array
    {
        if ($query->isEmpty()) {
            return [];
        }

        $params = [
            // L'annuaire cherche sur le nom et l'activité : on limite aux
            // mots significatifs pour éviter les requêtes trop larges.
            'q' => implode(' ', array_slice(preg_split('/\s+/u', trim($query->motsCles)) ?: [], 0, 4)),
            'page' => 1,
            'per_page' => max(1, min(25, $limit * 3)),
        ];

        if ($departement !== null && $departement !== '') {
            $params['departement'] = $departement;
        }

        try {
            $response = $this->httpClient->request('GET', self::ENDPOINT, [
                'query' => $params,
                'timeout' => 10,
                'max_duration' => 15,
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException(
                    'Annuaire des entreprises : réponse HTTP ' . $response->getStatusCode() . '.'
                );
            }

            $data = $response->toArray(false);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Annuaire des entreprises injoignable : ' . $e->getMessage(), 0, $e);
        }

        $results = $data['results'] ?? [];

        if (!is_array($results)) {
            return [];
        }

        $entreprises = [];

        foreach ($results as $item) {
            if (!is_array($item)) {
                continue;
            }

            $siren = (string) ($item['siren'] ?? '');
            $nom = $this->text((string) ($item['nom_complet'] ?? ''));

            if ($siren === '' || $nom === '') {
                continue;
            }

            if (($item['etat_administratif'] ?? 'A') !== 'A') {
                continue;
            }

            $siege = is_array($item['siege'] ?? null) ? $item['siege'] : [];

            if (($siege['etat_administratif'] ?? 'A') !== 'A') {
                continue;
            }

            // L'API filtre sur les établissements : quand l'entreprise n'a pas
            // son siège dans la zone, on affiche l'établissement local trouvé.
            $etablissement = $this->localEtablissement($item) ?? $siege;

            $effectifCode = (string) ($etablissement['tranche_effectif_salarie'] ?? '');
            $entrepriseEffectif = (string) ($item['tranche_effectif_salarie'] ?? '');

            // Un établissement peut ne pas déclarer d'effectif : on retombe
            // alors sur celui de l'entreprise pour rester lisible.
            if ($effectifCode === '' || in_array($effectifCode, ['NN', '00'], true)) {
                $effectifCode = $entrepriseEffectif;
            }

            $categorie = (string) ($item['categorie_entreprise'] ?? '');
            $naf = (string) ($item['activite_principale'] ?? $etablissement['activite_principale'] ?? '');
            $ville = $this->text((string) ($etablissement['libelle_commune'] ?? ''));
            $codePostal = (string) ($etablissement['code_postal'] ?? '');

            $entreprises[] = [
                'name' => $nom,
                'ville' => $codePostal !== '' ? $ville . ' (' . $codePostal . ')' : $ville,
                'secteur' => $naf,
                'effectif' => self::EFFECTIFS[$effectifCode] ?? '',
                'categorie' => match ($categorie) {
                    'PME' => 'PME',
                    'ETI' => 'ETI',
                    'GE' => 'Grande entreprise',
                    default => '',
                },
                'siren' => $siren,
                'url' => 'https://annuaire-entreprises.data.gouv.fr/entreprise/' . $siren,
            ];

            if (count($entreprises) >= $limit) {
                break;
            }
        }

        return $entreprises;
    }

    /**
     * Choisit l'établissement correspondant à la zone recherchée.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    private function localEtablissement(array $item): ?array
    {
        $etablissements = $item['matching_etablissements'] ?? null;

        if (!is_array($etablissements)) {
            return null;
        }

        foreach ($etablissements as $etablissement) {
            if (!is_array($etablissement)) {
                continue;
            }

            if (($etablissement['etat_administratif'] ?? 'A') !== 'A') {
                continue;
            }

            // On privilégie un établissement employeur : c'est lui qui recrute.
            if (($etablissement['caractere_employeur'] ?? 'O') === 'O') {
                return $etablissement;
            }
        }

        return is_array($etablissements[0] ?? null) ? $etablissements[0] : null;
    }

    private function text(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
