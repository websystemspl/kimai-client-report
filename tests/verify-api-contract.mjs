#!/usr/bin/env node
// Contract checks for the plugin's API: input validation, permissions, the client
// link lifecycle and the PDF endpoints. Creates one client link and deletes it
// again at the end.
//
// Usage:
//   KIMAI_URL=... KIMAI_TOKEN=... [KIMAI_USER_TOKEN=<token of a plain ROLE_USER>] \
//     node tests/verify-api-contract.mjs

import { readFileSync } from 'node:fs';

const base = (process.env.KIMAI_URL || '').replace(/\/+$/, '');
const token = process.env.KIMAI_TOKEN || (process.env.KIMAI_TOKEN_FILE ? readFileSync(process.env.KIMAI_TOKEN_FILE, 'utf8').trim() : '');
const userToken = process.env.KIMAI_USER_TOKEN || '';
if (!base || !token) {
    console.error('Set KIMAI_URL and KIMAI_TOKEN (or KIMAI_TOKEN_FILE).');
    process.exit(2);
}

async function call(method, path, { body, bearer = token } = {}) {
    const res = await fetch(base + path, {
        method,
        headers: { Authorization: `Bearer ${bearer}`, Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}) },
        body: body ? JSON.stringify(body) : undefined,
    });
    const type = res.headers.get('content-type') || '';
    const raw = Buffer.from(await res.arrayBuffer());
    let json = null;
    if (type.includes('json') && raw.length > 0) {
        json = JSON.parse(raw.toString('utf8'));
    }
    return { status: res.status, type, json, raw };
}

let failures = 0;
let checks = 0;
function ok(label, condition, detail = '') {
    checks++;
    console.log(`${condition ? 'ok  ' : 'FAIL'} ${label}${condition ? '' : ' ' + detail}`);
    if (!condition) {
        failures++;
    }
}

// ---- input validation ----
const bad = [
    ['period and dates together', '/api/work-report?period=this_week&begin=2026-10-01&end=2026-10-02', 400],
    ['unknown period', '/api/work-report?period=fortnight', 400],
    ['only begin', '/api/work-report?begin=2026-10-01', 400],
    ['impossible date', '/api/work-report?begin=2026-02-31&end=2026-03-01', 400],
    ['end before begin', '/api/work-report?begin=2026-10-10&end=2026-10-01', 400],
    ['range over 366 days', '/api/work-report?begin=2025-01-01&end=2026-06-30', 400],
    ['unknown user', '/api/work-report?users=nobody.here', 404],
    ['unknown project', '/api/work-report?projects=999999', 404],
    ['non numeric customer', '/api/work-report?customers=abc', 400],
    ['bad billable', '/api/work-report?billable=maybe', 400],
    ['bad timezone', '/api/work-report?timezone=Mars/Olympus', 400],
];
for (const [label, path, status] of bad) {
    const r = await call('GET', path);
    ok(`rejects ${label} with ${status}`, r.status === status && typeof r.json?.message === 'string', `got ${r.status} ${JSON.stringify(r.json)}`);
}

// ---- same answer for period and explicit dates ----
const byPeriod = await call('GET', '/api/work-report?period=last_week&entries=0');
const byDates = await call('GET', `/api/work-report?begin=${byPeriod.json.meta.begin}&end=${byPeriod.json.meta.end}&entries=0`);
ok('period and its explicit dates give the same totals', JSON.stringify(byPeriod.json.totals) === JSON.stringify(byDates.json.totals));
ok('entries=0 leaves the entry list out', byPeriod.json.entries === undefined);
const someone = byPeriod.json.users[0];
ok('users given by login and by id are the same report',
    JSON.stringify((await call('GET', `/api/work-report?period=last_week&users=${encodeURIComponent(someone.username)}&entries=0`)).json.totals)
    === JSON.stringify((await call('GET', `/api/work-report?period=last_week&users=${someone.id}&entries=0`)).json.totals));

// ---- billable split ----
const all = byPeriod.json.totals;
const paid = (await call('GET', '/api/work-report?period=last_week&billable=1&entries=0')).json.totals;
const unpaid = (await call('GET', '/api/work-report?period=last_week&billable=0&entries=0')).json.totals;
ok('billable=1 plus billable=0 equals everything', paid.seconds + unpaid.seconds === all.seconds, `${paid.seconds}+${unpaid.seconds}!=${all.seconds}`);
ok('billable=1 has no non-billable time', paid.nonBillableSeconds === 0);

