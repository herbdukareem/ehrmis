<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Promotion letter - {{ $application->application_number }}</title>
    <style>
        @page { margin: 18mm 18mm 16mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #15213a; font-family: 'DejaVu Sans', sans-serif; font-size: 11px; line-height: 1.65; }
        .letterhead { width: 100%; padding-bottom: 12px; border-bottom: 3px solid #b68a3a; }
        .letterhead td { vertical-align: middle; }
        .logo { width: 62px; height: 62px; object-fit: contain; border: 2px solid #b68a3a; border-radius: 50%; }
        .gov-line { color: #0f766e; font-size: 9px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; }
        .agency { margin-top: 2px; color: #162b58; font-family: 'DejaVu Serif', serif; font-size: 19px; font-weight: bold; line-height: 1.2; }
        .subline { margin-top: 3px; color: #697282; font-size: 9px; letter-spacing: 0.8px; text-transform: uppercase; }
        .meta { width: 180px; text-align: right; color: #697282; font-size: 9px; text-transform: uppercase; letter-spacing: 0.8px; }
        .meta strong { display: block; margin-top: 4px; color: #15213a; font-size: 11px; text-transform: none; letter-spacing: 0; }
        .ref-table { width: 100%; margin: 18px 0 22px; }
        .ref-table td { vertical-align: top; }
        .address { width: 60%; }
        .reference { text-align: right; color: #465161; }
        .reference strong { color: #15213a; }
        .subject { margin: 18px 0; padding: 9px 12px; background: #edf2f8; border-left: 4px solid #0f766e; color: #162b58; font-family: 'DejaVu Serif', serif; font-size: 13px; font-weight: bold; text-transform: uppercase; }
        p { margin: 0 0 11px; }
        .facts { width: 100%; margin: 16px 0; border-collapse: collapse; }
        .facts th { padding: 7px 8px; color: #667080; background: #f4f2ec; border: 1px solid #d8d3c7; text-align: left; font-size: 8px; letter-spacing: 0.6px; text-transform: uppercase; }
        .facts td { padding: 8px; border: 1px solid #d8d3c7; font-size: 10px; }
        .facts .value { font-weight: bold; color: #15213a; }
        .note { margin-top: 12px; padding: 10px 12px; background: #fff9e8; border: 1px solid #ead8a6; color: #61512b; }
        .signature-table { width: 100%; margin-top: 38px; }
        .signature-table td { vertical-align: bottom; }
        .signature { width: 240px; text-align: left; }
        .signature-img { height: 44px; margin-bottom: 5px; }
        .signature-line { display: block; width: 220px; margin-bottom: 5px; border-bottom: 1px solid #15213a; }
        .sign-name { font-weight: bold; color: #15213a; }
        .sign-title { color: #465161; }
        .copy { color: #697282; font-size: 9px; line-height: 1.5; }
        .footer { position: fixed; left: 0; right: 0; bottom: -8mm; padding-top: 6px; border-top: 1px solid #d8d3c7; color: #697282; font-size: 8px; text-align: center; }
    </style>
</head>
<body>
    <table class="letterhead">
        <tr>
            <td style="width:74px;">
                @if ($logoData)
                    <img class="logo" src="{{ $logoData }}" alt="">
                @endif
            </td>
            <td>
                <div class="gov-line">Government of {{ $stateName }}</div>
                <div class="agency">{{ $mda?->name ?? 'eHRMIS' }}</div>
                <div class="subline">{{ $setting?->acronym ?? $mda?->code }} official promotion correspondence</div>
            </td>
            <td class="meta">
                Promotion letter
                <strong>{{ $letter->letter_number }}</strong>
            </td>
        </tr>
    </table>

    <table class="ref-table">
        <tr>
            <td class="address">
                <strong>{{ $staff?->full_name ?? trim($application->surname.' '.$application->first_name.' '.$application->middle_name) }}</strong><br>
                Staff No: {{ $staff?->staff_number ?? $application->staff_number ?? '-' }}<br>
                {{ $staff?->currentEmployment?->department?->name ?? 'Department not specified' }}<br>
                {{ $mda?->name }}
            </td>
            <td class="reference">
                Ref: <strong>{{ $letter->letter_number }}</strong><br>
                Date: <strong>{{ $generatedAt->format('d M Y') }}</strong><br>
                Effective: <strong>{{ $letter->effective_date?->format('d M Y') }}</strong>
            </td>
        </tr>
    </table>

    <div class="subject">Notification of Promotion</div>

    <p>Dear {{ $application->surname }},</p>

    <p>
        I am pleased to inform you that, following the promotion consideration sitting held on
        <strong>{{ $application->sitting?->sitting_date?->format('d M Y') }}</strong>, your promotion has been
        <strong>{{ str_replace('_', ' ', $application->decision ?? 'approved') }}</strong>.
    </p>

    <p>
        Your promotion takes effect from <strong>{{ $letter->effective_date?->format('d M Y') }}</strong>. Your records
        should be updated to reflect the approved placement shown below, subject to all applicable public service rules,
        payroll validation, and any correction notes recorded by the commission.
    </p>

    <table class="facts">
        <thead>
            <tr>
                <th>Current placement</th>
                <th>Approved placement</th>
                <th>Application</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    Rank: <span class="value">{{ $application->currentRank?->name ?? $application->current_snapshot['rank']['name'] ?? '-' }}</span><br>
                    Scale: <span class="value">{{ $application->currentSalaryScale?->code ?? $application->current_snapshot['salary_scale']['code'] ?? '-' }}</span><br>
                    Level/Step: <span class="value">{{ $application->current_level ?? $application->current_snapshot['level'] ?? '-' }}/{{ $application->current_step ?? $application->current_snapshot['step'] ?? '-' }}</span>
                </td>
                <td>
                    Rank: <span class="value">{{ $application->proposedRank?->name ?? '-' }}</span><br>
                    Scale: <span class="value">{{ $application->proposedSalaryScale?->code ?? '-' }}</span><br>
                    Level/Step: <span class="value">{{ $application->proposed_level ?? '-' }}/{{ $application->proposed_step ?? '-' }}</span>
                </td>
                <td>
                    APA Ref: <span class="value">{{ $application->application_number }}</span><br>
                    Cycle: <span class="value">{{ $application->cycle?->title }}</span><br>
                    Status: <span class="value">{{ str_replace('_', ' ', $application->status) }}</span>
                </td>
            </tr>
        </tbody>
    </table>

    @if ($application->decision_remarks || $application->correction_notes)
        <div class="note">
            @if ($application->decision_remarks)
                <strong>Remark:</strong> {{ $application->decision_remarks }}<br>
            @endif
            @if ($application->correction_notes)
                <strong>Correction note:</strong> {{ $application->correction_notes }}
            @endif
        </div>
    @endif

    <p>
        Please accept our congratulations and continue to discharge your duties with diligence, discipline, and
        commitment to public service.
    </p>

    <table class="signature-table">
        <tr>
            <td class="copy">
                Cc:<br>
                Civil Service Commission<br>
                Director of Administration<br>
                Payroll/Accounts Department<br>
                Personnel File
            </td>
            <td class="signature">
                @if ($signatureData)
                    <img class="signature-img" src="{{ $signatureData }}" alt="">
                @else
                    <span class="signature-line">&nbsp;</span>
                @endif
                <div class="sign-name">{{ $headName }}</div>
                <div class="sign-title">{{ $headTitle }}</div>
            </td>
        </tr>
    </table>

    <div class="footer">
        Generated by eHRMIS on {{ $generatedAt->format('d M Y, h:i A') }}. Verify against the official staff record before payroll processing.
    </div>
</body>
</html>
