<?php

namespace App\Controller;

use App\Service\JobOfferSearch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class OfferSearchController extends AbstractController
{
    #[Route('/offres/recherche', name: 'app_offer_search', methods: ['GET', 'POST'])]
    public function index(Request $request, JobOfferSearch $search): Response
    {
        $session = $request->getSession();

        $query = trim((string) $request->query->get('q', ''));
        $ville = trim((string) $request->query->get('ville', ''));
        $rayon = (int) $request->query->get('rayon', 30);

        if ($ville === '') {
            $ville = (string) $session->get('job_ville', '');
        }
        if ($ville !== '') {
            $session->set('job_ville', $ville);
        }

        $rayon = max(0, min(100, $rayon));

        $recherche = $search->search($query, $ville, $rayon, 12);

        return $this->render('offer/recherche.html.twig', [
            'recherche' => $recherche,
        ]);
    }
}