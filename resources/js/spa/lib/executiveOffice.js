import { api } from './api';
export const officeLabel = value => String(value ?? '').replaceAll('_', ' ').replace(/\b\w/g, c => c.toUpperCase());
export async function officeDownload(path, params, filename) {
    const response = await api.get(path, { params, responseType: 'blob' });
    const url = URL.createObjectURL(response.data), link = document.createElement('a');
    link.href = url; link.download = response.headers['content-disposition']?.match(/filename="?([^";]+)"?/)?.[1] ?? filename;
    document.body.appendChild(link); link.click(); link.remove(); window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}
export const officeFields = {
    correspondence: [ ['direction', 'Direction', 'direction'], ['sender', 'Sender', 'text', true], ['recipient', 'Recipient'], ['external_reference', 'External reference'], ['origin', 'Origin'], ['document_date', 'Document date', 'date'], ['received_at', 'Date received', 'date'] ],
    appointment: [ ['requester', 'Requester', 'text', true], ['venue', 'Venue or meeting link', 'text', true], ['sensitive', 'Sensitive appointment', 'boolean'], ['participant_user_ids', 'Participants', 'users'], ['external_participants', 'External participants'] ],
    directive: [ ['instruction', 'Instruction', 'long', true] ],
    meeting: [ ['venue', 'Venue or meeting link', 'text', true], ['agenda', 'Agenda', 'long', true], ['participant_user_ids', 'Participants', 'users'], ['external_participants', 'External participants'], ['minutes', 'Draft minutes', 'long'] ],
    visitor: [ ['visitor_name', 'Visitor name', 'text', true], ['organisation', 'Organisation'], ['contact', 'Contact'], ['purpose', 'Purpose', 'long', true], ['host', 'Host', 'text', true], ['walk_in', 'Walk-in visitor', 'boolean'] ],
    briefing: [ ['briefing_date', 'Briefing date', 'date', true], ['summary', 'Executive briefing', 'long', true] ],
};
export function officeLocalDate(value, timezone = 'Africa/Lagos') {
    if (!value) return '';
    const parts = new Intl.DateTimeFormat('en-GB', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date(value));
    const get = type => parts.find(part => part.type === type)?.value;
    return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`;
}
