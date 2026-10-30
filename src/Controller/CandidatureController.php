<?php

namespace App\Controller;

use App\Repository\CandidatureRepository;
use App\Service\CandidatureStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CandidatureController extends AbstractController
{
    #[Route('/candidatures', name: 'app_candidature_index', methods: ['GET'])]
    public function index(CandidatureRepository $repository): Response
    {
        $items = $repository->all();

        usort($items, static function (array $a, array $b): int {
            return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
        });

        $groups = array_fill_keys(CandidatureStatus::keys(), []);

        foreach ($items as $item) {
            $status = $item['statut'] ?? CandidatureStatus::A_POSTULER;
            if (!CandidatureStatus::isValid($status)) {
                $status = CandidatureStatus::A_POSTULER;
            }
            $item['_statut'] = $status;
            $groups[$status][] = $item;
        }

        $today = new \DateTimeImmutable('today');
        $relances = ['retard' => [], 'proche' => []];

        foreach ($items as $item) {
            $status = $item['statut'] ?? CandidatureStatus::A_POSTULER;
            if (in_array($status, CandidatureStatus::FINAL, true)) {
                continue;
            }

            $dateRelance = $item['date_relance'] ?? null;
            if (!is_string($dateRelance) || $dateRelance === '') {
                continue;
            }

            try {
                $date = new \DateTimeImmutable($dateRelance);
            } catch (\Throwable) {
                continue;
            }

            $days = (int) $today->diff($date)->format('%r%a');
            $item['_jours_relance'] = $days;

            if ($days < 0) {
                $relances['retard'][] = $item;
            } elseif ($days <= 7) {
                $relances['proche'][] = $item;
            }
        }

        return $this->render('candidature/index.html.twig', [
            'statuses' => CandidatureStatus::ALL,
            'groups' => $groups,
            'total' => count($items),
            'relances' => $relances,
        ]);
    }

    #[Route('/candidatures/nouvelle', name: 'app_candidature_new', methods: ['GET', 'POST'])]
    public function new(Request $request, CandidatureRepository $repository): Response
    {
        $candidature = [
            'statut' => CandidatureStatus::A_POSTULER,
            'date_candidature' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
        ];

        if ($request->isMethod('GET')) {
            foreach (['poste', 'entreprise', 'lieu', 'type_contrat', 'salaire', 'url', 'competences'] as $field) {
                $value = $request->query->get($field);
                if (is_string($value) && $value !== '') {
                    $candidature[$field] = $value;
                }
            }
        }

        $erreurs = [];

        if ($request->isMethod('POST')) {
            $candidature = $this->readForm($request, $candidature);
            $erreurs = $this->validate($candidature);

            if ($erreurs === []) {
                $repository->save($candidature);
                $this->addFlash('success', 'Candidature ajoutée avec succès.');

                return $this->redirectToRoute('app_candidature_index');
            }
        }

        return $this->render('candidature/form.html.twig', [
            'candidature' => $candidature,
            'erreurs' => $erreurs,
            'statuses' => CandidatureStatus::ALL,
            'mode' => 'new',
        ]);
    }

    #[Route('/candidatures/{id}/modifier', name: 'app_candidature_edit', methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request, CandidatureRepository $repository): Response
    {
        $candidature = $repository->find($id);

        if ($candidature === null) {
            throw $this->createNotFoundException('Candidature introuvable.');
        }

        $erreurs = [];

        if ($request->isMethod('POST')) {
            $candidature = $this->readForm($request, $candidature);
            $erreurs = $this->validate($candidature);

            if ($erreurs === []) {
                $repository->save($candidature);
                $this->addFlash('success', 'Candidature mise à jour.');

                return $this->redirectToRoute('app_candidature_index');
            }
        }

        return $this->render('candidature/form.html.twig', [
            'candidature' => $candidature,
            'erreurs' => $erreurs,
            'statuses' => CandidatureStatus::ALL,
            'mode' => 'edit',
        ]);
    }

    #[Route('/candidatures/{id}/statut', name: 'app_candidature_statut', methods: ['POST'])]
    public function updateStatus(string $id, Request $request, CandidatureRepository $repository): Response
    {
        $status = (string) $request->request->get('statut', '');

        if (!CandidatureStatus::isValid($status) || $repository->find($id) === null) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['status' => 'error'], 400);
            }

            return $this->redirectToRoute('app_candidature_index');
        }

        $repository->updateStatus($id, $status);

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['status' => 'success', 'statut' => $status]);
        }

        return $this->redirectToRoute('app_candidature_index');
    }

    #[Route('/candidatures/{id}/supprimer', name: 'app_candidature_delete', methods: ['POST'])]
    public function delete(string $id, CandidatureRepository $repository): Response
    {
        $repository->delete($id);
        $this->addFlash('success', 'Candidature supprimée.');

        return $this->redirectToRoute('app_candidature_index');
    }

    /**
     * @param array<string, mixed> $candidature
     * @return array<string, mixed>
     */
    private function readForm(Request $request, array $candidature): array
    {
        $fields = [
            'entreprise', 'poste', 'url', 'lieu', 'type_contrat',
            'salaire', 'statut', 'date_candidature', 'date_relance',
            'competences', 'notes',
        ];

        foreach ($fields as $field) {
            $value = $request->request->get($field);
            $candidature[$field] = is_string($value) ? trim($value) : '';
        }

        if (!CandidatureStatus::isValid((string) $candidature['statut'])) {
            $candidature['statut'] = CandidatureStatus::A_POSTULER;
        }

        return $candidature;
    }

    /**
     * @param array<string, mixed> $candidature
     * @return array<string, string>
     */
    private function validate(array $candidature): array
    {
        $erreurs = [];

        if (($candidature['entreprise'] ?? '') === '') {
            $erreurs['entreprise'] = 'L’entreprise est obligatoire.';
        }

        if (($candidature['poste'] ?? '') === '') {
            $erreurs['poste'] = 'L’intitulé du poste est obligatoire.';
        }

        foreach (['date_candidature', 'date_relance'] as $dateField) {
            $value = $candidature[$dateField] ?? '';
            if ($value !== '' && !$this->isValidDate($value)) {
                $erreurs[$dateField] = 'La date doit être au format AAAA-MM-JJ.';
            }
        }

        return $erreurs;
    }

    private function isValidDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
