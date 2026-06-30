<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Posting letter - {{ $posting->request_number }}</title>
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
                <div class="agency">{{ $posting->fromMda?->name ?? 'eHRMIS' }}</div>
                <div class="subline">{{ $setting?->acronym ?? $posting->fromMda?->code }} official posting correspondence</div>
            </td>
            <td class="meta">
                Posting letter
                <strong>{{ $letter->letter_number }}</strong>
            </td>
        </tr>
    </table>

    <table class="ref-table">
        <tr>
            <td class="address">
                <strong>{{ $staff?->full_name }}</strong><br>
                Staff No: {{ $staff?->staff_number ?? '-' }}<br>
                {{ $posting->fromDepartment?->name ?? 'Department not specified' }}<br>
                {{ $posting->fromMda?->name }}
            </td>
            <td class="reference">
                Ref: <strong>{{ $letter->letter_number }}</strong><br>
                Request: <strong>{{ $posting->request_number }}</strong><br>
                Date: <strong>{{ $generatedAt->format('d M Y') }}</strong>
            </td>
        </tr>
    </table>

    <div class="subject">Notification of Staff Posting</div>

    <p>Dear {{ $staff?->surname ?? 'Officer' }},</p>

    <p>
        You are hereby posted from <strong>{{ $posting->fromMda?->name }}</strong> to
        <strong>{{ $posting->toMda?->name }}</strong> with effect from
        <strong>{{ $posting->effective_date?->format('d M Y') }}</strong>.
    </p>

    <p>
        You are expected to report to your new duty station and complete all handover, clearance, and resumption
        formalities in line with applicable public service rules.
    </p>

    <table class="facts">
        <thead>
            <tr>
                <th>Current posting</th>
                <th>New posting</th>
                <th>Request details</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    MDA: <span class="value">{{ $posting->fromMda?->name ?? '-' }}</span><br>
                    Department: <span class="value">{{ $posting->fromDepartment?->name ?? '-' }}</span><br>
                    Station: <span class="value">{{ $posting->fromStation?->name ?? '-' }}</span>
                </td>
                <td>
                    MDA: <span class="value">{{ $posting->toMda?->name ?? '-' }}</span><br>
                    Department: <span class="value">{{ $posting->toDepartment?->name ?? '-' }}</span><br>
                    Station: <span class="value">{{ $posting->toStation?->name ?? '-' }}</span>
                </td>
                <td>
                    Type: <span class="value">{{ str_replace('_', ' ', $posting->posting_type) }}</span><br>
                    Status: <span class="value">{{ str_replace('_', ' ', $posting->status) }}</span><br>
                    Effective: <span class="value">{{ $posting->effective_date?->format('d M Y') }}</span>
                </td>
            </tr>
        </tbody>
    </table>

    @if ($posting->reason)
        <p><strong>Reason:</strong> {{ $posting->reason }}</p>
    @endif

    <p>
        Kindly acknowledge receipt of this posting letter and ensure that your personnel record is updated upon
        resumption at the new station.
    </p>

    <table class="signature-table">
        <tr>
            <td class="copy">
                Cc:<br>
                Receiving MDA<br>
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
        Generated by eHRMIS on {{ $generatedAt->format('d M Y, h:i A') }}. This letter is valid only after the approved posting workflow is complete.
    </div>
</body>
</html>
