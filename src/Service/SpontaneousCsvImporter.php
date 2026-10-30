<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Lecture tolérante d'un fichier CSV de sociétés à démarcher.
 *
 * Formats acceptés :
 * - séparateur « ; », « , » ou tabulation ;
 * - en-têtes accentués ou non (« Nom société », « Téléphone », « E-mail »…) ;
 * - encodage UTF-8 ou ISO-8859-1 / Windows-1252 (export Excel).
 *
 * Les colonnes utiles sont : nom de société, téléphone, e-mail.
 * Des colonnes facultatives sont également reconnues (contact, ville,
 * secteur, poste visé, notes, date d'envoi, réponse).
 */
class SpontaneousCsvImporter
{
    private const MAX_SIZE = 2 * 1024 * 1024;

    /**
     * Alias d'en-têtes normalisés (sans accents, minuscules, sans ponctuation).
     *
     * @var array<string, list<string>>
     */
    private const HEADER_ALIASES = [
        'nom_societe' => [
            'nomsociete', 'societe', 'entreprise', 'nomentreprise', 'nomdelasociete',
            'raisonsociale', 'company', 'nom', 'nomduclient', 'client',
        ],
        'telephone' => [
            'telephone', 'tel', 'phone', 'numerotelephone', 'numerodetel',
            'mobile', 'portable', 'telmobile',
        ],
        'email' => [
            'email', 'mail', 'courriel', 'adressemail', 'adresseelectronique', 'e-mail',
        ],
        'contact' => [
            'contact', 'nomcontact', 'nomducontact', 'interlocuteur', 'responsable',
            'prenomnomcontact', 'chargeeaffaires',
        ],
        'ville' => ['ville', 'lieu', 'city', 'localite'],
        'secteur' => ['secteur', 'secteurdactivite', 'activite', 'domaine', 'industrie'],
        'poste' => ['poste', 'postevise', 'intituleposte', 'fonction', 'metier'],
        'site_web' => ['siteweb', 'site', 'url', 'web', 'siteinternet'],
        'notes' => ['notes', 'note', 'commentaire', 'commentaires', 'remarques'],
        'date_envoi' => ['dateenvoi', 'datedenvoi', 'datecandidature'],
        'reponse' => ['reponse', 'statutreponse', 'reponserecue', 'retour'],
        'date_reponse' => ['datereponse', 'datedereponse'],
    ];

    /**
     * @var array<string, string>
     */
    private array $headerIndex = [];

    /**
     * @return array{
     *     rows: array<int, array<string, string>>,
     *     ignored: int,
     *     errors: array<int, array{ligne: int, message: string}>,
     *     warnings: array<int, array{ligne: int, message: string}>
     * }
     */
    public function parse(UploadedFile $file): array
    {
        $this->assertValidFile($file);

        $content = (string) file_get_contents($file->getPathname());
        $content = $this->toUtf8($content);
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        if (trim($content) === '') {
            throw new \RuntimeException('Le fichier CSV est vide.');
        }

        $delimiter = $this->detectDelimiter($content);
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('Impossible de lire le fichier CSV.');
        }

        fwrite($handle, $content);
        rewind($handle);

        $rows = [];
        $ignored = 0;
        $errors = [];
        $warnings = [];
        $lineNumber = 0;
        $header = null;
        $positional = false;

        while (($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
            ++$lineNumber;

            if ($this->isEmptyRow($cells)) {
                ++$ignored;
                continue;
            }

            $cells = array_map(static fn ($value): string => trim((string) $value), $cells);

            if ($header === null) {
                $candidateHeader = $this->mapHeader($cells);

                if ($candidateHeader === []) {
                    // Aucun en-tête reconnu : on lit toutes les lignes comme des données
                    // au format positionnel « société ; téléphone ; e-mail ».
                    $positional = true;
                    $header = [];
                } else {
                    $header = $candidateHeader;
                    $this->headerIndex = $candidateHeader;
                    continue;
                }
            }

            if ($positional) {
                $data = [
                    'nom_societe' => $cells[0] ?? '',
                    'telephone' => $cells[1] ?? '',
                    'email' => $cells[2] ?? '',
                    'contact' => $cells[3] ?? '',
                    'ville' => $cells[4] ?? '',
                    'secteur' => $cells[5] ?? '',
                    'poste' => $cells[6] ?? '',
                    'notes' => $cells[7] ?? '',
                ];
            } else {
                $data = [];
                foreach ($header as $columnIndex => $field) {
                    $data[$field] = $cells[$columnIndex] ?? '';
                }
            }

            $data = $this->normalizeRow($data);

            if ($data['nom_societe'] === '' && $data['email'] === '') {
                $errors[] = [
                    'ligne' => $lineNumber,
                    'message' => 'Ligne ignorée : nom de société et e-mail absents.',
                ];
                continue;
            }

            if ($data['email'] !== '' && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
                $warnings[] = [
                    'ligne' => $lineNumber,
                    'message' => sprintf('E-mail invalide conservé tel quel : « %s ».', $data['email']),
                ];
            }

            if ($data['telephone'] !== '' && preg_match('/^[0-9+().\s-]{6,}$/', $data['telephone']) !== 1) {
                $warnings[] = [
                    'ligne' => $lineNumber,
                    'message' => sprintf('Téléphone au format inhabituel : « %s ».', $data['telephone']),
                ];
            }

            $rows[] = $data;
        }

        fclose($handle);

        return [
            'rows' => $rows,
            'ignored' => $ignored,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    private function assertValidFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new \RuntimeException('Le fichier n’a pas pu être transféré.');
        }

        $size = $file->getSize();

        if ($size === false || $size > self::MAX_SIZE) {
            throw new \RuntimeException('Le fichier CSV ne doit pas dépasser 2 Mo.');
        }

        $extension = mb_strtolower((string) $file->getClientOriginalExtension());

        if (!in_array($extension, ['csv', 'txt'], true)) {
            throw new \RuntimeException('Le fichier doit être au format CSV (.csv) ou texte (.txt).');
        }
    }

    private function toUtf8(string $content): string
    {
        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $content);

        if ($converted !== false) {
            return $converted;
        }

        $converted = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $content);

        return $converted !== false ? $converted : $content;
    }

    private function detectDelimiter(string $content): string
    {
        $firstLine = strtok($content, "\n") ?: '';
        $candidates = [';' => 0, ',' => 0, "\t" => 0];
        $inQuotes = false;
        $length = strlen($firstLine);

        for ($i = 0; $i < $length; ++$i) {
            $char = $firstLine[$i];

            if ($char === '"') {
                $inQuotes = !$inQuotes;
                continue;
            }

            if (!$inQuotes && isset($candidates[$char])) {
                ++$candidates[$char];
            }
        }

        arsort($candidates);
        $delimiter = array_key_first($candidates);

        return $delimiter !== null && $candidates[$delimiter] > 0 ? $delimiter : ';';
    }

    /**
     * @param list<string> $cells
     * @return array<int, string>
     */
    private function mapHeader(array $cells): array
    {
        $map = [];

        foreach ($cells as $index => $cell) {
            $normalized = $this->normalizeHeader($cell);

            foreach (self::HEADER_ALIASES as $field => $aliases) {
                if (in_array($normalized, $aliases, true)) {
                    $map[$index] = $field;
                    break;
                }
            }
        }

        return $map;
    }

    private function normalizeHeader(string $value): string
    {
        $value = trim($value);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = $ascii !== false ? $ascii : $value;
        $value = mb_strtolower($value);

        return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
    }

    /**
     * @param array<int, mixed> $cells
     */
    private function isEmptyRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, string> $data
     * @return array<string, string>
     */
    private function normalizeRow(array $data): array
    {
        $defaults = [
            'nom_societe' => '',
            'telephone' => '',
            'email' => '',
            'contact' => '',
            'ville' => '',
            'secteur' => '',
            'poste' => '',
            'site_web' => '',
            'notes' => '',
            'date_envoi' => '',
            'reponse' => '',
            'date_reponse' => '',
        ];

        $data = array_merge($defaults, $data);

        $data['nom_societe'] = trim($data['nom_societe']);
        $data['telephone'] = preg_replace('/\.0$/', '', trim($data['telephone'])) ?? '';
        $data['email'] = mb_strtolower(trim($data['email']));
        $data['contact'] = trim($data['contact']);
        $data['ville'] = trim($data['ville']);
        $data['secteur'] = trim($data['secteur']);
        $data['poste'] = trim($data['poste']);
        $data['site_web'] = trim($data['site_web']);
        $data['notes'] = trim($data['notes']);
        $data['date_envoi'] = $this->parseDate($data['date_envoi']) ?? '';
        $data['date_reponse'] = $this->parseDate($data['date_reponse']) ?? '';
        $data['reponse'] = $this->normalizeResponse($data['reponse'], $data['date_envoi']);

        if ($data['reponse'] === SpontaneousStatus::A_ENVOYER) {
            $data['date_envoi'] = '';
            $data['date_reponse'] = '';
        } elseif (!in_array($data['reponse'], SpontaneousStatus::WITH_ANSWER, true)) {
            $data['date_reponse'] = '';
        }

        return $data;
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) === 1) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];

            return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
        }

        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $value, $matches) === 1) {
            $day = (int) $matches[1];
            $month = (int) $matches[2];
            $year = (int) $matches[3];

            return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
        }

        return null;
    }

    private function normalizeResponse(string $value, string $dateEnvoi): string
    {
        $value = $this->normalizeHeader($value);
        $fallback = $dateEnvoi !== '' ? SpontaneousStatus::EN_ATTENTE : SpontaneousStatus::A_ENVOYER;

        if ($value === '') {
            return $fallback;
        }

        $notSent = ['aenvoyer', 'pasenvoyee', 'nonenvoyee', 'pasencoreenvoyee', 'todo'];
        $positive = ['oui', 'positif', 'positive', 'accepte', 'acceptee', 'interesse', 'reponsepositive'];
        $negative = ['non', 'negatif', 'negative', 'refus', 'refuse', 'refusee', 'reponsenegative'];
        $followUp = ['relance', 'relancee', 'relanceenvoyee', 'relancee1', 'relance1'];

        if (in_array($value, $notSent, true)) {
            return SpontaneousStatus::A_ENVOYER;
        }

        if (in_array($value, $positive, true)) {
            return SpontaneousStatus::POSITIVE;
        }

        if (in_array($value, $negative, true)) {
            return SpontaneousStatus::NEGATIVE;
        }

        if (in_array($value, $followUp, true)) {
            return SpontaneousStatus::RELANCE;
        }

        return $fallback;
    }
}
