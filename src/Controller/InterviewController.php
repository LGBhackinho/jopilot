<?php

namespace App\Controller;

use App\Repository\CvProfileRepository;
use App\Service\InterviewCoach;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class InterviewController extends AbstractController
{
    #[Route('/entretien', name: 'app_interview', methods: ['GET', 'POST'])]
    public function index(Request $request, InterviewCoach $coach, CvProfileRepository $cvProfile): Response
    {
        $erreur = null;
        $offre = '';
        $profile = $cvProfile->get();
        $cvText = $profile['cv_text'];
        $action = null;
        $questions = [];
        $feedback = null;

        if ($request->getSession()->has('interview_questions')) {
            $questions = $request->getSession()->get('interview_questions');
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
                $request->getSession()->remove('interview_questions');
                $request->getSession()->set('interview_offre', $offre);

                try {
                    $result = $coach->questions($offre, $cvText !== '' ? $cvText : null);
                    $questions = $result['questions'] ?? [];
                    $request->getSession()->set('interview_questions', $questions);
                } catch (\Throwable $e) {
                    $erreur = $this->formatError($e);
                }
            } elseif ($action === 'feedback') {
                $question = (string) $request->request->get('question', '');
                $answer = (string) $request->request->get('reponse', '');
                $offre = (string) $request->request->get('offre', '');

                if ($offre === '' && $request->getSession()->has('interview_offre')) {
                    $offre = (string) $request->getSession()->get('interview_offre');
                }

                if ($request->getSession()->has('interview_questions')) {
                    $questions = $request->getSession()->get('interview_questions');
                }

                try {
                    $feedback = [
                        'question' => $question,
                        'reponse' => $answer,
                        'evaluation' => $coach->feedback($question, $answer, $offre !== '' ? $offre : null),
                    ];
                } catch (\Throwable $e) {
                    $erreur = $this->formatError($e);
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
            'feedback' => $feedback,
        ]);
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