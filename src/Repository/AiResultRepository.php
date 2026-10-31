<?php

namespace App\Repository;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Mémoire persistante des propositions générées par l'IA.
 *
 * Chaque résultat est identifié par un « namespace » (type de tâche) et par
 * l'empreinte des données d'entrée. Tant que ces données ne changent pas, le
 * résultat enregistré est réutilisé : on ne rappelle donc pas l'IA inutilement.
 * Dès qu'un nouveau CV, une nouvelle offre ou un nouveau champ est fourni,
 * l'empreinte change et une nouvelle génération est déclenchée.
 */
class AiResultRepository
{
    /**
     * Nombre maximum d'entrées conservées (les plus récentes).
     */
    private const MAX_ENTRIES = 150;

    private Filesystem $filesystem;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/data/ai_results.json')]
        private string $filePath,
    ) {
        $this->filesystem = new Filesystem();
    }

    /**
     * Empreinte stable d'un jeu de données d'entrée.
     *
     * @param array<string, mixed> $input
     */
    public function fingerprint(string $namespace, array $input): string
    {
        $normalized = $this->normalize($input);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $namespace . ':' . sha1($json !== false ? $json : serialize($normalized));
    }

    /**
     * Retourne l'entrée enregistrée pour ces données d'entrée, ou null.
     *
     * @param array<string, mixed> $input
     * @return array{namespace: string, data: array<string, mixed>, meta: array<string, mixed>, created_at: string, updated_at: string}|null
     */
    public function get(string $namespace, array $input): ?array
    {
        $entries = $this->read();
        $entry = $entries[$this->fingerprint($namespace, $input)] ?? null;

        return $this->format($entry);
    }

    /**
     * Retourne uniquement le résultat (sans les métadonnées).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    public function result(string $namespace, array $input): ?array
    {
        $entry = $this->get($namespace, $input);

        return $entry['data'] ?? null;
    }

    /**
     * Enregistre (ou remplace) le résultat d'une génération IA.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     * @return array{namespace: string, data: array<string, mixed>, meta: array<string, mixed>, created_at: string, updated_at: string}
     */
    public function save(string $namespace, array $input, array $data, array $meta = []): array
    {
        $entries = $this->read();
        $key = $this->fingerprint($namespace, $input);
        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);

        $entry = [
            'namespace' => $namespace,
            'data' => $data,
            'meta' => $meta,
            'created_at' => (string) ($entries[$key]['created_at'] ?? $now),
            'updated_at' => $now,
        ];

        $entries[$key] = $entry;
        $this->write($this->prune($entries));

        return $this->format($entry) ?? [];
    }

    /**
     * Dernière entrée enregistrée pour un ou plusieurs namespaces.
     *
     * @param string|array<int, string> $namespaces
     * @return array{namespace: string, data: array<string, mixed>, meta: array<string, mixed>, created_at: string, updated_at: string}|null
     */
    public function latest(string|array $namespaces): ?array
    {
        $namespaces = is_array($namespaces) ? $namespaces : [$namespaces];
        $entries = $this->read();
        $latest = null;

        foreach ($entries as $entry) {
            if (!is_array($entry) || !in_array($entry['namespace'] ?? '', $namespaces, true)) {
                continue;
            }

            if ($latest === null || strcmp((string) ($entry['updated_at'] ?? ''), (string) ($latest['updated_at'] ?? '')) > 0) {
                $latest = $entry;
            }
        }

        return $this->format($latest);
    }

    /**
     * Supprime toutes les entrées (ou celles d'un namespace donné).
     */
    public function clear(?string $namespace = null): void
    {
        if ($namespace === null) {
            if (is_file($this->filePath)) {
                $this->filesystem->remove($this->filePath);
            }

            return;
        }

        $entries = array_filter(
            $this->read(),
            static fn (mixed $entry): bool => !is_array($entry) || ($entry['namespace'] ?? '') !== $namespace
        );

        $this->write($entries);
    }

    /**
     * Liste légère des entrées (sans le contenu des résultats) pour les tableaux
     * de bord et l'historique.
     *
     * @return array<int, array{namespace: string, created_at: string, updated_at: string}>
     */
    public function summaries(): array
    {
        $summaries = [];

        foreach ($this->read() as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $summaries[] = [
                'namespace' => (string) ($entry['namespace'] ?? ''),
                'created_at' => (string) ($entry['created_at'] ?? ''),
                'updated_at' => (string) ($entry['updated_at'] ?? ''),
            ];
        }

        usort($summaries, static function (array $a, array $b): int {
            return strcmp($b['updated_at'], $a['updated_at']);
        });

        return $summaries;
    }

    /**
     * @param array<string, mixed>|null $entry
     * @return array{namespace: string, data: array<string, mixed>, meta: array<string, mixed>, created_at: string, updated_at: string}|null
     */
    private function format(?array $entry): ?array
    {
        if ($entry === null || !isset($entry['data']) || !is_array($entry['data'])) {
            return null;
        }

        return [
            'namespace' => (string) ($entry['namespace'] ?? ''),
            'data' => $entry['data'],
            'meta' => is_array($entry['meta'] ?? null) ? $entry['meta'] : [],
            'created_at' => (string) ($entry['created_at'] ?? ''),
            'updated_at' => (string) ($entry['updated_at'] ?? ''),
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
     * @param array<string, array<string, mixed>> $entries
     */
    private function write(array $entries): void
    {
        $this->filesystem->mkdir(\dirname($this->filePath));

        file_put_contents(
            $this->filePath,
            json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Ne conserve que les entrées les plus récentes pour éviter un fichier
     * qui grossit indéfiniment.
     *
     * @param array<string, array<string, mixed>> $entries
     * @return array<string, array<string, mixed>>
     */
    private function prune(array $entries): array
    {
        if (count($entries) <= self::MAX_ENTRIES) {
            return $entries;
        }

        uasort($entries, static function (array $a, array $b): int {
            return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
        });

        return array_slice($entries, 0, self::MAX_ENTRIES, true);
    }

    /**
     * Normalise récursivement les entrées pour que l'empreinte soit stable
     * (ordre des clés, espaces de début/fin de texte).
     */
    private function normalize(mixed $value): mixed
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[$key] = $this->normalize($item);
        }

        if (!$isList) {
            ksort($normalized);
        }

        return $normalized;
    }
}
