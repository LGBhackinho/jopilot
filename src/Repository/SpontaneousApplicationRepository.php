<?php

namespace App\Repository;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Stockage fichier JSON des sociétés démarchées en candidature spontanée.
 *
 * Même approche que CandidatureRepository : pas de base de données,
 * un fichier lisible dans var/data.
 */
class SpontaneousApplicationRepository
{
    private Filesystem $filesystem;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/data/candidatures_spontanees.json')]
        private string $filePath,
    ) {
        $this->filesystem = new Filesystem();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return array_values($this->read());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $items = $this->read();

        return $items[$id] ?? null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function save(array $data): array
    {
        $items = $this->read();

        $id = $data['id'] ?? null;
        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);

        if (!is_string($id) || $id === '' || !isset($items[$id])) {
            $id = bin2hex(random_bytes(8));
            $data['created_at'] = $now;
        }

        $data['id'] = $id;
        $data['updated_at'] = $now;
        $items[$id] = $data;

        $this->write($items);

        return $data;
    }

    /**
     * Enregistre plusieurs lignes (import CSV) en une seule écriture disque.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return int Nombre de lignes réellement ajoutées.
     */
    public function saveMany(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $items = $this->read();
        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $count = 0;

        foreach ($rows as $row) {
            $id = bin2hex(random_bytes(8));
            $row['id'] = $id;
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $items[$id] = $row;
            ++$count;
        }

        $this->write($items);

        return $count;
    }

    public function delete(string $id): void
    {
        $items = $this->read();

        if (isset($items[$id])) {
            unset($items[$id]);
            $this->write($items);
        }
    }

    /**
     * Construit un index des doublons probables avant un import.
     *
     * Règle : même e-mail (insensible à la casse) ou, à défaut d'e-mail,
     * même nom de société déjà enregistré sans e-mail.
     *
     * @return array{emails: array<string, true>, companiesWithoutEmail: array<string, true>}
     */
    public function duplicateIndex(): array
    {
        $emails = [];
        $companiesWithoutEmail = [];

        foreach ($this->read() as $item) {
            $email = $this->normalizeEmail((string) ($item['email'] ?? ''));
            $company = $this->normalizeCompany((string) ($item['nom_societe'] ?? ''));

            if ($email !== '') {
                $emails[$email] = true;
            } elseif ($company !== '') {
                $companiesWithoutEmail[$company] = true;
            }
        }

        return [
            'emails' => $emails,
            'companiesWithoutEmail' => $companiesWithoutEmail,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function read(): array
    {
        if (!is_file($this->filePath)) {
            return [];
        }

        $content = (string) file_get_contents($this->filePath);

        if (trim($content) === '') {
            return [];
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, array<string, mixed>> $items
     */
    private function write(array $items): void
    {
        $this->filesystem->mkdir(\dirname($this->filePath));

        file_put_contents(
            $this->filePath,
            json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function normalizeCompany(string $company): string
    {
        $company = mb_strtolower(trim($company));

        // Ignore la ponctuation et les espaces multiples pour comparer « ACME » et « Acme. ».
        $company = preg_replace('/[^a-z0-9]+/u', '', $company) ?? '';

        return $company;
    }
}
