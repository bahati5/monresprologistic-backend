<?php

/**
 * Cadrage produit PRD V3 — chapitres 1 à 3 (référence affichée dans l’app).
 * Source conceptuelle : PRD_v3_work.md §1–§3.
 */
return [
    'version' => '3.0',
    'updated_at' => '2026-04',

    'chapter_1' => [
        'title' => 'Contexte et vision produit',
        'who' => [
            'heading' => 'Qui est Monrespro',
            'paragraphs' => [
                'Monrespro est une entreprise basée à Kinshasa (RD Congo), active dans la logistique internationale.',
            ],
            'services' => [
                [
                    'name' => 'Achat assisté (proxy shopping)',
                    'description' => 'Le client envoie des liens ou des descriptions ; Monrespro achète, réceptionne et expédie. Service le plus générateur de revenus.',
                ],
                [
                    'name' => 'Expédition internationale',
                    'description' => 'Dépôt au comptoir à Kinshasa pour envoi vers l’étranger (USA, Canada, Europe, autres pays africains).',
                ],
                [
                    'name' => 'Ramassage & livraison',
                    'description' => 'Collecte chez le client ou livraison à domicile à l’arrivée.',
                ],
            ],
        ],
        'situation' => [
            'heading' => 'Situation à transformer',
            'items' => [
                'Formulaires papier au comptoir, champs souvent incomplets.',
                'Coordination forte sur WhatsApp (devis, confirmations, remboursements).',
                'Outils historiques peu connectés entre eux ; données client éclatées.',
                'Manque de notifications automatiques sur l’état des colis.',
            ],
        ],
        'vision' => [
            'heading' => 'Vision',
            'statement' => 'Monrespro Logistic est le système nerveux central : entrées, opérations, sorties (livraison, facturation, reporting) transitent par la plateforme. Freshsales, Odoo et WordPress sont des destinations synchronisées, pas des sources de vérité.',
        ],
        'objectives' => [
            'heading' => 'Objectifs mesurables',
            'items' => [
                'Zéro formulaire papier comme source de données primaire.',
                '100 % des expéditions avec numéro de suivi généré automatiquement.',
                'Création d’une expédition au comptoir : moins de 3 minutes.',
                'Client informé automatiquement à chaque changement de statut.',
                'Devis achat assisté : moins de 5 minutes.',
                'Données synchronisées vers Freshsales et Odoo sans ressaisie manuelle.',
            ],
        ],
    ],

    'chapter_2' => [
        'title' => 'Problèmes réels observés sur le terrain',
        'intro' => 'Ces constats orientent les priorités produit ; ils ne sont pas théoriques.',
        'problems' => [
            [
                'title' => 'Formulaire papier et erreurs en cascade',
                'points' => [
                    'Champs critiques souvent vides (poids, valeur, suivi).',
                    'Écritures parfois illisibles ; écart avec la précision attendue en base (pays, région, ville).',
                    'Ressaisie tardive, erreurs et retards.',
                ],
                'expected_fix' => 'Saisie numérique en premier ; impression pour signature ensuite. La donnée existe dans le système avant la signature.',
            ],
            [
                'title' => 'Visibilité client insuffisante',
                'points' => [
                    'Peu ou pas de notifications automatiques aux changements de statut.',
                    'Charge opérationnelle : appels, recherches, risque d’incohérence de nommage.',
                ],
                'expected_fix' => 'Notifications multi-canal à chaque étape ; suivi en ligne clair.',
            ],
            [
                'title' => 'Remboursements sans process formalisé',
                'points' => [
                    'Historiques gérés hors outil (messagerie) : faible traçabilité comptable et juridique.',
                ],
                'expected_fix' => 'Workflow remboursement dans l’app, validation, écriture et traçabilité.',
            ],
            [
                'title' => 'Dette fonctionnelle achat assisté',
                'points' => [
                    'Anciennement deux logiques parallèles (ex. flux URL vs panier) : statuts et UX divergents.',
                ],
                'expected_fix' => 'Un seul flux unifié (AssistedPurchase) et une expérience unique.',
            ],
            [
                'title' => 'Pilotage business',
                'points' => [
                    'Statistiques peu fiables lorsque des outils généralistes sont détournés de leur usage.',
                ],
                'expected_fix' => 'Reporting depuis la plateforme (CA par service, délais, volumes).',
            ],
        ],
    ],

    'chapter_3' => [
        'title' => 'Principes directeurs',
        'intro' => 'Ces principes priment sur toute décision de design ou d’architecture.',
        'principles' => [
            [
                'name' => 'La donnée naît dans le système',
                'description' => 'Aucune information critique uniquement sur papier ou dans une messagerie personnelle.',
            ],
            [
                'name' => 'Zéro ressaisie',
                'description' => 'Une information saisie une fois ne doit pas être ressaisie manuellement ailleurs.',
            ],
            [
                'name' => 'Le client s’informe seul',
                'description' => 'Chaque changement de statut déclenche une notification ; le client n’a pas besoin d’appeler pour connaître l’état.',
            ],
            [
                'name' => 'Le staff voit ce qui est urgent',
                'description' => 'Le tableau de bord met en avant les actions en attente, pas une simple liste de menus.',
            ],
            [
                'name' => 'Le papier pour la légalité, pas pour la donnée',
                'description' => 'On imprime pour signer ; la source de vérité est déjà numérique.',
            ],
            [
                'name' => 'Pas d’IA dans le produit',
                'description' => 'Automatisations déterministes et configurables (règles métier), pas de modèle génératif embarqué.',
            ],
            [
                'name' => 'Fraîcheur de la donnée financière',
                'description' => 'Remboursements clairs, traçables, vers le moyen de paiement utilisé — pas de « wallet » opaque.',
            ],
        ],
    ],
];
