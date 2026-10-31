<?php

namespace App\Controller;

use App\Repository\AiResultRepository;
use App\Service\OfferAnalyzer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class OfferController extends AbstractController
{
    private const ANALYSIS_NAMESPACE = 'offer_analysis';

    #[Route('/offres/analyser', name: 'app_offer_analyser', methods: ['GET', 'POST'])]
    public function analyser(Request $request, OfferAnalyzer $analyzer, AiResultRepository $aiResults): Response
    {
        $resultat = null;
        $erreur = null;
        $offre = '';
        $analyseAt = null;
        $analyseFromCache = false;

        if ($request->isMethod('POST')) {
            $offre = (string) $request->request->get('offre', '');
            $force = (bool) $request->request->get('force', false);

            try {
                $input = ['offre' => $offre];
                $cached = $force ? null : $aiResults->get(self::ANALYSIS_NAMESPACE, $input);

                if ($cached !== null) {
                    $resultat = $cached['data'];
                    $analyseAt = $cached['updated_at'];
                    $analyseFromCache = true;
                } else {
                    $resultat = $analyzer->analyze($offre);
                    $entry = $aiResults->save(self::ANALYSIS_NAMESPACE, $input, $resultat, ['offre' => $offre]);
                    $analyseAt = $entry['updated_at'] ?? (new \DateTimeImmutable())->format(\DATE_ATOM);
                }
            } catch (\Throwable $e) {
                $erreur = $e instanceof \RuntimeException
                    ? $e->getMessage()
                    : 'Impossible d’analyser l’annonce. Vérifiez la clé Gemini et les quotas API.';

                if ($this->getParameter('kernel.debug')) {
                    $erreur .= ' Détail : ' . $e->getMessage();
                }
            }
        } else {
            $latest = $aiResults->latest(self::ANALYSIS_NAMESPACE);

            if ($latest !== null) {
                $resultat = $latest['data'];
                $offre = (string) ($latest['meta']['offre'] ?? '');
                $analyseAt = $latest['updated_at'];
                $analyseFromCache = true;
            }
        }

        return $this->render('offer/analyser.html.twig', [
            'resultat' => $resultat,
            'erreur' => $erreur,
            'offre' => $offre,
            'analyse_at' => $analyseAt,
            'analyse_from_cache' => $analyseFromCache,
        ]);
    }
}
