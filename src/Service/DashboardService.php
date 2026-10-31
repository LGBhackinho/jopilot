<?php

namespace App\Service;

use App\Repository\AiResultRepository;
use App\Repository\CandidatureRepository;
use App\Repository\CvProfileRepository;
use App\Repository\SpontaneousApplicationRepository;

/**
 * Agrège les données locales de l'application pour le tableau de bord d'accueil.
 *
 * Aucun appel réseau n'est effectué ici : le tableau de bord doit rester rapide.
 */
class DashboardService
{
    /**
     * Libellés et destinations des différents résultats IA.
     *
     * @var array<string, array{label: string, icon: string, route: string, params: array<string, string>}>
     */
    private const AI_LABELS = [
        'cv_analysis' => ['label' => 'Analyse du CV', 'icon' => '📊', 'route' => 'app_cv_analyser', 'params' => ['tab' => 'analyse']],
        'offer_analysis' => ['label' => 'Analyse d’une annonce', 'icon' => '🧠', 'route' => 'app_offer_analyser', 'params' => []],
        'assistant_match' => ['label' => 'Matching CV ↔ offre', 'icon' => '🎯', 'route' => 'app_assistant', 'params' => []],
        'assistant_letter' => ['label' => 'Lettre de motivation', 'icon' => '✍️', 'route' => 'app_assistant', 'params' => []],
        'interview_questions' => ['label' => 'Questions d’entretien', 'icon' => '🗣️', 'route' => 'app_interview', 'params' => []],
        'interview_feedback' => ['label' => 'Retour d’entraînement', 'icon' => '⚡', 'route' => 'app_interview', 'params' => []],
        'spontaneous_letter' => ['label' => 'Lettre spontanée', 'icon' => '📮', 'route' => 'app_spontaneous_index', 'params' => []],
    ];

