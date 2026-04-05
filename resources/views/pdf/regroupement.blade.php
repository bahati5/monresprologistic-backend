<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Regroupement — {{ $regroupement->batch_number }}</title>
    <style>
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; font-size: 11px; color: #111; margin: 16px; }
        h1 { font-size: 16px; margin: 0 0 8px 0; }
        .meta { margin-bottom: 16px; color: #444; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
        th { background: #f1f5f9; font-size: 10px; text-transform: uppercase; }
    </style>
</head>
<body>
    <h1>Lot {{ $regroupement->batch_number }}</h1>
    <p class="meta">
        @if($regroupement->agency)
            Agence : {{ $regroupement->agency->name ?? '—' }}<br>
        @endif
        Statut : {{ $regroupement->status?->label() ?? $regroupement->status }}<br>
        Créé le {{ $regroupement->created_at?->format('d/m/Y H:i') ?? '—' }}
    </p>

    <p><strong>Expéditions incluses ({{ $regroupement->shipments->count() }})</strong></p>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Tracking</th>
                <th>Expéditeur</th>
                <th>Destinataire</th>
            </tr>
        </thead>
        <tbody>
            @foreach($regroupement->shipments as $s)
                <tr>
                    <td>{{ $s->id }}</td>
                    <td>{{ $s->public_tracking }}</td>
                    <td>{{ $s->senderProfile?->full_name ?? '—' }}</td>
                    <td>{{ $s->recipientProfile?->full_name ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
