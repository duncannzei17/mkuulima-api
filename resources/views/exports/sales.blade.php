<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Sales Ledger</title>
    <style>
        @page { margin: 28px; }
        body { color: #111827; font-family: DejaVu Sans, sans-serif; font-size: 9px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .meta { color: #4b5563; margin-bottom: 16px; }
        .summary { font-size: 12px; font-weight: bold; margin-bottom: 12px; }
        table { border-collapse: collapse; width: 100%; }
        th { background: #f3f4f6; text-align: left; text-transform: uppercase; }
        th, td { border: 1px solid #d1d5db; padding: 5px; }
        .amount { text-align: right; white-space: nowrap; }
        .empty { color: #6b7280; padding: 24px; text-align: center; }
        tfoot td { background: #f9fafb; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Sales Ledger</h1>
    <div class="meta">Generated {{ $generatedAt->format('d M Y, H:i T') }}</div>
    <div class="summary">{{ $sales->count() }} records | Net income: KES {{ number_format($total, 2) }}</div>
    <table>
        <thead><tr><th>Date</th><th>Buyer</th><th>Quantity</th><th>Market</th><th>Status</th><th>Payment</th><th class="amount">Net income (KES)</th></tr></thead>
        <tbody>
            @forelse ($sales as $sale)
                <tr>
                    <td>{{ $sale->sale_date->format('d M Y') }}</td>
                    <td>{{ optional($sale->buyer)->name ?? $sale->buyer_name ?? 'Walk-in buyer' }}</td>
                    <td>{{ number_format((float) $sale->quantity_sold, 3) }} {{ $sale->unit }}</td>
                    <td>{{ $sale->market_location ?? '-' }}</td>
                    <td>{{ ucfirst($sale->status) }}</td>
                    <td>{{ ucfirst($sale->payment_status) }}</td>
                    <td class="amount">{{ number_format((float) $sale->net_income, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">No sales matched the selected filters.</td></tr>
            @endforelse
        </tbody>
        <tfoot><tr><td colspan="6">Total</td><td class="amount">{{ number_format($total, 2) }}</td></tr></tfoot>
    </table>
</body>
</html>
