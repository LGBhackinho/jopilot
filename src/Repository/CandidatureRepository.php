<?php

namespace App\Repository;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

class CandidatureRepository
{
    private Filesystem $filesystem;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/data/candidatures.json')]
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

        if ($id === null || !isset($items[$id])) {
            $id = bin2hex(random_bytes(8));
            $data['id'] = $id;
            $data['created_at'] = $now;
        }

        $data['updated_at'] = $now;
        $items[$id] = $data;

        $this->write($items);

        return $data;
    }

    public function updateStatus(string $id, string $status): ?array
    {
        $item = $this->find($id);

        if ($item === null) {
            return null;
        }

        $item['statut'] = $status;

        return $this->save($item);
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
}