// ---- PDF ----
const pdf = await call('GET', '/api/work-report/pdf?period=this_week');
ok('API PDF is a PDF', pdf.status === 200 && pdf.type.includes('pdf') && pdf.raw.subarray(0, 5).toString() === '%PDF-', `${pdf.status} ${pdf.type}`);
const pdfMonth = await call('GET', '/api/work-report/pdf?period=last_month');
ok('month PDF renders (landscape grid)', pdfMonth.status === 200 && pdfMonth.raw.subarray(0, 5).toString() === '%PDF-');

// ---- plain user permissions ----
if (userToken) {
    const own = await call('GET', '/api/work-report?period=this_week', { bearer: userToken });
    ok('plain user gets a report', own.status === 200, `got ${own.status}`);
    ok('plain user report covers only themselves', own.json?.users?.length <= 1 && own.json?.meta?.filters?.users?.length === 1);
    const other = await call('GET', '/api/work-report?period=this_week&users=admin', { bearer: userToken });
    ok('plain user cannot ask for someone else (403)', other.status === 403, `got ${other.status}`);
    const links = await call('GET', '/api/client-reports', { bearer: userToken });
    ok('plain user cannot list client links (403)', links.status === 403, `got ${links.status}`);
} else {
    console.log('skip plain user checks (no KIMAI_USER_TOKEN)');
}

// ---- client link lifecycle ----
const project = byPeriod.json.projects.find((p) => p.id !== null && p.billableSeconds > 0);
const badCreate = await call('POST', '/api/client-reports', { body: { begin: '2026-10-01', end: '2026-10-07' } });
ok('create without customer or project is 400', badCreate.status === 400, `got ${badCreate.status}`);
const unknownField = await call('POST', '/api/client-reports', { body: { project: project.id, begin: '2026-10-01', end: '2026-10-07', colour: 'red' } });
ok('create with an unknown field is 400', unknownField.status === 400, `got ${unknownField.status}`);

const created = await call('POST', '/api/client-reports', {
    body: { project: project.id, begin: byPeriod.json.meta.begin, end: byPeriod.json.meta.end, locale: 'pl', title: 'API test - delete me', showNonBillable: true },
});
ok('create returns 201 with url and pdfUrl', created.status === 201 && /\/share\/[0-9a-f]{32}$/.test(created.json?.url || '') && created.json?.pdfUrl?.endsWith('/pdf'), `got ${created.status} ${JSON.stringify(created.json)}`);

if (created.status === 201) {
    const id = created.json.id;
    const data = await call('GET', `/api/client-reports/${id}/data`);
    ok('client link data matches the work report for that project',
        data.json?.totals?.seconds === project.seconds && data.json?.totals?.billableSeconds === project.billableSeconds,
        `${data.json?.totals?.seconds}/${data.json?.totals?.billableSeconds} vs ${project.seconds}/${project.billableSeconds}`);

    const listed = await call('GET', `/api/client-reports?project=${project.id}&state=active`);
    ok('new link is listed as active', listed.json?.some((r) => r.id === id));

    // the PDF, not the page: opening the page counts a view and mails the creator
    const publicPdf = await fetch(created.json.pdfUrl);
    ok('public PDF opens without login', publicPdf.status === 200 && (publicPdf.headers.get('content-type') || '').includes('pdf'));

    const revoked = await call('DELETE', `/api/client-reports/${id}`);
    ok('DELETE revokes', revoked.status === 200 && revoked.json?.state === 'revoked');
    ok('revoked link is gone for the client (404)', (await fetch(created.json.pdfUrl)).status === 404);

    const restored = await call('POST', `/api/client-reports/${id}/restore`);
    ok('restore makes it active again', restored.json?.state === 'active');

    const purged = await call('DELETE', `/api/client-reports/${id}?purge=1`);
    ok('purge deletes it', purged.status === 204);
    ok('purged link is 404 in the API', (await call('GET', `/api/client-reports/${id}`)).status === 404);
}

console.log(`\n${checks} checks, ${failures} failed`);
process.exit(failures === 0 ? 0 : 1);
