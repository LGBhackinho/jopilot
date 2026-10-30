<?php

namespace App\Service\Ai;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class AiManager
{
    private bool $iaAlbert = false;
    private string $aiProvider = 'auto';
    private string $aiModel = '';
    private bool $onlyAlbert = false;

    public function __construct(
        #[TaggedIterator('app.ai_client')] private iterable $clients,
        private ParameterBagInterface $params,
        private ?LoggerInterface $logger = null,
    ) {
        $envIa = $_ENV['IA_ALBERT'] ?? getenv('IA_ALBERT') ?? 'false';
        if (is_array($envIa)) {
            $envIa = $envIa[0] ?? 'false';
        }
        $this->iaAlbert = filter_var($envIa, FILTER_VALIDATE_BOOLEAN);
        $prov = $_ENV['AI_PROVIDER'] ?? getenv('AI_PROVIDER') ?? 'auto';
        if (is_array($prov)) {
            $prov = $prov[0] ?? 'auto';
        }
        $mod = $_ENV['AI_MODEL'] ?? getenv('AI_MODEL') ?? '';
        if (is_array($mod)) {
            $mod = $mod[0] ?? '';
        }
        $this->aiProvider = is_string($prov) && trim($prov) !== '' ? trim($prov) : 'auto';
        $this->aiModel = is_string($mod) ? trim($mod) : '';
        $only = $_ENV['AI_ONLY_ALBERT'] ?? getenv('AI_ONLY_ALBERT') ?? 'false';
        if (is_array($only)) {
            $only = $only[0] ?? 'false';
        }
        $this->onlyAlbert = filter_var($only, FILTER_VALIDATE_BOOLEAN);
    }

    public function getActiveClient(): AiClientInterface
    {
        $preferred = $this->getPreferredClient();

        if ($preferred !== null) {
            return $preferred;
        }

        foreach ($this->getClientsByPriority() as $client) {
            if (!$client instanceof AiClientInterface) {
                continue;
            }
            if ($client->isConfigured()) {
                $n = strtolower($client->getName());
                if ($this->onlyAlbert && !str_contains($n, 'albert')) {
                    continue;
                }
                return $client;
            }
        }

        throw new \RuntimeException(
            'Aucun provider IA configuré. Renseigne ALBERT_API_KEY / OPENAI_API_KEY / ANTHROPIC_API_KEY / GEMINI_API_KEY dans ton .env ou active IA_ALBERT.'
        );
    }

    /**
     * Fournisseur explicitement demandé via AI_PROVIDER (hors « auto »).
     */
    private function getPreferredClient(): ?AiClientInterface
    {
        $provider = strtolower(trim($this->aiProvider));

        if ($provider === '' || $provider === 'auto') {
            return null;
        }

        foreach ($this->getClientsByPriority() as $client) {
            if (!$client instanceof AiClientInterface) {
                continue;
            }

            $name = strtolower($client->getName());

            if (($name === $provider || str_contains($name, $provider)) && $client->isConfigured()) {
                return $client;
            }
        }

        return null;
    }

    /**
     * Clients réellement appelés pour une requête.
     *
     * Si AI_PROVIDER désigne un fournisseur configuré, il est utilisé seul :
     * on évite ainsi les erreurs parasites d'un autre fournisseur (clé expirée,
     * endpoint indisponible) et on n'envoie pas les données à un service non voulu.
     *
     * @return array<int, AiClientInterface>
     */
    private function getRequestClients(): array
    {
        $preferred = $this->getPreferredClient();

        return $preferred !== null ? [$preferred] : $this->getClientsByPriority();
    }

