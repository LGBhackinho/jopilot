<?php

namespace App\Service\JobOffer;

/**
 * Critères communs envoyés aux fournisseurs d'offres.
 *
 * Objet immuable : chaque fournisseur reçoit exactement la même recherche
 * et ne peut pas la modifier pour les suivants.
 */
final class SearchQuery
{
    public function __construct(
        public readonly string $motsCles,
        public readonly string $ville = '',
        public readonly ?string $codeInsee = null,
        public readonly int $rayon = 30,
    ) {
    }

    public function isEmpty(): bool
    {
        return trim($this->motsCles) === '';
    }

    /**
     * Ville exploitable par les APIs d'offres.
     *
     * L'analyse du CV renvoie parfois une liste de communes
     * (« Poitiers, Chasseneuil-du-Poitou, Vivonne, Poitiers »). Adzuna
     * géocode `where` comme un lieu unique : lui passer la liste donne
     * systématiquement 0 résultat. On ne garde donc que la commune
     * principale, celle autour de laquelle le rayon s'applique.
     */
    public function villePrincipale(): string
    {
        $premiere = trim(preg_split('/[,;]/', $this->ville)[0] ?? '');
        $premiere = preg_replace('/^\d{4,5}\s+/', '', $premiere) ?? $premiere;

        return trim($premiere);
    }
}
