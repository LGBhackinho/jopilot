<?php

namespace App\Controller;

use App\Service\DashboardService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(DashboardService $dashboard): Response
    {
        return $this->render('home/index.html.twig', [
            'dashboard' => $dashboard->build(),
        ]);
    }
}
