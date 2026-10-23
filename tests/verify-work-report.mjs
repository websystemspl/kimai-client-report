#!/usr/bin/env node
// Cross-checks /api/work-report against Kimai's own /api/timesheets.
//
// For every period and every person (plus "everyone") it recomputes the totals
// from the raw entries and compares them with the plugin's numbers: grand total,
// billable total, entry count, the set of entry ids, per person, per day and per
// project. Any difference is printed and the script exits with 1.
//
// Usage:
//   KIMAI_URL=https://kimai.example.com KIMAI_TOKEN=... node tests/verify-work-report.mjs
//   (KIMAI_TOKEN_FILE=/path/to/file works too)

import { readFileSync } from 'node:fs';

const base = (process.env.KIMAI_URL || '').replace(/\/+$/, '');
const token = process.env.KIMAI_TOKEN || (process.env.KIMAI_TOKEN_FILE ? readFileSync(process.env.KIMAI_TOKEN_FILE, 'utf8').trim() : '');
if (!base || !token) {
    console.error('Set KIMAI_URL and KIMAI_TOKEN (or KIMAI_TOKEN_FILE).');
    process.exit(2);
}

async function api(path) {
    const res = await fetch(base + path, { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
    const body = await res.text();
    if (!res.ok) {
        throw new Error(`${res.status} ${path}: ${body.slice(0, 300)}`);
    }
    return JSON.parse(body);
}

async function allTimesheets(begin, end, userParam) {
    const out = [];
    for (let page = 1; ; page++) {
        const q = new URLSearchParams({ begin: `${begin}T00:00:00`, end: `${end}T23:59:59`, size: '500', page: String(page), full: 'true', user: userParam });
        const batch = await api(`/api/timesheets?${q}`);
        out.push(...batch);
        if (batch.length < 500) {
            return out;
        }
    }
}

function localDate(iso, tz) {
    // en-CA formats as YYYY-MM-DD
    return new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(iso));
}

let failures = 0;
let checks = 0;
function expect(label, actual, expected) {
    checks++;
    if (JSON.stringify(actual) !== JSON.stringify(expected)) {
        failures++;
        console.log(`  FAIL ${label}: report=${JSON.stringify(actual)} raw=${JSON.stringify(expected)}`);
    }
}

function sumBy(list, key) {
    const map = {};
    for (const item of list) {
        const k = key(item);
        map[k] = (map[k] || 0) + item.duration;
    }
    return Object.fromEntries(Object.entries(map).sort());
}

async function verify(label, params, userParam, tz) {
    const report = await api(`/api/work-report?${new URLSearchParams(params)}`);
    const { begin, end } = report.meta;
    const raw = (await allTimesheets(begin, end, userParam)).filter((t) => t.end !== null);

    const total = raw.reduce((s, t) => s + t.duration, 0);
    const billable = raw.filter((t) => t.billable).reduce((s, t) => s + t.duration, 0);

    console.log(`${label} ${begin}..${end}: ${report.totals.hm} in ${report.totals.entries} entries (raw ${raw.length})`);

    expect(`${label} total seconds`, report.totals.seconds, total);
    expect(`${label} billable seconds`, report.totals.billableSeconds, billable);
    expect(`${label} entry count`, report.totals.entries, raw.length);
    expect(`${label} entry ids`, report.entries.map((e) => e.id).sort((a, b) => a - b), raw.map((t) => t.id).sort((a, b) => a - b));
    expect(`${label} integrity flag`, report.integrity.ok, true);

    const perUser = Object.fromEntries(report.users.map((u) => [String(u.id), u.seconds]).sort());
    expect(`${label} per person`, perUser, sumBy(raw, (t) => String(t.user.id ?? t.user)));

    const perDay = Object.fromEntries(report.days.filter((d) => d.seconds > 0).map((d) => [d.date, d.seconds]).sort());
    expect(`${label} per day`, perDay, sumBy(raw, (t) => localDate(t.begin, tz)));

    const perProject = Object.fromEntries(report.projects.map((p) => [String(p.id), p.seconds]).sort());
    expect(`${label} per project`, perProject, sumBy(raw, (t) => String(t.project.id ?? t.project)));

    for (const e of report.entries) {
        const t = raw.find((x) => x.id === e.id);
        if (t) {
            expect(`${label} entry ${e.id} duration`, e.seconds, t.duration);
            expect(`${label} entry ${e.id} description`, e.description, (t.description || '').trim());
        }
    }
}

const me = await api('/api/users/me');
const tz = me.timezone || 'UTC';
const users = (await api('/api/users?visible=3')).filter((u) => u.enabled);
const periods = (process.env.PERIODS || 'this_week,last_week,this_month,last_month').split(',');

for (const period of periods) {
    await verify(`[${period}] everyone`, { period }, 'all', tz);
    for (const u of users) {
        await verify(`[${period}] ${u.username}`, { period, users: String(u.id) }, String(u.id), tz);
    }
}
// a custom range crossing a month boundary
await verify('[custom] everyone', { begin: '2026-09-28', end: '2026-10-04' }, 'all', tz);

console.log(`\n${checks} checks, ${failures} failed`);
process.exit(failures === 0 ? 0 : 1);
