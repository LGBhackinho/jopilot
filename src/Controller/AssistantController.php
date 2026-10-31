<?php

namespace App\Controller;

use App\Repository\AiResultRepository;
use App\Repository\CvProfileRepository;
use App\Service\ApplicationAssistant;
use App\Service\CvPdfTextExtractor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AssistantController extends AbstractController
{
    private const MATCH_NAMESPACE = 'assistant_match';
    private const LETTER_NAMESPACE = 'assistant_letter';

    #[Route('/assistant', name: 'app_assistant', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        ApplicationAssistant $assistant,
        CvPdfTextExtractor $extractor,
        CvProfileRepository $cvProfile,
        AiResultRepository $aiResults,
    ): Response {
        $resultat = null;
        $erreur = null;
        $action = null;
        $generatedAt = null;
        $fromCache = false;
        $profile = $cvProfile->get();
        $cvText = $profile['cv_text'];
        $offre = '';

        if ($request->isMethod('POST')) {
            $action = (string) $request->request->get('action', 'match');
            $cvText = (string) $request->request->get('cv_text', '');
            $offre = (string) $request->request->get('offre', '');
            $fichier = $request->files->get('cv_file');
            $force = (bool) $request->request->get('force', false);

            try {
                if ($fichier) {
                    $cvText = $extractor->extract($fichier);
                }

                if (trim($cvText) !== '') {
                    $cvProfile->saveText(
                        $cvText,
                        $fichier instanceof \Symfony\Component\HttpFoundation\File\UploadedFile ? $fichier->getClientOriginalName() : ''
                    );
                }

                $namespace = $action === 'lettre' ? self::LETTER_NAMESPACE : self::MATCH_NAMESPACE;
                $input = ['cv_text' => $cvText, 'offre' => $offre];
                $cached = $force ? null : $aiResults->get($namespace, $input);

                if ($cached !== null) {
                    $resultat = $cached['data'];
                    $generatedAt = $cached['updated_at'];
                    $fromCache = true;
                } else {
                    $resultat = $action === 'lettre'
                        ? $assistant->coverLetter($cvText, $offre)
                        : $assistant->match($cvText, $offre);

                    $entry = $aiResults->save($namespace, $input, $resultat, [
                        'cv_text' => $cvText,
                        'offre' => $offre,
                    ]);
                    $generatedAt = $entry['updated_at'] ?? (new \DateTimeImmutable())->format(\DATE_ATOM);
                }
            } catch (\Throwable $e) {
                $erreur = $e instanceof \RuntimeException
                    ? $e->getMessage()
                    : 'Impossible de générer le résultat. Vérifiez la clé Gemini et les quotas API.';

                if ($this->getParameter('kernel.debug')) {
                    $erreur .= ' Détail : ' . $e->getMessage();
                }
            }
        } else {
            $latest = $aiResults->latest([self::MATCH_NAMESPACE, self::LETTER_NAMESPACE]);

            if ($latest !== null) {
                $resultat = $latest['data'];
                $action = $latest['namespace'] === self::LETTER_NAMESPACE ? 'lettre' : 'match';
                $offre = (string) ($latest['meta']['offre'] ?? '');
                $generatedAt = $latest['updated_at'];
                $fromCache = true;

                if ($cvText === '') {
                    $cvText = (string) ($latest['meta']['cv_text'] ?? '');
                }
            }
        }

        return $this->render('assistant/index.html.twig', [
            'action' => $action,
            'resultat' => $resultat,
            'erreur' => $erreur,
            'cv_text' => $cvText,
            'cv_profile' => $cvProfile->get(),
            'offre' => $offre,
            'generated_at' => $generatedAt,
            'from_cache' => $fromCache,
        ]);
    }
}
