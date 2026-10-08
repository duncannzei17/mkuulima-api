<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Expense Ledger</title>
    <style>
        @page { margin: 28px; }
        body { color: #111827; font-family: DejaVu Sans, sans-serif; font-size: 10px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .meta { color: #4b5563; margin-bottom: 18px; }
        .summary { font-size: 12px; font-weight: bold; margin-bottom: 12px; }
        table { border-collapse: collapse; width: 100%; }
        th { background: #f3f4f6; font-size: 9px; text-align: left; text-transform: uppercase; }
        th, td { border: 1px solid #d1d5db; padding: 6px; vertical-align: top; }
        .amount { text-align: right; white-space: nowrap; }
        .empty { color: #6b7280; padding: 24px; text-align: center; }
        tfoot td { background: #f9fafb; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Expense Ledger</h1>
    <div class="meta">Generated {{ $generatedAt->format('d M Y, H:i T') }}</div>
    <div class="summary">{{ $expenses->count() }} records | Total: KES {{ number_format($total, 2) }}</div>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Description</th>
                <th>Category</th>
                <th>Recorded by</th>
                <th>Status</th>
                <th class="amount">Amount (KES)</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($expenses as $expense)
                <tr>
                    <td>{{ optional($expense->expense_date)->format('d M Y') }}</td>
                    <td>{{ $expense->description }}</td>
                    <td>{{ optional($expense->category)->name ?? 'Uncategorized' }}</td>
                    <td>{{ optional($expense->creator)->name ?? 'Unknown' }}</td>
                    <td>{{ ucfirst($expense->status) }}</td>
                    <td class="amount">{{ number_format((float) $expense->amount, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">No expense records matched the selected filters.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5">Total</td>
                <td class="amount">{{ number_format($total, 2) }}</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
