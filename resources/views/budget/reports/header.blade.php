<header class="report-header">
    <p>Government of Niger State</p>
    <p>{{ $workbook->mda?->name ?? 'MDA' }}</p>
    <h1>{{ $report['title'] }}</h1>
</header>

<section class="report-meta">
    <span>Workbook: Budget #{{ $workbook->id }}</span>
    <span>Movement Year: {{ $workbook->year }}</span>
    <span>Status: {{ strtoupper($workbook->status) }}</span>
</section>
