<?php

namespace App\Service\JobOffer;

/**
 * Déduit le secteur d'activité (codes NAF) d'une recherche d'emploi.
 *
 * L'annuaire officiel des entreprises ne cherche que sur les **noms** et
 * l'**activité principale** des sociétés. Lui envoyer une requête issue du CV
 * comme « Développeur Web Full Stack PHP Symfony React » ne renvoie donc
 * jamais rien : aucun employeur ne s'appelle ainsi.
 *
 * Ce mapping traduit les métiers et compétences du CV en codes NAF
 * (nomenclature officielle INSEE) que l'API sait filtrer. Il sert aussi de
 * secours : chaque secteur expose un mot-clé lisible (`informatique`,
 * `comptabilité`…) utilisable comme recherche plein texte.
 */
final class ActivitySectorMapper
{
    /**
     * @var array<string, array{
     *     label: string,
     *     section: string,
     *     keyword: string,
     *     naf: list<string>,
     *     keywords: list<string>
     * }>
     */
    private const SECTORS = [
        'informatique' => [
            'label' => 'Informatique & numérique',
            'section' => 'J',
            'keyword' => 'informatique',
            'naf' => [
                '58.21Z', '58.29A', '58.29C',
                '62.01Z', '62.02A', '62.02B', '62.03Z', '62.09Z',
                '63.11Z', '63.12Z',
                '95.11Z', '95.12Z',
            ],
            'keywords' => [
                'informatique', 'numérique', 'numerique', 'informatcien', 'informaticien',
                'développeur', 'developpeur', 'développeuse', 'developpeuse', 'developer',
                'programmeur', 'software', 'logiciel', 'web', 'fullstack', 'full stack',
                'full-stack', 'frontend', 'front end', 'backend', 'back end', 'front-end', 'back-end',
                'php', 'symfony', 'laravel', 'react', 'vue', 'vuejs', 'angular', 'javascript',
                'typescript', 'node', 'nodejs', 'java', 'spring', 'python', 'django', 'flask',
                'c++', 'c#', '.net', 'dotnet', 'golang', 'rust', 'kotlin', 'swift',
                'devops', 'sre', 'cloud', 'aws', 'azure', 'gcp', 'kubernetes', 'docker',
                'sysadmin', 'réseau', 'reseau', 'réseaux', 'reseaux', 'système', 'systeme',
                'administrateur', 'support', 'helpdesk', 'help desk', 'technicien',
                'cybersécurité', 'cybersecurite', 'cybersecurity', 'sécurité', 'securite',
                'data', 'datascience', 'data science', 'data analyst', 'data engineer',
                'intelligence artificielle', 'machine learning', 'deep learning', 'ia',
                'erp', 'crm', 'testeur', 'qa', 'mobile', 'android', 'ios', 'wordpress',
                'intégrateur', 'integrateur', 'architecte', 'sre', 'sql', 'dba', 'scrum',
                'agile', 'product owner', 'product manager', 'ux', 'ui', 'webmaster',
            ],
        ],
        'marketing-communication' => [
            'label' => 'Marketing, communication & création',
            'section' => 'M',
            'keyword' => 'communication',
            'naf' => [
                '70.21Z', '73.11Z', '73.12Z', '73.20Z', '74.90B',
                '74.10Z', '74.20Z', '63.91Z', '90.03A', '90.03B',
                '59.11A', '59.11B', '59.11C', '59.12Z', '59.13A', '59.13B', '59.14Z',
            ],
            'keywords' => [
                'marketing', 'communication', 'communicant', 'community manager', 'social media',
                'seo', 'sea', 'sem', 'growth', 'growth hacker', 'traffic manager',
                'rédacteur', 'redacteur', 'rédactrice', 'redactrice', 'copywriter', 'content manager',
                'graphiste', 'graphisme', 'designer', 'design', 'directeur artistique',
                'illustrateur', 'photographe', 'photographie', 'vidéaste', 'videaste', 'monteur',
                'événementiel', 'evenementiel', 'publicité', 'publicite', 'brand', 'branding',
                'chargé de communication', 'attache de presse', 'journaliste', 'rédaction',
                'storytelling', 'motion design', 'webdesign', 'web design',
            ],
        ],
        'comptabilite-finance' => [
            'label' => 'Comptabilité, gestion & finance',
            'section' => 'M',
            'keyword' => 'comptabilité',
            'naf' => [
                '69.10Z', '69.20Z', '64.19Z', '64.30Z', '64.91Z', '64.99Z',
                '66.11Z', '66.12Z', '66.19B', '66.21Z', '66.22Z', '66.29Z', '66.30Z',
                '70.22Z', '70.10Z',
            ],
            'keywords' => [
                'comptable', 'comptabilité', 'comptabilite', 'compta', 'expert-comptable',
                'expert comptable', 'audit', 'auditeur', 'contrôleur de gestion', 'controleur de gestion',
                'contrôle de gestion', 'finance', 'financier', 'financière', 'analyste financier',
                'trésorerie', 'tresorerie', 'fiscalité', 'fiscalite', 'fiscaliste', 'paie',
                'gestionnaire de paie', 'banque', 'assurance', 'assureur', 'courtier',
                'gestion', 'gestionnaire', 'billing', 'recouvrement', 'crédit', 'credit',
                'consolidation', 'reporting', 'budget', 'cac', 'expertise comptable',
            ],
        ],
        'ressources-humaines' => [
            'label' => 'Ressources humaines & recrutement',
            'section' => 'M',
            'keyword' => 'ressources humaines',
            'naf' => [
                '78.10Z', '78.20Z', '78.30Z', '70.22Z', '94.11Z', '94.12Z',
            ],
            'keywords' => [
                'ressources humaines', 'ressource humaine', 'rh', 'recruteur', 'recruteuse',
                'recrutement', 'talent acquisition', 'chasseur de têtes', 'chasseur de tetes',
                'gestionnaire rh', 'chargé rh', 'charge rh', 'drh', 'sirh', 'formation',
                'formateur', 'formatrice', 'coach', 'psychologue du travail', 'relations sociales',
                'droit du travail', 'salaire', 'rémunération', 'remuneration', 'onboarding',
            ],
        ],
        'commercial-vente' => [
            'label' => 'Commercial, vente & achats',
            'section' => 'M',
            'keyword' => 'commercial',
            'naf' => [
                '46.19B', '46.49Z', '46.90Z', '47.91A', '47.91B',
                '70.22Z', '73.20Z', '74.90B', '82.99Z',
            ],
            'keywords' => [
                'commercial', 'commerciale', 'vente', 'ventes', 'vendeur', 'vendeuse',
                'business developer', 'business development', 'account manager', 'key account',
                'ingénieur d\'affaires', 'ingenieur d\'affaires', 'ingénieur commercial',
                'chargé d\'affaires', 'charge d\'affaires', 'chef de secteur', 'chef de produit',
                'responsable des ventes', 'directeur commercial', 'retail', 'e-commerce', 'ecommerce',
                'acheteur', 'acheteuse', 'achats', 'sourcing', 'négociation', 'negociation',
                'advisor', 'conseiller', 'conseillère', 'conseillere', 'technico-commercial',
                'prospection', 'prospect', 'closing', 'sdr', 'csm',
            ],
        ],
        'commerce-retail' => [
            'label' => 'Commerce & distribution',
            'section' => 'G',
            'keyword' => 'commerce',
            'naf' => [
                '47.11B', '47.11C', '47.11D', '47.11E', '47.11F', '47.19A', '47.19B',
                '47.21Z', '47.22Z', '47.23Z', '47.24Z', '47.25Z', '47.26Z', '47.29Z',
                '47.30Z', '47.41Z', '47.42Z', '47.43Z', '47.51Z', '47.52A', '47.52B',
                '47.53Z', '47.54Z', '47.59A', '47.59B', '47.61Z', '47.62Z', '47.63Z',
                '47.64Z', '47.65Z', '47.71Z', '47.72A', '47.72B', '47.73Z', '47.74Z',
                '47.75Z', '47.76Z', '47.77Z', '47.78A', '47.78B', '47.78C', '47.79Z',
                '47.81Z', '47.82Z', '47.89Z', '47.91A', '47.91B', '47.99A', '47.99B',
            ],
            'keywords' => [
                'commerce', 'commerçant', 'commercant', 'magasin', 'boutique', 'retail',
                'caissier', 'caissière', 'caissiere', 'hôte de caisse', 'employé libre-service',
                'vendeur', 'vendeuse', 'rayon', 'chef de rayon', 'adjoint de rayon',
                'responsable de magasin', 'directeur de magasin', 'merchandiser', 'merchandising',
                'distribution', 'épicerie', 'epicerie', 'supérette', 'superette', 'grande surface',
            ],
        ],
        'logistique-transport' => [
            'label' => 'Logistique, transport & supply chain',
            'section' => 'H',
            'keyword' => 'logistique',
            'naf' => [
                '49.31Z', '49.32Z', '49.39A', '49.39B', '49.39C',
                '49.41A', '49.41B', '49.42Z',
                '50.10Z', '50.20Z', '50.30Z', '50.40Z',
                '51.10Z', '51.21Z',
                '52.10A', '52.10B', '52.21Z', '52.24A', '52.29A', '52.29B',
                '53.10Z', '53.20Z',
            ],
            'keywords' => [
                'logistique', 'supply chain', 'transport', 'transporteur', 'chauffeur',
                'conducteur', 'chauffeur-livreur', 'livreur', 'routier', 'poids lourd',
                'magasinier', 'préparateur de commandes', 'preparateur de commandes',
                'préparation de commandes', 'cariste', 'entrepôt', 'entrepot', 'dock',
                'approvisionnement', 'approvisionneur', 'ordonnancement', 'planification',
                'douane', 'transit', 'transitaire', 'fret', 'freight', 'affrètement',
                'gestionnaire de stock', 'stock', 'inventaire', 'manutention', 'acheminement',
                'coursier', 'taxi', 'vtc', 'ambulancier', 'déménageur', 'demenageur',
            ],
        ],
        'btp-construction' => [
            'label' => 'BTP, construction & immobilier',
            'section' => 'F',
            'keyword' => 'bâtiment',
            'naf' => [
                '41.10A', '41.10B', '41.10C', '41.10D', '41.20A', '41.20B',
                '42.11Z', '42.12Z', '42.13A', '42.21Z', '42.22Z', '42.91Z',
                '43.11Z', '43.12A', '43.12B', '43.21A', '43.22A', '43.29A',
                '43.31Z', '43.32A', '43.32B', '43.33Z', '43.34Z', '43.39Z',
                '43.91A', '43.91B', '43.99C', '43.99D',
                '71.11Z', '71.12B', '68.10Z', '68.20A', '68.20B', '68.31Z', '68.32A', '68.32B',
            ],
            'keywords' => [
                'btp', 'bâtiment', 'batiment', 'construction', 'chantier', 'travaux',
                'maçon', 'maçonnerie', 'macon', 'maconnerie', 'électricien', 'electricien',
                'électricité', 'electricite', 'plombier', 'plomberie', 'menuisier', 'menuiserie',
                'charpentier', 'charpente', 'couvreur', 'couverture', 'peintre', 'peinture',
                'carreleur', 'carrelage', 'plaquiste', 'plâtrerie', 'platrerie', 'isolation',
                'conducteur de travaux', 'chef de chantier', 'génie civil', 'genie civil',
                'architecte', 'dessinateur', 'topographe', 'géomètre', 'geometre',
                'immobilier', 'agent immobilier', 'transaction', 'gestion locative',
                'syndic', 'promoteur', 'second œuvre', 'second oeuvre', 'gros œuvre', 'gros oeuvre',
                'chauffagiste', 'climatisation', 'frigoriste', 'serrurier',
            ],
        ],
        'sante-social' => [
            'label' => 'Santé, soin & social',
            'section' => 'Q',
            'keyword' => 'santé',
            'naf' => [
                '86.10Z', '86.21Z', '86.22A', '86.22B', '86.22C', '86.23Z',
                '86.90A', '86.90B', '86.90C', '86.90D', '86.90E', '86.90F',
                '87.10A', '87.20A', '87.30A', '87.90A',
                '88.10A', '88.91A', '88.99B', '75.00Z',
            ],
            'keywords' => [
                'santé', 'sante', 'soin', 'soignant', 'soignante', 'infirmier', 'infirmière',
                'infirmiere', 'médecin', 'medecin', 'aide-soignant', 'aide soignant',
                'aide-soignante', 'auxiliaire de vie', 'kinésithérapeute', 'kinesitherapeute',
                'kiné', 'kine', 'ostéopathe', 'osteopathe', 'pharmacien', 'pharmacie',
                'dentiste', 'sage-femme', 'orthophoniste', 'ergothérapeute', 'opticien',
                'vétérinaire', 'veterinaire', 'ehpad', 'ephad', 'gériatrie', 'geriatrie',
                'psychologue', 'psychiatre', 'social', 'éducateur', 'educateur',
                'assistante sociale', 'assistant social', 'accompagnant', 'cesf',
                'secrétaire médical', 'secretaire medical', 'laboratoire', 'biologie',
            ],
        ],
        'hotellerie-restauration' => [
            'label' => 'Hôtellerie, restauration & tourisme',
            'section' => 'I',
            'keyword' => 'restauration',
            'naf' => [
                '55.10Z', '55.20Z', '55.30Z',
                '56.10A', '56.10B', '56.10C', '56.21Z', '56.29A', '56.29B', '56.30Z',
                '10.71C', '10.71D', '79.11Z', '79.12Z', '79.90Z', '93.29Z',
            ],
            'keywords' => [
                'hôtellerie', 'hotellerie', 'hôtel', 'hotel', 'restauration', 'restaurant',
                'cuisine', 'cuisinier', 'cuisinière', 'cuisiniere', 'chef de partie',
                'second de cuisine', 'serveur', 'serveuse', 'barman', 'barista',
                'réceptionniste', 'receptionniste', 'gouvernante', 'femme de chambre',
                'tourisme', 'camping', 'traiteur', 'pizzaiolo', 'boulanger', 'boulangère',
                'pâtissier', 'patissier', 'boucher', 'charcutier', 'poissonnier',
                'sommelier', 'plongeur', 'room service', 'hostellerie',
            ],
        ],
        'industrie-production' => [
            'label' => 'Industrie & production',
            'section' => 'C',
            'keyword' => 'industrie',
            'naf' => [
                '10.71C', '10.71D', '22.19Z', '22.29A', '22.29B',
                '24.10Z', '24.20Z', '24.34Z', '24.44Z', '24.51Z', '24.52Z',
                '25.11Z', '25.12Z', '25.21Z', '25.29Z', '25.50A', '25.50B',
                '25.61Z', '25.62A', '25.62B', '25.71Z', '25.72Z', '25.73A', '25.73B', '25.91Z', '25.92Z', '25.99A', '25.99B',
                '27.11Z', '27.12Z', '27.20Z', '27.31Z', '27.32Z', '27.33Z', '27.40Z', '27.51Z', '27.52Z', '27.90Z',
                '28.11Z', '28.12Z', '28.13Z', '28.14Z', '28.15Z', '28.21Z', '28.22Z', '28.24Z', '28.25Z', '28.29A', '28.29B',
                '28.30Z', '28.41Z', '28.49Z', '28.91Z', '28.92Z', '28.93Z', '28.94Z', '28.95Z', '28.96Z', '28.99A', '28.99B',
                '29.10Z', '29.20Z', '29.31Z', '29.32Z', '30.20Z', '30.30Z', '30.40Z', '30.92Z',
                '33.11Z', '33.12Z', '33.14Z', '33.20A', '33.20B', '33.20C', '33.20D',
            ],
            'keywords' => [
                'industrie', 'industriel', 'industrielle', 'production', 'opérateur', 'operateur',
                'usine', 'atelier', 'fabrication', 'assemblage', 'mécanique', 'mecanique',
                'mécanicien', 'mecanicien', 'maintenance', 'électromécanique', 'electromecanique',
                'chaudronnier', 'chaudronnerie', 'soudeur', 'soudure', 'usinage', 'tourneur',
                'fraiseur', 'ajusteur', 'rectifieur', 'outilleur', 'mouleur',
                'qualité', 'qualite', 'hse', 'qhse', 'hygiène', 'hygiene', 'sécurité industrielle',
                'méthodes', 'methodes', 'industrialisation', 'bureau d\'études', 'bureau d\'etudes',
                'dessinateur industriel', 'automatisme', 'automaticien', 'robotique', 'mécatronique',
                'mecatronique', 'logistique industrielle', 'lean', 'six sigma', 'amélioration continue',
                'ingénieur', 'ingenieur', 'technicien de maintenance', 'contrôle qualité',
                'emballage', 'conditionnement', 'laboratoire d\'analyses', 'chimie', 'chimiste',
            ],
        ],
        'juridique' => [
            'label' => 'Juridique & administration',
            'section' => 'M',
            'keyword' => 'juridique',
            'naf' => [
                '69.10Z', '82.11Z', '82.19Z', '82.30Z', '84.11Z', '84.13Z', '84.21Z', '84.22Z', '84.23Z', '84.24Z', '84.25Z',
            ],
            'keywords' => [
                'juridique', 'juriste', 'droit', 'avocat', 'avocate', 'notaire', 'clerc',
                'paralegal', 'parajuriste', 'contentieux', 'legal', 'légal', 'contrats',
                'compliance', 'conformité', 'conformite', 'secrétariat juridique', 'greffe',
                'administratif', 'administration', 'agent administratif', 'assistant administratif',
                'secrétaire', 'secretaire', 'accueil', 'back office', 'office manager',
                'gestionnaire administratif', 'fonction publique', 'collectivité',
            ],
        ],
        'education-formation' => [
            'label' => 'Éducation, formation & enfance',
            'section' => 'P',
            'keyword' => 'formation',
            'naf' => [
                '85.10Z', '85.20Z', '85.31Z', '85.32Z', '85.41Z', '85.42Z',
                '85.51Z', '85.52Z', '85.53Z', '85.59A', '85.59B', '85.60Z',
                '88.91A', '88.99B', '94.99Z',
            ],
            'keywords' => [
                'enseignement', 'enseignant', 'enseignante', 'professeur', 'prof', 'instituteur',
                'institutrice', 'formateur', 'formatrice', 'formation', 'éducation', 'education',
                'école', 'ecole', 'collège', 'college', 'lycée', 'lycee', 'université', 'universite',
                'pédagogie', 'pedagogie', 'pédagogique', 'pedagogique', 'animateur', 'animatrice',
                'animation', 'éducateur', 'educateur', 'éducatrice', 'educatrice', 'enfance',
                'crèche', 'creche', 'périscolaire', 'periscolaire', 'AESH', 'ATSEM', 'surveillant',
                'documentaliste', 'orientation', 'conseiller pédagogique', 'petite enfance',
            ],
        ],
        'artisanat-services' => [
            'label' => 'Artisanat, services & propreté',
            'section' => 'S',
            'keyword' => 'services',
            'naf' => [
                '95.11Z', '95.12Z', '95.21Z', '95.22Z', '95.23Z', '95.24Z', '95.25Z',
                '95.29Z', '96.01A', '96.01B', '96.02A', '96.02B', '96.04Z', '96.09Z',
                '80.10Z', '80.20Z', '80.30Z',
                '81.21Z', '81.22Z', '81.29A', '81.29B', '81.30Z', '81.10Z',
            ],
            'keywords' => [
                'coiffeur', 'coiffeuse', 'coiffure', 'esthétique', 'esthetique', 'esthéticienne',
                'estheticienne', 'barbier', 'spa', 'bien-être', 'bien etre', 'masseur',
                'jardinier', 'jardinière', 'jardiniere', 'paysagiste', 'espaces verts',
                'entretien', 'nettoyage', 'propreté', 'proprete', 'agent de nettoyage',
                'agent d\'entretien', 'technicien de surface', 'pressing', 'blanchisserie',
                'sécurité', 'securite', 'agent de sécurité', 'gardien', 'gardiennage',
                'sûreté', 'surete', 'cordonnier', 'réparation', 'reparation', 'taxidermie',
                'services à la personne', 'services a la personne', 'aide à domicile',
                'aide a domicile', 'ménage', 'menage', 'repassage', 'conciergerie',
            ],
        ],
        'agriculture' => [
            'label' => 'Agriculture, viticulture & environnement',
            'section' => 'A',
            'keyword' => 'agriculture',
            'naf' => [
                '01.11Z', '01.13Z', '01.19Z', '01.21Z', '01.24Z', '01.25Z', '01.29Z',
                '01.41Z', '01.42Z', '01.43Z', '01.45Z', '01.46Z', '01.47Z', '01.49Z', '01.50Z',
                '01.61Z', '01.62Z', '01.63Z', '01.64Z',
                '02.10Z', '02.20Z', '02.30Z', '02.40Z',
                '03.11Z', '03.21Z', '03.22Z',
                '75.00Z',
            ],
            'keywords' => [
                'agriculture', 'agricole', 'agriculteur', 'agricultrice', 'exploitation',
                'viticulture', 'viticole', 'viticulteur', 'vigne', 'raisin', 'élevage', 'elevage',
                'éleveur', 'eleveur', 'maraîcher', 'maraicher', 'maraîchage', 'maraichage',
                'horticulture', 'horticulteur', 'pépinière', 'pepiniere', 'sylviculture',
                'forestier', 'bûcheron', 'bucheron', 'forêt', 'foret', 'céréales', 'cereales',
                'céréalier', 'cerealier', 'tracteur', 'récolte', 'recolte', 'vétérinaire agricole',
            ],
        ],
        'banque-assurance' => [
            'label' => 'Banque & assurance',
            'section' => 'K',
            'keyword' => 'banque',
            'naf' => [
                '64.11Z', '64.19Z', '64.20Z', '64.30Z', '64.91Z', '64.92Z', '64.99Z',
                '65.11Z', '65.12Z', '65.20Z', '65.30Z',
                '66.11Z', '66.12Z', '66.19B', '66.21Z', '66.22Z', '66.29Z', '66.30Z',
            ],
            'keywords' => [
                'banque', 'bancaire', 'banquier', 'assurance', 'assureur', 'assureuse',
                'courtier', 'courtage', 'agent général d\'assurance', 'mutuelle',
                'conseiller bancaire', 'conseiller clientèle', 'chargé de clientèle',
                'gestionnaire de sinistres', 'sinistres', 'actuaire', 'actuariat',
                'risques', 'souscription', 'recouvrement', 'crédit immobilier', 'patrimoine',
                'conseiller en gestion de patrimoine', 'cif', 'bourse', 'trading', 'middle office',
            ],
        ],
    ];

