<?php

namespace App\Controller;

use App\Repository\AiResultRepository;
use App\Repository\CvProfileRepository;
use App\Repository\SpontaneousApplicationRepository;
use App\Service\ApplicationAssistant;
use App\Service\CvPdfTextExtractor;
use App\Service\SpontaneousCsvImporter;
use App\Service\SpontaneousStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/candidatures/spontanees', name: 'app_spontaneous_')]
class SpontaneousApplicationController extends AbstractController
{
    /**
     * Colonnes modifiables via le formulaire société.
     *
     * @var list<string>
     */
    private const FORM_FIELDS = [
        'nom_societe', 'contact', 'telephone', 'email', 'ville', 'secteur',
        'site_web', 'poste', 'notes', 'date_envoi', 'reponse', 'date_reponse',
    ];

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, SpontaneousApplicationRepository $repository): Response
    {
        $items = $this->sortItems($repository->all());
        $items = $this->enrich($items);

        $stats = [
            'total' => count($items),
            'a_envoyer' => count(array_filter($items, static fn (array $item): bool => ($item['reponse'] ?? '') === SpontaneousStatus::A_ENVOYER)),
            'en_attente' => count(array_filter($items, static fn (array $item): bool => in_array($item['reponse'] ?? '', [SpontaneousStatus::EN_ATTENTE, SpontaneousStatus::RELANCE], true))),
            'reponses' => count(array_filter($items, static fn (array $item): bool => SpontaneousStatus::hasAnswer($item))),
        ];

        $query = trim((string) $request->query->get('q', ''));
        $responseFilter = (string) $request->query->get('reponse', '');

        if (!SpontaneousStatus::isValid($responseFilter)) {
            $responseFilter = '';
        }

        $filtered = array_values(array_filter($items, static function (array $item) use ($query, $responseFilter): bool {
            if ($responseFilter !== '' && ($item['reponse'] ?? SpontaneousStatus::A_ENVOYER) !== $responseFilter) {
                return false;
            }

            if ($query === '') {
                return true;
            }

            $needle = mb_strtolower($query);
            foreach (['nom_societe', 'contact', 'email', 'telephone', 'ville', 'secteur', 'poste', 'notes'] as $field) {
                if (str_contains(mb_strtolower((string) ($item[$field] ?? '')), $needle)) {
                    return true;
                }
            }

            return false;
        }));

        return $this->render('spontaneous/index.html.twig', [
            'items' => $filtered,
            'stats' => $stats,
            'statuses' => SpontaneousStatus::ALL,
            'filters' => ['q' => $query, 'reponse' => $responseFilter],
        ]);
    }

    #[Route('/nouvelle', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, SpontaneousApplicationRepository $repository): Response
    {
        $item = $this->emptyItem();
        $erreurs = [];

        if ($request->isMethod('POST')) {
            $item = $this->readForm($request, $item);
            $erreurs = $this->validate($item);

            if ($erreurs === []) {
                $repository->save($item);
                $this->addFlash('success', 'Société ajoutée à votre suivi de candidatures spontanées.');

                return $this->redirectToRoute('app_spontaneous_index');
            }
        }

        return $this->render('spontaneous/form.html.twig', [
            'item' => $item,
            'erreurs' => $erreurs,
            'statuses' => SpontaneousStatus::ALL,
            'mode' => 'new',
        ]);
    }

    #[Route('/{id}/modifier', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request, SpontaneousApplicationRepository $repository): Response
    {
        $item = $repository->find($id);

        if ($item === null) {
            throw $this->createNotFoundException('Société introuvable.');
        }

        $erreurs = [];

        if ($request->isMethod('POST')) {
            $item = $this->readForm($request, $item);
            $erreurs = $this->validate($item);

            if ($erreurs === []) {
                $repository->save($item);
                $this->addFlash('success', 'Fiche société mise à jour.');

                return $this->redirectToRoute('app_spontaneous_index');
            }
        }

        return $this->render('spontaneous/form.html.twig', [
            'item' => $item,
            'erreurs' => $erreurs,
            'statuses' => SpontaneousStatus::ALL,
            'mode' => 'edit',
        ]);
    }

    #[Route('/importer', name: 'import', methods: ['POST'])]
    public function import(
        Request $request,
        SpontaneousApplicationRepository $repository,
        SpontaneousCsvImporter $importer,
    ): Response {
        $file = $request->files->get('csv_file');

        if (!$file instanceof UploadedFile) {
            $this->addFlash('error', 'Veuillez sélectionner un fichier CSV.');

            return $this->redirectToRoute('app_spontaneous_index');
        }

        try {
            $report = $importer->parse($file);
        } catch (\Throwable $e) {
            $this->addFlash('error', $e instanceof \RuntimeException
                ? $e->getMessage()
                : 'Impossible de lire ce fichier CSV.');

            return $this->redirectToRoute('app_spontaneous_index');
        }

        $rowsToInsert = [];
        $duplicates = 0;
        $duplicateIndex = $repository->duplicateIndex();
        $seenEmails = $duplicateIndex['emails'];
        $seenCompanies = $duplicateIndex['companiesWithoutEmail'];

        foreach ($report['rows'] as $row) {
            $emailKey = mb_strtolower(trim((string) ($row['email'] ?? '')));
            $companyKey = $this->normalizeCompanyKey((string) ($row['nom_societe'] ?? ''));

            $isDuplicate = ($emailKey !== '' && isset($seenEmails[$emailKey]))
                || ($emailKey === '' && $companyKey !== '' && isset($seenCompanies[$companyKey]));

            if ($isDuplicate) {
                ++$duplicates;
                continue;
            }

            if ($emailKey !== '') {
                $seenEmails[$emailKey] = true;
            } elseif ($companyKey !== '') {
                $seenCompanies[$companyKey] = true;
            }

            $row['reponse'] = SpontaneousStatus::isValid((string) ($row['reponse'] ?? ''))
                ? $row['reponse']
                : SpontaneousStatus::A_ENVOYER;
            $row['lettre_objet'] = '';
            $row['lettre_texte'] = '';
            $row['lettre_generee_at'] = '';
            $rowsToInsert[] = $row;
        }

        $inserted = $repository->saveMany($rowsToInsert);
        $message = sprintf(
            '%d société%s importée%s.',
            $inserted,
            $inserted > 1 ? 's' : '',
            $inserted > 1 ? 's' : ''
        );

        if ($duplicates > 0) {
            $message .= sprintf(' %d doublon%s ignoré%s.', $duplicates, $duplicates > 1 ? 's' : '', $duplicates > 1 ? 's' : '');
        }

        if ($report['errors'] !== []) {
            $message .= sprintf(' %d ligne%s en erreur.', count($report['errors']), count($report['errors']) > 1 ? 's' : '');
        }

        $this->addFlash($inserted > 0 ? 'success' : 'error', $message);

        if ($report['errors'] !== []) {
            $details = array_map(
                static fn (array $error): string => 'Ligne ' . $error['ligne'] . ' : ' . $error['message'],
                array_slice($report['errors'], 0, 5)
            );
            $this->addFlash('warning', implode(' ', $details));
        }

        if ($report['warnings'] !== []) {
            $details = array_map(
                static fn (array $warning): string => 'Ligne ' . $warning['ligne'] . ' : ' . $warning['message'],
                array_slice($report['warnings'], 0, 5)
            );
            $this->addFlash('warning', implode(' ', $details));
        }

        return $this->redirectToRoute('app_spontaneous_index');
    }

    #[Route('/modele-csv', name: 'csv_template', methods: ['GET'])]
    public function csvTemplate(): Response
    {
        $content = "\xEF\xBB\xBF"
            . "Nom societe;Telephone;Email;Contact;Ville;Secteur;Poste vise;Notes\n"
            . "ACME;0123456789;contact@acme.fr;Marie Dupont;Lyon;Industrie;Developpeur PHP;Contact via le site\n"
            . "Exemple Conseil;0987654321;recrutement@exemple.fr;;Paris;Conseil;Chef de projet;A relancer si pas de reponse\n";

        $response = new Response($content);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set(
            'Content-Disposition',
            'attachment; filename="modele-candidatures-spontanees.csv"'
        );

        return $response;
    }

    #[Route('/{id}/suivi', name: 'tracking', methods: ['POST'])]
    public function tracking(string $id, Request $request, SpontaneousApplicationRepository $repository): Response
    {
        $item = $repository->find($id);

        if ($item === null) {
            throw $this->createNotFoundException('Société introuvable.');
        }

        $dateEnvoi = trim((string) $request->request->get('date_envoi', ''));
        $reponse = (string) $request->request->get('reponse', SpontaneousStatus::A_ENVOYER);
        $dateReponse = trim((string) $request->request->get('date_reponse', ''));

        if ($dateEnvoi !== '' && !$this->isValidDate($dateEnvoi)) {
            $this->addFlash('error', 'La date d’envoi doit être au format AAAA-MM-JJ.');

            return $this->redirectToRoute('app_spontaneous_index');
        }

        if (!SpontaneousStatus::isValid($reponse)) {
            $reponse = SpontaneousStatus::A_ENVOYER;
        }

        if ($dateReponse !== '' && !$this->isValidDate($dateReponse)) {
            $this->addFlash('error', 'La date de réponse doit être au format AAAA-MM-JJ.');

            return $this->redirectToRoute('app_spontaneous_index');
        }

        $item['date_envoi'] = $reponse === SpontaneousStatus::A_ENVOYER ? '' : $dateEnvoi;
        $item['reponse'] = $reponse;

        if (in_array($reponse, SpontaneousStatus::WITH_ANSWER, true)) {
            if ($dateReponse === '') {
                $dateReponse = (string) ($item['date_reponse'] ?? '');
            }
            if ($dateReponse === '') {
                $dateReponse = (new \DateTimeImmutable('today'))->format('Y-m-d');
            }
        } else {
            $dateReponse = '';
        }

        $item['date_reponse'] = $dateReponse;

        $repository->save($item);
        $this->addFlash('success', 'Suivi mis à jour.');

        return $this->redirectToRoute('app_spontaneous_index');
    }

    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'])]
    public function delete(string $id, SpontaneousApplicationRepository $repository): Response
    {
        if ($repository->find($id) !== null) {
            $repository->delete($id);
            $this->addFlash('success', 'Société supprimée du suivi.');
        }

        return $this->redirectToRoute('app_spontaneous_index');
    }

    #[Route('/{id}/lettre', name: 'letter', methods: ['GET', 'POST'])]
    public function letter(
        string $id,
        Request $request,
        SpontaneousApplicationRepository $repository,
        ApplicationAssistant $assistant,
        CvPdfTextExtractor $extractor,
        CvProfileRepository $cvProfile,
        AiResultRepository $aiResults,
    ): Response {
        $item = $repository->find($id);

        if ($item === null) {
            throw $this->createNotFoundException('Société introuvable.');
        }

        $profile = $cvProfile->get();
        $erreur = null;
        $cvText = $profile['cv_text'];
        $formData = [
            'poste' => (string) ($item['lettre_poste'] ?? ($item['poste'] ?? '')),
            'competences' => (string) ($item['lettre_competences'] ?? ''),
            'motivation' => (string) ($item['lettre_motivation'] ?? ''),
            'nom_candidat' => (string) (($item['lettre_nom_candidat'] ?? '') !== '' ? $item['lettre_nom_candidat'] : $profile['nom_candidat']),
        ];

        if ($request->isMethod('POST')) {
            $action = (string) $request->request->get('action', 'generate');

            if ($action === 'save') {
                $objet = trim((string) $request->request->get('lettre_objet', ''));
                $texte = trim((string) $request->request->get('lettre_texte', ''));

                if ($texte === '') {
                    $this->addFlash('error', 'La lettre ne peut pas être vide.');
                } else {
                    $item['lettre_objet'] = $objet;
                    $item['lettre_texte'] = $texte;
                    $repository->save($item);
                    $this->addFlash('success', 'Lettre enregistrée.');
                }

                return $this->redirectToRoute('app_spontaneous_letter', ['id' => $id]);
            }

            if ($action === 'delete_letter') {
                $item['lettre_objet'] = '';
                $item['lettre_texte'] = '';
                $item['lettre_avertissements'] = [];
                $item['lettre_elements_reutilises'] = [];
                $item['lettre_generee_at'] = '';
                $repository->save($item);
                $this->addFlash('success', 'Lettre supprimée.');

                return $this->redirectToRoute('app_spontaneous_letter', ['id' => $id]);
            }

            $cvText = trim((string) $request->request->get('cv_text', ''));
            $fichier = $request->files->get('cv_file');
            $poste = trim((string) $request->request->get('poste', ''));
            $competences = trim((string) $request->request->get('competences', ''));
            $motivation = trim((string) $request->request->get('motivation', ''));
            $nomCandidat = trim((string) $request->request->get('nom_candidat', ''));
            $force = (bool) $request->request->get('force', false) || $action === 'regenerate';

            // Le CV enregistré sert de secours si le champ a été vidé côté navigateur.
            if ($cvText === '' && !$fichier instanceof UploadedFile) {
                $cvText = $profile['cv_text'];
            }

            $formData = [
                'poste' => $poste,
                'competences' => $competences,
                'motivation' => $motivation,
                'nom_candidat' => $nomCandidat,
            ];

            try {
                if ($fichier instanceof UploadedFile) {
                    $cvText = $extractor->extract($fichier);
                }

                $originalFilename = $fichier instanceof UploadedFile ? $fichier->getClientOriginalName() : '';

                if ($cvText !== '') {
                    $cvProfile->saveText(
                        $cvText,
                        $originalFilename,
                        $nomCandidat !== '' ? $nomCandidat : null
                    );
                }

                if ($action === 'save_cv') {
                    if ($cvText === '') {
                        throw new \RuntimeException('Veuillez fournir le contenu de votre CV.');
                    }

                    $this->addFlash('success', 'Votre CV a bien été enregistré.');
                    return $this->redirectToRoute('app_spontaneous_letter', ['id' => $id]);
                }

                // Empreinte de toutes les données utilisées par l'IA : la lettre
                // n'est régénérée que si l'une d'elles change.
                $input = [
                    'cv_text' => $cvText,
                    'societe' => (string) ($item['nom_societe'] ?? ''),
                    'contact' => (string) ($item['contact'] ?? ''),
                    'ville' => (string) ($item['ville'] ?? ''),
                    'secteur' => (string) ($item['secteur'] ?? ''),
                    'poste' => $poste,
                    'competences' => $competences,
                    'motivation' => $motivation,
                    'nom_candidat' => $nomCandidat,
                ];

                $cached = $force ? null : $aiResults->get('spontaneous_letter', $input);

                if ($cached !== null) {
                    $resultat = $cached['data'];
                } else {
                    $resultat = $assistant->spontaneousCoverLetter(
                        $cvText,
                        $item,
                        $poste,
                        $competences,
                        $motivation,
                        $nomCandidat
                    );

                    $aiResults->save('spontaneous_letter', $input, $resultat, [
                        'societe' => (string) ($item['nom_societe'] ?? ''),
                    ]);
                }

                $item['lettre_objet'] = trim((string) ($resultat['objet'] ?? ''));
                $item['lettre_texte'] = trim((string) ($resultat['lettre'] ?? ''));
                $item['lettre_elements_reutilises'] = is_array($resultat['elements_reutilises'] ?? null)
                    ? $resultat['elements_reutilises']
                    : [];
                $item['lettre_avertissements'] = is_array($resultat['avertissements'] ?? null)
                    ? $resultat['avertissements']
                    : [];
                $item['lettre_generee_at'] = $cached !== null
                    ? (string) ($cached['updated_at'] ?? (new \DateTimeImmutable())->format(\DATE_ATOM))
                    : (new \DateTimeImmutable())->format(\DATE_ATOM);
                $item['lettre_poste'] = $poste;
                $item['lettre_competences'] = $competences;
                $item['lettre_motivation'] = $motivation;
                $item['lettre_nom_candidat'] = $nomCandidat;

                if ($item['lettre_texte'] === '') {
                    throw new \RuntimeException('L’IA n’a pas renvoyé de texte de lettre exploitable.');
                }

                $repository->save($item);
                $this->addFlash(
                    'success',
                    $cached !== null
                        ? 'Lettre déjà générée à partir de ces informations : elle a été réutilisée sans nouvel appel IA.'
                        : 'Lettre de candidature spontanée générée.'
                );

                return $this->redirectToRoute('app_spontaneous_letter', ['id' => $id]);
            } catch (\Throwable $e) {
                $erreur = $e instanceof \RuntimeException
                    ? $e->getMessage()
                    : 'Impossible de générer la lettre. Vérifiez la configuration de l’IA.';

                if ($this->getParameter('kernel.debug')) {
                    $erreur .= ' Détail : ' . $e->getMessage();
                }
            }
        }

        return $this->render('spontaneous/letter.html.twig', [
            'item' => $item,
            'erreur' => $erreur,
            'cv_text' => $cvText,
            'cv_profile' => $cvProfile->get(),
            'form_data' => $formData,
        ]);
    }

    #[Route('/{id}/lettre/telecharger', name: 'letter_download', methods: ['GET'])]
    public function downloadLetter(string $id, SpontaneousApplicationRepository $repository): Response
    {
        $item = $repository->find($id);

        if ($item === null) {
            throw $this->createNotFoundException('Société introuvable.');
        }

        $texte = trim((string) ($item['lettre_texte'] ?? ''));

        if ($texte === '') {
            throw $this->createNotFoundException('Aucune lettre à télécharger.');
        }

        $objet = trim((string) ($item['lettre_objet'] ?? ''));
        $content = ($objet !== '' ? 'Objet : ' . $objet . "\n\n" : '') . $texte . "\n";

        $filename = 'lettre-' . $this->slug((string) ($item['nom_societe'] ?? 'societe')) . '.txt';
        $response = new Response($content);
        $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function enrich(array $items): array
    {
        foreach ($items as &$item) {
            $status = (string) ($item['reponse'] ?? SpontaneousStatus::A_ENVOYER);
            if (!SpontaneousStatus::isValid($status)) {
                $status = SpontaneousStatus::A_ENVOYER;
            }
            $item['reponse'] = $status;
            $item['_a_lettre'] = trim((string) ($item['lettre_texte'] ?? '')) !== '';
        }
        unset($item);

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function sortItems(array $items): array
    {
        usort($items, static function (array $a, array $b): int {
            $dateA = (string) ($a['date_envoi'] ?? '');
            $dateB = (string) ($b['date_envoi'] ?? '');

            if ($dateA !== $dateB) {
                return strcmp($dateB, $dateA);
            }

            return strcmp(
                mb_strtolower((string) ($a['nom_societe'] ?? '')),
                mb_strtolower((string) ($b['nom_societe'] ?? ''))
            );
        });

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyItem(): array
    {
        return [
            'nom_societe' => '',
            'contact' => '',
            'telephone' => '',
            'email' => '',
            'ville' => '',
            'secteur' => '',
            'site_web' => '',
            'poste' => '',
            'notes' => '',
            'date_envoi' => '',
            'reponse' => SpontaneousStatus::A_ENVOYER,
            'date_reponse' => '',
            'lettre_objet' => '',
            'lettre_texte' => '',
            'lettre_generee_at' => '',
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function readForm(Request $request, array $item): array
    {
        foreach (self::FORM_FIELDS as $field) {
            $value = $request->request->get($field);
            $item[$field] = is_string($value) ? trim($value) : '';
        }

        if (!SpontaneousStatus::isValid((string) $item['reponse'])) {
            $item['reponse'] = SpontaneousStatus::A_ENVOYER;
        }

        if ($item['reponse'] === SpontaneousStatus::A_ENVOYER) {
            $item['date_envoi'] = '';
            $item['date_reponse'] = '';
        }

        if (in_array($item['reponse'], SpontaneousStatus::WITH_ANSWER, true)) {
            if (($item['date_reponse'] ?? '') === '') {
                $item['date_reponse'] = (new \DateTimeImmutable('today'))->format('Y-m-d');
            }
        } else {
            $item['date_reponse'] = '';
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, string>
     */
    private function validate(array $item): array
    {
        $erreurs = [];

        if (($item['nom_societe'] ?? '') === '') {
            $erreurs['nom_societe'] = 'Le nom de la société est obligatoire.';
        }

        $email = (string) ($item['email'] ?? '');
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $erreurs['email'] = 'L’adresse e-mail n’est pas valide.';
        }

        foreach (['date_envoi', 'date_reponse'] as $dateField) {
            $value = (string) ($item[$dateField] ?? '');
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

    private function normalizeCompanyKey(string $company): string
    {
        $company = mb_strtolower(trim($company));

        return preg_replace('/[^a-z0-9]+/u', '', $company) ?? '';
    }

    private function slug(string $value): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = $ascii !== false ? $ascii : $value;
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

        return trim($value, '-') ?: 'societe';
    }
}
