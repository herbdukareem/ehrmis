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
    if (pathname === '/reports') return response.writeHead(200, { 'Content-Type': 'text/html' }).end(shell);
    const path = resolve(publicRoot, decodeURIComponent(pathname).replace(/^\/+/, ''));
    if (!path.startsWith(`${publicRoot}${sep}`)) return response.writeHead(403).end();
    try { response.writeHead(200, { 'Content-Type': mime[extname(path)] || 'application/octet-stream' }).end(await readFile(path)); }
    catch { response.writeHead(404).end(); }
});
await new Promise(done => server.listen(0, '127.0.0.1', done));
const base = `http://127.0.0.1:${server.address().port}`;
const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } });
page.setDefaultTimeout(15000);
const errors = [];
const requests = [];
page.on('pageerror', error => errors.push(error.message));
const options = {
    mdas: [{ id: 1, name: 'Hospital Management Board' }],
    departments: [{ id: 1, mda_id: 1, name: 'Clinical services' }],
    stations: [{ id: 1, mda_id: 1, name: 'General Hospital Minna' }, { id: 2, mda_id: 1, name: 'General Hospital Suleja' }],
    cadres: [{ id: 1, department_id: 1, name: 'Medical Officer' }, { id: 2, department_id: 1, name: 'Nursing Officer' }],
    salary_scales: [{ id: 1, code: 'CONMESS' }], statuses: ['active', 'retired'],
    columns: [{ key: 'staff_number', label: 'Staff number' }, { key: 'full_name', label: 'Full name' }, { key: 'phone', label: 'Phone' }],
    report_types: [
        { key: 'staff_list', label: 'Staff list' }, { key: 'facility', label: 'Group by facility' },
        { key: 'cadre', label: 'Group by cadre' }, { key: 'rank', label: 'Group by rank' }, { key: 'salary_grade', label: 'Group by salary grade' },
    ],
};
const labels = {
    facility: ['General Hospital Minna', 'General Hospital Suleja'], cadre: ['Medical Officer', 'Nursing Officer'],
    rank: ['Senior Medical Officer', 'Nursing Officer I'], salary_grade: ['CONMESS / Grade 10', 'CONHESS / Grade 10'],
};
await page.route('https://fonts.bunny.net/**', route => route.fulfill({ contentType: 'text/css', body: '' }));
await page.route('**/api/**', async route => {
    const url = new URL(route.request().url());
    if (url.pathname === '/api/me') return route.fulfill({ json: { data: { id: 1, name: 'Report Officer', roles: ['Super Admin'], permissions: ['view-reports', 'export-reports'], enabled_modules: ['dashboards_analytics'] } } });
    if (url.pathname === '/api/reports/staff-list/options') return route.fulfill({ json: { data: options } });
    if (url.pathname === '/api/reports/staff-list/export') {
        requests.push(url);
        return route.fulfill({ contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', headers: { 'content-disposition': 'attachment; filename="staff-report.xlsx"' }, body: 'browser download fixture' });
    }
    if (url.pathname === '/api/reports/staff-list') {
        requests.push(url);
        const type = url.searchParams.get('report_type');
        const selected = url.searchParams.getAll('columns[]');
        const pageNumber = Number(url.searchParams.get('page') || 1);
        const groups = (labels[type] || []).map((label, index) => ({ id: String(index + 1), group_label: label, staff_count: index === 0 ? 11 : 1 }));
        return route.fulfill({ json: {
            columns: options.columns.filter(column => selected.includes(column.key)), allowance_types: [], groups,
            data: (pageNumber === 1 ? [1, 2] : [3]).map((id, index) => ({ id, group_key: String(index + 1), staff_number: `000${id}`, full_name: `Test Officer ${id}`, phone: '08012345678', allowances: [] })),
            meta: { total: 12, from: pageNumber === 1 ? 1 : 11, to: pageNumber === 1 ? 10 : 12, current_page: pageNumber, last_page: 2, report_type: type, generated_at: '2026-10-20T14:30:00+01:00' },
        } });
    }
    return route.fulfill({ json: { data: {} } });
});
const chooseMultiple = async (label, names) => {
    await page.getByLabel(label, { exact: true }).click();
    const list = page.getByRole('listbox', { name: label, exact: true });
    for (const name of names) await list.getByRole('option', { name, exact: true }).click();
    await list.locator('..').press('Escape');
};
try {
    await page.goto(`${base}/reports`);
    await page.getByRole('heading', { name: 'Staff reports', exact: true }).waitFor();
    await page.getByRole('button', { name: 'Generate report', exact: true }).waitFor({ state: 'visible' });
    await page.getByRole('button', { name: 'Clear columns', exact: true }).click();
    assert.equal(await page.getByRole('button', { name: 'Generate report', exact: true }).isDisabled(), true);
    await chooseMultiple('Columns to include', ['Staff number', 'Full name']);
    await chooseMultiple('Stations', ['General Hospital Minna', 'General Hospital Suleja']);
    await chooseMultiple('Cadres', ['Medical Officer', 'Nursing Officer']);
    await page.getByRole('button', { name: 'Generate report', exact: true }).click();
    await page.getByRole('cell', { name: 'Test Officer 1', exact: true }).waitFor();
    let request = requests.at(-1);
    assert.deepEqual(request.searchParams.getAll('station_ids[]'), ['1', '2']);
    assert.deepEqual(request.searchParams.getAll('cadre_ids[]'), ['1', '2']);
    assert.deepEqual(request.searchParams.getAll('columns[]'), ['staff_number', 'full_name']);
    assert.deepEqual(await page.getByRole('columnheader').allTextContents(), ['S/N', 'Staff number', 'Full name']);
    for (const type of ['facility', 'cadre', 'rank', 'salary_grade']) {
        await page.getByLabel('Report format', { exact: true }).click();
        await page.getByRole('button', { name: options.report_types.find(item => item.key === type).label, exact: true }).click();
        assert.equal(await page.getByRole('button', { name: 'Download Excel', exact: true }).isDisabled(), true);
        await page.getByRole('button', { name: 'Generate report', exact: true }).click();
        await page.getByRole('heading', { name: labels[type][0], exact: true }).waitFor();
        assert.equal(await page.getByRole('table').count(), 2);
        assert.equal(await page.getByRole('cell', { name: 'Test Officer 1', exact: true }).count(), 1);
        assert.ok(await page.getByText('11 staff in this group', { exact: false }).count());
    }
    const downloadEvent = page.waitForEvent('download');
    await page.getByRole('button', { name: 'Download Excel', exact: true }).click();
    const download = await downloadEvent;
    assert.equal(download.suggestedFilename(), 'staff-report.xlsx');
    request = requests.at(-1);
    assert.equal(request.searchParams.get('report_type'), 'salary_grade');
    assert.deepEqual(request.searchParams.getAll('columns[]'), ['staff_number', 'full_name']);
    await chooseMultiple('Cadres', ['Nursing Officer']);
    await page.getByRole('button', { name: 'Next', exact: true }).click();
    await page.getByRole('cell', { name: 'Test Officer 3', exact: true }).waitFor();
    assert.deepEqual(requests.at(-1).searchParams.getAll('cadre_ids[]'), ['1', '2'], 'Pagination must use the generated report choices');
    assert.equal(await page.getByRole('button', { name: 'Download Excel', exact: true }).isDisabled(), true);
    const output = resolve('storage/app/private/ui-checks/staff-reports');
    await mkdir(output, { recursive: true });
    await page.screenshot({ path: resolve(output, 'desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.waitForFunction(() => document.querySelector('.civic-rail').getBoundingClientRect().right <= 1);
    await page.screenshot({ path: resolve(output, 'mobile.png'), fullPage: true });
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'The page must fit a mobile viewport');
    const staffCard = page.getByRole('heading', { name: 'Choose staff', exact: true }).locator('xpath=ancestor::section[1]');
    assert.ok(await staffCard.evaluate(card => [...card.querySelectorAll('input, button')].every(control => control.getBoundingClientRect().right <= card.getBoundingClientRect().right)), 'Filter controls must fit their card on mobile');
    assert.deepEqual(errors, []);
    console.log('Staff reports browser checks passed: columns, multi-select filters, all grouped formats, pagination, export parameters, and mobile layout.');
} finally {
    await browser.close();
    await new Promise(done => server.close(done));
}