    /**
     * Métiers précis reconnus dans une recherche.
     *
     * Le secteur NAF donne des employeurs corrects mais hétérogènes (un
     * « chaudronnier » tombe sur toute l'industrie). Quand un métier précis
     * est reconnu, on l'interroge en premier : l'API cherche aussi dans le
     * libellé d'activité, et « chaudronnerie » remonte les vraies entreprises
     * du métier.
     *
     * @var array<string, array{label: string, q: string, naf: list<string>, keywords: list<string>}>
     */
    private const ACTIVITIES = [
        'developpement-web' => [
            'label' => 'Développement web & logiciel',
            'q' => 'informatique',
            'naf' => ['62.01Z', '62.02A', '62.02B', '62.09Z', '58.29C'],
            'keywords' => [
                'développeur web', 'developpeur web', 'développeur full stack', 'fullstack',
                'full stack', 'frontend', 'front end', 'backend', 'back end', 'symfony',
                'laravel', 'react', 'vuejs', 'angular', 'nodejs', 'php', 'wordpress',
                'webmaster', 'intégrateur web', 'integrateur web',
            ],
        ],
        'infrastructure-reseaux' => [
            'label' => 'Infrastructure, réseaux & support',
            'q' => 'informatique',
            'naf' => ['62.03Z', '62.09Z', '63.11Z', '61.10Z', '61.20Z', '61.90Z', '95.11Z', '95.12Z'],
            'keywords' => [
                'administrateur système', 'administrateur systeme', 'sysadmin', 'réseau',
                'reseau', 'réseaux', 'reseaux', 'infrastructure', 'support informatique',
                'helpdesk', 'help desk', 'technicien informatique', 'devops', 'cloud',
                'cybersécurité', 'cybersecurite', 'sécurité informatique', 'sre',
            ],
        ],
        'plomberie' => [
            'label' => 'Plomberie & chauffage',
            'q' => 'plomberie',
            'naf' => ['43.22A', '43.22B', '43.21A'],
            'keywords' => ['plombier', 'plomberie', 'chauffagiste', 'chauffage', 'sanitaire', 'frigoriste', 'climatisation'],
        ],
        'electricite' => [
            'label' => 'Électricité',
            'q' => 'electricite',
            'naf' => ['43.21A', '43.21B', '33.20A', '27.12Z'],
            'keywords' => ['électricien', 'electricien', 'électricité', 'electricite', 'électrotechnique', 'electrotechnique'],
        ],
        'menuiserie' => [
            'label' => 'Menuiserie & bois',
            'q' => 'menuiserie',
            'naf' => ['43.32A', '43.32B', '43.32C', '16.23Z', '31.01Z', '31.02Z'],
            'keywords' => ['menuisier', 'menuiserie', 'charpentier', 'charpente', 'ébéniste', 'ebeniste', 'bois'],
        ],
        'maconnerie' => [
            'label' => 'Maçonnerie & gros œuvre',
            'q' => 'maconnerie',
            'naf' => ['43.11Z', '43.99C', '41.20A', '41.20B'],
            'keywords' => ['maçon', 'macon', 'maçonnerie', 'maconnerie', 'gros œuvre', 'gros oeuvre', 'béton', 'beton'],
        ],
        'peinture-batiment' => [
            'label' => 'Peinture & finitions',
            'q' => 'peinture',
            'naf' => ['43.34Z', '43.33Z'],
            'keywords' => ['peintre', 'peinture', 'carreleur', 'carrelage', 'plaquiste', 'plâtrerie', 'platrerie', 'façadier', 'facadier'],
        ],
        'couverture' => [
            'label' => 'Couverture & toiture',
            'q' => 'couverture',
            'naf' => ['43.91A', '43.91B', '43.99C'],
            'keywords' => ['couvreur', 'couverture', 'toiture', 'zinguerie', 'étanchéité', 'etancheite'],
        ],
        'chaudronnerie' => [
            'label' => 'Chaudronnerie & structures métalliques',
            'q' => 'chaudronnerie',
            'naf' => ['25.11Z', '25.29Z', '25.62B', '33.20A'],
            'keywords' => ['chaudronnier', 'chaudronnerie', 'métallerie', 'metallerie', 'soudeur', 'soudure', 'tuyauteur', 'serrurier', 'serrurerie'],
        ],
        'usinage' => [
            'label' => 'Usinage & mécanique de précision',
            'q' => 'usinage',
            'naf' => ['25.62A', '25.62B', '25.73B', '25.73A'],
            'keywords' => ['usinage', 'tourneur', 'fraiseur', 'ajusteur', 'rectifieur', 'outilleur', 'décolletage', 'decolletage', 'mécanique de précision'],
        ],
        'maintenance-industrielle' => [
            'label' => 'Maintenance industrielle',
            'q' => 'maintenance industrielle',
            'naf' => ['33.12Z', '33.20A', '33.20B', '33.20C', '33.20D'],
            'keywords' => ['maintenance industrielle', 'maintenance', 'électromécanique', 'electromecanique', 'mécanicien', 'mecanicien', 'automaticien', 'automatisme'],
        ],
        'pharmacie' => [
            'label' => 'Pharmacie',
            'q' => 'pharmacie',
            'naf' => ['47.73Z', '21.20Z'],
            'keywords' => ['pharmacien', 'pharmacienne', 'pharmacie', 'préparateur en pharmacie', 'preparateur en pharmacie'],
        ],
        'veterinaire' => [
            'label' => 'Vétérinaire',
            'q' => 'veterinaire',
            'naf' => ['75.00Z'],
            'keywords' => ['vétérinaire', 'veterinaire', 'vétérinaires', 'veterinaires'],
        ],
        'soins-infirmiers' => [
            'label' => 'Soins infirmiers',
            'q' => 'infirmier',
            'naf' => ['86.90D', '86.90A', '86.90B', '86.90E', '87.10A', '87.30A'],
            'keywords' => ['infirmier', 'infirmière', 'infirmiere', 'aide-soignant', 'aide soignant', 'aide-soignante', 'soins', 'kinésithérapeute', 'kinesitherapeute', 'kiné', 'kine'],
        ],
        'medecine' => [
            'label' => 'Médecine & dentaire',
            'q' => 'medecin',
            'naf' => ['86.21Z', '86.22A', '86.22B', '86.22C', '86.23Z'],
            'keywords' => ['médecin', 'medecin', 'généraliste', 'generaliste', 'dentiste', 'orthodontiste', 'ophtalmologue', 'cardiologue', 'radiologue', 'psychiatre', 'sage-femme'],
        ],
        'restauration' => [
            'label' => 'Restauration',
            'q' => 'restauration',
            'naf' => ['56.10A', '56.10B', '56.10C', '56.21Z', '56.29A', '56.29B'],
            'keywords' => ['restaurant', 'restauration', 'cuisinier', 'cuisinière', 'cuisiniere', 'chef de partie', 'second de cuisine', 'serveur', 'serveuse', 'barman', 'pizzaiolo', 'traiteur', 'plongeur'],
        ],
        'hotellerie' => [
            'label' => 'Hôtellerie & tourisme',
            'q' => 'hotellerie',
            'naf' => ['55.10Z', '55.20Z', '55.30Z', '79.11Z', '79.12Z'],
            'keywords' => ['hôtel', 'hotel', 'hôtellerie', 'hotellerie', 'réceptionniste', 'receptionniste', 'gouvernante', 'femme de chambre', 'tourisme', 'camping', 'animatrice'],
        ],
        'boulangerie' => [
            'label' => 'Boulangerie & pâtisserie',
            'q' => 'boulangerie',
            'naf' => ['10.71C', '10.71D', '47.24Z'],
            'keywords' => ['boulanger', 'boulangère', 'boulangere', 'boulangerie', 'pâtissier', 'patissier', 'pâtisserie', 'patisserie', 'viennoiserie'],
        ],
        'boucherie' => [
            'label' => 'Boucherie & charcuterie',
            'q' => 'boucherie',
            'naf' => ['10.11Z', '10.13A', '10.13B', '47.22Z'],
            'keywords' => ['boucher', 'boucherie', 'charcutier', 'charcuterie', 'poissonnier', 'poissonnerie', 'traiteur'],
        ],
        'avocat' => [
            'label' => 'Avocature & notariat',
            'q' => 'avocat',
            'naf' => ['69.10Z'],
            'keywords' => ['avocat', 'avocate', 'juriste', 'notaire', 'clerc de notaire', 'contentieux', 'droit'],
        ],
        'coiffure' => [
            'label' => 'Coiffure & esthétique',
            'q' => 'coiffure',
            'naf' => ['96.02A', '96.02B', '96.04Z'],
            'keywords' => ['coiffeur', 'coiffeuse', 'coiffure', 'esthétique', 'esthetique', 'esthéticienne', 'estheticienne', 'barbier', 'spa', 'bien-être', 'bien etre'],
        ],
        'nettoyage' => [
            'label' => 'Propreté & nettoyage',
            'q' => 'nettoyage',
            'naf' => ['81.21Z', '81.22Z', '81.29A', '81.29B', '96.01A', '96.01B'],
            'keywords' => ['nettoyage', 'propreté', 'proprete', 'agent de nettoyage', 'agent d\'entretien', 'technicien de surface', 'ménage', 'menage', 'pressing', 'blanchisserie'],
        ],
        'securite-privee' => [
            'label' => 'Sécurité privée',
            'q' => 'securite privee',
            'naf' => ['80.10Z', '80.20Z', '80.30Z'],
            'keywords' => ['agent de sécurité', 'agent de securite', 'sécurité privée', 'securite privee', 'gardien', 'gardiennage', 'sûreté', 'surete', 'vigile'],
        ],
        'jardinage' => [
            'label' => 'Paysage & espaces verts',
            'q' => 'jardinage',
            'naf' => ['81.30Z', '01.61Z', '02.40Z'],
            'keywords' => ['jardinier', 'jardinière', 'jardiniere', 'jardinage', 'paysagiste', 'espaces verts', 'paysage', 'élagage', 'elagage'],
        ],
        'demenagement' => [
            'label' => 'Déménagement',
            'q' => 'demenagement',
            'naf' => ['49.42Z', '52.21Z'],
            'keywords' => ['déménageur', 'demenageur', 'déménagement', 'demenagement', 'livreur', 'coursier'],
        ],
        'laboratoire' => [
            'label' => 'Laboratoires & analyses',
            'q' => 'laboratoire',
            'naf' => ['86.90F', '71.20B', '72.11Z', '72.19Z'],
            'keywords' => ['laboratoire', 'biologie', 'biologiste', 'technicien de laboratoire', 'analyses', 'chimie', 'chimiste', 'recherche'],
        ],
        'comptabilite' => [
            'label' => 'Comptabilité & expertise comptable',
            'q' => 'comptabilite',
            'naf' => ['69.20Z'],
            'keywords' => [
                'comptable', 'comptabilité', 'comptabilite', 'expert-comptable',
                'expert comptable', 'expertise comptable', 'aide-comptable', 'gestionnaire de paie',
                'paie', 'audit', 'auditeur', 'contrôleur de gestion', 'controleur de gestion',
            ],
        ],
        'immobilier' => [
            'label' => 'Immobilier & transaction',
            'q' => 'immobilier',
            'naf' => ['68.10Z', '68.20A', '68.20B', '68.31Z', '68.32A', '68.32B'],
            'keywords' => [
                'immobilier', 'agent immobilier', 'agence immobilière', 'agence immobiliere',
                'transaction', 'gestion locative', 'syndic', 'promoteur', 'négociateur',
                'negociateur', 'chasseur immobilier', 'diagnostiqueur',
            ],
        ],
        'communication' => [
            'label' => 'Communication & marketing',
            'q' => 'communication',
            'naf' => ['73.11Z', '73.12Z', '73.20Z', '74.10Z', '74.90B', '70.21Z'],
            'keywords' => [
                'communication', 'chargé de communication', 'charge de communication',
                'community manager', 'social media', 'relations presse', 'attaché de presse',
                'attache de presse', 'marketing', 'seo', 'sea', 'growth', 'rédacteur web',
                'redacteur web', 'copywriter', 'content manager', 'graphiste', 'directeur artistique',
            ],
        ],
    ];

