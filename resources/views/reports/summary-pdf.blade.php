<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Rapport Monrespro</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        .meta { color: #555; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
        th { background: #f3f4f6; }
    </style>
</head>
<body>
    <h1>Synthèse — {{ $section }}</h1>
    <div class="meta">
        Période : {{ $period }} · Généré le {{ $generated_at }}
    </div>
    @if(!empty($data['kpis']) && is_array($data['kpis']))
        <table>
            <thead>
                <tr><th>Indicateur</th><th>Valeur</th></tr>
            </thead>
            <tbody>
                @foreach($data['kpis'] as $kpi)
                    <tr>
                        <td>{{ $kpi['label'] ?? ($kpi['title'] ?? '—') }}</td>
                        <td>{{ $kpi['value'] ?? '—' }}{{ isset($kpi['suffix']) ? ' '.$kpi['suffix'] : '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p>Aucune donnée agrégée pour cette section.</p>
    @endif
</body>
</html>
