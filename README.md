# JobPilot

Application Symfony personnelle d’aide à la recherche d’emploi et au suivi des candidatures.

## Objectif

Centraliser le parcours de candidature dans un seul outil :
- analyser son CV avec l’IA ;
- rechercher des offres ;
- mesurer l’adéquation entre un CV et une annonce ;
- générer des lettres de motivation sans inventer d’expérience ;
- préparer les entretiens ;
- suivre les candidatures classiques et spontanées.

## Tableau de bord d’accueil

L’accueil est un tableau de bord personnel construit à partir des données locales :
- indicateurs : candidatures actives, entretiens, relances, candidatures spontanées, propositions IA ;
- état du CV et de son analyse IA (à jour, absente ou obsolète) ;
- graphiques CSS/HTML : activité des 8 dernières semaines, pipeline des candidatures, suivi spontané ;
- liste **« À faire maintenant »** générée automatiquement selon la situation réelle : relances en retard, entretiens à préparer, candidatures à postuler, CV à analyser, etc. ;
- dernières activités (candidatures, spontanées et résultats IA) ;
- accès rapide à tous les outils.

Le tableau de bord ne réalise aucun appel réseau : il reste rapide et fonctionne uniquement avec les fichiers locaux.

## Analyse et propositions IA

Les résultats produits par l’IA sont enregistrés dans `var/data/ai_results.json`.

Chaque résultat est associé à une empreinte des données d’entrée (contenu du CV, texte de l’offre, réponse d’entraînement, etc.) :
- si les données n’ont pas changé, le résultat enregistré est **réutilisé sans nouvel appel IA** ;
- dès qu’un nouveau CV, une nouvelle offre ou un nouveau champ est fourni, une nouvelle génération est proposée ;
- un bouton **« Relancer / Régénérer »** permet de forcer volontairement une nouvelle génération.

Cela couvre l’analyse du CV, l’analyse d’une annonce, le matching CV ↔ offre, les lettres, les questions d’entretien et les retours d’entraînement.

## Mon CV

La page **Mon CV** est organisée en trois onglets :
1. **Mon CV** : saisie, modification, import PDF et analyse ;
2. **Analyse IA** : synthèse du profil, compétences, technologies, expériences, formations, langues, métiers suggérés, mots-clés ATS et pistes d’amélioration ;
3. **Offres** : recherche d’offres accessible à tout moment, avec mots-clés, ville et rayon, préremplie à partir de l’analyse du CV.

Les expériences sont affichées sous forme de cartes lisibles (poste, entreprise, période, points clés), plus de JSON brut.

Un spinner plein écran s’affiche pendant les traitements IA et les recherches d’offres.

## Candidatures spontanées

Le menu **Candidatures** propose un second onglet **Candidatures spontanées** :
- ajout manuel d’une société (nom, contact, téléphone, e-mail, ville, secteur, poste visé) ;
- import CSV (séparateur `;`, `,` ou tabulation, en-têtes accentués acceptés) avec détection des doublons ;
- génération d’une lettre de motivation spontanée à partir du CV, sans invention d’expérience ;
- tableau de suivi : date d’envoi, statut de réponse, date de réponse et accès à la lettre.

Les données sont stockées dans `var/data/candidatures_spontanees.json`. Un modèle CSV téléchargeable est disponible depuis l’onglet.

## Profil CV persistant

Le CV est enregistré une seule fois dans `var/data/cv.json` (texte extrait, nom du fichier d’origine et nom du candidat), puis réutilisé automatiquement dans :
- **Mon CV** ;
- **CV ↔ Offre** ;
- **Entretien** ;
- **Candidatures spontanées**.

Le PDF binaire n’est pas conservé : seul son texte extrait est stocké, et il peut être remplacé à tout moment.

## Stockage des données

Pas de base de données : les données sont des fichiers JSON dans `var/data/`.

| Fichier | Contenu |
| --- | --- |
| `cv.json` | Texte du CV, nom du fichier d’origine, nom du candidat |
| `candidatures.json` | Suivi des candidatures classiques |
| `candidatures_spontanees.json` | Suivi des sociétés contactées en spontané et lettres générées |
| `ai_results.json` | Résultats IA enregistrés, réutilisables tant que les données d’entrée ne changent pas |

## Configuration IA

Les providers sont configurables via les variables d’environnement (voir `.env.ai.dist`) :
- `AI_PROVIDER` : `auto`, `albert`, `openai`, `claude` ou `gemini` ;
- `AI_MODEL` : modèle à forcer (facultatif) ;
- clés et endpoints : `ALBERT_*`, `OPENAI_*`, `ANTHROPIC_*`, `GEMINI_*`.

L’application privilégie le provider configuré et bascule sur les autres providers configurés en cas d’échec.

## Langages & technologies

- **Backend** : PHP 8.x, Symfony (cf. `composer.json`, `symfony.lock`)
- **Frontend** : Twig, CSS et JavaScript natifs (aucune librairie de graphiques)
- **Dépendances** : Composer
- **Configuration** : `config/`, variables via `.env` (non commité)

## Architecture

- `src/Controller/` : contrôleurs HTTP ;
- `src/Repository/` : accès aux fichiers JSON (`CvProfileRepository`, `CandidatureRepository`, `SpontaneousApplicationRepository`, `AiResultRepository`) ;
- `src/Service/` : logique métier, intégration IA, recherche d’offres et agrégation du tableau de bord (`DashboardService`) ;
- `src/Service/Ai/` : gestion multi-providers et clients IA ;
- `templates/` : vues Twig ;
- `var/data/` : données persistantes ;
- `bin/console` : commandes Symfony.

## Démarrage (dev)

```bash
composer install
php bin/console cache:clear
symfony serve        # ou : php -S 127.0.0.1:8000 -t public
```

## Remarques

- Les données locales ne sont pas versionnées : pensez à sauvegarder `var/data/`.
- La recherche d’offres locales utilise l’API France Travail lorsqu’elle est configurée (`FRANCE_TRAVAIL_CLIENT_ID`, `FRANCE_TRAVAIL_CLIENT_SECRET`). Sinon, l’application affiche des offres à distance et des liens vers les plateformes.