    /**
     * @return array<int, AiClientInterface>
     */
    private function getClientsByPriority(): array
    {
        $all = is_array($this->clients) ? $this->clients : iterator_to_array($this->clients);
        $configured = [];
        $others = [];

        foreach ($all as $client) {
            if (!$client instanceof AiClientInterface) {
                continue;
            }
            if ($client->isConfigured()) {
                $configured[] = $client;
            } else {
                $others[] = $client;
            }
        }

        $ordered = [...$configured, ...$others];

        if ($this->iaAlbert || $this->onlyAlbert) {
            usort($ordered, function (AiClientInterface $a, AiClientInterface $b) {
                $an = strtolower($a->getName());
                $bn = strtolower($b->getName());
                $aAlbert = str_contains($an, 'albert');
                $bAlbert = str_contains($bn, 'albert');
                if ($aAlbert && !$bAlbert) {
                    return -1;
                }
                if ($bAlbert && !$aAlbert) {
                    return 1;
                }
                return 0;
            });
        }

        return $ordered;
    }

    public function getName(): string
    {
        try {
            return $this->getActiveClient()->getName();
        } catch (\Throwable $e) {
            return 'aucun';
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    public function generateText(string $prompt, array $options = []): string
    {
        if (!isset($options['model']) || !is_string($options['model']) || trim($options['model']) === '') {
            if ($this->aiModel !== '') {
                $options['model'] = $this->aiModel;
            }
        }

        $clients = $this->getRequestClients();
        $errors = [];
        foreach ($clients as $client) {
            if (!$client instanceof AiClientInterface || !$client->isConfigured()) {
                continue;
            }
            $name = strtolower($client->getName());
            if ($this->onlyAlbert && !str_contains($name, 'albert')) {
                throw new \RuntimeException($client->getName() . ': Requête Albert échouée : HTTP 404');
            }
            try {
                return $client->generateText($prompt, $options);
            } catch (\Throwable $e) {
                $errors[] = $client->getName() . ': ' . $e->getMessage();
                if ($this->onlyAlbert) {
                    throw new \RuntimeException(implode(' | ', array_slice($errors, 0, 5)));
                }
                continue;
            }
        }
        if ($errors !== []) {
            throw new \RuntimeException(implode(' | ', array_slice($errors, 0, 5)));
        }
        throw new \RuntimeException(
            'Aucun provider IA configuré. Renseigne ALBERT_API_KEY / OPENAI_API_KEY / ANTHROPIC_API_KEY / GEMINI_API_KEY dans ton .env ou active IA_ALBERT.'
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function generateJson(string $prompt, array $options = []): array
    {
        if (!isset($options['model']) || !is_string($options['model']) || trim($options['model']) === '') {
            if ($this->aiModel !== '') {
                $options['model'] = $this->aiModel;
            }
        }

        $clients = $this->getRequestClients();
        $errors = [];
        foreach ($clients as $client) {
            if (!$client instanceof AiClientInterface || !$client->isConfigured()) {
                continue;
            }
            $name = strtolower($client->getName());
            if ($this->onlyAlbert && !str_contains($name, 'albert')) {
                throw new \RuntimeException($client->getName() . ': Requête Albert échouée : HTTP 404');
            }
            try {
                $result = $client->generateJson($prompt, $options);
                if (is_array($result)) {
                    return $result;
                }
            } catch (\Throwable $e) {
                $errors[] = $client->getName() . ': ' . $e->getMessage();
                if ($this->onlyAlbert) {
                    throw new \RuntimeException(implode(' | ', array_slice($errors, 0, 5)));
                }
                continue;
            }
        }
        if ($errors !== []) {
            throw new \RuntimeException(implode(' | ', array_slice($errors, 0, 5)));
        }
        throw new \RuntimeException(
            'Aucun provider IA configuré. Renseigne ALBERT_API_KEY / OPENAI_API_KEY / ANTHROPIC_API_KEY / GEMINI_API_KEY dans ton .env ou active IA_ALBERT.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $list = [];
        foreach ($this->getRequestClients() as $client) {
            $list[] = [
                'name' => $client->getName(),
                'configured' => $client->isConfigured(),
                'supportsJson' => $client->supportsJson(),
            ];
        }

        return [
            'active' => $this->getName(),
            'ia_albert' => $this->iaAlbert,
            'ai_provider' => $this->aiProvider,
            'ai_model' => $this->aiModel !== '' ? $this->aiModel : null,
            'only_albert' => $this->onlyAlbert,
            'providers' => $list,
        ];
    }
}
