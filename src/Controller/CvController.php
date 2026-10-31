<?php

namespace App\Controller;

use App\Repository\AiResultRepository;
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
    private const ANALYSIS_NAMESPACE = 'cv_analysis';

    #[Route('/cv/analyser', name: 'app_cv_analyser', methods: ['GET', 'POST'])]
    public function analyser(
        Request $request,
        GeminiCvAnalyzer $gemini,
        CvPdfTextExtractor $extractor,
        JobOfferSearch $jobSearch,
        CvProfileRepository $cvProfile,
        AiResultRepository $aiResults,
    ): Response {
        $resultat = null;
        $erreur = null;
        $recherche = null;
        $analyseAt = null;
        $analyseFromCache = false;
        $force = (bool) $request->request->get('force', false);

        $profile = $cvProfile->get();
        $resultatCache = $profile['cv_text'] !== ''
            ? $aiResults->get(self::ANALYSIS_NAMESPACE, ['cv_text' => $profile['cv_text']])
            : null;

        if ($request->isMethod('POST')) {
            $action = (string) $request->request->get('action', 'analyze');

            if ($action === 'delete') {
                $cvProfile->clear();
                $aiResults->clear(self::ANALYSIS_NAMESPACE);
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

                        if ($texte !== $cvProfile->get()['cv_text']) {
                            $cvProfile->saveText($texte);
                        }
                    }

                    $input = ['cv_text' => $texte];
                    $cached = $force ? null : $aiResults->get(self::ANALYSIS_NAMESPACE, $input);

                    if ($cached !== null) {
                        // Même CV qu'une analyse précédente : on réutilise le résultat
                        // sans rappeler l'IA.
                        $resultat = $cached['data'];
                        $analyseAt = $cached['updated_at'];
                        $analyseFromCache = true;
                    } else {
                        $resultat = $gemini->analyze($texte);
                        $entry = $aiResults->save(self::ANALYSIS_NAMESPACE, $input, $resultat, [
                            'cv_filename' => $cvProfile->get()['cv_filename'],
                        ]);
                        $analyseAt = $entry['updated_at'] ?? (new \DateTimeImmutable())->format(\DATE_ATOM);
                    }

                    try {
                        $query = $jobSearch->buildQuery($resultat);
                        $ville = $this->resolveCity($request, $resultat);
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

        $profile = $cvProfile->get();

        // Affichage d'une analyse déjà enregistrée lors d'une visite simple.
        if (!$request->isMethod('POST') && $resultat === null && $resultatCache !== null) {
            $resultat = $resultatCache['data'];
            $analyseAt = $resultatCache['updated_at'];
            $analyseFromCache = true;
        }

        $latestAnalysis = $aiResults->latest(self::ANALYSIS_NAMESPACE);
        $analysisOutdated = $latestAnalysis !== null
            && $resultat === null
            && $profile['cv_text'] !== '';

        $offreQuery = $resultat !== null ? $jobSearch->buildQuery($resultat) : '';

        // Recherche d'offres demandée depuis l'onglet « Offres ».
        $offresQuery = trim((string) $request->query->get('offres_q', ''));
        $ville = $this->resolveCity($request, $resultat ?? []);
        $rayon = (int) $request->query->get('rayon', 30);
        $rayon = in_array($rayon, [10, 30, 50, 100], true) ? $rayon : 30;

        if (!$request->isMethod('POST') && $offresQuery !== '') {
            $recherche = $jobSearch->search($offresQuery, $ville, $rayon, 12);
            $offreQuery = $offresQuery;
        } elseif ($offresQuery !== '') {
            $offreQuery = $offresQuery;
        }

        $offreLiens = $offreQuery !== '' ? $jobSearch->searchLinks($offreQuery, $ville) : [];

        $tab = (string) $request->query->get('tab', '');
        if (in_array($tab, ['cv', 'analyse', 'offres'], true)) {
            $activeTab = $tab;
        } elseif ($request->isMethod('POST') && $resultat !== null) {
            $activeTab = 'analyse';
        } elseif (!$request->isMethod('POST') && $offresQuery !== '') {
            $activeTab = 'offres';
        } else {
            $activeTab = 'cv';
        }

        return $this->render('cv/analyser.html.twig', [
            'resultat' => $resultat,
            'erreur' => $erreur,
            'recherche' => $recherche,
            'cv_profile' => $profile,
            'analyse_at' => $analyseAt,
            'analyse_from_cache' => $analyseFromCache,
            'analysis_outdated' => $analysisOutdated,
            'latest_analysis_at' => $latestAnalysis['updated_at'] ?? null,
            'offre_query' => $offreQuery,
            'offre_liens' => $offreLiens,
            'offre_ville' => $ville,
            'offre_rayon' => $rayon,
            'active_tab' => $activeTab,
        ]);
    }

    /**
     * @param array<string, mixed> $resultat
     */
    private function resolveCity(Request $request, array $resultat): string
    {
        $session = $request->getSession();
        $ville = trim((string) ($resultat['ville'] ?? ''));

        if ($ville === '' && is_array($resultat['identite'] ?? null)) {
            $ville = trim((string) ($resultat['identite']['localisation'] ?? ''));
        }

        if ($ville === '') {
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
