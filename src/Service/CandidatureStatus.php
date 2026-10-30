<?php

namespace App\Service;

final class CandidatureStatus
{
    public const A_POSTULER = 'a_postuler';
    public const CANDIDATURE_ENVOYEE = 'candidature_envoyee';
    public const ENTRETIEN = 'entretien';
    public const REFUS = 'refus';
    public const ACCEPTE = 'accepte';

    public const ALL = [
        self::A_POSTULER => ['label' => 'À postuler', 'icon' => '📝', 'color' => '#697187'],
        self::CANDIDATURE_ENVOYEE => ['label' => 'Candidature envoyée', 'icon' => '📨', 'color' => '#2563eb'],
        self::ENTRETIEN => ['label' => 'Entretien', 'icon' => '🗣️', 'color' => '#d97706'],
        self::REFUS => ['label' => 'Refus', 'icon' => '❌', 'color' => '#dc2626'],
        self::ACCEPTE => ['label' => 'Accepté', 'icon' => '🎉', 'color' => '#16a34a'],
    ];

    /** Statuts considérés comme « terminés » (plus de relance nécessaire). */
    public const FINAL = [self::REFUS, self::ACCEPTE];

    public static function all(): array
    {
        return self::ALL;
    }

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

    public static function meta(string $status): array
    {
        return self::ALL[$status] ?? ['label' => $status, 'icon' => '•', 'color' => '#697187'];
    }
}