    public function __construct(
        private CandidatureRepository $candidatures,
        private SpontaneousApplicationRepository $spontaneous,
        private CvProfileRepository $cvProfile,
        private AiResultRepository $aiResults,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $today = new \DateTimeImmutable('today');
        $candidatures = $this->candidatures->all();
        $spontaneous = $this->spontaneous->all();
        $cv = $this->cvProfile->get();
        $aiSummaries = $this->aiResults->summaries();

        $candidatureStats = $this->buildCandidatureStats($candidatures, $today);
        $spontaneousStats = $this->buildSpontaneousStats($spontaneous, $today);
        $cvStats = $this->buildCvStats($cv);
        $aiStats = $this->buildAiStats($aiSummaries);
        $activity = $this->buildActivity($candidatures, $spontaneous, $today);

        return [
            'today' => $today,
            'cv' => $cvStats,
            'candidatures' => $candidatureStats,
            'spontaneous' => $spontaneousStats,
            'ai' => $aiStats,
            'activity' => $activity,
            'donut' => $this->buildDonut($candidatureStats['by_status']),
            'todos' => $this->buildTodos($cvStats, $candidatureStats, $spontaneousStats, $aiStats),
            'timeline' => $this->buildTimeline($candidatures, $spontaneous, $aiSummaries),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function buildCandidatureStats(array $items, \DateTimeImmutable $today): array
    {
        $byStatus = [];

        foreach (CandidatureStatus::all() as $key => $meta) {
            $byStatus[$key] = [
                ...$meta,
                'count' => 0,
                'percent' => 0,
            ];
        }

        $active = 0;
        $interviews = 0;
        $relancesRetard = [];
        $relancesProches = [];

        foreach ($items as $item) {
            $status = (string) ($item['statut'] ?? CandidatureStatus::A_POSTULER);

            if (!CandidatureStatus::isValid($status)) {
                $status = CandidatureStatus::A_POSTULER;
            }

            ++$byStatus[$status]['count'];

            if (!in_array($status, CandidatureStatus::FINAL, true)) {
                ++$active;
            }

            if ($status === CandidatureStatus::ENTRETIEN) {
                ++$interviews;
            }

            if (in_array($status, CandidatureStatus::FINAL, true)) {
                continue;
            }

            $dateRelance = $this->date($item['date_relance'] ?? null);

            if ($dateRelance === null) {
                continue;
            }

            $days = (int) $today->diff($dateRelance)->format('%r%a');
            $entry = [
                'id' => (string) ($item['id'] ?? ''),
                'label' => $this->candidatureLabel($item),
                'days' => $days,
                'date' => $dateRelance,
            ];

            if ($days < 0) {
                $relancesRetard[] = $entry;
            } elseif ($days <= 7) {
                $relancesProches[] = $entry;
            }
        }

        $total = count($items);

        foreach ($byStatus as &$row) {
            $row['percent'] = $total > 0 ? (int) round($row['count'] / $total * 100) : 0;
        }
        unset($row);

        usort($relancesRetard, static fn (array $a, array $b): int => $a['days'] <=> $b['days']);
        usort($relancesProches, static fn (array $a, array $b): int => $a['days'] <=> $b['days']);

        $recent = $items;
        usort($recent, static function (array $a, array $b): int {
            return strcmp(
                (string) ($b['updated_at'] ?? $b['created_at'] ?? ''),
                (string) ($a['updated_at'] ?? $a['created_at'] ?? '')
            );
        });

        return [
            'total' => $total,
            'active' => $active,
            'interviews' => $interviews,
            'a_postuler' => $byStatus[CandidatureStatus::A_POSTULER]['count'],
            'envoyees' => $byStatus[CandidatureStatus::CANDIDATURE_ENVOYEE]['count'],
            'by_status' => $byStatus,
            'relances_retard' => $relancesRetard,
            'relances_proches' => $relancesProches,
            'recent' => array_slice($recent, 0, 4),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function buildSpontaneousStats(array $items, \DateTimeImmutable $today): array
    {
        $byStatus = [];

        foreach (SpontaneousStatus::all() as $key => $meta) {
            $byStatus[$key] = [
                ...$meta,
                'count' => 0,
                'percent' => 0,
            ];
        }

        $letters = 0;
        $aRelancer = [];

        foreach ($items as $item) {
            $status = (string) ($item['reponse'] ?? SpontaneousStatus::A_ENVOYER);

            if (!SpontaneousStatus::isValid($status)) {
                $status = SpontaneousStatus::A_ENVOYER;
            }

            ++$byStatus[$status]['count'];

            if (trim((string) ($item['lettre_texte'] ?? '')) !== '') {
                ++$letters;
            }

            // Sans réponse depuis plus de 21 jours : une relance est pertinente.
            if ($status === SpontaneousStatus::EN_ATTENTE) {
                $dateEnvoi = $this->date($item['date_envoi'] ?? null);

                if ($dateEnvoi !== null && (int) $dateEnvoi->diff($today)->format('%r%a') > 21) {
                    $aRelancer[] = [
                        'id' => (string) ($item['id'] ?? ''),
                        'label' => (string) ($item['nom_societe'] ?? 'Société'),
                        'days' => (int) $dateEnvoi->diff($today)->format('%a'),
                    ];
                }
            }
        }

        $total = count($items);

        foreach ($byStatus as &$row) {
            $row['percent'] = $total > 0 ? (int) round($row['count'] / $total * 100) : 0;
        }
        unset($row);

        usort($aRelancer, static fn (array $a, array $b): int => $b['days'] <=> $a['days']);

        return [
            'total' => $total,
            'a_envoyer' => $byStatus[SpontaneousStatus::A_ENVOYER]['count'],
            'en_cours' => $byStatus[SpontaneousStatus::EN_ATTENTE]['count'] + $byStatus[SpontaneousStatus::RELANCE]['count'],
            'reponses' => $byStatus[SpontaneousStatus::POSITIVE]['count'] + $byStatus[SpontaneousStatus::NEGATIVE]['count'],
            'positives' => $byStatus[SpontaneousStatus::POSITIVE]['count'],
            'negatives' => $byStatus[SpontaneousStatus::NEGATIVE]['count'],
            'lettres' => $letters,
            'a_relancer' => $aRelancer,
            'by_status' => $byStatus,
        ];
    }

    /**
     * @param array{cv_text: string, cv_filename: string, nom_candidat: string, updated_at: string} $cv
     * @return array<string, mixed>
     */
    private function buildCvStats(array $cv): array
    {
        $hasCv = trim((string) $cv['cv_text']) !== '';
        $analysis = $hasCv
            ? $this->aiResults->get('cv_analysis', ['cv_text' => $cv['cv_text']])
            : null;
        $latest = $this->aiResults->latest('cv_analysis');

        return [
            'has_cv' => $hasCv,
            'filename' => (string) $cv['cv_filename'],
            'updated_at' => (string) $cv['updated_at'],
            'nom_candidat' => (string) $cv['nom_candidat'],
            'analysis_at' => $analysis['updated_at'] ?? null,
            'analysis_current' => $analysis !== null,
            'analysis_outdated' => $hasCv && $analysis === null && $latest !== null,
            'latest_analysis_at' => $latest['updated_at'] ?? null,
        ];
    }

    /**
     * @param array<int, array{namespace: string, created_at: string, updated_at: string}> $summaries
     * @return array<string, mixed>
     */
    private function buildAiStats(array $summaries): array
    {
        $byNamespace = [];
        $lastAt = null;

        foreach ($summaries as $summary) {
            $namespace = $summary['namespace'];
            $label = self::AI_LABELS[$namespace] ?? [
                'label' => 'Résultat IA',
                'icon' => '✨',
                'route' => 'app_home',
                'params' => [],
            ];

            if (!isset($byNamespace[$namespace])) {
                $byNamespace[$namespace] = [
                    ...$label,
                    'namespace' => $namespace,
                    'count' => 0,
                    'last_at' => $summary['updated_at'],
                ];
            }

            ++$byNamespace[$namespace]['count'];

            if ($lastAt === null || strcmp($summary['updated_at'], $lastAt) > 0) {
                $lastAt = $summary['updated_at'];
            }
        }

        uasort($byNamespace, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return [
            'total' => count($summaries),
            'by_namespace' => array_values($byNamespace),
            'last_at' => $lastAt,
        ];
    }

    /**
     * Activité combinée des 8 dernières semaines.
     *
     * @param array<int, array<string, mixed>> $candidatures
     * @param array<int, array<string, mixed>> $spontaneous
     * @return array<string, mixed>
     */
    private function buildActivity(array $candidatures, array $spontaneous, \DateTimeImmutable $today): array
    {
        $currentWeek = $today->modify('monday this week');
        $firstWeek = $currentWeek->modify('-7 weeks');

        $weeks = [];

        for ($i = 0; $i < 8; ++$i) {
            $start = $firstWeek->modify(sprintf('+%d weeks', $i));
            $weeks[$start->format('Y-m-d')] = [
                'start' => $start,
                'label' => $start->format('d/m'),
                'candidatures' => 0,
                'spontanees' => 0,
                'total' => 0,
            ];
        }

        foreach ($candidatures as $item) {
            $date = $this->date($item['date_candidature'] ?? null)
                ?? $this->date($item['created_at'] ?? null);

            if ($date === null) {
                continue;
            }

            $key = $date->modify('monday this week')->format('Y-m-d');

            if (isset($weeks[$key])) {
                ++$weeks[$key]['candidatures'];
                ++$weeks[$key]['total'];
            }
        }

        foreach ($spontaneous as $item) {
            $date = $this->date($item['date_envoi'] ?? null)
                ?? $this->date($item['created_at'] ?? null);

            if ($date === null) {
                continue;
            }

            $key = $date->modify('monday this week')->format('Y-m-d');

            if (isset($weeks[$key])) {
                ++$weeks[$key]['spontanees'];
                ++$weeks[$key]['total'];
            }
        }

        $max = 0;

        foreach ($weeks as $week) {
            $max = max($max, $week['total']);
        }

        foreach ($weeks as &$week) {
            $week['height'] = $week['total'] > 0 && $max > 0
                ? max(4, (int) round($week['total'] / $max * 100))
                : 0;
            $week['candidatures_share'] = $week['total'] > 0
                ? (int) round($week['candidatures'] / $week['total'] * 100)
                : 0;
            $week['spontanees_share'] = $week['total'] > 0 ? 100 - $week['candidatures_share'] : 0;
        }
        unset($week);

        $values = array_values($weeks);
        $current = $values[7] ?? null;
        $previous = $values[6] ?? null;

        return [
            'weeks' => $values,
            'max' => $max,
            'current' => $current['total'] ?? 0,
            'previous' => $previous['total'] ?? 0,
        ];
    }

    /**
     * @param array<string, array{label: string, icon: string, color: string, count: int, percent: int}> $byStatus
     * @return array<string, mixed>
     */
    private function buildDonut(array $byStatus): array
    {
        $total = array_sum(array_column($byStatus, 'count'));

        if ($total === 0) {
            return [
                'total' => 0,
                'gradient' => 'conic-gradient(var(--border) 0% 100%)',
                'legend' => [],
            ];
        }

        $segments = [];
        $legend = [];
        $cursor = 0.0;

        foreach ($byStatus as $status => $row) {
            if ($row['count'] === 0) {
                continue;
            }

            $start = $cursor;
            $cursor += $row['count'] / $total * 100;

            $segments[] = sprintf('%s %.2f%% %.2f%%', $row['color'], $start, $cursor);
            $legend[] = [
                'status' => $status,
                'label' => $row['label'],
                'icon' => $row['icon'],
                'color' => $row['color'],
                'count' => $row['count'],
                'percent' => $row['percent'],
            ];
        }

        return [
            'total' => $total,
            'gradient' => 'conic-gradient(' . implode(', ', $segments) . ')',
            'legend' => $legend,
        ];
    }

    /**
     * Propositions d'actions concrètes, triées par priorité.
     *
     * @param array<string, mixed> $cv
     * @param array<string, mixed> $candidatures
     * @param array<string, mixed> $spontaneous
     * @param array<string, mixed> $ai
     * @return array<int, array<string, mixed>>
     */
    private function buildTodos(array $cv, array $candidatures, array $spontaneous, array $ai): array
    {
        $todos = [];

        if (!$cv['has_cv']) {
            $todos[] = [
                'priority' => 100,
                'level' => 'danger',
                'icon' => '📄',
                'title' => 'Importez votre CV',
                'text' => 'Il sert de base à toutes les analyses et permet à l’IA de ne jamais inventer votre parcours.',
                'route' => 'app_cv_analyser',
                'params' => [],
                'cta' => 'Ouvrir Mon CV',
            ];
        } elseif (!$cv['analysis_current']) {
            $todos[] = [
                'priority' => 90,
                'level' => $cv['analysis_outdated'] ? 'warn' : 'info',
                'icon' => '📊',
                'title' => $cv['analysis_outdated'] ? 'Votre analyse CV est obsolète' : 'Analysez votre CV',
                'text' => $cv['analysis_outdated']
                    ? 'Le CV a changé depuis la dernière analyse. Relancez-la pour obtenir des résultats à jour.'
                    : 'Obtenez une synthèse de votre profil, vos compétences et vos pistes d’amélioration.',
                'route' => 'app_cv_analyser',
                'params' => ['tab' => 'analyse'],
                'cta' => $cv['analysis_outdated'] ? 'Relancer l’analyse' : 'Analyser mon CV',
            ];
        }

        if ($candidatures['interviews'] > 0) {
            $todos[] = [
                'priority' => 85,
                'level' => 'info',
                'icon' => '🗣️',
                'title' => sprintf(
                    '%d entretien%s à préparer',
                    $candidatures['interviews'],
                    $candidatures['interviews'] > 1 ? 's' : ''
                ),
                'text' => 'Générez des questions adaptées à l’offre et entraînez-vous avec un retour immédiat.',
                'route' => 'app_interview',
                'params' => [],
                'cta' => 'Préparer mes entretiens',
            ];
        }

        if ($candidatures['relances_retard'] !== []) {
            $names = array_slice(array_column($candidatures['relances_retard'], 'label'), 0, 3);
            $todos[] = [
                'priority' => 95,
                'level' => 'danger',
                'icon' => '⏰',
                'title' => sprintf(
                    '%d relance%s en retard',
                    count($candidatures['relances_retard']),
                    count($candidatures['relances_retard']) > 1 ? 's' : ''
                ),
                'text' => 'À relancer : ' . implode(', ', $names) . '.',
                'route' => 'app_candidature_index',
                'params' => [],
                'cta' => 'Voir mes candidatures',
            ];
        }

        if ($candidatures['relances_proches'] !== []) {
            $todos[] = [
                'priority' => 80,
                'level' => 'warn',
                'icon' => '📅',
                'title' => sprintf(
                    '%d relance%s dans les 7 jours',
                    count($candidatures['relances_proches']),
                    count($candidatures['relances_proches']) > 1 ? 's' : ''
                ),
                'text' => 'Préparez vos messages pour ne pas laisser passer le bon moment.',
                'route' => 'app_candidature_index',
                'params' => [],
                'cta' => 'Voir le suivi',
            ];
        }

        if ($candidatures['a_postuler'] > 0) {
            $todos[] = [
                'priority' => 70,
                'level' => 'info',
                'icon' => '📝',
                'title' => sprintf(
                    '%d candidature%s à postuler',
                    $candidatures['a_postuler'],
                    $candidatures['a_postuler'] > 1 ? 's' : ''
                ),
                'text' => 'Des offres sont enregistrées mais pas encore envoyées.',
                'route' => 'app_candidature_index',
                'params' => [],
                'cta' => 'Passer à l’action',
            ];
        }

        if ($candidatures['total'] === 0) {
            $todos[] = [
                'priority' => 50,
                'level' => 'info',
                'icon' => '➕',
                'title' => 'Commencez votre suivi de candidatures',
                'text' => 'Ajoutez une offre pour suivre son statut, les relances et les entretiens au même endroit.',
                'route' => 'app_candidature_new',
                'params' => [],
                'cta' => 'Ajouter une candidature',
            ];
        }

        if ($spontaneous['a_relancer'] !== []) {
            $names = array_slice(array_column($spontaneous['a_relancer'], 'label'), 0, 3);
            $todos[] = [
                'priority' => 65,
                'level' => 'warn',
                'icon' => '🔁',
                'title' => sprintf(
                    '%d société%s sans réponse depuis 21 jours',
                    count($spontaneous['a_relancer']),
                    count($spontaneous['a_relancer']) > 1 ? 's' : ''
                ),
                'text' => 'Pensez à relancer : ' . implode(', ', $names) . '.',
                'route' => 'app_spontaneous_index',
                'params' => [],
                'cta' => 'Voir les spontanées',
            ];
        }

        if ($spontaneous['a_envoyer'] > 0) {
            $todos[] = [
                'priority' => 60,
                'level' => 'info',
                'icon' => '📮',
                'title' => sprintf(
                    '%d candidature%s spontanée%s à envoyer',
                    $spontaneous['a_envoyer'],
                    $spontaneous['a_envoyer'] > 1 ? 's' : '',
                    $spontaneous['a_envoyer'] > 1 ? 's' : ''
                ),
                'text' => 'Des sociétés sont enregistrées mais n’ont pas encore reçu votre candidature.',
                'route' => 'app_spontaneous_index',
                'params' => [],
                'cta' => 'Ouvrir le suivi',
            ];
        }

        if ($ai['total'] === 0 && $cv['has_cv']) {
            $todos[] = [
                'priority' => 40,
                'level' => 'info',
                'icon' => '🎯',
                'title' => 'Testez le matching CV ↔ offre',
                'text' => 'Collez une annonce pour obtenir un score d’adéquation et des conseils concrets.',
                'route' => 'app_assistant',
                'params' => [],
                'cta' => 'Matcher une offre',
            ];
        }

        if ($todos === []) {
            $todos[] = [
                'priority' => 10,
                'level' => 'success',
                'icon' => '✅',
                'title' => 'Tout est à jour',
                'text' => 'Aucune action urgente. Profitez-en pour chercher de nouvelles offres et préparer un entretien.',
                'route' => 'app_cv_analyser',
                'params' => ['tab' => 'offres'],
                'cta' => 'Chercher des offres',
            ];
        }

        usort($todos, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

        return array_slice($todos, 0, 4);
    }

    /**
     * @param array<int, array<string, mixed>> $candidatures
     * @param array<int, array<string, mixed>> $spontaneous
     * @param array<int, array{namespace: string, created_at: string, updated_at: string}> $aiSummaries
     * @return array<int, array<string, mixed>>
     */
    private function buildTimeline(array $candidatures, array $spontaneous, array $aiSummaries): array
    {
        $items = [];

        foreach ($candidatures as $item) {
            $status = (string) ($item['statut'] ?? CandidatureStatus::A_POSTULER);
            $meta = CandidatureStatus::meta($status);

            $items[] = [
                'at' => (string) ($item['updated_at'] ?? $item['created_at'] ?? ''),
                'icon' => $meta['icon'],
                'title' => $this->candidatureLabel($item),
                'subtitle' => 'Candidature · ' . $meta['label'],
                'route' => 'app_candidature_index',
                'params' => [],
            ];
        }

        foreach ($spontaneous as $item) {
            $status = (string) ($item['reponse'] ?? SpontaneousStatus::A_ENVOYER);
            $meta = SpontaneousStatus::meta($status);
            $hasLetter = trim((string) ($item['lettre_texte'] ?? '')) !== '';

            $items[] = [
                'at' => (string) ($item['updated_at'] ?? $item['created_at'] ?? ''),
                'icon' => $meta['icon'],
                'title' => (string) ($item['nom_societe'] ?? 'Société'),
                'subtitle' => 'Spontanée · ' . $meta['label'],
                'route' => $hasLetter ? 'app_spontaneous_letter' : 'app_spontaneous_index',
                'params' => $hasLetter ? ['id' => (string) ($item['id'] ?? '')] : [],
            ];
        }

        foreach ($aiSummaries as $summary) {
            $namespace = $summary['namespace'];
            $label = self::AI_LABELS[$namespace] ?? [
                'label' => 'Résultat IA',
                'icon' => '✨',
                'route' => 'app_home',
                'params' => [],
            ];

            $items[] = [
                'at' => $summary['updated_at'],
                'icon' => $label['icon'],
                'title' => $label['label'],
                'subtitle' => 'Proposition IA enregistrée',
                'route' => $label['route'],
                'params' => $label['params'],
            ];
        }

        $items = array_values(array_filter($items, static fn (array $item): bool => $item['at'] !== ''));

        usort($items, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return array_slice($items, 0, 8);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function candidatureLabel(array $item): string
    {
        $poste = trim((string) ($item['poste'] ?? ''));
        $entreprise = trim((string) ($item['entreprise'] ?? ''));

        if ($poste !== '' && $entreprise !== '') {
            return $poste . ' · ' . $entreprise;
        }

        return $poste !== '' ? $poste : ($entreprise !== '' ? $entreprise : 'Candidature');
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
