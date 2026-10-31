<?php

namespace App\Controller;

use App\Repository\AiResultRepository;
use App\Repository\CvProfileRepository;
use App\Service\InterviewCoach;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class InterviewController extends AbstractController
{
    private const QUESTIONS_NAMESPACE = 'interview_questions';
    private const FEEDBACK_NAMESPACE = 'interview_feedback';

    #[Route('/entretien', name: 'app_interview', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        InterviewCoach $coach,
        CvProfileRepository $cvProfile,
        AiResultRepository $aiResults,
    ): Response {
        $erreur = null;
        $offre = '';
        $profile = $cvProfile->get();
        $cvText = $profile['cv_text'];
        $action = null;
        $questions = [];
        $extras = [];
        $feedback = null;
        $generatedAt = null;
        $fromCache = false;

        if ($request->getSession()->has('interview_questions')) {
            $questions = $request->getSession()->get('interview_questions');
        }
        if ($request->getSession()->has('interview_extras')) {
            $extras = $request->getSession()->get('interview_extras');
        }

        if ($request->isMethod('POST')) {
            $action = (string) $request->request->get('action', 'questions');
            $offre = (string) $request->request->get('offre', '');
            $postedCv = $request->request->get('cv_text');

            if (is_string($postedCv)) {
                $cvText = $postedCv;
            }

            if (trim($cvText) !== '') {
                $cvProfile->saveText($cvText);
            }

            if ($action === 'questions') {
                $questions = [];
                $extras = [];
                $request->getSession()->remove('interview_questions');
                $request->getSession()->remove('interview_extras');
                $request->getSession()->set('interview_offre', $offre);

                try {
                    $input = ['cv_text' => $cvText, 'offre' => $offre];
                    $force = (bool) $request->request->get('force', false);
                    $cached = $force ? null : $aiResults->get(self::QUESTIONS_NAMESPACE, $input);

                    if ($cached !== null) {
                        $payload = $cached['data'];
                        $generatedAt = $cached['updated_at'];
                        $fromCache = true;
                    } else {
                        $payload = $this->buildQuestionsPayload(
                            $coach->prepare($cvText, $offre)
                        );
                        $entry = $aiResults->save(self::QUESTIONS_NAMESPACE, $input, $payload, [
                            'offre' => $offre,
                            'cv_text' => $cvText,
                        ]);
                        $generatedAt = $entry['updated_at'] ?? (new \DateTimeImmutable())->format(\DATE_ATOM);
                    }

                    $questions = $payload['questions'] ?? [];
                    $extras = $payload['extras'] ?? [];
                    $request->getSession()->set('interview_questions', $questions);
                    $request->getSession()->set('interview_extras', $extras);
                } catch (\Throwable $e) {
                    $erreur = $this->formatError($e);
                }
            } elseif ($action === 'feedback') {
                $question = (string) $request->request->get('question', '');
                $answer = (string) $request->request->get('reponse', '');
                $postedOffre = (string) $request->request->get('offre', '');

                if ($postedOffre !== '') {
                    $offre = $postedOffre;
                }

                if ($offre === '' && $request->getSession()->has('interview_offre')) {
                    $offre = (string) $request->getSession()->get('interview_offre');
                }

                if ($request->getSession()->has('interview_questions')) {
                    $questions = $request->getSession()->get('interview_questions');
                }
                if ($request->getSession()->has('interview_extras')) {
                    $extras = $request->getSession()->get('interview_extras');
                }

                try {
                    $input = [
                        'cv_text' => $cvText,
                        'offre' => $offre,
                        'question' => $question,
                        'reponse' => $answer,
                    ];
                    $force = (bool) $request->request->get('force', false);
                    $cached = $force ? null : $aiResults->get(self::FEEDBACK_NAMESPACE, $input);

                    if ($cached !== null) {
                        $evaluation = $cached['data'];
                        $generatedAt = $cached['updated_at'];
                        $fromCache = true;
                    } else {
                        $evaluation = $this->buildFeedback(
                            $coach->practice($question, $answer, $cvText, $offre)
                        );
                        $entry = $aiResults->save(self::FEEDBACK_NAMESPACE, $input, $evaluation, [
                            'question' => $question,
                            'offre' => $offre,
                        ]);
                        $generatedAt = $entry['updated_at'] ?? (new \DateTimeImmutable())->format(\DATE_ATOM);
                    }

                    $feedback = [
                        'question' => $question,
                        'reponse' => $answer,
                        'evaluation' => $evaluation,
                    ];
                } catch (\Throwable $e) {
                    $erreur = $this->formatError($e);
                }
            }
        } else {
            $latestQuestions = $aiResults->latest(self::QUESTIONS_NAMESPACE);

            if ($latestQuestions !== null && $questions === []) {
                $questions = $latestQuestions['data']['questions'] ?? [];
                $extras = $latestQuestions['data']['extras'] ?? [];
                $offre = (string) ($latestQuestions['meta']['offre'] ?? '');
                $generatedAt = $latestQuestions['updated_at'];
                $fromCache = true;

                if ($cvText === '') {
                    $cvText = (string) ($latestQuestions['meta']['cv_text'] ?? '');
                }
            }

            $latestFeedback = $aiResults->latest(self::FEEDBACK_NAMESPACE);

            if ($latestFeedback !== null) {
                $feedback = [
                    'question' => (string) ($latestFeedback['meta']['question'] ?? ''),
                    'reponse' => '',
                    'evaluation' => $latestFeedback['data'],
                ];

                if ($offre === '') {
                    $offre = (string) ($latestFeedback['meta']['offre'] ?? '');
                }
            }
        }

        return $this->render('interview/index.html.twig', [
            'action' => $action,
            'erreur' => $erreur,
            'offre' => $offre,
            'cv_text' => $cvText,
            'cv_profile' => $cvProfile->get(),
            'questions' => $questions,
            'extras' => $extras,
            'feedback' => $feedback,
            'generated_at' => $generatedAt,
            'from_cache' => $fromCache,
        ]);
    }

    /**
     * Aplatit la réponse de l'IA en une liste de questions exploitable par la vue.
     *
     * @param array<string, mixed> $result
     * @return array{questions: array<int, array<string, string>>, extras: array<string, mixed>}
     */
    private function buildQuestionsPayload(array $result): array
    {
        $questions = [];

        $sources = [
            'questions_techniques' => 'technique',
            'questions_comportementales' => 'comportementale',
            'questions_recruteur_probables' => 'rh',
        ];

        foreach ($sources as $key => $type) {
            $items = $result[$key] ?? [];

            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (is_string($item)) {
                    $questions[] = [
                        'type' => $type,
                        'question' => $item,
                        'attendu' => '',
                        'piste_reponse' => '',
                    ];
                    continue;
                }

                if (!is_array($item) || trim((string) ($item['question'] ?? '')) === '') {
                    continue;
                }

                $questions[] = [
                    'type' => $type,
                    'question' => (string) $item['question'],
                    'attendu' => (string) ($item['objectif'] ?? $item['conseil'] ?? ''),
                    'piste_reponse' => (string) ($item['piste_reponse'] ?? ''),
                ];
            }
        }

        return [
            'questions' => $questions,
            'extras' => [
                'questions_a_poser' => is_array($result['questions_a_poser'] ?? null) ? $result['questions_a_poser'] : [],
                'points_a_anticiper' => is_array($result['points_a_anticiper'] ?? null) ? $result['points_a_anticiper'] : [],
                'conseils_rapides' => is_array($result['conseils_rapides'] ?? null) ? $result['conseils_rapides'] : [],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function buildFeedback(array $result): array
    {
        return [
            'note' => $result['note_globale'] ?? null,
            'appreciation' => (string) ($result['appreciation'] ?? ''),
            'points_forts' => is_array($result['points_forts'] ?? null) ? $result['points_forts'] : [],
            'axes_amelioration' => is_array($result['axes_amelioration'] ?? null) ? $result['axes_amelioration'] : [],
            'reponse_amelioree' => (string) ($result['reponse_modele'] ?? ''),
            'conseils' => is_array($result['conseils'] ?? null) ? $result['conseils'] : [],
        ];
    }

    private function formatError(\Throwable $e): string
    {
        $message = $e instanceof \RuntimeException
            ? $e->getMessage()
            : 'Impossible de générer le contenu. Vérifiez la clé Gemini et les quotas API.';

        if ($this->getParameter('kernel.debug')) {
            $message .= ' Détail : ' . $e->getMessage();
        }

        return $message;
    }
}
