# Jobpilot

Application Symfony pour le pilotage/recherche d'offres d'emploi (Jobpilot).

## Objectif
Aider au suivi des candidatures/offres avec une logique métier structurée.

## Candidatures spontanées
Le menu **Candidatures** propose un second onglet **Candidatures spontanées** :
- ajout manuel d'une société (nom, contact, téléphone, e-mail, ville, secteur, poste visé) ;
- import CSV (séparateur `;`, `,` ou tabulation, en-têtes accentués acceptés) avec détection des doublons ;
- génération d'une lettre de motivation spontanée à partir du CV, sans invention d'expérience ;
- tableau de suivi : date d'envoi, statut de réponse, date de réponse et accès à la lettre.

Les données sont stockées dans `var/data/candidatures_spontanees.json`. Un modèle CSV téléchargeable est disponible depuis l'onglet.

## Profil CV persistant
Le CV est enregistré une seule fois dans `var/data/cv.json` (texte extrait, nom du fichier d'origine et nom du candidat), puis réutilisé automatiquement dans :
- **Mon CV** (enregistrement, modification, remplacement par PDF, analyse) ;
- **CV ↔ Offre** ;
- **Entretien** ;
- **Candidatures spontanées** (génération de lettre).

Le PDF binaire n'est pas conservé : seul son texte extrait est stocké, et il peut être remplacé à tout moment.

## Langages & Technologies
- **Backend** : PHP 8.x, Symfony (cf. `symfony.lock`, `composer.json`)
- **Frontend** : Twig (`templates/`), assets classiques
- **Dépendances** : Composer
- **Config** : `config/`, variables via `.env` (non commité)

## Architecture
- Architecture Symfony (MVC): `src/` (Controller/Entity/Repository/Service), `config/`, `templates/`, `public/` (point d'entrée)
- `bin/console` pour commandes
- `var/` cache/logs, `vendor/`

## Démarrage (dev)
```bash
composer install
symfony serve  # ou php -S
```

## Remarques
Projet complet type Symfony, à configurer avec sa base/variables.