    /**
     * Secteur le plus probable pour une recherche.
     *
     * @return array{key: string, label: string, section: string, keyword: string, naf: list<string>}|null
     */
    public function detect(string $query): ?array
    {
        $best = $this->best(self::SECTORS, $query);

        if ($best === null) {
            return null;
        }

        return [
            'key' => $best['key'],
            'label' => $best['label'],
            'section' => $best['section'],
            'keyword' => $best['keyword'],
            'naf' => $best['naf'],
        ];
    }

    /**
     * Métier précis reconnu dans la recherche, s'il y en a un.
     *
     * @return array{key: string, label: string, q: string, naf: list<string>}|null
     */
    public function detectActivity(string $query): ?array
    {
        $best = $this->best(self::ACTIVITIES, $query);

        if ($best === null) {
            return null;
        }

        return [
            'key' => $best['key'],
            'label' => $best['label'],
            'q' => $best['q'],
            'naf' => $best['naf'],
        ];
    }

    /**
     * @param array<string, array{keywords: list<string>}> $catalogue
     * @return array<string, mixed>|null
     */
    private function best(array $catalogue, string $query): ?array
    {
        $haystack = ' ' . $this->normalize($query) . ' ';
        $best = null;
        $bestScore = 0;

        foreach ($catalogue as $key => $entry) {
            $score = 0;

            foreach ($entry['keywords'] as $keyword) {
                $needle = $this->normalize($keyword);

                if ($needle === '') {
                    continue;
                }

                // Les mots de 1 à 2 lettres (« rh », « ia », « qa ») ne sont
                // acceptés qu'en mot entier pour éviter les faux positifs.
                $found = mb_strlen($needle) <= 2
                    ? str_contains($haystack, ' ' . $needle . ' ')
                    : str_contains($haystack, $needle);

                if ($found) {
                    // Un mot rare et long pèse plus qu'un mot générique court :
                    // « cybersécurité » doit l'emporter sur « support ».
                    $score += mb_strlen($needle);
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = ['key' => $key] + $entry;
            }
        }

        return $best;
    }

    /**
     * Mots significatifs d'une requête, du plus discriminant au moins utile.
     *
     * @return list<string>
     */
    public function keywords(string $query, int $limit = 4): array
    {
        $stopwords = [
            'de', 'des', 'du', 'et', 'en', 'la', 'le', 'les', 'un', 'une', 'aux', 'au',
            'the', 'and', 'for', 'with', 'of', 'senior', 'junior', 'confirmé', 'confirme',
            'débutant', 'debutant', 'développeur', 'developpeur', 'développeuse',
            'developpeuse', 'developer', 'ingénieur', 'ingenieur', 'technicien',
            'technicienne', 'chargé', 'charge', 'chargée', 'chargee', 'responsable',
            'assistant', 'assistante', 'chef', 'manager', 'spécialiste', 'specialiste',
            'consultant', 'consultante', 'freelance', 'stage', 'alternance', 'cdi', 'cdd',
            'h/f', 'f/h',
        ];

        $raw = preg_split('/[\s,;|()\/]+/u', mb_strtolower($query)) ?: [];
        $keywords = [];

        foreach ($raw as $token) {
            $token = trim($token, ".'\"");
            $token = preg_replace('/^\d+$/', '', $token) ?? $token;
            $token = trim($token);

            if (mb_strlen($token) < 3 || in_array($token, $stopwords, true)) {
                continue;
            }

            $keywords[] = $token;
        }

        $keywords = array_values(array_unique($keywords));

        // Les termes les plus longs sont les plus spécifiques.
        usort($keywords, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return array_slice($keywords, 0, $limit);
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower($value);
        $value = strtr($value, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
            'ç' => 'c',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'õ' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
            'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae',
        ]);
        $value = preg_replace('/[^a-z0-9+#.]+/', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
