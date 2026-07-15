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
        .address { width: 58%; }
        .reference { text-align: right; color: #465161; }
        .reference strong { color: #15213a; }
        .subject { margin: 18px 0 12px; color: #162b58; font-family: 'DejaVu Serif', serif; font-size: 13px; font-weight: bold; text-transform: uppercase; text-decoration: underline; }
        p { margin: 0 0 11px; }
        .facts { width: 100%; margin: 16px 0; border-collapse: collapse; }
        .facts th { padding: 7px 8px; color: #15213a; background: #f4f2ec; border: 1px solid #2e3e56; text-align: left; font-size: 9px; font-weight: bold; }
        .facts td { padding: 8px; border: 1px solid #d8d3c7; font-size: 10px; }
        .facts td { border-color: #2e3e56; }
        .facts .serial { width: 42px; }
        .signature-table { width: 100%; margin-top: 34px; }
        .signature-table td { vertical-align: bottom; }
        .signature { width: 280px; text-align: left; }
        .signature-img { height: 44px; margin-bottom: 5px; }
        .signature-line { display: block; width: 220px; margin-bottom: 5px; border-bottom: 1px solid #15213a; }
        .sign-name { font-weight: bold; color: #15213a; font-style: italic; }
        .sign-title { color: #15213a; font-weight: bold; font-style: italic; }
        .sign-for { color: #15213a; font-weight: bold; font-style: italic; }
        .copy { color: #697282; font-size: 9px; line-height: 1.5; }
        .manual-number { margin-bottom: 10px; }
        .footer { position: fixed; left: 0; right: 0; bottom: -8mm; padding-top: 6px; border-top: 1px solid #d8d3c7; color: #697282; font-size: 8px; text-align: center; }
    </style>
</head>
<body>
    @php
        $items = $posting->items->isNotEmpty()
            ? $posting->items
            : collect([(object) ['staff_snapshot' => $posting->staff_snapshot]]);
        $stationLabel = $posting->toStation?->name ?? $posting->toDepartment?->name ?? $posting->toMda?->name ?? '-';
        $staffNoun = $items->count() === 1 ? 'staff member' : 'staff';
    @endphp

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
                <strong>{{ $letter->recipient_name ?: 'Recipient not entered' }}</strong><br>
                {{ $letter->recipient_organisation ?: ($posting->toStation?->name ?? $posting->toMda?->name ?? '-') }}<br>
                @if ($letter->recipient_location)
                    {{ $letter->recipient_location }}<br>
                @endif
                @if ($letter->attention_line)
                    Attention: {{ $letter->attention_line }}
                @endif
            </td>
            <td class="reference">
                <strong>{{ $letter->official_reference ?: $letter->letter_number }}</strong><br>
                Date: <strong>{{ $generatedAt->format('d/m/Y') }}</strong>
            </td>
        </tr>
    </table>

    <div class="subject">{{ $letter->subject_line ?: 'POSTING OF STAFF' }}</div>

    <p>
        I write to convey the management's approval for the posting of the under listed {{ $staffNoun }}
        to your establishment for your information and further necessary action please.
    </p>

    <table class="facts">
        <thead>
            <tr>
                <th class="serial">S/N</th>
                <th>Name</th>
                <th>Station</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $index => $item)
                @php
                    $snapshot = $item->staff_snapshot ?? [];
                @endphp
                <tr>
                    <td>{{ $index + 1 }}.</td>
                    <td>{{ $snapshot['full_name'] ?? '-' }}</td>
                    <td>{{ $stationLabel }}</td>
                    <td>Staff</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="manual-number">2. This posting is with immediate effect.</p>
    <p class="manual-number">
        3. You are therefore expected to inform the board on the date the
        {{ $items->count() === 1 ? 'officer assumes' : 'officers assume' }} official duty.
    </p>
    <p>Thank You.</p>

    <table class="signature-table">
        <tr>
            <td></td>
            <td class="signature">
                @if ($signatureData)
                    <img class="signature-img" src="{{ $signatureData }}" alt="">
                @else
                    <span class="signature-line">&nbsp;</span>
                @endif
                <div class="sign-name">{{ $letter->signatory_name ?: 'Authorized Officer' }}</div>
                <div class="sign-title">{{ $letter->signatory_title ?: 'Authorized Officer' }}</div>
                @if ($letter->signatory_for_line)
                    <div class="sign-for">{{ $letter->signatory_for_line }}</div>
                @endif
            </td>
        </tr>
    </table>

    <div class="footer">
        Generated by eHRMIS on {{ $generatedAt->format('d M Y, h:i A') }}. This letter is valid only after the approved posting workflow is complete.
    </div>
</body>
</html>
