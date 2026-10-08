<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Profitability Report</title>
    <style>
        @page { margin: 28px; }
        body { color: #111827; font-family: DejaVu Sans, sans-serif; font-size: 9px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .meta { color: #4b5563; margin-bottom: 16px; }
        table { border-collapse: collapse; width: 100%; }
        th { background: #f3f4f6; text-align: left; text-transform: uppercase; }
        th, td { border: 1px solid #d1d5db; padding: 6px; }
        .amount { text-align: right; }
        .profit { color: #166534; }
        .loss { color: #991b1b; }
    </style>
</head>
<body>
    <h1>Farm Profitability Report</h1>
    <div class="meta">Generated {{ $generatedAt->format('d M Y, H:i T') }}</div>
    <table>
        <thead><tr><th>Crop</th><th>Season</th><th class="amount">Revenue</th><th class="amount">Production cost</th><th class="amount">Net profit</th><th class="amount">ROI</th><th class="amount">Yield efficiency</th></tr></thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row['cycle']->crop_name }}</td>
                    <td>{{ $row['cycle']->season_name ?? '-' }}</td>
                    <td class="amount">KES {{ number_format((float) $row['snapshot']->total_revenue, 2) }}</td>
                    <td class="amount">KES {{ number_format((float) $row['snapshot']->cost_of_production, 2) }}</td>
                    <td class="amount {{ (float) $row['snapshot']->net_profit >= 0 ? 'profit' : 'loss' }}">KES {{ number_format((float) $row['snapshot']->net_profit, 2) }}</td>
                    <td class="amount">{{ number_format((float) $row['snapshot']->roi_percentage, 2) }}%</td>
                    <td class="amount">{{ number_format((float) $row['snapshot']->yield_efficiency, 2) }}%</td>
                </tr>
            @empty
                <tr><td colspan="7">No crop cycles are available for this report.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
