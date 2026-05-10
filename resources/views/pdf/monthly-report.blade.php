<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Rapport mensuel — {{ $stats['period'] }}</title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 12px; color: #333; padding: 20px; }
        h1 { font-size: 22px; margin-bottom: 4px; color: #1e293b; }
        h2 { font-size: 14px; margin-top: 20px; color: #475569; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; }
        .kpi-grid { display: table; width: 100%; margin: 15px 0; }
        .kpi-cell { display: table-cell; text-align: center; padding: 10px; border: 1px solid #e2e8f0; }
        .kpi-value { font-size: 20px; font-weight: 900; color: #2563eb; }
        .kpi-label { font-size: 10px; color: #64748b; margin-top: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table th, table td { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: left; font-size: 11px; }
        table th { background: #f8fafc; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Rapport Mensuel — Monrespro Logistic</h1>
    <p style="color: #64748b;">Période : {{ $stats['period'] }}</p>

    <div class="kpi-grid">
        <div class="kpi-cell">
            <div class="kpi-value">{{ $stats['shipments_total'] }}</div>
            <div class="kpi-label">Expéditions</div>
        </div>
        <div class="kpi-cell">
            <div class="kpi-value">{{ $stats['shipments_delivered'] }}</div>
            <div class="kpi-label">Livrées</div>
        </div>
        <div class="kpi-cell">
            <div class="kpi-value">{{ $stats['assisted_purchases'] }}</div>
            <div class="kpi-label">Achats assistés</div>
        </div>
        <div class="kpi-cell">
            <div class="kpi-value">{{ number_format((float) $stats['revenue'], 2) }}</div>
            <div class="kpi-label">CA (USD)</div>
        </div>
    </div>

    <h2>Top 10 Clients</h2>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Client</th>
                <th>Expéditions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($stats['top_clients'] as $i => $client)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $client['name'] }}</td>
                <td>{{ $client['count'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
