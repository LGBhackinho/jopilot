<?php

namespace App\Controller;

use App\Repository\CvProfileRepository;
use App\Service\ApplicationAssistant;
use App\Service\CvPdfTextExtractor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AssistantController extends AbstractController
{
    #[Route('/assistant', name: 'app_assistant', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        ApplicationAssistant $assistant,
        CvPdfTextExtractor $extractor,
        CvProfileRepository $cvProfile,
    ): Response {
        $resultat = null;
        $erreur = null;
        $action = null;
        $profile = $cvProfile->get();
        $cvText = $profile['cv_text'];
        $offre = '';

        if ($request->isMethod('POST')) {
            $action = (string) $request->request->get('action', 'match');
            $cvText = (string) $request->request->get('cv_text', '');
            $offre = (string) $request->request->get('offre', '');
            $fichier = $request->files->get('cv_file');

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

                $resultat = $action === 'lettre'
                    ? $assistant->coverLetter($cvText, $offre)
                    : $assistant->match($cvText, $offre);
            } catch (\Throwable $e) {
                $erreur = $e instanceof \RuntimeException
                    ? $e->getMessage()
                    : 'Impossible de générer le résultat. Vérifiez la clé Gemini et les quotas API.';

                if ($this->getParameter('kernel.debug')) {
                    $erreur .= ' Détail : ' . $e->getMessage();
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
        ]);
    }
}
