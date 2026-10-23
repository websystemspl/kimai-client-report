#!/usr/bin/env node
// MCP server (stdio) for Kimai with the ClientReport plugin.
//
// Gives an agent everything it needs to write time reports without doing any
// arithmetic itself: the work report (totals, per person, per day, per project,
// warnings, every entry with its description) comes from the plugin's
// /api/work-report, where it is calculated once and cross-checked server side.
// The agent's job is to quote those numbers, not to add them up.
//
// No dependencies: Node 18+ only. Configuration through environment variables:
//   KIMAI_URL         e.g. https://kimai.example.com
//   KIMAI_TOKEN       API token (Kimai > Profile > API), or
//   KIMAI_TOKEN_FILE  path to a file holding the token
//   KIMAI_PDF_DIR     where PDFs are saved when no path is given (default: current dir)

import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { resolve, dirname, join } from 'node:path';
import { createInterface } from 'node:readline';

const VERSION = '1.3.0';
const BASE = (process.env.KIMAI_URL || '').replace(/\/+$/, '');
const TOKEN = (process.env.KIMAI_TOKEN || (process.env.KIMAI_TOKEN_FILE ? readFileSync(process.env.KIMAI_TOKEN_FILE, 'utf8') : '')).trim();
const PDF_DIR = process.env.KIMAI_PDF_DIR || process.cwd();

// ---------------------------------------------------------------- Kimai client

class KimaiError extends Error {}

