<?php

namespace App\Service;

/**
 * Statuts de suivi pour les candidatures spontanées.
 *
 * Le statut est la source de vérité : « À envoyer » est un état à part entière,
 * distinct de « En attente de réponse ».
 */
final class SpontaneousStatus
{
    public const A_ENVOYER = 'a_envoyer';
    public const EN_ATTENTE = 'en_attente';
    public const RELANCE = 'relance_envoyee';
    public const POSITIVE = 'reponse_positive';
    public const NEGATIVE = 'reponse_negative';

    public const ALL = [
        self::A_ENVOYER => ['label' => 'À envoyer', 'icon' => '📮', 'color' => '#697187', 'class' => 'neutral'],
        self::EN_ATTENTE => ['label' => 'En attente de réponse', 'icon' => '⏳', 'color' => '#0369a1', 'class' => 'info'],
        self::RELANCE => ['label' => 'Relance envoyée', 'icon' => '🔁', 'color' => '#b45309', 'class' => 'warn'],
        self::POSITIVE => ['label' => 'Réponse positive', 'icon' => '✅', 'color' => '#15803d', 'class' => 'success'],
        self::NEGATIVE => ['label' => 'Réponse négative', 'icon' => '❌', 'color' => '#b4233a', 'class' => 'danger'],
    ];

    /** Statuts pour lesquels une réponse a été reçue. */
    public const WITH_ANSWER = [self::POSITIVE, self::NEGATIVE];

    /**
     * @return array<string, array{label: string, icon: string, color: string, class: string}>
     */
    public static function all(): array
    {
        return self::ALL;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    public static function isValid(string $status): bool
    {
        return isset(self::ALL[$status]);
    }

    public static function label(string $status): string
    {
        return self::ALL[$status]['label'] ?? $status;
    }

    /**
     * @return array{label: string, icon: string, color: string, class: string}
     */
    public static function meta(string $status): array
    {
        return self::ALL[$status] ?? [
            'label' => $status,
            'icon' => '•',
            'color' => '#72798d',
            'class' => 'neutral',
        ];
    }

    /**
     * Indique si une réponse (positive ou négative) a été reçue.
     *
     * @param array<string, mixed> $item
     */
    public static function hasAnswer(array $item): bool
    {
        return in_array($item['reponse'] ?? '', self::WITH_ANSWER, true);
    }
}
