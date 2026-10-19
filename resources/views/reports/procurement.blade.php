<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $report['title'] }}</title>
    <style>
        @page { margin: 30px 28px 40px; }
        body { font-family: DejaVu Sans, sans-serif; color: #163632; font-size: 10px; }
        h1 { font-size: 22px; margin: 0 0 12px; }
        .metadata { margin-bottom: 10px; line-height: 1.7; }
        .metadata span { margin-right: 20px; }
        .basis { font-size: 9px; color: #475569; margin-bottom: 12px; }
        table { border-collapse: collapse; width: 100%; table-layout: fixed; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th { background: #134e4a; color: white; text-align: left; }
        td, th { border: 1px solid #cbd5e1; padding: 7px 6px; overflow-wrap: break-word; word-wrap: break-word; }
        tbody tr:nth-child(even) { background: #f1f5f9; }
        .number { text-align: right; }
        .totals { margin: 12px 0; }
        .totals td { padding: 5px; }
        footer { position: fixed; bottom: -26px; font-size: 9px; color: #64748b; }
    </style>
</head>
<body>
    <h1>{{ $report['title'] }}</h1>
    <div class="metadata">
        @foreach ($report['metadata'] as $label => $value)
            <span><strong>{{ $label }}:</strong> {{ $value }}</span>
        @endforeach
    </div>
    <p class="basis">{{ $report['basis'] }}</p>
    @foreach ($report['summary']['currencies'] as $currency => $totals)
        <p><strong>{{ $currency }}</strong>
        @foreach ($totals as $key => $value)
            &nbsp; {{ ucwords(str_replace('_', ' ', $key)) }}: {{ number_format((float) $value, 2) }}
        @endforeach
        </p>
    @endforeach
    @if ($report['type'] === 'procurement-tracking')
        <p>@foreach ($report['summary']['counts'] as $key => $value) {{ ucwords(str_replace('_', ' ', $key)) }}: {{ $value }} &nbsp; @endforeach Mean line delivery: {{ $report['summary']['delivery_progress'] }}%</p>
    @endif
    <table>
        <thead><tr>@foreach ($report['columns'] as $column)<th>{{ $column['label'] }}</th>@endforeach</tr></thead>
        <tbody>
        @forelse ($report['rows'] as $row)
            <tr>@foreach ($report['columns'] as $column)
                <td class="{{ in_array($column['type'], ['money', 'number', 'percent']) ? 'number' : '' }}">@if ($column['type'] === 'money'){{ number_format((float) ($row[$column['key']] ?? 0), 2) }}@elseif ($column['type'] === 'percent'){{ $row[$column['key']] }}%@else{{ $row[$column['key']] ?? '-' }}@endif</td>
            @endforeach</tr>
        @empty
            <tr><td colspan="{{ count($report['columns']) }}">No matching records</td></tr>
        @endforelse
        </tbody>
    </table>
    <footer>Budget &amp; procurement | {{ $report['metadata']['MDA'] }}</footer>
</body>
</html>
