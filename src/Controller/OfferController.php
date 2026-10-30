<?php

namespace App\Controller;

use App\Service\OfferAnalyzer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class OfferController extends AbstractController
{
    #[Route('/offres/analyser', name: 'app_offer_analyser', methods: ['GET', 'POST'])]
    public function analyser(Request $request, OfferAnalyzer $analyzer): Response
    {
        $resultat = null;
        $erreur = null;
        $offre = '';

        if ($request->isMethod('POST')) {
            $offre = (string) $request->request->get('offre', '');

            try {
                $resultat = $analyzer->analyze($offre);
            } catch (\Throwable $e) {
                $erreur = $e instanceof \RuntimeException
                    ? $e->getMessage()
                    : 'Impossible d’analyser l’annonce. Vérifiez la clé Gemini et les quotas API.';

                if ($this->getParameter('kernel.debug')) {
                    $erreur .= ' Détail : ' . $e->getMessage();
                }
            }
        }

        return $this->render('offer/analyser.html.twig', [
            'resultat' => $resultat,
            'erreur' => $erreur,
            'offre' => $offre,
        ]);
    }
}
