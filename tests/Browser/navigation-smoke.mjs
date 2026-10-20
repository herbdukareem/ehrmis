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
    if (!extname(pathname)) return response.writeHead(200, { 'Content-Type': 'text/html' }).end(shell);
    const path = resolve(publicRoot, decodeURIComponent(pathname).replace(/^\/+/, ''));
    if (!path.startsWith(`${publicRoot}${sep}`)) return response.writeHead(403).end();
    try {
        response.writeHead(200, { 'Content-Type': mime[extname(path)] || 'application/octet-stream' }).end(await readFile(path));
    } catch { response.writeHead(404).end(); }
});
await new Promise(done => server.listen(0, '127.0.0.1', done));
const base = process.argv[3] || process.env.NAV_BROWSER_URL || `http://127.0.0.1:${server.address().port}`;
const permissions = [...(await readFile('database/seeders/RolesAndPermissionsSeeder.php', 'utf8')).matchAll(/'([a-z]+-[a-z-]+)'/g)].map(match => match[1]);
const user = {
    id: 1, name: 'Navigation Test', roles: ['Super Admin'], has_global_access: true, permissions,
    enabled_modules: ['service_reporting', 'budget_procurement', 'workplan_performance', 'dashboards_analytics', 'staff_registry', 'settings', 'movement_budget', 'legacy_import', 'access_management'],
};
const output = resolve('storage/app/private/ui-checks/navigation');
await mkdir(output, { recursive: true });
const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
page.setDefaultTimeout(15000);
const runtimeErrors = [];
const documentRequests = [];
let summaryCount = 0;
let holdSummaries = false;
let summaryStatus = 200;
let holdWorkplans = false;
const pendingSummaries = [];
const pendingWorkplans = [];
let expectedImportFailure = false;
page.on('pageerror', error => { if (!expectedImportFailure) runtimeErrors.push(error.message); });
page.on('request', request => { if (request.isNavigationRequest() && request.frame() === page.mainFrame()) documentRequests.push(request.url()); });
await page.route('https://fonts.bunny.net/**', route => route.fulfill({ contentType: 'text/css', body: '' }));
await page.route('**/api/**', async route => {
    const path = new URL(route.request().url()).pathname;
    let body = { data: [] };
    if (path === '/api/me') body = { data: user };
    if (path === '/api/public-context') body = { data: {} };
    if (path === '/api/service-reports') body = { data: { mdas: [], stations: [], summary: {}, compliance: [] } };
    if (path === '/api/budget-procurement/options') body = { data: { mda_id: 1, mdas: [], cycles: [] } };
    if (path === '/api/budget-procurement/notifications/summary') {
        summaryCount++;
        if (holdSummaries) await new Promise(done => pendingSummaries.push(done));
        return route.fulfill({ status: summaryStatus, json: { data: { mda_id: 1, unread_count: 3 }, message: 'Notification check unavailable.' } });
    }
    if (path === '/api/workplans' && holdWorkplans) await new Promise(done => pendingWorkplans.push(done));
    return route.fulfill({ json: body });
});
const waitFor = async (check, message) => {
    for (let attempt = 0; attempt < 100; attempt++) {
        if (await check()) return;
        await page.waitForTimeout(50);
    }
    assert.fail(message);
};
async function openGroup(name) {
    const menu = page.getByRole('button', { name: 'Menu', exact: true });
    if (await menu.isVisible() && !await page.locator('.civic-rail').evaluate(node => node.classList.contains('civic-rail-open'))) await menu.click();
    const group = page.getByRole('button', { name, exact: true });
    if (await group.getAttribute('aria-expanded') !== 'true') await group.click();
}
async function navigate(group, label, path) {
    await openGroup(group);
    await page.locator('.civic-nav').getByRole('link', { name: label, exact: false }).click();
    await page.waitForURL(`${base}${path}`);
    await page.locator('.civic-brand-loader-overlay').waitFor({ state: 'detached' });
    assert.ok((await page.locator('.civic-page').innerText()).trim(), `Blank page: ${path}`);
    assert.equal(await page.getByText('Page unavailable', { exact: true }).count(), 0);
}
try {
    await page.clock.install();
    await page.goto(`${base}/service-reports/submit`);
    await page.getByRole('heading', { name: 'Create New Report', exact: true }).waitFor();
    await page.locator('.civic-brand-loader-overlay').waitFor({ state: 'detached' });
    assert.equal(await page.locator('.civic-nav-parent[aria-expanded="true"]').count(), 0);

    // A slow scheduled notification request must not obscure forms or block the sidebar.
    await page.evaluate(() => {
        window.loaderInsertions = 0;
        new MutationObserver(records => {
            for (const record of records) for (const node of record.addedNodes) {
                if (node.nodeType === 1 && (node.matches('.civic-brand-loader-overlay') || node.querySelector('.civic-brand-loader-overlay'))) window.loaderInsertions++;
            }
        }).observe(document.body, { childList: true, subtree: true });
    });
    holdSummaries = true;
    const priorSummaryCount = summaryCount;
    await page.clock.fastForward(61000);
    await waitFor(() => summaryCount > priorSummaryCount, 'Scheduled notification poll did not run');
    await page.waitForTimeout(150);
    assert.equal(await page.locator('.civic-brand-loader-overlay').count(), 0, 'Background polling covered the page');
    await openGroup('Workplan');
    assert.equal(await page.evaluate(() => window.loaderInsertions), 0, 'Background polling flashed the full-page loader');
    holdSummaries = false;
    pendingSummaries.splice(0).forEach(done => done());
    await page.clock.resume();

    // Completing a background request must not decrement a concurrent foreground request.
    holdSummaries = true;
    holdWorkplans = true;
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await waitFor(() => pendingSummaries.length > 0, 'Focus notification poll did not run');
    await page.locator('.civic-nav').getByRole('link', { name: 'Annual workplans', exact: false }).click();
    await waitFor(() => pendingWorkplans.length > 0, 'Foreground page request did not start');
    await page.locator('.civic-brand-loader-overlay').waitFor();
    pendingSummaries.splice(0).forEach(done => done());
    holdSummaries = false;
    await page.waitForTimeout(150);
    assert.equal(await page.locator('.civic-brand-loader-overlay').count(), 1, 'Background completion cleared foreground loading');
    holdWorkplans = false;
    pendingWorkplans.splice(0).forEach(done => done());
    await page.locator('.civic-brand-loader-overlay').waitFor({ state: 'detached' });

    for (const [group, label, path] of [
        ['HR operations', 'Staff registry', '/staff'],
        ['Reports & returns', 'Service reports', '/service-reports'],
        ['Movement & budget', 'Recurrent budget', '/budget-workbooks'],
        ['Budget & procurement', 'Budget management', '/budget-procurement/cycles'],
        ['Workplan', 'Annual workplans', '/workplans'],
    ]) await navigate(group, label, path);

    summaryStatus = 403;
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await page.waitForTimeout(150);
    assert.equal(await page.getByText('Access unavailable', { exact: true }).count(), 0, 'Background failure replaced the current page');
    assert.equal(await page.locator('.civic-brand-loader-overlay').count(), 0);
    summaryStatus = 200;
    assert.equal(documentRequests.length, 1, 'Sidebar navigation caused a full-page reload');
    await page.screenshot({ path: `${output}/cross-group-desktop.png`, fullPage: true });

    await page.setViewportSize({ width: 390, height: 844 });
    await navigate('Reports & returns', 'Service reports', '/service-reports');
    await navigate('Workplan', 'Annual workplans', '/workplans');
    assert.equal(await page.locator('.civic-rail-open').count(), 0, 'Mobile menu did not close');
    assert.equal(documentRequests.length, 1, 'Mobile navigation caused a reload');
    await page.screenshot({ path: `${output}/cross-group-mobile.png`, fullPage: true });

    // Start a fresh document to avoid the browser's already-loaded module cache.
    await page.goto(`${base}/service-reports/submit`);
    await page.getByRole('heading', { name: 'Create New Report', exact: true }).waitFor();
    await page.route('**/*WorkplanIndexView*', route => route.abort());
    expectedImportFailure = true;
    const documentCount = documentRequests.length;
    await openGroup('Workplan');
    await page.locator('.civic-nav').getByRole('link', { name: 'Annual workplans', exact: false }).click();
    await page.getByText('Page unavailable', { exact: true }).waitFor();
    assert.equal(page.url(), `${base}/service-reports/submit`);
    assert.equal(await page.getByRole('heading', { name: 'Create New Report', exact: true }).count(), 1, 'Failed navigation discarded the current page');
    await page.waitForTimeout(1000);
    assert.equal(documentRequests.length, documentCount, 'Failed imports triggered an automatic reload');
    await page.screenshot({ path: `${output}/failed-import-mobile.png`, fullPage: true });
    await page.unroute('**/*WorkplanIndexView*');
    await page.getByRole('link', { name: 'Reload page', exact: true }).click();
    await page.waitForURL(`${base}/workplans`);
    await page.getByRole('heading', { name: 'Annual workplans', exact: true }).waitFor();
    assert.equal(await page.getByText('Page unavailable', { exact: true }).count(), 0);
    assert.equal(documentRequests.length, documentCount + 1, 'Manual recovery did not reload the intended page');
    assert.deepEqual(runtimeErrors, []);
    console.log(JSON.stringify({ result: 'passed', crossGroupNavigation: 'desktop and mobile', backgroundPolling: 'non-blocking', failedImportRecovery: 'manual', screenshots: output }));
} finally {
    pendingSummaries.splice(0).forEach(done => done());
    pendingWorkplans.splice(0).forEach(done => done());
    await browser.close();
    await new Promise(done => server.close(done));
}
