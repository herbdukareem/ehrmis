import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { mkdir, readFile } from 'node:fs/promises';
import { extname, resolve, sep } from 'node:path';
import { createServer } from 'node:http';

const require = createRequire(import.meta.url);
const { chromium } = require(process.argv[2] || 'playwright');
const manifest = JSON.parse(await readFile('public/build/manifest.json', 'utf8'));
const entry = manifest['resources/js/app.js'];
const shell = `<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width, initial-scale=1">${entry.css.map(file => `<link rel="stylesheet" href="/build/${file}">`).join('')}<script type="module" src="/build/${entry.file}"></script></head><body><div id="app"></div></body></html>`;
const publicRoot = resolve('public');
const mime = { '.js': 'text/javascript', '.css': 'text/css', '.jpg': 'image/jpeg', '.png': 'image/png', '.svg': 'image/svg+xml' };
const server = createServer(async (request, response) => {
    const pathname = new URL(request.url, 'http://localhost').pathname;
    if (pathname.startsWith('/executive-office')) return response.writeHead(200, { 'Content-Type': 'text/html' }).end(shell);
    const path = resolve(publicRoot, decodeURIComponent(pathname).replace(/^\/+/, ''));
    if (!path.startsWith(`${publicRoot}${sep}`)) return response.writeHead(403).end();
    try { response.writeHead(200, { 'Content-Type': mime[extname(path)] || 'application/octet-stream' }).end(await readFile(path)); }
    catch { response.writeHead(404).end(); }
});
await new Promise(done => server.listen(0, '127.0.0.1', done));
const base = `http://127.0.0.1:${server.address().port}`;
const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1100 }, timezoneId: 'Africa/Lagos' });
page.setDefaultTimeout(15000);
await page.clock.install({ time: new Date('2026-10-26T07:00:00Z') });
const errors = [], writes = [];
page.on('pageerror', error => errors.push(error.message));
let role = 'executive';
const ids = { executive: 10, lead_pa: 11, action_officer: 12, registry: 13, administrator: 14 };
const users = [{ id: 10, name: 'Executive User' }, { id: 11, name: 'Lead PA' }, { id: 12, name: 'Action Officer' }, { id: 13, name: 'Reception Officer' }, { id: 14, name: 'Office Administrator' }];
const abilities = {
    executive: ['read', 'create', 'edit', 'schedule', 'register', 'meetings', 'route', 'decide', 'review', 'respond', 'dispatch', 'notes', 'brief', 'report', 'delegate', 'archive'],
    lead_pa: ['read', 'create', 'edit', 'schedule', 'register', 'meetings', 'route', 'review', 'respond', 'dispatch', 'notes', 'brief', 'report', 'archive'],
    action_officer: ['read', 'respond'], registry: ['read', 'register'], administrator: [],
};
const office = { id: 1, mda_id: 1, title: 'Executive Secretary', reference_prefix: 'HMB/EO', timezone: 'Africa/Lagos', pa_confirms_routine: true, reminder_minutes: 60, workday_start: '08:00', workday_end: '17:00', workdays: [1, 2, 3, 4, 5] };
const types = ['correspondence', 'appointment', 'directive', 'meeting', 'visitor', 'briefing'];
const rows = [
    { id: 1, type: 'correspondence', subject: 'Quarterly information request', status: 'received', details: { direction: 'incoming', sender: 'Ministry of Health', recipient: 'HMB', origin: 'Ministry', document_date: '2026-10-26' } },
    { id: 2, type: 'appointment', subject: 'Routine appointment', status: 'requested', starts_at: '2026-10-26T09:00:00Z', ends_at: '2026-10-26T10:00:00Z', details: { requester: 'Visiting Director', venue: 'Executive office', sensitive: false } },
    { id: 3, type: 'directive', subject: 'Prepare quarterly figures', status: 'assigned', assigned_to: 12, due_at: '2026-10-27T09:00:00Z', details: { instruction: 'Provide figures for the quarterly review.' } },
    { id: 4, type: 'meeting', subject: 'Monthly coordination meeting', status: 'confirmed', starts_at: '2026-10-26T11:00:00Z', ends_at: '2026-10-26T12:00:00Z', details: { venue: 'Conference room', agenda: 'Review outstanding actions', participant_user_ids: [12], minutes: 'Draft minutes' } },
    { id: 5, type: 'visitor', subject: 'Expected delegation', status: 'expected', details: { visitor_name: 'Visiting Director', purpose: 'Coordination visit', organisation: 'MOH', host: 'Executive Secretary', walk_in: false } },
    { id: 6, type: 'briefing', subject: 'Daily executive briefing', status: 'draft', details: { briefing_date: '2026-10-26', summary: 'Review the schedule and outstanding decisions.' } },
].map(row => ({ mda_id: 1, office_id: 1, reference: `HMB/EO/2026/${String(row.id).padStart(6, '0')}`, body: 'Official office content.', priority: 'normal', confidentiality: 'standard', version: 1, approved_version: null, archived_at: null, closed_at: null, ...row }));
const members = users.map(user => ({ id: user.id, user_id: user.id, user, role: Object.entries(ids).find(([, id]) => id === user.id)[0], starts_at: '2026-10-25T07:00:00Z', ends_at: null, revoked_at: null }));
const grants = [];
function visible(row) { return role === 'administrator' ? false : (role === 'registry' ? row.type === 'visitor' : (role === 'action_officer' ? row.assigned_to === 12 || row.details.participant_user_ids?.includes(12) : true)); }
function decorate(row) {
    let actions = [];
    if (row.type === 'correspondence') actions = row.status === 'received' ? ['submit_decision'] : (row.status === 'awaiting_decision' && role === 'executive' ? ['approve', 'refer', 'return'] : []);
    if (row.type === 'appointment' && abilities[role].includes('schedule')) actions = row.status === 'requested' ? ['confirm', 'reschedule'] : ['complete', 'cancel'];
    if (row.type === 'directive') actions = role === 'action_officer' ? ['acknowledge', 'submit_response'] : ['accept_response'];
    if (row.type === 'visitor') actions = row.status === 'expected' ? ['arrive'] : (row.status === 'arrived' ? ['depart'] : []);
    if (row.type === 'meeting') actions = role === 'action_officer' ? ['rsvp_accept', 'rsvp_decline'] : ['record_attendance', 'held'];
    if (row.type === 'briefing' && abilities[role].includes('brief')) actions = ['publish'];
    return { ...row, notes: abilities[role].includes('notes') ? [{ id: 1, visibility: 'office', body: 'PA preparation note' }] : [], attachments: [], links: [], warnings: [], events: [], actions,
        can_edit: abilities[role].includes('edit'), participants: row.type === 'meeting' ? [{ id: 12, name: 'Action Officer', response: 'Awaiting response', attendance: 'Not recorded' }] : [] };
}
await page.route('https://fonts.bunny.net/**', route => route.fulfill({ contentType: 'text/css', body: '' }));
await page.route('**/api/**', async route => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname;
    const body = request.postData() && request.headers()['content-type']?.includes('application/json') ? request.postDataJSON() : {};
    if (request.method() !== 'GET') writes.push({ path, body });
    if (path === '/api/me') return route.fulfill({ json: { data: { id: ids[role], name: users.find(user => user.id === ids[role]).name, roles: [role], permissions: ['view-executive-office', ...(role === 'administrator' ? ['manage-executive-office'] : [])], enabled_modules: ['executive_office'], assigned_mda: { id: 1, name: 'Hospital Management Board' } } } });
    if (path === '/api/executive-office/context') return route.fulfill({ json: { data: [{ mda_id: 1, mda_name: 'Hospital Management Board', office, abilities: abilities[role], roles: [role], executive_content: role === 'executive', can_configure: role === 'administrator', suggested_title: 'Executive Secretary', suggested_prefix: 'HMB/EO' }], catalog: { types, roles: Object.keys(ids), states: Object.fromEntries(types.map(type => [type, [...new Set(rows.filter(row => row.type === type).map(row => row.status))]])), classifications: ['standard', 'office_restricted', 'executive_only'] } } });
    if (path === '/api/executive-office/options') return route.fulfill({ json: { users, departments: [{ id: 1, name: 'Administration' }] } });
    if (path === '/api/executive-office/team') return route.fulfill({ json: { data: members, users } });
    if (path === '/api/executive-office/office') { Object.assign(office, body); return route.fulfill({ json: { data: office } }); }
    if (path === '/api/executive-office/notifications') return route.fulfill({ json: { data: [] } });
    if (path === '/api/executive-office/dashboard') {
        const queues = { schedule: rows.filter(row => ['appointment', 'meeting'].includes(row.type) && visible(row)), decisions: rows.filter(row => row.type === 'correspondence' && visible(row)), urgent: [], overdue: [], visitors: rows.filter(row => row.type === 'visitor' && visible(row)), preparation: [] };
        return route.fulfill({ json: { data: { role: role === 'executive' ? 'Executive' : 'Office coordination', counts: Object.fromEntries(Object.entries(queues).map(([key, value]) => [key, value.length])), queues } } });
    }
    if (path === '/api/executive-office/diary') return route.fulfill({ json: { data: { from: '2026-10-26', to: url.searchParams.get('view') === 'day' ? '2026-10-26' : '2026-11-01', timezone: 'Africa/Lagos', events: rows.filter(row => ['appointment', 'meeting'].includes(row.type)).map(({ id, subject, starts_at, ends_at, status }) => ({ id, subject, starts_at, ends_at, status })) } } });
    if (path === '/api/executive-office/delegations') { if (request.method() === 'POST') grants.push({ id: grants.length + 1, ...body }); return route.fulfill({ json: { data: grants, abilities: ['read', 'schedule', 'decide'] } }); }
    if (/\/delegations\/\d+$/.test(path)) { grants.find(grant => grant.id === Number(path.split('/').at(-1))).revoked_at = new Date().toISOString(); return route.fulfill({ json: { message: 'Revoked' } }); }
    if (path === '/api/executive-office/briefings/prepare' || path === '/api/executive-office/handover/pack') return route.fulfill({ json: { data: rows.find(row => row.type === 'briefing') } });
    if (path === '/api/executive-office/handover') return route.fulfill({ json: { data: { transferred_records: 1 } } });
    if (path === '/api/executive-office/reports/export' || /\/print$/.test(path)) return route.fulfill({ headers: { 'content-disposition': `attachment; filename="office.${path.endsWith('print') ? 'pdf' : 'xlsx'}"` }, contentType: 'application/octet-stream', body: 'download fixture' });
    if (path === '/api/executive-office/reports') return route.fulfill({ json: { summary: { total: rows.length, completed: 0, overdue: 0, average_completion_hours: null }, by_department: [{ id: 1, department: 'Administration', total: rows.length }], data: rows.filter(visible), meta: { total: rows.length, current_page: 1, last_page: 1 } } });
    if (path === '/api/executive-office/records') {
        if (request.method() === 'POST') { const row = { ...rows[0], ...body, id: rows.length + 1, status: body.type === 'appointment' ? 'requested' : 'draft', version: 1, reference: `HMB/EO/2026/NEW-${rows.length + 1}` }; rows.push(row); return route.fulfill({ status: 201, json: { data: decorate(row) } }); }
        const data = rows.filter(row => visible(row) && (!url.searchParams.get('type') || row.type === url.searchParams.get('type')));
        return route.fulfill({ json: { data, meta: { total: data.length, current_page: 1, last_page: 1 } } });
    }
    const match = path.match(/\/records\/(\d+)(?:\/(.*))?$/);
    if (match) {
        const row = rows.find(item => item.id === Number(match[1]));
        if (match[2] === 'actions') { row.status = ({ submit_decision: 'awaiting_decision', approve: 'completed', confirm: 'confirmed', arrive: 'arrived', depart: 'departed', acknowledge: 'acknowledged', submit_response: 'response_submitted', publish: 'published' })[body.action] || row.status; row.version++; }
        if (match[2] === 'notes') return route.fulfill({ status: 201, json: { message: 'Saved' } });
        if (match[2] === 'directives') return route.fulfill({ status: 201, json: { data: [] } });
        if (request.method() === 'PUT') { Object.assign(row, body); row.version++; }
        return route.fulfill({ json: { data: decorate(row) } });
    }
    return route.fulfill({ json: { data: {} } });
});
const choose = async (label, name) => { await page.getByLabel(label, { exact: true }).click(); await page.getByRole('button', { name, exact: true }).click(); };
const visit = async next => { role = next; await page.goto(`${base}/executive-office`); await page.getByRole('heading', { name: `Office of the ${office.title}`, exact: true }).waitFor(); await page.locator('.civic-brand-loader-overlay').waitFor({ state: 'detached' }); };
const output = resolve('storage/app/private/ui-checks/executive-office');
try {
    await mkdir(output, { recursive: true });
    await visit('executive');
    await page.getByRole('heading', { name: 'Your executive briefing', exact: true }).waitFor();
    await page.screenshot({ path: resolve(output, 'executive-overview.png'), fullPage: true });
    await page.getByRole('tab', { name: 'Appointment', exact: true }).click();
    await page.getByRole('heading', { name: 'Executive diary', exact: true }).waitFor();
    await page.getByRole('tab', { name: 'Day', exact: true }).click();
    await page.getByRole('tab', { name: 'Month', exact: true }).click();
    await page.getByRole('tab', { name: 'Correspondence', exact: true }).click();
    await page.getByRole('button', { name: 'Open', exact: true }).first().click();
    await choose('Action', 'Submit Decision');
    await page.getByRole('button', { name: 'Apply action', exact: true }).click();
    await page.getByText('HMB/EO/2026/000001 · Awaiting Decision', { exact: false }).waitFor();
    await choose('Action', 'Approve');
    await page.getByRole('button', { name: 'Apply action', exact: true }).click();
    await page.getByText('HMB/EO/2026/000001 · Completed', { exact: false }).waitFor();
    await page.screenshot({ path: resolve(output, 'correspondence-detail.png'), fullPage: true });
    await page.getByRole('tab', { name: 'Delegation and handover', exact: true }).click();
    await choose('Delegate', 'Lead PA');
    await page.getByLabel('Authority starts (office time)', { exact: true }).fill('2026-10-26T09:00');
    await page.getByLabel('Authority ends (office time)', { exact: true }).fill('2026-10-27T09:00');
    await page.getByLabel('Reason and authority scope', { exact: true }).fill('Diary cover');
    await page.getByRole('button', { name: 'Grant dated authority', exact: true }).click();
    await page.getByText('Delegation recorded.', { exact: false }).waitFor();
    await page.getByRole('button', { name: 'Revoke authority', exact: true }).click();
    await page.getByText('Revoked', { exact: true }).waitFor();
    await page.getByRole('tab', { name: 'Office reports', exact: true }).click();
    await page.getByRole('heading', { name: 'Office activity reports', exact: true }).waitFor();
    await page.getByRole('button', { name: 'Generate office report', exact: true }).click();
    const downloadEvent = page.waitForEvent('download');
    await page.getByRole('button', { name: 'Download Excel', exact: true }).click();
    assert.equal((await downloadEvent).suggestedFilename(), 'office.xlsx');
    await visit('lead_pa');
    await page.getByRole('heading', { name: 'Your office coordination desk', exact: true }).waitFor();
    assert.equal(await page.getByRole('heading', { name: 'Acting cover and limited delegation', exact: true }).count(), 0);
    await page.getByRole('tab', { name: 'Appointment', exact: true }).click();
    await page.getByRole('button', { name: 'New appointment', exact: true }).click();
    await page.getByLabel('Subject', { exact: true }).fill('PA scheduled engagement');
    await page.getByLabel('Starts (office time)', { exact: true }).fill('2026-10-27T10:00');
    await page.getByLabel('Ends (office time)', { exact: true }).fill('2026-10-27T11:00');
    await page.getByLabel('Requester', { exact: true }).fill('Guest');
    await page.getByLabel('Venue or meeting link', { exact: true }).fill('Office');
    await page.getByRole('button', { name: 'Save appointment', exact: true }).click();
    await page.getByRole('heading', { name: 'PA scheduled engagement', exact: true }).waitFor();
    assert.equal(writes.find(item => item.path === '/api/executive-office/records' && item.body.subject === 'PA scheduled engagement').body.type, 'appointment');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.waitForFunction(() => document.querySelector('.civic-rail').getBoundingClientRect().right <= 1);
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({ path: resolve(output, 'mobile-office.png'), fullPage: true });
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'Office page must fit a mobile viewport');
    await page.setViewportSize({ width: 1440, height: 1100 });
    await visit('action_officer');
    await page.getByRole('tab', { name: 'Assigned to me', exact: true }).click();
    assert.equal(await page.getByRole('button', { name: 'New directive', exact: true }).count(), 0);
    await page.getByRole('button', { name: 'Open', exact: true }).first().click();
    await choose('Action', 'Submit Response');
    await page.getByLabel('Decision, response, or reason', { exact: true }).fill('Figures submitted.');
    await page.getByRole('button', { name: 'Apply action', exact: true }).click();
    await page.getByText('HMB/EO/2026/000003 · Response Submitted', { exact: false }).waitFor();
    assert.equal(await page.getByText('PA preparation note', { exact: true }).count(), 0);
    await visit('registry');
    await page.getByRole('tab', { name: 'Visitor', exact: true }).click();
    await page.getByRole('button', { name: 'Open', exact: true }).first().click();
    await choose('Action', 'Arrive');
    await page.getByRole('button', { name: 'Apply action', exact: true }).click();
    await page.getByText('HMB/EO/2026/000005 · Arrived', { exact: false }).waitFor();
    assert.equal(await page.getByRole('heading', { name: 'Executive diary', exact: true }).count(), 0);
    await visit('administrator');
    await page.getByRole('tab', { name: 'Office administration', exact: true }).click();
    await page.getByLabel('Executive title', { exact: true }).fill('General Manager');
    await page.getByRole('button', { name: 'Save office settings', exact: true }).click();
    await page.getByText('Office settings saved.', { exact: true }).waitFor();
    assert.equal(await page.getByRole('tab', { name: 'Correspondence', exact: true }).count(), 0);
    await page.screenshot({ path: resolve(output, 'office-administration.png'), fullPage: true });
    assert.deepEqual(errors, []);
    console.log('Executive Office browser checks passed: executive, PA, recipient, registry, administrator, diary views, decisions, delegation, reports, and mobile layout.');
} finally { await browser.close(); await new Promise(done => server.close(done)); }
