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
    if (pathname === '/staff') return response.writeHead(200, { 'Content-Type': 'text/html' }).end(shell);
    const path = resolve(publicRoot, decodeURIComponent(pathname).replace(/^\/+/, ''));
    if (!path.startsWith(`${publicRoot}${sep}`)) return response.writeHead(403).end();
    try { response.writeHead(200, { 'Content-Type': mime[extname(path)] || 'application/octet-stream' }).end(await readFile(path)); }
    catch { response.writeHead(404).end(); }
});
await new Promise(done => server.listen(0, '127.0.0.1', done));
const base = `http://127.0.0.1:${server.address().port}`;
const output = resolve('storage/app/private/ui-checks/staff-creation');
await mkdir(output, { recursive: true });
const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
page.setDefaultTimeout(15000);
const runtimeErrors = [];
page.on('pageerror', error => runtimeErrors.push(error.message));
let permissions = ['view-staff'];
let stationScoped = false;
let failCreation = false;
let releaseCreation;
const writes = [];
const rows = [];
const mdas = [{ id: 1, code: 'HMB', name: 'Hospital Management Board' }, { id: 2, code: 'MOH', name: 'Ministry of Health' }];
const options = {
    mdas,
    departments: [{ id: 1, mda_id: 1, name: 'Administration' }, { id: 2, mda_id: 2, name: 'Finance' }],
    stations: [{ id: 1, mda_id: 1, name: 'Headquarters' }, { id: 2, mda_id: 2, name: 'Ministry headquarters' }],
    cadres: [{ id: 1, department_id: 1, name: 'Administrative Officer' }, { id: 2, department_id: 2, name: 'Accountant' }],
    ranks: [{ id: 1, cadre_id: 1, name: 'Officer I' }, { id: 2, cadre_id: 2, name: 'Accountant I' }],
    salary_scales: [{ id: 1, code: 'GL', name: 'Grade Level' }], qualification_types: [{ id: 1, name: 'B.Sc.' }],
    statuses: ['active', 'retired', 'inactive', 'duplicate'],
};
await page.route('https://fonts.bunny.net/**', route => route.fulfill({ contentType: 'text/css', body: '' }));
await page.route('**/api/**', async route => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/api/me') return route.fulfill({ json: { data: { id: 1, name: 'Staff Officer', permissions, roles: ['Staff Officer'], enabled_modules: ['staff_registry'], assigned_mda: mdas[0], assigned_station: stationScoped ? { id: 1, mda_id: 1 } : null } } });
    if (path === '/api/staff/options') return route.fulfill({ json: { data: options } });
    if (path === '/api/staff' && route.request().method() === 'POST') {
        const body = route.request().postDataJSON();
        writes.push(body);
        if (failCreation) return route.fulfill({ status: 422, json: { message: 'The staff number has already been taken.', errors: { staff_number: ['The staff number has already been taken.'] } } });
        await new Promise(done => { releaseCreation = done; });
        const row = { ...body, id: rows.length + 1, mda: mdas[0], salary_display: 'GL 09 / 2', status: 'active' };
        rows.unshift(row);
        return route.fulfill({ status: 201, json: { message: 'Staff record created.', data: row } });
    }
    if (path === '/api/staff') return route.fulfill({ json: { data: rows, meta: { total: rows.length, current_page: 1, last_page: 1 } } });
    return route.fulfill({ json: { data: [] } });
});
const dialog = page.getByRole('dialog', { name: 'New staff', exact: true });
const choose = async (label, value) => {
    await dialog.getByRole('button', { name: label, exact: true }).click();
    await dialog.getByRole('button', { name: value, exact: true }).click();
};
async function visit() {
    await page.goto(`${base}/staff`);
    await page.locator('.civic-brand-loader-overlay').waitFor({ state: 'detached' });
}
async function fillIdentity(number) {
    await dialog.getByLabel('Staff number *', { exact: true }).fill(number);
    await dialog.getByLabel('Surname *', { exact: true }).fill('Musa');
    await dialog.getByLabel('First name *', { exact: true }).fill('Amina');
    assert.equal(await dialog.getByLabel('Full name', { exact: true }).inputValue(), 'Musa Amina');
}
async function waitForWrite(count) {
    for (let attempt = 0; attempt < 100 && writes.length < count; attempt++) await page.waitForTimeout(50);
    assert.equal(writes.length, count);
}
try {
    await visit();
    assert.equal(await page.getByRole('button', { name: 'New staff', exact: true }).count(), 0, 'Read-only users saw creation control');
    permissions = ['view-staff', 'update-staff'];
    await visit();
    assert.equal(await page.getByRole('button', { name: 'New staff', exact: true }).count(), 0, 'Update permission implicitly granted creation');
    permissions = ['view-staff', 'create-staff'];
    await visit();
    await page.getByRole('button', { name: 'New staff', exact: true }).click();
    await dialog.waitFor();
    assert.equal(await dialog.getByRole('button', { name: 'Rank', exact: true }).isDisabled(), true);
    await fillIdentity('HMB-NEW-001');
    await page.screenshot({ path: `${output}/new-staff-desktop.png`, animations: 'disabled' });
    await choose('Cadre', 'Administrative Officer');
    await choose('Rank', 'Officer I');
    await choose('Station / facility', 'Headquarters');
    await dialog.getByLabel('Email', { exact: true }).fill('amina@example.com');
    await dialog.getByLabel('Contract staff', { exact: true }).check();
    await dialog.getByLabel('Record salary placement', { exact: true }).check();
    await choose('Salary scale *', 'GL');
    await dialog.getByLabel('Level *', { exact: true }).fill('9');
    await dialog.getByLabel('Step *', { exact: true }).fill('2');

    failCreation = true;
    await dialog.getByRole('button', { name: 'Create staff', exact: true }).click();
    await waitForWrite(1);
    await dialog.getByText('Staff record was not created', { exact: true }).waitFor();
    assert.equal(await dialog.getByLabel('Staff number *', { exact: true }).inputValue(), 'HMB-NEW-001');
    assert.equal(await dialog.getByText('The staff number has already been taken.', { exact: true }).count(), 2);

    failCreation = false;
    await dialog.getByLabel('Staff number *', { exact: true }).fill('HMB-NEW-002');
    await dialog.getByRole('button', { name: 'Create staff', exact: true }).click();
    await waitForWrite(2);
    assert.equal(await dialog.getByRole('button', { name: 'Creating...', exact: true }).isDisabled(), true);
    await page.keyboard.press('Escape');
    assert.equal(await dialog.isVisible(), true, 'Saving dialog could be dismissed');
    releaseCreation();
    await dialog.waitFor({ state: 'hidden' });
    await page.getByRole('link', { name: 'HMB-NEW-002', exact: true }).waitFor();
    assert.equal(writes[1].employment.department_id, 1);
    assert.equal(writes[1].employment.cadre_id, 1);
    assert.equal(writes[1].employment.rank_id, 1);
    assert.equal(writes[1].employment.station_id, 1);
    assert.equal(writes[1].is_contract_staff, true);
    assert.equal(Number(writes[1].salary_placement.level), 9);

    await page.getByRole('button', { name: 'New staff', exact: true }).click();
    assert.equal(await dialog.getByLabel('Staff number *', { exact: true }).inputValue(), '');
    await choose('MDA *', 'MOH - Ministry of Health');
    await choose('Department', 'Finance');
    await choose('Cadre', 'Accountant');
    await choose('Rank', 'Accountant I');
    await choose('MDA *', 'HMB - Hospital Management Board');
    assert.equal(await dialog.getByRole('button', { name: 'Rank', exact: true }).isDisabled(), true);
    assert.ok(!(await dialog.getByRole('button', { name: 'Department', exact: true }).innerText()).includes('Finance'));
    await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
    await dialog.waitFor({ state: 'hidden' });

    await page.setViewportSize({ width: 390, height: 844 });
    stationScoped = true;
    await visit();
    await page.getByRole('button', { name: 'New staff', exact: true }).click();
    await dialog.waitFor();
    assert.equal(await dialog.getByRole('button', { name: 'MDA *', exact: true }).isDisabled(), true);
    assert.equal(await dialog.getByRole('button', { name: 'Station / facility *', exact: true }).isDisabled(), true);
    await fillIdentity('HMB-MOBILE-001');
    await page.screenshot({ path: `${output}/new-staff-mobile.png`, animations: 'disabled' });
    const inputBounds = await dialog.getByLabel('Staff number *', { exact: true }).boundingBox();
    assert.ok(inputBounds.x >= 0 && inputBounds.x + inputBounds.width <= 390, 'Mobile fields overflow');
    await dialog.getByRole('button', { name: 'Create staff', exact: true }).click();
    await waitForWrite(3);
    releaseCreation();
    await dialog.waitFor({ state: 'hidden' });
    await page.getByRole('link', { name: 'HMB-MOBILE-001', exact: true }).waitFor();
    assert.equal(writes[2].employment.station_id, 1);
    assert.equal(writes[2].salary_placement, undefined);
    assert.deepEqual(runtimeErrors, []);
    console.log(JSON.stringify({ result: 'passed', permissionChecks: 3, created: rows.length, validation: 'preserves input', viewports: ['desktop', 'mobile'], screenshots: output }));
} finally {
    releaseCreation?.();
    await browser.close();
    await new Promise(done => server.close(done));
}
