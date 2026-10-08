<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Inventory report</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 10px; }
        h1 { margin: 0 0 4px; font-size: 20px; }
        p { margin: 0 0 14px; color: #4b5563; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d1d5db; padding: 6px; text-align: left; }
        th { background: #e5e7eb; }
        .number { text-align: right; }
    </style>
</head>
<body>
    <h1>Inventory report</h1>
    <p>Generated {{ $generatedAt->format('Y-m-d H:i') }} | Total value KES {{ number_format($totalValue, 2) }}</p>
    <table>
        <thead>
            <tr>
                <th>Name</th><th>Category</th><th>Quantity</th><th>Unit</th>
                <th>Unit cost</th><th>Total value</th><th>Minimum</th><th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($items as $item)
                <tr>
                    <td>{{ $item->name }}</td>
                    <td>{{ $item->category }}</td>
                    <td class="number">{{ $item->current_quantity }}</td>
                    <td>{{ $item->unit }}</td>
                    <td class="number">{{ number_format((float) $item->cost_per_unit, 2) }}</td>
                    <td class="number">{{ number_format((float) $item->total_value, 2) }}</td>
                    <td class="number">{{ $item->min_quantity }}</td>
                    <td>{{ $item->status }}</td>
                </tr>
            @empty
                <tr><td colspan="8">No inventory items found.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
