<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Valeurs par défaut (surchargées par la table settings / Setting::getValue)
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'site_name' => 'Monrespro',
    ],

    /**
     * Texte des conditions affiché sur la facture d’expédition si aucun texte n’est défini dans les paramètres (clé invoice_terms).
     */
    'default_invoice_terms' => <<<'TXT'
ACCEPTÉ : L'expéditeur déclare ne pas envoyer d'argent, d'explosifs, d'armes, de bijoux ni de produits chimiques. En cas de saisie douanière, les taxes seront à la charge du client. Monrespro prendra en charge la valeur de la marchandise entre 0,00 $ et 100 $, selon l'évaluation et les critères établis par l'entreprise. Monrespro décline toute responsabilité en cas de casse ou de dommage. Le client autorise l'agent à examiner visuellement le contenu du colis.
TXT,

    'logo_thumb' => [
        'w' => 120,
        'h' => 40,
    ],

    'weight_unit' => env('SHIPMENT_DOCUMENT_WEIGHT_UNIT', 'kg'),

    /** Diviseur volumétrique (cm³ → kg équivalent), comme l’ancien volumetric_percentage */
    'volumetric_divisor' => (float) env('SHIPMENT_DOCUMENT_VOLUMETRIC_DIVISOR', 5000),

    'barcode_base_url' => env('SHIPMENT_DOCUMENT_BARCODE_URL', 'https://barcode.tec-it.com/barcode.ashx'),
];
