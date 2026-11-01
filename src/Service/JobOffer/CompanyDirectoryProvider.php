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
 * En attendant cette habilitation, l'annuaire fournit des employeurs réels,
 * filtrés par **activité (codes NAF)** et par département.
 *
 * Point clé : l'API ne cherche que sur le nom et l'activité des sociétés.
 * Une requête issue du CV (« Développeur Web Full Stack PHP ») ne correspond
 * à aucun nom d'entreprise et renvoie donc zéro résultat. On traduit d'abord
 * la recherche en codes NAF via ActivitySectorMapper, puis on élargit
 * progressivement (section NAF, mots-clés, national).
 */
class CompanyDirectoryProvider
{
    private const ENDPOINT = 'https://recherche-entreprises.api.gouv.fr/search';

    /** Nombre maximal de résultats demandés par appel (limite de l'API). */
    private const PER_PAGE = 25;

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

    /** Message à afficher quand la recherche a dû être élargie. */
    private ?string $notice = null;

    public function __construct(
        private HttpClientInterface $httpClient,
        private ActivitySectorMapper $sectors,
    ) {
    }

    public function label(): string
    {
        return 'Annuaire des entreprises';
    }

    /** Complément d'information sur la dernière recherche (élargissement…). */
    public function lastNotice(): ?string
    {
        return $this->notice;
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
        $this->notice = null;

        if ($query->isEmpty()) {
            return [];
        }

        $limit = max(1, $limit);
        $secteur = $this->sectors->detect($query->motsCles);
        $metier = $this->sectors->detectActivity($query->motsCles);

        // Tentatives du plus précis au plus large : on s'arrête dès qu'on a
        // assez d'employeurs. Chaque tentative est une requête HTTP distincte.
        $tentatives = [];

        if ($metier !== null) {
            $tentatives[] = ['params' => ['q' => $metier['q']], 'local' => true];
            $tentatives[] = [
                'params' => ['activite_principale' => implode(',', $metier['naf'])],
                'local' => true,
            ];
        }

        if ($secteur !== null) {
            $tentatives[] = [
                'params' => ['activite_principale' => implode(',', $secteur['naf'])],
                'local' => true,
            ];
            $tentatives[] = [
                'params' => ['section_activite_principale' => $secteur['section']],
                'local' => true,
            ];
        }

        foreach ($this->sectors->keywords($query->motsCles, 3) as $motCle) {
            // Sans secteur ni métier reconnu, une recherche par mot-clé ne
            // ramènerait que des homonymes (ex. « Pilote de ligne » →
            // « CO-PILOTE ») : mieux vaut afficher franchement zéro résultat.
            if ($secteur === null && $metier === null) {
                break;
            }

            $tentatives[] = ['params' => ['q' => $motCle], 'local' => true];
        }

        // Repli national : sans lui, une ville dont le département compte peu
        // d'entreprises du secteur renvoie une liste vide.
        if ($departement !== null && $departement !== '') {
            foreach (array_slice($tentatives, 0, 2) as $tentative) {
                $tentative['local'] = false;
                $tentatives[] = $tentative;
            }
        }

        $codesConnus = array_values(array_unique(array_merge(
            $metier['naf'] ?? [],
            $secteur['naf'] ?? [],
        )));

        $entreprises = [];
        $dejaVues = [];
        $homonymes = [];
        $elargi = false;

        foreach ($tentatives as $tentative) {
            if (count($entreprises) >= $limit) {
                break;
            }

            $local = (bool) $tentative['local'];
            $params = $tentative['params'];

            if ($local && $departement !== null && $departement !== '') {
                $params['departement'] = $departement;
            }

            foreach ($this->fetch($params) as $item) {
                $entreprise = $this->normalize($item);

                if ($entreprise === null || isset($dejaVues[$entreprise['siren']])) {
                    continue;
                }

                // Une recherche plein texte ramène des homonymes (« AUTO ET
                // COMMUNICATION » pour un chargé de communication). On les met
                // de côté : ils ne serviront qu'à compléter la liste.
                if (isset($params['q']) && $codesConnus !== []
                    && !in_array($entreprise['secteur'], $codesConnus, true)
                ) {
                    $homonymes[$entreprise['siren']] = $entreprise;
                    continue;
                }

                $dejaVues[$entreprise['siren']] = true;
                $entreprises[] = $entreprise;

                if (count($entreprises) >= $limit) {
                    break;
                }
            }

            if (!$local && $entreprises !== []) {
                $elargi = true;
            }
        }

        // Complète avec les homonymes uniquement si le secteur n'a pas suffi.
        foreach ($homonymes as $siren => $entreprise) {
            if (count($entreprises) >= $limit) {
                break;
            }

            if (isset($dejaVues[$siren])) {
                continue;
            }

            $dejaVues[$siren] = true;
            $entreprises[] = $entreprise;
        }

        if ($elargi) {
            $this->notice = 'Peu d’entreprises de ce secteur dans le département : '
                . 'la liste a été élargie au niveau national.';
        }

        if ($entreprises === [] && $secteur === null && $metier === null) {
            $this->notice = 'Métier non reconnu : précisez l’intitulé du poste '
                . '(par exemple « développeur web », « comptable », « infirmier ») '
                . 'pour obtenir des entreprises ciblées.';
        }

        // Les structures sans salarié sont de simples holdings ou coquilles :
        // on les classe après les vrais employeurs.
        usort(
            $entreprises,
            fn (array $a, array $b): int => $this->rank($b) <=> $this->rank($a)
        );

        return array_slice($entreprises, 0, $limit);
    }

