<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $report['title'] }} - {{ $workbook->mda?->code }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px; color: #000; background: #fff; font-family: Arial, Helvetica, sans-serif; font-size: 11px; }
        .toolbar { display: flex; justify-content: flex-end; gap: 8px; margin-bottom: 18px; }
        .toolbar button, .toolbar a { padding: 9px 14px; color: #fff; background: #00584f; border: 0; font: inherit; font-weight: 700; cursor: pointer; text-decoration: none; }
        .report-header { margin-bottom: 18px; text-align: center; text-transform: uppercase; }
        .report-header h1 { margin: 0 0 6px; font-size: 18px; line-height: 1.35; }
        .report-header p { margin: 3px 0; font-size: 11px; font-weight: 700; }
        .report-meta { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 16px; padding: 9px 10px; border: 2px solid #000; font-weight: 700; text-transform: uppercase; }
        h2 { margin: 20px 0 7px; font-size: 12px; text-transform: uppercase; }
        table { width: 100%; margin-bottom: 22px; border-collapse: collapse; page-break-inside: auto; }
        tr { page-break-inside: avoid; page-break-after: auto; }
        th, td { padding: 6px 7px; border: 2px solid #000; font-size: 10px; vertical-align: middle; }
        th { font-weight: 800; text-align: center; text-transform: uppercase; }
        td { font-weight: 600; }
        .staff-section-row th { background: #e8edf3; text-align: center; }
        .staff-repeat-heading th { background: #fff; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .total-row th, .total-row td { font-weight: 900; }
        .report-page + .report-page { margin-top: 32px; }
        body.report-recurrent { font-size: 10px; }
        body.report-recurrent .report-header { margin-bottom: 6px; }
        body.report-recurrent .report-header h1 { margin-bottom: 3px; font-size: 14px; line-height: 1.15; }
        body.report-recurrent .report-header p { margin: 1px 0; font-size: 9px; }
        body.report-recurrent .report-meta { margin-bottom: 6px; padding: 5px 7px; }
        body.report-recurrent h2 { margin: 6px 0 4px; font-size: 10px; }
        body.report-recurrent th, body.report-recurrent td { padding: 3px 4px; font-size: 8px; line-height: 1.05; }
        @page { size: A4 landscape; margin: 10mm; }
        @media print {
            body { padding: 0; }
            .toolbar { display: none; }
            .report-page + .report-page { margin-top: 0; break-before: page; page-break-before: always; }
            .report-header, .report-meta, h2 { break-after: avoid; page-break-after: avoid; }
            thead { display: table-header-group; }
            table { margin-bottom: 0; }
        }
    </style>
</head>
<body class="{{ $report['type'] === 'recurrent-expenditure' ? 'report-recurrent' : '' }}">
    <div class="toolbar">
        @if (in_array($report['type'], \App\Domain\Budget\Services\BudgetReportService::EXCEL_REPORTS, true))
            <a href="{{ route('api.budget-workbooks.reports.export', ['budgetWorkbook' => $workbook->id, 'report' => $report['type']]) }}">Export to Excel</a>
        @endif
        <button type="button" onclick="window.print()">Print report</button>
    </div>

    @if ($report['type'] !== 'recurrent-expenditure')
        @include('budget.reports.header')
    @endif

    @if ($report['type'] === 'recurrent-expenditure')
        @foreach ($report['groups'] as $group)
            <section class="report-page">
                @include('budget.reports.header')
                <h2>{{ $group['department'] }} : {{ $group['scale'] }}</h2>
                @foreach ($report['notes'] ?? [] as $note)
                    <p>{{ $note }}</p>
                @endforeach
                <table>
                    <thead>
                        <tr>
                            <th>Grade Level<br>{{ $group['grade_label'] ?? $group['scale_code'] ?? '' }}</th>
                            <th>No. of Staff Approved<br>{{ $workbook->year }}</th>
                            <th>Actual No. of Staff<br>Jan - June {{ $workbook->year }}</th>
                            <th>Approved Estimate<br>{{ $workbook->year }}</th>
                            <th>Actual Exp.<br>Jan - June {{ $workbook->year }}</th>
                            <th>No. of Staff Required<br>{{ $workbook->movementWorkbook?->budget_year ?? $workbook->year + 1 }}</th>
                            <th>Proposed Estimate<br>{{ $workbook->movementWorkbook?->budget_year ?? $workbook->year + 1 }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($group['rows'] as $row)
                            <tr>
                                <td class="text-center">{{ $row['level'] }}</td>
                                <td class="text-right">{{ number_format($row['approved_staff']) }}</td>
                                <td class="text-right">{{ number_format($row['actual_staff']) }}</td>
                                <td class="text-right">{{ number_format($row['approved_estimate'], 2) }}</td>
                                <td class="text-right">{{ number_format($row['actual_expense'], 2) }}</td>
                                <td class="text-right">{{ number_format($row['required_staff']) }}</td>
                                <td class="text-right">{{ number_format($row['proposed_estimate'], 2) }}</td>
                            </tr>
                        @endforeach
                        @foreach ($group['summary_rows'] ?? [['label' => 'Total', ...$group['totals']]] as $summary)
                            <tr class="total-row">
                                <th>{{ $summary['label'] }}</th>
                                <td class="text-right">{{ is_numeric($summary['approved_staff'] ?? null) ? number_format($summary['approved_staff']) : '' }}</td>
                                <td class="text-right">{{ is_numeric($summary['actual_staff'] ?? null) ? number_format($summary['actual_staff']) : '' }}</td>
                                <td class="text-right">{{ is_numeric($summary['approved_estimate'] ?? null) ? number_format($summary['approved_estimate'], 2) : '' }}</td>
                                <td class="text-right">{{ is_numeric($summary['actual_expense'] ?? null) ? number_format($summary['actual_expense'], 2) : '' }}</td>
                                <td class="text-right">{{ is_numeric($summary['required_staff'] ?? null) ? number_format($summary['required_staff']) : '' }}</td>
                                <td class="text-right">{{ is_numeric($summary['proposed_estimate'] ?? null) ? number_format($summary['proposed_estimate'], 2) : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endforeach

        <section class="report-page">
            @include('budget.reports.header')
            <h2>Grand Total</h2>
            <table>
                <tr class="total-row">
                    <th>No. Approved</th>
                    <th>Actual Staff</th>
                    <th>Approved Estimate</th>
                    <th>Actual Exp.</th>
                    <th>Required Staff</th>
                    <th>Proposed Estimate</th>
                </tr>
                <tr>
                    <td class="text-right">{{ number_format($report['grand_totals']['approved_staff']) }}</td>
                    <td class="text-right">{{ number_format($report['grand_totals']['actual_staff']) }}</td>
                    <td class="text-right">{{ number_format($report['grand_totals']['approved_estimate'], 2) }}</td>
                    <td class="text-right">{{ number_format($report['grand_totals']['actual_expense'], 2) }}</td>
                    <td class="text-right">{{ number_format($report['grand_totals']['required_staff']) }}</td>
                    <td class="text-right">{{ number_format($report['grand_totals']['proposed_estimate'], 2) }}</td>
                </tr>
            </table>
        </section>
    @elseif ($report['type'] === 'staff-list')
        @foreach ($report['groups'] as $group)
            <h2>{{ $group['department'] }}</h2>
            <table>
                <tbody>
                    @foreach ($group['sections'] ?? [['title' => null, 'rows' => $group['rows']]] as $section)
                        @if ($section['title'])
                            <tr class="staff-section-row">
                                <th colspan="14">{{ $section['title'] }}</th>
                            </tr>
                        @endif
                        <tr class="staff-repeat-heading">
                            <th>SN</th>
                            <th>Name</th>
                            <th>Sex</th>
                            <th>DOB</th>
                            <th>LGA</th>
                            <th>Qualifications</th>
                            <th>DFA</th>
                            <th>DPA</th>
                            <th>Rank</th>
                            <th>Level/Step</th>
                            <th>PSN</th>
                            <th>File No.</th>
                            <th>CNO</th>
                            <th>Remark</th>
                        </tr>
                        @foreach ($section['rows'] as $row)
                            <tr>
                                <td class="text-center">{{ $row['sn'] }}</td>
                                <td>{{ $row['name'] }}</td>
                                <td class="text-center">{{ $row['sex'] }}</td>
                                <td class="text-center">{{ \App\Support\ReportFormatter::date($row['dob']) }}</td>
                                <td>{{ $row['lga'] }}</td>
                                <td>{{ $row['qualification'] }}</td>
                                <td class="text-center">{{ \App\Support\ReportFormatter::date($row['dfa']) }}</td>
                                <td class="text-center">{{ \App\Support\ReportFormatter::date($row['dpa']) }}</td>
                                <td>{{ $row['rank'] }}</td>
                                <td class="text-center">{{ $row['level_step'] }}</td>
                                <td>{{ $row['psn'] }}</td>
                                <td>{{ $row['file_no'] }}</td>
                                <td>{{ $row['cno'] }}</td>
                                <td>{{ $row['remark'] }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        @endforeach
    @elseif ($report['type'] === 'qualification-distribution')
        @foreach ($report['groups'] as $group)
            <h2>{{ $group['department'] }} : {{ $group['scale'] }}</h2>
            <table>
                <thead>
                    <tr>
                        <th rowspan="2">Level</th>
                        @foreach ($group['qualifications'] as $qualification)
                            <th colspan="2">{{ $qualification }}</th>
                        @endforeach
                        <th rowspan="2">Total</th>
                    </tr>
                    <tr>
                        @foreach ($group['qualifications'] as $qualification)
                            <th>Male</th>
                            <th>Female</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($group['rows'] as $row)
                        <tr>
                            <td class="text-center">{{ $row['level'] }}</td>
                            @foreach ($row['cells'] as $cell)
                                <td class="text-right">{{ number_format($cell['male']) }}</td>
                                <td class="text-right">{{ number_format($cell['female']) }}</td>
                            @endforeach
                            <td class="text-right">{{ number_format($row['total']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach
    @elseif ($report['type'] === 'staff-strength')
        <table>
            <thead>
                <tr>
                    <th>Department</th>
                    <th>Current Staff</th>
                    <th>Retiring</th>
                    <th>Required Staff</th>
                    <th>Current Gross</th>
                    <th>Proposed Gross</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['groups'] as $group)
                    <tr>
                        <td>{{ $group['department'] }}</td>
                        <td class="text-right">{{ number_format($group['staff_count']) }}</td>
                        <td class="text-right">{{ number_format($group['retiring_count']) }}</td>
                        <td class="text-right">{{ number_format($group['required_staff']) }}</td>
                        <td class="text-right">{{ number_format($group['current_gross_total'], 2) }}</td>
                        <td class="text-right">{{ number_format($group['proposed_gross_total'], 2) }}</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <th>Total</th>
                    <td class="text-right">{{ number_format($report['totals']['staff_count']) }}</td>
                    <td class="text-right">{{ number_format($report['totals']['retiring_count']) }}</td>
                    <td class="text-right">{{ number_format($report['totals']['required_staff']) }}</td>
                    <td class="text-right">{{ number_format($report['totals']['current_gross_total'], 2) }}</td>
                    <td class="text-right">{{ number_format($report['totals']['proposed_gross_total'], 2) }}</td>
                </tr>
            </tbody>
        </table>
    @endif
</body>
</html>
