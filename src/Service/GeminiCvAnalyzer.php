<?php

namespace App\Service;

use App\Service\Ai\AiManager;

class GeminiCvAnalyzer
{
    private const MAX_LENGTH = 30000;

    public function __construct(
        private AiManager $ai,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function analyze(string $cvText): array
    {
        $cvText = trim($cvText);

        if ($cvText === '') {
            throw new \RuntimeException('Veuillez fournir le contenu de votre CV.');
        }

        if (mb_strlen($cvText) > self::MAX_LENGTH) {
            $cvText = mb_substr($cvText, 0, self::MAX_LENGTH);
        }

        $prompt = <<<PROMPT
Tu es un spécialiste de l'analyse de CV à destination d'un candidat qui veut cibler ses candidatures efficacement.

Analyse UNIQUEMENT le CV ci-dessous. N'invente rien.

Retourne uniquement un JSON valide avec exactement ces champs :
{
  "identite": {"nom": "", "prenom": "", "contact": "", "localisation": ""},
  "profil": "Synthèse factuelle du profil en 2-4 phrases",
  "competences": ["Compétences clairement présentes dans le CV"],
  "technologies": ["Outils/langages clairement présents"],
  "formations": ["Formations présentes"],
  "experiences": [{"intitule": "", "entreprise": "", "periode": "", "points_cles": ["Réalisations/tâches présentes"]}],
  "langues": ["Langues indiquées"],
  "qualites": ["Qualités/soft skills explicitement indiqués ou très évidents"],
  "metiers_suggeres": ["Postes types correspondant AU MIEUX aux éléments présents"],
  "mots_cles_ats": ["Mots-clés utiles à valoriser tels qu'ils apparaissent"],
  "axes_amelioration": ["Suggestions concrètes SANS ajouter d'information inexistante"]
}

CV à analyser :
<<<
{$cvText}
>>>
PROMPT;

        return $this->ai->generateJson($prompt, ['maxTokens' => 4096]);
    }
}