    /**
     * @param array<string, string|int|bool> $params
     * @return list<array<string, mixed>>
     */
    private function fetch(array $params): array
    {
        $params += [
            'page' => 1,
            'per_page' => self::PER_PAGE,
        ];

        try {
            $response = $this->httpClient->request('GET', self::ENDPOINT, [
                'query' => $params,
                'timeout' => 10,
                'max_duration' => 15,
            ]);

            if ($response->getStatusCode() !== 200) {
                // Une tentative invalide ne doit pas casser les suivantes :
                // on renvoie une liste vide et l'orchestrateur voit l'état final.
                return [];
            }

            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Annuaire des entreprises injoignable : ' . $e->getMessage(), 0, $e);
        }

        $results = $data['results'] ?? [];

        return is_array($results) ? array_values(array_filter($results, 'is_array')) : [];
    }

    /**
     * @param array<string, mixed> $item
     * @return array{
     *     name: string,
     *     ville: string,
     *     secteur: string,
     *     effectif: string,
     *     categorie: string,
     *     siren: string,
     *     url: string
     * }|null
     */
    private function normalize(array $item): ?array
    {
        $siren = (string) ($item['siren'] ?? '');
        $nom = $this->text((string) ($item['nom_complet'] ?? $item['nom_raison_sociale'] ?? ''));

        if ($siren === '' || $nom === '') {
            return null;
        }

        if (($item['etat_administratif'] ?? 'A') !== 'A') {
            return null;
        }

        $siege = is_array($item['siege'] ?? null) ? $item['siege'] : [];

        if (($siege['etat_administratif'] ?? 'A') !== 'A') {
            return null;
        }

        // L'API filtre sur les établissements : quand l'entreprise n'a pas
        // son siège dans la zone, on affiche l'établissement local trouvé.
        $etablissement = $this->localEtablissement($item) ?? $siege;

        // Une entreprise sans caractère employeur ni salarié n'a personne à
        // qui adresser une candidature spontanée : on l'écarte.
        $employeur = ($etablissement['caractere_employeur'] ?? '') === 'O'
            || ($siege['caractere_employeur'] ?? '') === 'O';

        $effectifCode = (string) ($etablissement['tranche_effectif_salarie'] ?? '');
        $entrepriseEffectif = (string) ($item['tranche_effectif_salarie'] ?? '');

        // Un établissement peut ne pas déclarer d'effectif : on retombe
        // alors sur celui de l'entreprise pour rester lisible.
        if ($effectifCode === '' || in_array($effectifCode, ['NN', '00'], true)) {
            $effectifCode = $entrepriseEffectif;
        }

        if (!$employeur && in_array($effectifCode, ['', 'NN', '00'], true)) {
            return null;
        }

        $categorie = (string) ($item['categorie_entreprise'] ?? '');
        $naf = (string) ($item['activite_principale'] ?? $etablissement['activite_principale'] ?? '');
        $ville = $this->text((string) ($etablissement['libelle_commune'] ?? ''));
        $codePostal = (string) ($etablissement['code_postal'] ?? '');

        return [
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

    /**
     * Vrai employeur (au moins un salarié) ou structure vide.
     *
     * @param array<string, mixed> $entreprise
     */
    private function rank(array $entreprise): int
    {
        $label = (string) ($entreprise['effectif'] ?? '');

        return $label === '' || $label === '0 salarié' ? 0 : 1;
    }
}
