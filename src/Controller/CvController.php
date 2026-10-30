<?php

namespace App\Controller;

use App\Repository\CvProfileRepository;
use App\Service\CvPdfTextExtractor;
use App\Service\GeminiCvAnalyzer;
use App\Service\JobOfferSearch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CvController extends AbstractController
{
    #[Route('/cv/analyser', name: 'app_cv_analyser', methods: ['GET', 'POST'])]
    public function analyser(
        Request $request,
        GeminiCvAnalyzer $gemini,
        CvPdfTextExtractor $extractor,
        JobOfferSearch $jobSearch,
        CvProfileRepository $cvProfile,
    ): Response {
        $resultat = null;
        $erreur = null;
        $recherche = null;

        if ($request->isMethod('POST')) {
            $action = (string) $request->request->get('action', 'analyze');

            if ($action === 'delete') {
                $cvProfile->clear();
                $this->addFlash('success', 'Le CV enregistré a été supprimé.');
                return $this->redirectToRoute('app_cv_analyser');
            }

            if ($action === 'save') {
                $texte = trim((string) $request->request->get('cv_text', ''));

                try {
                    if ($texte === '') {
                        throw new \RuntimeException('Le CV ne peut pas être vide.');
                    }
                    $cvProfile->saveText($texte);
                    $this->addFlash('success', 'Votre CV a bien été enregistré.');
                    return $this->redirectToRoute('app_cv_analyser');
                } catch (\Throwable $e) {
                    $erreur = $e instanceof \RuntimeException
                        ? $e->getMessage()
                        : 'Impossible d’enregistrer le CV.';
                }
            } else {
                $fichier = $request->files->get('cv');

                try {
                    if ($fichier) {
                        $texte = $extractor->extract($fichier);
                        $cvProfile->saveText($texte, $fichier->getClientOriginalName());
                    } else {
                        $texte = trim((string) $request->request->get('cv_text', ''));

                        if ($texte === '') {
                            $texte = $cvProfile->get()['cv_text'];
                        }

                        if ($texte === '') {
                            throw new \RuntimeException('Veuillez sélectionner un PDF ou enregistrer votre CV.');
                        }

                        $cvProfile->saveText($texte);
                    }

                    $resultat = $gemini->analyze($texte);

                    try {
                        $query = $jobSearch->buildQuery($resultat);
                        $ville = $this->detectCity($request, $resultat);
                        if ($query !== '') {
                            $recherche = $jobSearch->search($query, $ville, 30, 12);
                        }
                    } catch (\Throwable) {
                        $recherche = null;
                    }
                } catch (\Throwable $e) {
                    $erreur = $e instanceof \RuntimeException && $this->isUserFacing($e)
                        ? $e->getMessage()
                        : 'Impossible d’analyser le CV. Vérifiez le PDF, la clé Gemini et les quotas API.';

                    if ($this->getParameter('kernel.debug')) {
                        $erreur .= ' Détail : ' . $e->getMessage();
                    }
                }
            }
        }

        return $this->render('cv/analyser.html.twig', [
            'resultat' => $resultat,
            'erreur' => $erreur,
            'recherche' => $recherche,
            'cv_profile' => $cvProfile->get(),
        ]);
    }

    private function detectCity(Request $request, array $resultat): string
    {
        $session = $request->getSession();
        $ville = (string) ($resultat['ville'] ?? '');

        if (trim($ville) === '') {
            $ville = (string) $session->get('job_ville', '');
        }

        if (trim($ville) !== '') {
            $session->set('job_ville', trim($ville));
        }

        return trim($ville);
    }

    private function isUserFacing(\RuntimeException $e): bool
    {
        return str_starts_with($e->getMessage(), 'Le fichier')
            || str_starts_with($e->getMessage(), 'Aucun texte')
            || str_starts_with($e->getMessage(), 'Veuillez')
            || str_starts_with($e->getMessage(), 'Le CV');
    }
}
