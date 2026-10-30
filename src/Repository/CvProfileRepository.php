<?php

namespace App\Repository;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Profil CV persistant, partagé par tous les écrans de l'application.
 *
 * Seul le texte extrait du CV est conservé (pas le PDF binaire), ce qui suffit
 * à l'IA et évite de stocker un document lourd.
 */
class CvProfileRepository
{
    private const MAX_LENGTH = 30000;

    private Filesystem $filesystem;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/data/cv.json')]
        private string $filePath,
    ) {
        $this->filesystem = new Filesystem();
    }

    /**
     * @return array{cv_text: string, cv_filename: string, nom_candidat: string, updated_at: string}
     */
    public function get(): array
    {
        $data = $this->read();

        return [
            'cv_text' => (string) ($data['cv_text'] ?? ''),
            'cv_filename' => (string) ($data['cv_filename'] ?? ''),
            'nom_candidat' => (string) ($data['nom_candidat'] ?? ''),
            'updated_at' => (string) ($data['updated_at'] ?? ''),
        ];
    }

    public function hasCv(): bool
    {
        return trim($this->get()['cv_text']) !== '';
    }

    /**
     * Enregistre ou remplace le CV.
     *
     * @param string|null $candidateName null pour conserver le nom déjà enregistré.
     * @return array{cv_text: string, cv_filename: string, nom_candidat: string, updated_at: string}
     */
    public function saveText(string $text, string $filename = '', ?string $candidateName = null): array
    {
        $text = trim($text);

        if ($text === '') {
            throw new \RuntimeException('Le CV ne peut pas être vide.');
        }

        if (mb_strlen($text) > self::MAX_LENGTH) {
            $text = mb_substr($text, 0, self::MAX_LENGTH);
        }

        $data = $this->read();
        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);

        $data['cv_text'] = $text;
        if ($filename !== '') {
            $data['cv_filename'] = trim($filename);
        }
        if ($candidateName !== null) {
            $data['nom_candidat'] = trim($candidateName);
        }

        $data['updated_at'] = $now;
        $data['created_at'] = (string) ($data['created_at'] ?? $now);

        $this->write($data);

        return $this->get();
    }

    /**
     * @param string $name Nom du candidat à réutiliser pour les lettres.
     * @return array{cv_text: string, cv_filename: string, nom_candidat: string, updated_at: string}
     */
    public function saveCandidateName(string $name): array
    {
        $data = $this->read();
        $data['nom_candidat'] = trim($name);
        $data['updated_at'] = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $data['created_at'] = (string) ($data['created_at'] ?? $data['updated_at']);

        $this->write($data);

        return $this->get();
    }

    public function clear(): void
    {
        if (is_file($this->filePath)) {
            $this->filesystem->remove($this->filePath);
        }
    }

    /**
     * @return array<string, mixed>
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
     * @param array<string, mixed> $data
     */
    private function write(array $data): void
    {
        $this->filesystem->mkdir(\dirname($this->filePath));

        file_put_contents(
            $this->filePath,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}
