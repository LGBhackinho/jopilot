<?php

namespace App\Service;

use App\Service\Ai\AiManager;

class InterviewCoach
{
    private const MAX_LENGTH = 30000;

    public function __construct(
        private AiManager $ai,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function prepare(string $cvText, string $offerText): array
    {
        $cvText = $this->normalize($cvText, 'Veuillez fournir le contenu de votre CV.');
        $offerText = $this->normalize($offerText, 'Veuillez coller l’annonce visée.');

        $prompt = <<<PROMPT
Tu es un coach pour entretiens d'embauche en France. Tu aides un candidat à se préparer de manière pragmatique et réaliste.

Base-toi UNIQUEMENT sur le CV et l'annonce. N'invente pas d'expérience.

Retourne uniquement un JSON valide avec exactement ces champs :
{
  "questions_techniques": [{"question": "Question technique/adaptée", "objectif": "Ce que le recruteur veut vérifier", "piste_reponse": "Piste basée sur le CV (éléments existants seulement)"}],
  "questions_comportementales": [{"question": "Question comportementale", "objectif": "...", "piste_reponse": "Piste basée sur le CV"}],
  "questions_recruteur_probables": [{"question": "Question fréquente", "conseil": "Conseil pratique"}],
  "questions_a_poser": ["Question pertinente à poser au recruteur"],
  "points_a_anticiper": ["Point potentiellement sensible à anticiper (basé sur les écarts réels)"],
  "conseils_rapides": ["Conseils concrets pour cet entretien"]
}

CV du candidat :
<<<
{$cvText}
>>>

Annonce visée :
<<<
{$offerText}
>>>
PROMPT;

        return $this->ai->generateJson($prompt, ['maxTokens' => 2400]);
    }

    /**
     * @return array<string, mixed>
     */
    public function practice(string $question, string $answer, string $cvText = '', string $offerText = ''): array
    {
        $question = trim($question);
        $answer = trim($answer);

        if ($question === '' || $answer === '') {
            throw new \RuntimeException('Question et réponse sont obligatoires.');
        }

        $context = '';
        if ($cvText !== '' || $offerText !== '') {
            $context = "\n\nContexte (à utiliser strictement si pertinent, sans inventer) :\n";
            if ($cvText !== '') {
                $ctxCv = mb_strlen($cvText) > 8000 ? mb_substr($cvText, 0, 8000) : $cvText;
                $context .= "CV : <<<\n{$ctxCv}\n>>>\n";
            }
            if ($offerText !== '') {
                $ctxOff = mb_strlen($offerText) > 8000 ? mb_substr($offerText, 0, 8000) : $offerText;
                $context .= "Annonce : <<<\n{$ctxOff}\n>>>\n";
            }
        }

        $prompt = <<<PROMPT
Tu fais un retour constructif et factuel à un candidat qui s'entraîne à un entretien.

Question posée : {$question}
Réponse du candidat : {$answer}
{$context}

Retourne uniquement un JSON valide avec exactement ces champs :
{
  "note_globale": 0,
  "points_forts": ["Ce qui est bien"],
  "axes_amelioration": ["Amélioration concrète et réaliste"],
  "reponse_modele": "Une réponse modèle SOBRE, basée sur les éléments existants du CV si disponibles, sans rien inventer",
  "conseils": ["Conseil pratique immédiat"]
}

note_globale entre 0 et 10.
PROMPT;

        return $this->ai->generateJson($prompt, ['maxTokens' => 2000]);
    }

    private function normalize(string $text, string $error): string
    {
        $text = trim($text);

        if ($text === '') {
            throw new \RuntimeException($error);
        }

        if (mb_strlen($text) > self::MAX_LENGTH) {
            $text = mb_substr($text, 0, self::MAX_LENGTH);
        }

        return $text;
    }
}
