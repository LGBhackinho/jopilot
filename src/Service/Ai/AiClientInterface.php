<?php

namespace App\Service\Ai;

interface AiClientInterface
{
    public function getName(): string;

    public function supportsJson(): bool;

    public function isConfigured(): bool;

    /**
     * @param array<string, mixed> $options
     */
    public function generateText(string $prompt, array $options = []): string;

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function generateJson(string $prompt, array $options = []): array;
}
