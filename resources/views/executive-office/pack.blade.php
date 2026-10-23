<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>{{ $record['reference'] }}</title>
<style>body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#173a36;margin:30px}header{border-bottom:2px solid #134e4a;padding-bottom:12px;margin-bottom:20px}h1{font-size:19px}h2{font-size:14px;margin-top:22px}.content{white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.6}.meta{color:#526763;font-size:10px}table{border-collapse:collapse;width:100%}th,td{text-align:left;padding:7px;border:1px solid #d6dedb;vertical-align:top}th{background:#eef3f0}@media print{body{margin:0}.no-print{display:none}}.section{page-break-inside:auto}</style></head>
<body><header><p>{{ $mdaName }}</p><h1>Office of the {{ $office->title }}</h1><p class="meta">{{ $record['reference'] }} · {{ ucwords(str_replace('_', ' ', $record['type'])) }} · {{ ucwords(str_replace('_', ' ', $record['confidentiality'])) }}</p></header>
<h2>{{ $record['subject'] }}</h2><p class="meta">Status: {{ $record['status'] }} · Record version {{ $record['version'] }}@if($record['approved_version'] ?? null) · Approved version {{ $record['approved_version'] }}@endif</p>
<div class="content">{{ $record['body'] ?? '' }}</div>
@foreach($record['details'] ?? [] as $key => $value)
@if(!is_array($value) && $value !== null && !in_array($key, ['rsvps', 'participant_user_ids', 'attended_user_ids']))
<section class="section"><h2>{{ ucwords(str_replace('_', ' ', $key)) }}</h2><div class="content">{{ is_bool($value) ? ($value ? 'Yes' : 'No') : $value }}</div></section>
@endif
@endforeach
@if(count($record['participants'] ?? []))<h2>Participants and attendance</h2><table><thead><tr><th>Name</th><th>Invitation</th><th>Attendance</th></tr></thead><tbody>@foreach($record['participants'] as $participant)<tr><td>{{ $participant['name'] }}</td><td>{{ $participant['response'] }}</td><td>{{ $participant['attendance'] }}</td></tr>@endforeach</tbody></table>@endif
@if(count($record['notes'] ?? []))<h2>Permitted notes and instructions</h2>@foreach($record['notes'] as $note)<p class="meta">{{ $note['visibility'] }}</p><div class="content">{{ $note['body'] }}</div>@endforeach @endif
@if(count($record['links'] ?? []))<h2>Related records</h2>@foreach($record['links'] as $link)<p>{{ $link['reference'] }} — {{ $link['subject'] }}</p>@endforeach @endif
@if(count($record['attachments'] ?? []))<h2>Supporting documents</h2><p class="meta">Documents are downloaded separately through the office workspace.</p>@foreach($record['attachments'] as $attachment)<p>{{ $attachment['name'] }} · version {{ $attachment['record_version'] }}</p>@endforeach @endif
<p class="meta">Generated {{ now()->timezone($office->timezone)->format('d-m-Y H:i') }} ({{ $office->timezone }}). Access classification applies to printed copies.</p></body></html>
