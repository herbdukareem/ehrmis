<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Staff record slip - {{ $staff['full_name'] }}</title>
    <style>
        @page { margin: 16mm 14mm; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: #15213a;
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10px;
            line-height: 1.5;
        }
        .header-table { width: 100%; border-bottom: 3px solid #b68a3a; padding-bottom: 10px; margin-bottom: 14px; }
        .header-table td { vertical-align: middle; }
        .logo { width: 56px; height: 56px; object-fit: contain; background: #fff; border: 2px solid #b68a3a; border-radius: 50%; }
        .gov-line { color: #31588f; font-size: 9px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; }
        .agency-name { margin: 2px 0 0; color: #162b58; font-family: 'DejaVu Serif', serif; font-size: 18px; font-weight: bold; }
        .agency-sub { margin: 2px 0 0; color: #697282; font-size: 9px; text-transform: uppercase; letter-spacing: 1px; }
        .meta-box { text-align: right; color: #697282; font-size: 9px; text-transform: uppercase; letter-spacing: 1px; }
        .meta-box strong { display: block; margin-top: 3px; color: #15213a; font-size: 11px; text-transform: none; letter-spacing: 0; }

        .hero { padding: 10px 12px; margin-bottom: 14px; background: #edf2f8; border: 1px solid #d5deea; border-radius: 4px; }
        .hero-table { width: 100%; }
        .hero-eyebrow { color: #31588f; font-size: 8px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; }
        .hero-name { margin: 2px 0 0; color: #162b58; font-family: 'DejaVu Serif', serif; font-size: 16px; font-weight: bold; }
        .hero-role { margin: 2px 0 0; color: #465161; font-size: 10px; }
        .status-pill { display: inline-block; padding: 4px 9px; color: #20513e; background: #e8f2ed; border-left: 2px solid #25634b; font-size: 8px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; }
        .status-pill.inactive { color: #812929; background: #f8eaea; border-left-color: #9b3131; }

        .section-title { margin: 0 0 6px; padding-bottom: 4px; color: #162b58; border-bottom: 1px solid #d8d3c7; font-family: 'DejaVu Serif', serif; font-size: 12px; font-weight: bold; }
        .cards-table { width: 100%; margin-bottom: 14px; }
        .cards-table > tr > td { width: 50%; vertical-align: top; padding: 0 7px 0 0; }
        .cards-table > tr > td + td { padding: 0 0 0 7px; }
        .card { padding: 10px 12px; margin-bottom: 14px; border: 1px solid #d8d3c7; border-radius: 4px; background: #fffefa; }

        .fact-table { width: 100%; border-collapse: collapse; }
        .fact-table td { padding: 5px 0; border-bottom: 1px dashed #d8d3c7; }
        .fact-table tr:last-child td { border-bottom: 0; }
        .fact-label { width: 46%; color: #697282; font-size: 8px; font-weight: bold; letter-spacing: 0.5px; text-transform: uppercase; }
        .fact-value { color: #15213a; font-size: 10px; font-weight: bold; text-align: right; }

        .allowance-table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        .allowance-table th { padding: 6px 8px; color: #667080; background: #f4f2ec; border-bottom: 1px solid #d8d3c7; text-align: left; font-size: 8px; letter-spacing: 0.5px; text-transform: uppercase; }
        .allowance-table td { padding: 6px 8px; border-bottom: 1px solid #e8e4dc; font-size: 9px; }
        .allowance-table td.amount { text-align: right; font-weight: bold; }
        .empty-note { color: #697282; font-size: 9px; }

        .totals-table { width: 100%; margin-top: 10px; border-top: 2px solid #162b58; }
        .totals-table td { padding: 5px 8px; font-size: 9px; }
        .totals-table .label { color: #697282; text-transform: uppercase; letter-spacing: 0.5px; }
        .totals-table .value { text-align: right; font-weight: bold; }
        .totals-table .grand td { padding-top: 8px; border-top: 1px solid #d8d3c7; font-size: 12px; }
        .totals-table .grand .value { color: #162b58; }

        .footer-table { width: 100%; margin-top: 22px; padding-top: 12px; border-top: 1px solid #d8d3c7; }
        .footer-table td { vertical-align: top; font-size: 8px; color: #697282; }
        .signature-block { text-align: right; }
        .signature-image { height: 36px; margin-bottom: 4px; }
        .signature-line { display: block; width: 180px; margin: 0 0 4px; border-bottom: 1px solid #15213a; }
        .disclaimer { max-width: 320px; line-height: 1.6; }
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td style="width:64px;">
                @if ($logoData)
                    <img class="logo" src="{{ $logoData }}" alt="">
                @endif
            </td>
            <td>
                <div class="gov-line">Government of {{ $stateName }}</div>
                <div class="agency-name">{{ $staff['mda']['name'] ?? 'eHRMIS' }}</div>
                <div class="agency-sub">{{ $staff['mda']['acronym'] ?? '' }} &middot; Official Staff Record Slip</div>
            </td>
            <td class="meta-box" style="width:150px;">
                Generated on
                <strong>{{ $generatedAt->format('d M Y, h:i A') }}</strong>
            </td>
        </tr>
    </table>

    <div class="hero">
        <table class="hero-table">
            <tr>
                <td>
                    <div class="hero-eyebrow">Officer Profile</div>
                    <div class="hero-name">{{ $staff['full_name'] }}</div>
                    <div class="hero-role">{{ $staff['current_employment']['rank_name'] ?? 'No rank' }} / {{ $staff['current_employment']['cadre_name'] ?? 'No cadre' }}</div>
                </td>
                <td style="width:120px; text-align:right;">
                    <span class="status-pill {{ ($staff['status'] ?? '') === 'active' ? '' : 'inactive' }}">{{ $staff['status'] ?? '-' }}</span>
                </td>
            </tr>
        </table>
    </div>

    <table class="cards-table">
        <tr>
            <td>
                <div class="card">
                    <div class="section-title">Identity Details</div>
                    <table class="fact-table">
                        <tr><td class="fact-label">Staff number</td><td class="fact-value">{{ $staff['staff_number'] ?? '-' }}</td></tr>
                        <tr><td class="fact-label">Legacy CNO</td><td class="fact-value">{{ $staff['legacy_cno'] ?? '-' }}</td></tr>
                        <tr><td class="fact-label">Legacy PSN</td><td class="fact-value">{{ $staff['legacy_psn'] ?? '-' }}</td></tr>
                        <tr><td class="fact-label">Date of birth</td><td class="fact-value">{{ $staff['date_of_birth'] ?? '-' }}</td></tr>
                        <tr><td class="fact-label">Sex</td><td class="fact-value">{{ $staff['sex'] ?? '-' }}</td></tr>
                    </table>
                </div>
            </td>
            <td>
                <div class="card">
                    <div class="section-title">Salary Placement</div>
                    <table class="fact-table">
                        <tr><td class="fact-label">Salary scale</td><td class="fact-value">{{ $staff['current_salary_placement']['salary_scale_code'] ?? '-' }}</td></tr>
                        <tr><td class="fact-label">Level / Step</td><td class="fact-value">{{ $staff['current_salary_placement']['level'] ?? '-' }}/{{ $staff['current_salary_placement']['step'] ?? '-' }}</td></tr>
                        <tr><td class="fact-label">Basic salary</td><td class="fact-value">{{ number_format($staff['salary_summary']['basic_salary'] ?? 0, 2) }}</td></tr>
                        <tr><td class="fact-label">Total allowances</td><td class="fact-value">{{ number_format($staff['salary_summary']['total_allowances'] ?? 0, 2) }}</td></tr>
                        <tr><td class="fact-label">Calculated gross</td><td class="fact-value">{{ number_format($staff['salary_summary']['calculated_gross_salary'] ?? 0, 2) }}</td></tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <div class="card" style="margin-bottom:14px;">
        <div class="section-title">Appointment Details</div>
        <table class="cards-table">
            <tr>
                <td>
                    <table class="fact-table">
                        <tr><td class="fact-label">MDA</td><td class="fact-value">{{ $staff['mda']['name'] ?? '-' }}</td></tr>
                        <tr><td class="fact-label">Department</td><td class="fact-value">{{ $staff['current_employment']['department_name'] ?? '-' }}</td></tr>
                        <tr><td class="fact-label">Station</td><td class="fact-value">{{ $staff['current_employment']['station_name'] ?? '-' }}</td></tr>
                        <tr><td class="fact-label">Cadre</td><td class="fact-value">{{ $staff['current_employment']['cadre_name'] ?? '-' }}</td></tr>
                    </table>
                </td>
                <td>
                    <table class="fact-table">
                        <tr><td class="fact-label">Rank</td><td class="fact-value">{{ $staff['current_employment']['rank_name'] ?? '-' }}</td></tr>
                        <tr><td class="fact-label">First appointment</td><td class="fact-value">{{ $staff['current_employment']['date_first_appointment'] ?? '-' }}</td></tr>
                        <tr><td class="fact-label">Last promotion</td><td class="fact-value">{{ $staff['current_employment']['date_last_promotion'] ?? '-' }}</td></tr>
                        <tr><td class="fact-label">Retirement date</td><td class="fact-value">{{ $staff['current_employment']['expected_retirement_date'] ?? '-' }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    <div class="card">
        <div class="section-title">Eligible Allowances</div>
        @php
            $breakdown = $staff['salary_summary']['allowance_breakdown'] ?? [];
            $allowanceNames = collect($staff['allowance_types'] ?? [])->pluck('name', 'code');
        @endphp
        @if (count($breakdown))
            <table class="allowance-table">
                <thead>
                    <tr><th>Allowance</th><th style="text-align:right;">Amount</th></tr>
                </thead>
                <tbody>
                    @foreach ($breakdown as $code => $amount)
                        <tr>
                            <td>{{ $allowanceNames[$code] ?? ucwords(str_replace(['_', '-'], ' ', $code)) }}</td>
                            <td class="amount">{{ number_format($amount, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="empty-note">No allowance amount is currently active for this placement.</p>
        @endif

        <table class="totals-table">
            <tr>
                <td class="label">Basic salary</td>
                <td class="value">{{ number_format($staff['salary_summary']['basic_salary'] ?? 0, 2) }}</td>
            </tr>
            <tr>
                <td class="label">Total allowances</td>
                <td class="value">{{ number_format($staff['salary_summary']['total_allowances'] ?? 0, 2) }}</td>
            </tr>
            <tr class="grand">
                <td class="label">Calculated gross salary</td>
                <td class="value">{{ number_format($staff['salary_summary']['calculated_gross_salary'] ?? 0, 2) }}</td>
            </tr>
        </table>
    </div>

    <table class="footer-table">
        <tr>
            <td>
                <div class="disclaimer">
                    This slip is system-generated from the eHRMIS establishment register and should be used alongside any official personnel file. It is valid as printed on {{ $generatedAt->format('d M Y') }}.
                </div>
            </td>
            <td class="signature-block">
                @if ($signatureData)
                    <img class="signature-image" src="{{ $signatureData }}" alt="">
                @else
                    <span class="signature-line">&nbsp;</span>
                @endif
                <div>{{ $headName ?? 'Authorized Officer' }}</div>
                <div>{{ $headTitle ?? 'Head of Establishment' }}</div>
            </td>
        </tr>
    </table>
</body>
</html>
