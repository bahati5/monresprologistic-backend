<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; line-height: 1.5; color: #1e293b;">
    <p>Bonjour,</p>
    <p><strong>Vos articles ont été achetés et sont en route vers notre entrepôt européen&nbsp;!</strong></p>
    <p>
        Nous avons passé commande chez le fournisseur pour votre dossier d’achat assisté
        @if(!empty($reference)) (référence n°&nbsp;{{ $reference }}) @endif
        .
    </p>
    @if(!empty($tracking))
        <p>
            <strong>Numéro de suivi fournisseur&nbsp;:</strong> {{ $tracking }}
        </p>
    @endif
    <p>
        Vous serez informé des prochaines étapes (réception à l’entrepôt, expédition) depuis votre espace client.
    </p>
    <p style="margin-top: 1.5rem; color: #64748b; font-size: 0.875rem;">L’équipe Monrespro</p>
</body>
</html>
