<?php

namespace App\Service\JobOffer;

/**
 * Contrat commun à tous les fournisseurs d'offres d'emploi.
 *
 * Ajouter une source (Adzuna, Jooble, autre) revient à créer une classe
 * qui implémente cette interface : l'orchestrateur n'a pas à être modifié.
 */
interface JobOfferProviderInterface
{
    /** Nom technique, utilisé pour les logs et le cache. */
    public function name(): string;

    /** Nom affiché dans l'interface. */
    public function label(): string;

    /** Vrai si la configuration (clé API, identifiants) est présente. */
    public function isConfigured(): bool;

    /**
     * Retourne des offres normalisées.
     *
     * Une erreur réseau ou un refus de l'API doit lever une exception :
     * c'est l'orchestrateur qui décide comment l'afficher. Renvoyer un
     * tableau vide doit signifier « aucune offre », jamais « erreur ».
     *
     * @return list<array{
     *     title: string,
     *     company: string,
     *     url: string,
     *     location: string,
     *     salary: string,
     *     type: string,
     *     date: string,
     *     description: string,
     *     source: string
     * }>
     */
    public function search(SearchQuery $query, int $limit): array;
}
