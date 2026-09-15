export function reportCno(cno, staffNumber) {
    return String(cno ?? '').trim() || String(staffNumber ?? '').trim() || '—';
}

export function reportDate(value) {
    if (!value) return '—';
    const match = String(value).match(/^(\d{4})-(\d{2})-(\d{2})(?:$|[T ])/);
    return match ? `${match[3]}-${match[2]}-${match[1]}` : '—';
}

export function reportDateTime(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    return `${day}-${month}-${date.getFullYear()}, ${date.toLocaleTimeString('en-GB')}`;
}