async function kimai(method, path, { query, body, raw = false } = {}) {
    if (!BASE || !TOKEN) {
        throw new KimaiError('KIMAI_URL and KIMAI_TOKEN (or KIMAI_TOKEN_FILE) must be set for the kimai MCP server.');
    }
    const url = new URL(BASE + path);
    for (const [key, value] of Object.entries(query || {})) {
        if (value === undefined || value === null || value === '') {
            continue;
        }
        if (Array.isArray(value)) {
            value.forEach((v) => url.searchParams.append(`${key}[]`, String(v)));
        } else {
            url.searchParams.set(key, String(value));
        }
    }
    const res = await fetch(url, {
        method,
        headers: {
            Authorization: `Bearer ${TOKEN}`,
            Accept: raw ? '*/*' : 'application/json',
            ...(body ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
    });
    if (raw && res.ok) {
        return { buffer: Buffer.from(await res.arrayBuffer()), headers: res.headers };
    }
    const text = await res.text();
    let data = null;
    try {
        data = text ? JSON.parse(text) : null;
    } catch {
        // not JSON, keep the text for the error message
    }
    if (!res.ok) {
        const message = data?.message || text.slice(0, 300) || res.statusText;
        throw new KimaiError(`Kimai ${method} ${path} failed with ${res.status}: ${message}`);
    }
    return data;
}

async function paged(path, query, cap = 5000) {
    const out = [];
    for (let page = 1; out.length < cap; page++) {
        const batch = await kimai('GET', path, { query: { ...query, page, size: 500 } });
        out.push(...batch);
        if (batch.length < 500) {
            return { items: out, truncated: false };
        }
    }
    return { items: out.slice(0, cap), truncated: true };
}

// ------------------------------------------------------------- name resolution

const fold = (s) => String(s ?? '').normalize('NFD').replace(/\p{M}/gu, '').toLowerCase().trim();

/**
 * Resolves a reference given by an agent (id, login, or name) to exactly one item.
 * Exact matches win over partial ones; anything ambiguous or unknown is an error
 * listing the candidates, never a guess.
 */
function pick(items, ref, label, fields) {
    const raw = String(ref).trim();
    if (/^\d+$/.test(raw)) {
        const hit = items.find((i) => String(i.id) === raw);
        if (!hit) {
            throw new KimaiError(`No ${label} with id ${raw}.`);
        }
        return hit;
    }
    const q = fold(raw);
    const exact = items.filter((i) => fields.some((f) => fold(f(i)) === q));
    if (exact.length === 1) {
        return exact[0];
    }
    const partial = exact.length > 1 ? exact : items.filter((i) => fields.some((f) => fold(f(i)).includes(q)));
    if (partial.length === 1) {
        return partial[0];
    }
    const describe = (i) => `${i.id}: ${fields.map((f) => f(i)).filter(Boolean).join(' / ')}`;
    if (partial.length === 0) {
        throw new KimaiError(`No ${label} matches "${raw}". Use one of: ${items.slice(0, 40).map(describe).join('; ')}`);
    }
    throw new KimaiError(`"${raw}" matches more than one ${label}, use the id: ${partial.map(describe).join('; ')}`);
}

const cache = {};
async function list(kind) {
    if (cache[kind]) {
        return cache[kind];
    }
    const loaders = {
        users: () => kimai('GET', '/api/users', { query: { visible: 3, full: 'true' } }),
        teams: () => kimai('GET', '/api/teams'),
        customers: () => kimai('GET', '/api/customers', { query: { visible: 3 } }),
        projects: () => kimai('GET', '/api/projects', { query: { visible: 3, ignoreDates: 1 } }),
        activities: () => kimai('GET', '/api/activities', { query: { visible: 3 } }),
        tags: () => kimai('GET', '/api/tags/find', { query: { name: '' } }).catch(() => kimai('GET', '/api/tags')),
    };
    cache[kind] = await loaders[kind]();
    return cache[kind];
}

const RESOLVERS = {
    users: ['user', [(u) => u.username, (u) => u.alias, (u) => u.title]],
    teams: ['team', [(t) => t.name]],
    customers: ['customer', [(c) => c.name, (c) => c.number]],
    projects: ['project', [(p) => p.name, (p) => p.number]],
    activities: ['activity', [(a) => a.name]],
    tags: ['tag', [(t) => t.name]],
};

async function resolveIds(kind, refs) {
    if (!refs || refs.length === 0) {
        return undefined;
    }
    const [label, fields] = RESOLVERS[kind];
    const items = await list(kind);
    return refs.map((ref) => pick(items, ref, label, fields).id);
}

// --------------------------------------------------------------- work report

async function workReportQuery(args) {
    const query = {
        period: args.period,
        begin: args.begin,
        end: args.end,
        timezone: args.timezone,
        users: await resolveIds('users', args.users),
        teams: await resolveIds('teams', args.teams),
        customers: await resolveIds('customers', args.customers),
        projects: await resolveIds('projects', args.projects),
        activities: await resolveIds('activities', args.activities),
        tags: await resolveIds('tags', args.tags),
    };
    if (args.billable === 'billable') {
        query.billable = '1';
    } else if (args.billable === 'non_billable') {
        query.billable = '0';
    }
    return query;
}

function summarize(r) {
    const f = r.meta.filters;
    const filters = [
        f.users.length ? `people: ${f.users.map((u) => u.name).join(', ')}` : 'people: everyone visible',
        f.teams.length ? `teams: ${f.teams.map((x) => x.name).join(', ')}` : '',
        f.customers.length ? `customers: ${f.customers.map((x) => x.name).join(', ')}` : '',
        f.projects.length ? `projects: ${f.projects.map((x) => x.name).join(', ')}` : '',
        f.activities.length ? `types of work: ${f.activities.map((x) => x.name).join(', ')}` : '',
        f.tags.length ? `tags: ${f.tags.map((x) => x.name).join(', ')}` : '',
        f.billable === true ? 'billable only' : f.billable === false ? 'non-billable only' : '',
    ].filter(Boolean).join('; ');

    const t = r.totals;
    const lines = [
        `WORK REPORT ${r.meta.begin} to ${r.meta.end} (${r.meta.days} days, timezone ${r.meta.timezone})`,
        `Filters: ${filters}`,
        `TOTAL ${t.hm} h:mm = ${t.hours} h | billable ${t.billableHm} | non-billable ${t.nonBillableHm} | ${t.entries} entries | ${t.users} people | ${t.daysWithTime} days with time`,
        '',
        'Per person (total / billable):',
        ...r.users.map((u) => `- ${u.name} (${u.username}): ${u.hm} / ${u.billableHm}, ${u.entries} entries`),
        '',
        'Per project:',
        ...r.projects.map((p) => `- ${p.customer ? p.customer.name + ' / ' : ''}${p.name}: ${p.hm} (billable ${p.billableHm}, ${p.share}%) - ${p.users.map((u) => `${u.name} ${u.hm}`).join(', ')}`),
        '',
        'Per day:',
        ...r.days.filter((d) => d.seconds > 0).map((d) => `- ${d.date}: ${d.hm}`),
    ];
    if (r.amounts && r.amounts.some((a) => a.rate || a.internalRate)) {
        lines.push('', 'Amounts (from Kimai rates):', ...r.amounts.map((a) => `- ${a.currency}: total ${a.rate}, billable ${a.billableRate}, internal cost ${a.internalRate}`));
    }
    const w = r.warnings;
    lines.push('', `Warnings: ${r.warningCount}` + (r.warningCount ? ` (${Object.entries(w).filter(([, v]) => v.length).map(([k, v]) => `${k} ${v.length}`).join(', ')})` : ''));
    for (const o of w.overlaps) {
        lines.push(`- overlap ${o.user.name} ${o.date}: entries ${o.entryIds.join(' & ')} overlap by ${o.hm}, both counted in full`);
    }
    for (const x of w.running) {
        lines.push(`- running ${x.user.name} since ${x.begin} on ${x.project}: NOT in totals`);
    }
    for (const x of w.missingDescription) {
        lines.push(`- no description ${x.user.name} ${x.date} ${x.hm} on ${x.project} (entry ${x.entryId})`);
    }
    for (const x of w.longEntries) {
        lines.push(`- entry over 10 h ${x.user.name} ${x.date} ${x.hm} (entry ${x.entryId})`);
    }
    for (const x of w.longDays) {
        lines.push(`- day over 12 h ${x.user.name} ${x.date} ${x.hm}`);
    }
    for (const x of w.crossMidnight) {
        lines.push(`- past midnight ${x.user.name} ${x.date} ${x.hm} (entry ${x.entryId}), counted on the start day`);
    }
    lines.push(
        '',
        `Integrity: ${r.integrity.ok ? 'OK' : 'FAILED'} - ${r.integrity.entriesCounted} entries, ${r.integrity.sumOfEntrySeconds} s, breakdowns checked: ${r.integrity.checked.join(', ')}`,
        `Rules: ${r.meta.rules.join(' ')}`,
        r.links ? `Panel: ${r.links.panel}` : '',
    );
    return lines.filter((l) => l !== undefined).join('\n');
}

// -------------------------------------------------------------------- tools

const REF_LIST = (what) => ({ type: 'array', items: { type: ['string', 'integer'] }, description: `${what}: ids, or names/logins (resolved exactly; ambiguous names are an error listing the candidates).` });
const PERIOD_PROPS = {
    period: { type: 'string', enum: ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_year', 'last_year'], description: 'Named period, resolved by Kimai in the report timezone. Do not combine with begin/end.' },
    begin: { type: 'string', description: 'First day YYYY-MM-DD (inclusive). Needs end.' },
    end: { type: 'string', description: 'Last day YYYY-MM-DD (inclusive). Needs begin. Max 366 days.' },
    timezone: { type: 'string', description: 'IANA timezone for day boundaries. Default: the token owner\'s Kimai timezone.' },
    users: REF_LIST('People'),
    teams: REF_LIST('Teams (all members)'),
    customers: REF_LIST('Customers'),
    projects: REF_LIST('Projects'),
    activities: REF_LIST('Types of work (activities)'),
    tags: REF_LIST('Tags (entries having any of them)'),
    billable: { type: 'string', enum: ['all', 'billable', 'non_billable'], description: 'Default all.' },
};

const TOOLS = [
    {
        name: 'kimai_status',
        description: 'Who the token belongs to, Kimai version, timezone and plugin version. Call first to confirm the connection.',
        inputSchema: { type: 'object', properties: {} },
        run: async () => {
            const [me, version, plugins] = await Promise.all([
                kimai('GET', '/api/users/me'),
                kimai('GET', '/api/version').catch(() => null),
                kimai('GET', '/api/plugins').catch(() => []),
            ]);
            return json({
                kimai: BASE,
                version: version?.version,
                user: { id: me.id, username: me.username, name: me.alias || me.username, timezone: me.timezone, language: me.language, roles: me.roles },
                clientReportPlugin: plugins.find?.((p) => p.name === 'ClientReportBundle' || p.name === 'ClientReport')?.version ?? 'unknown',
                mcpVersion: VERSION,
            });
        },
    },
    {
        name: 'kimai_work_report',
        description: [
            'THE tool for "how much / on what did X work" questions and for writing time reports.',
            'Returns totals, per person, per day, per project, per customer, per type of work, warnings and (by default) every entry with its description.',
            'All sums are computed and cross-checked by Kimai: QUOTE these numbers, never add up entries yourself and never round differently.',
            'Running entries are not in any total (see warnings). Overlapping entries are both counted in full - mention them when they matter.',
            'Answer = first text block (human summary); second block = full JSON.',
        ].join(' '),
        inputSchema: {
            type: 'object',
            properties: { ...PERIOD_PROPS, include_entries: { type: 'boolean', description: 'Include every entry (default true). Turn off for long team-wide periods when only totals are needed.' } },
        },
        run: async (args) => {
            const query = await workReportQuery(args);
            if (args.include_entries === false) {
                query.entries = '0';
            }
            const report = await kimai('GET', '/api/work-report', { query });
            if (!report?.integrity?.ok) {
                throw new KimaiError('The work report did not pass its integrity check, do not use these numbers.');
            }
            return [text(summarize(report)), json(report)];
        },
    },
    {
        name: 'kimai_work_report_pdf',
        description: 'Saves the work report as a PDF file (same numbers as kimai_work_report) and returns the file path. For internal use; for a client use kimai_client_report_create.',
        inputSchema: {
            type: 'object',
            properties: {
                ...PERIOD_PROPS,
                locale: { type: 'string', enum: ['pl', 'en'], description: 'PDF language. Default: the token owner\'s language.' },
                save_to: { type: 'string', description: 'File path to write. Default: KIMAI_PDF_DIR/work-report-<who>-<begin>-<end>.pdf' },
            },
        },
        run: async (args) => {
            const query = await workReportQuery(args);
            query.locale = args.locale;
            const { buffer, headers } = await kimai('GET', '/api/work-report/pdf', { query, raw: true });
            const suggested = /filename="([^"]+)"/.exec(headers.get('content-disposition') || '')?.[1] || 'work-report.pdf';
            const path = resolve(args.save_to || join(PDF_DIR, suggested));
            mkdirSync(dirname(path), { recursive: true });
            writeFileSync(path, buffer);
            return json({ saved: path, bytes: buffer.length });
        },
    },
    {
        name: 'kimai_timesheets',
        description: 'Raw Kimai timesheet entries (all pages, up to 5000) with Kimai\'s own fields. Use for details not in the work report; for totals use kimai_work_report.',
        inputSchema: {
            type: 'object',
            required: ['begin', 'end'],
            properties: {
                begin: { type: 'string', description: 'YYYY-MM-DD' },
                end: { type: 'string', description: 'YYYY-MM-DD (inclusive)' },
                users: REF_LIST('People (default: everyone visible)'),
                customers: REF_LIST('Customers'),
                projects: REF_LIST('Projects'),
                activities: REF_LIST('Activities'),
                term: { type: 'string', description: 'Search in descriptions.' },
                exported: { type: 'string', enum: ['all', 'exported', 'not_exported'] },
                billable: { type: 'string', enum: ['all', 'billable', 'non_billable'] },
            },
        },
        run: async (args) => {
            const users = await resolveIds('users', args.users);
            const query = {
                begin: `${args.begin}T00:00:00`,
                end: `${args.end}T23:59:59`,
                full: 'true',
                customers: await resolveIds('customers', args.customers),
                projects: await resolveIds('projects', args.projects),
                activities: await resolveIds('activities', args.activities),
                term: args.term,
                exported: { exported: 1, not_exported: 0 }[args.exported],
                billable: { billable: 1, non_billable: 0 }[args.billable],
            };
            if (users) {
                query.users = users;
            } else {
                query.user = 'all';
            }
            const { items, truncated } = await paged('/api/timesheets', query);
            return json({ count: items.length, truncated, entries: items });
        },
    },
    listTool('kimai_list_users', 'People in Kimai (id, login, name, enabled, teams).', 'users'),
    listTool('kimai_list_teams', 'Teams with their members.', 'teams'),
    listTool('kimai_list_customers', 'Customers (including hidden ones).', 'customers'),
    listTool('kimai_list_projects', 'Projects with their customer (including hidden ones).', 'projects'),
    listTool('kimai_list_activities', 'Types of work (activities).', 'activities'),
    {
        name: 'kimai_find',
        description: 'Resolves a name to exactly one Kimai item (user, team, customer, project, activity, tag). Errors list candidates when the name is unknown or ambiguous.',
        inputSchema: { type: 'object', required: ['kind', 'name'], properties: { kind: { type: 'string', enum: Object.keys(RESOLVERS) }, name: { type: 'string' } } },
        run: async (args) => {
            const [label, fields] = RESOLVERS[args.kind];
            return json(pick(await list(args.kind), args.name, label, fields));
        },
    },
    {
        name: 'kimai_client_report_create',
        description: [
            'Creates a public client link (web page + PDF, no login needed) for ONE customer or ONE project.',
            'Before sending it to anyone, call kimai_client_report_data and check the numbers.',
            'Mark unpaid work as non-billable in Kimai first: the report takes "billable" straight from each entry.',
        ].join(' '),
        inputSchema: {
            type: 'object',
            required: ['begin', 'end'],
            properties: {
                customer: { type: ['string', 'integer'], description: 'Customer id or name (whole customer). Exactly one of customer/project.' },
                project: { type: ['string', 'integer'], description: 'Project id or name.' },
                begin: { type: 'string', description: 'YYYY-MM-DD' },
                end: { type: 'string', description: 'YYYY-MM-DD (inclusive)' },
                locale: { type: 'string', enum: ['pl', 'en'], description: 'Language of the client page. Default en.' },
                title: { type: 'string' },
                show_non_billable: { type: 'boolean', description: 'List unpaid entries too (they are always counted in the summary). Default true.' },
                expires_at: { type: 'string', description: 'YYYY-MM-DD, link stops working after this day.' },
                users: REF_LIST('Only these people'),
                activities: REF_LIST('Only these types of work'),
                tags: REF_LIST('Only entries with these tags'),
                excluded_projects: REF_LIST('Projects to leave out of a customer-wide report'),
            },
        },
        run: async (args) => {
            const body = { begin: args.begin, end: args.end };
            if (args.customer !== undefined) {
                body.customer = (await resolveIds('customers', [args.customer]))[0];
            }
            if (args.project !== undefined) {
                body.project = (await resolveIds('projects', [args.project]))[0];
            }
            if (args.locale) body.locale = args.locale;
            if (args.title) body.title = args.title;
            if (args.show_non_billable !== undefined) body.showNonBillable = args.show_non_billable;
            if (args.expires_at) body.expiresAt = args.expires_at;
            for (const [arg, field, kind] of [['users', 'users', 'users'], ['activities', 'activities', 'activities'], ['tags', 'tags', 'tags'], ['excluded_projects', 'excludedProjects', 'projects']]) {
                const ids = await resolveIds(kind, args[arg]);
                if (ids) body[field] = ids;
            }
            return json(await kimai('POST', '/api/client-reports', { body }));
        },
    },
    {
        name: 'kimai_client_report_list',
        description: 'Client links with their state, views and URLs.',
        inputSchema: { type: 'object', properties: { state: { type: 'string', enum: ['active', 'revoked', 'expired'] }, customer: { type: ['string', 'integer'] }, project: { type: ['string', 'integer'] } } },
        run: async (args) => json(await kimai('GET', '/api/client-reports', {
            query: {
                state: args.state,
                customer: args.customer !== undefined ? (await resolveIds('customers', [args.customer]))[0] : undefined,
                project: args.project !== undefined ? (await resolveIds('projects', [args.project]))[0] : undefined,
            },
        })),
    },
    {
        name: 'kimai_client_report_data',
        description: 'The exact numbers and entries the client will see under a link (totals, billable, per person, per type of work). Use it to check a link before sending it.',
        inputSchema: { type: 'object', required: ['id'], properties: { id: { type: 'integer' } } },
        run: async (args) => json(await kimai('GET', `/api/client-reports/${Number(args.id)}/data`)),
    },
    {
        name: 'kimai_client_report_revoke',
        description: 'Revokes a client link (client sees "no longer active"). Reversible with kimai_client_report_restore.',
        inputSchema: { type: 'object', required: ['id'], properties: { id: { type: 'integer' } } },
        run: async (args) => json(await kimai('DELETE', `/api/client-reports/${Number(args.id)}`)),
    },
    {
        name: 'kimai_client_report_restore',
        description: 'Makes a revoked client link work again.',
        inputSchema: { type: 'object', required: ['id'], properties: { id: { type: 'integer' } } },
        run: async (args) => json(await kimai('POST', `/api/client-reports/${Number(args.id)}/restore`)),
    },
];

function listTool(name, description, kind) {
    return {
        name,
        description,
        inputSchema: { type: 'object', properties: {} },
        run: async () => {
            delete cache[kind];
            return json(await list(kind));
        },
    };
}

const text = (t) => ({ type: 'text', text: t });
const json = (data) => text(JSON.stringify(data, null, 1));

// ------------------------------------------------------------ JSON-RPC stdio

function send(message) {
    process.stdout.write(JSON.stringify(message) + '\n');
}

async function handle(msg) {
    const { id, method, params } = msg;
    const reply = (result) => id !== undefined && send({ jsonrpc: '2.0', id, result });
    const fail = (code, message) => id !== undefined && send({ jsonrpc: '2.0', id, error: { code, message } });

    switch (method) {
        case 'initialize':
            return reply({
                protocolVersion: params?.protocolVersion || '2025-06-18',
                capabilities: { tools: {} },
                serverInfo: { name: 'kimai', version: VERSION },
                instructions: 'Kimai time tracking. For any question about hours, use kimai_work_report and quote its totals exactly; never sum entries yourself. Mention warnings (overlaps, running timers, missing descriptions) when they affect the answer.',
            });
        case 'notifications/initialized':
        case 'notifications/cancelled':
            return undefined;
        case 'ping':
            return reply({});
        case 'tools/list':
            return reply({ tools: TOOLS.map(({ name, description, inputSchema }) => ({ name, description, inputSchema })) });
        case 'tools/call': {
            const tool = TOOLS.find((t) => t.name === params?.name);
            if (!tool) {
                return fail(-32602, `Unknown tool: ${params?.name}`);
            }
            try {
                const out = await tool.run(params.arguments || {});
                return reply({ content: Array.isArray(out) ? out : [out] });
            } catch (e) {
                return reply({ content: [text(e instanceof KimaiError ? e.message : `Unexpected error: ${e?.stack || e}`)], isError: true });
            }
        }
        default:
            return fail(-32601, `Method not found: ${method}`);
    }
}

const rl = createInterface({ input: process.stdin });
rl.on('line', (line) => {
    if (!line.trim()) {
        return;
    }
    let msg;
    try {
        msg = JSON.parse(line);
    } catch {
        send({ jsonrpc: '2.0', id: null, error: { code: -32700, message: 'Parse error' } });
        return;
    }
    handle(msg).catch((e) => send({ jsonrpc: '2.0', id: msg.id ?? null, error: { code: -32603, message: String(e) } }));
});
