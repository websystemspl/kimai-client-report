#!/usr/bin/env node
// Drives mcp/kimai-mcp.mjs the way an MCP client does (JSON-RPC over stdio) and
// checks that what an agent gets is the same as the API's numbers.
//
// Usage: KIMAI_URL=... KIMAI_TOKEN_FILE=... node tests/verify-mcp.mjs [person name]

import { spawn } from 'node:child_process';
import { readFileSync, statSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const base = (process.env.KIMAI_URL || '').replace(/\/+$/, '');
const token = process.env.KIMAI_TOKEN || readFileSync(process.env.KIMAI_TOKEN_FILE, 'utf8').trim();
const person = process.argv[2] || null;

const server = spawn(process.execPath, [join(here, '..', 'mcp', 'kimai-mcp.mjs')], { stdio: ['pipe', 'pipe', 'inherit'], env: process.env });
let buffer = '';
const waiting = new Map();
server.stdout.on('data', (chunk) => {
    buffer += chunk;
    let i;
    while ((i = buffer.indexOf('\n')) >= 0) {
        const line = buffer.slice(0, i);
        buffer = buffer.slice(i + 1);
        const msg = JSON.parse(line);
        waiting.get(msg.id)?.(msg);
    }
});
let nextId = 1;
function rpc(method, params) {
    const id = nextId++;
    return new Promise((resolve) => {
        waiting.set(id, resolve);
        server.stdin.write(JSON.stringify({ jsonrpc: '2.0', id, method, params }) + '\n');
    });
}
const call = async (name, args = {}) => (await rpc('tools/call', { name, arguments: args })).result;

let failures = 0;
let checks = 0;
function ok(label, condition, detail = '') {
    checks++;
    console.log(`${condition ? 'ok  ' : 'FAIL'} ${label}${condition ? '' : ' ' + detail}`);
    if (!condition) failures++;
}

const init = await rpc('initialize', { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'test', version: '0' } });
ok('initialize answers with server info', init.result?.serverInfo?.name === 'kimai');
server.stdin.write(JSON.stringify({ jsonrpc: '2.0', method: 'notifications/initialized' }) + '\n');

const tools = (await rpc('tools/list')).result.tools.map((t) => t.name);
ok('lists the work report and client link tools', ['kimai_work_report', 'kimai_work_report_pdf', 'kimai_client_report_create', 'kimai_timesheets'].every((t) => tools.includes(t)), tools.join(','));

const status = await call('kimai_status');
ok('status works', !status.isError, status.content?.[0]?.text);

const direct = await (await fetch(`${base}/api/work-report?period=last_week&entries=0`, { headers: { Authorization: `Bearer ${token}` } })).json();
const team = await call('kimai_work_report', { period: 'last_week', include_entries: false });
const teamJson = JSON.parse(team.content[1].text);
ok('team totals via MCP equal the API', JSON.stringify(teamJson.totals) === JSON.stringify(direct.totals));
ok('summary quotes the total', team.content[0].text.includes(`TOTAL ${direct.totals.hm}`));

const who = person || direct.users[0]?.name;
if (who) {
    const one = await call('kimai_work_report', { period: 'last_week', users: [who] });
    ok(`report for "${who}" by name works`, !one.isError, one.content?.[0]?.text);
    if (!one.isError) {
        const r = JSON.parse(one.content[1].text);
        const expected = direct.users.find((u) => u.id === r.meta.filters.users[0].id);
        ok(`"${who}" total equals the team report row`, r.totals.seconds === (expected?.seconds ?? 0));
        ok('entries carry descriptions', Array.isArray(r.entries) && r.entries.every((e) => typeof e.description === 'string'));
    }
}

const ambiguous = await call('kimai_work_report', { period: 'last_week', users: ['a'] });
ok('ambiguous name is an error listing candidates, not a guess', ambiguous.isError && /more than one|use the id/.test(ambiguous.content[0].text), ambiguous.content[0].text.slice(0, 120));
const unknown = await call('kimai_work_report', { period: 'last_week', users: ['Zzyzx Nobody'] });
ok('unknown name is an error', unknown.isError);
const bad = await call('kimai_work_report', { period: 'this_week', begin: '2026-01-01', end: '2026-01-02' });
ok('API validation errors reach the agent', bad.isError && /either/.test(bad.content[0].text));

const dir = mkdtempSync(join(tmpdir(), 'kimai-mcp-'));
const pdf = await call('kimai_work_report_pdf', { period: 'last_week', locale: 'pl', save_to: join(dir, 'r.pdf') });
ok('PDF is saved', !pdf.isError && statSync(join(dir, 'r.pdf')).size > 1000, pdf.content?.[0]?.text);

const raw = await call('kimai_timesheets', { begin: direct.meta.begin, end: direct.meta.end });
const rawJson = JSON.parse(raw.content[0].text);
ok('raw timesheets count matches (stopped + running)', rawJson.count === direct.totals.entries + direct.totals.runningExcluded, `${rawJson.count} vs ${direct.totals.entries}+${direct.totals.runningExcluded}`);

const links = await call('kimai_client_report_list', {});
ok('client links can be listed', !links.isError);

server.kill();
console.log(`\n${checks} checks, ${failures} failed`);
process.exit(failures === 0 ? 0 : 1);
