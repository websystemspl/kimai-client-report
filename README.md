# ClientReport - time reports under a public link

A plugin for Kimai 2.x. It does what Kimai core does not: **a URL where a client opens
a report of hours without logging in**, as a web page and as a PDF. Since 1.3.0 also an
**internal work report** (who worked on what, per person or team, any period, with
PDF), a **JSON API** for both, and an **MCP server** for agents that write reports.

Written for `kimai.web-systems.pl`, but there is nothing in it tied to that one
instance.

[Polska wersja README](README.pl.md)

## What it gives you

- **Public link with a token**: `https://<kimai>/share/<32 hex chars>`. The client has
  no Kimai account and does not need one.
- **PDF under the same link**: `/share/<token>/pdf`, same content, a layout ready to send.
- **Billable and non-billable on one sheet.** The summary always counts both, so the
  client sees "65:43 (75.41%) of 87:09" instead of a shorter list with no explanation of
  what is missing. Non-billable rows get a "no charge" badge. They can be hidden from the
  table; the summary stays complete anyway.
- **Revocation and expiry date.** A revoked or expired link returns 404, exactly like a
  typo in the token. From the outside one cannot be told from the other, so there is no
  way to probe the address space.
- **View counter** with the date of the last visit.
- **Two report languages** (English, Polish), chosen when the link is created. A foreign
  client gets English regardless of the settings of the account that created the link.
- Scope: one project or a whole customer, any date range, optionally narrowed to
  selected users, activities or tags. A customer-wide report can leave out chosen
  projects, e.g. a fixed-price project invoiced separately.
- **Email notification on first open** of the link by the client, sent to the person
  who created it. Later visits show up in the counter, without an email.
- **"Share with the client" button on the Kimai Export screen**, carrying over the filters
  set there (date range, project or customer, billable, users, activities).
- Company logo on the page and in the PDF.

Repository: <https://github.com/websystemspl/kimai-client-report> (public, MIT).
Releases with a ZIP package: `releases`. The `mcp/` and `tests/` directories are not
needed inside Kimai and can be left out of `var/plugins/`.

## How it differs from Customer Portal

[Customer Portal](https://www.kimai.org/store/customer-portal.html) gives a customer
a standing view of a project or a whole customer: browse month by month, optional
password, rates, budgets and charts. ClientReport is built for a different moment:
**sending one fixed report**, usually together with an invoice.

- The link is a snapshot of a chosen date range and filters (users, activities, tags),
  not a live view the client can browse.
- The same link gives a PDF, so the report can be attached to an invoice or archived.
- Billable and non-billable time are shown side by side with a complete summary.
- A link can expire and be revoked, and an invalid link is indistinguishable from a
  wrong token.
- The creator gets an email when the client opens the report for the first time, and
  sees a view counter.
- The report language is set per link, not taken from the creator's account.
- A report is created straight from the Export screen, with the filters already set.

## Work report (internal, since 1.3.0)

Menu **Reporting > Work report** (*Raportowanie > Raport pracy*). Who worked on what
and for how long, for one person, several people, a team or everyone, over a day, a
week, a month or any range up to 366 days:

- period shortcuts (today, yesterday, this/last week, month, year) and free dates,
- filters: people, teams, customers, projects, types of work, tags, billable,
- totals (all, billable, non-billable, entries, days with time),
- a people-by-days grid (for ranges up to 31 days),
- hours per project (with who worked on it) and per type of work,
- **warnings**: overlapping entries of one person, running timers, entries without a
  description, entries over 10 h, days over 12 h, entries past midnight,
- every entry, grouped by person and day, with its description,
- **Download PDF** with the same content (landscape when the grid is wider than a week).

Who sees what: everyone with access to reporting can open it. People without the
permission to see other users' times get their own time only; a teamlead sees the
teams they lead; admins see everyone.

Rules behind the numbers, the same in the panel, the PDF and the API:

- durations are whole seconds; `h:mm` is cut to full minutes,
- an entry counts on the day it begins, in the report timezone (the viewer's by default),
- running entries are listed as a warning and are **not** in any total,
- every breakdown (people, days, projects, customers, types of work, entries) is summed
  again and compared with the grand total before the report is shown. A difference of
  one second refuses the report instead of showing wrong numbers.

## API

All routes sit under Kimai's `/api` and use its authentication:
`Authorization: Bearer <API token>` (Kimai > Profile > API).

### Work report

```
GET /api/work-report?period=this_week&users=j.doe
GET /api/work-report?begin=2026-10-01&end=2026-10-31&teams=1&billable=1&entries=0
GET /api/work-report/pdf?period=last_month&locale=pl
```

| Parameter | Meaning |
|---|---|
| `period` | `today`, `yesterday`, `this_week`, `last_week`, `this_month`, `last_month`, `this_year`, `last_year` |
| `begin`, `end` | `YYYY-MM-DD`, both inclusive, instead of `period` (not together) |
| `users` | ids or logins, `users[]=1&users[]=2` or `users=1,2` |
| `teams`, `customers`, `projects`, `activities` | ids |
| `tags` | ids or names (entries with any of them) |
| `billable` | `1` billable only, `0` non-billable only, empty = all |
| `entries` | `0` leaves the entry list out (totals only) |
| `timezone` | IANA name, default: the token owner's timezone |
| `locale` | PDF only: `pl` or `en` |

The parser is strict: an unknown id, an impossible date (`2026-02-31`), `end` before
`begin`, a range over 366 days, or a user the caller may not see is an error
(`400`/`403`/`404`, body `{"code": ..., "message": ...}`), never a silently smaller
report.

The answer is the exact array the panel renders: `meta` (period, timezone, filters,
rules), `totals`, `days`, `users` (with per-day and per-project seconds), `projects`
(with per-person seconds), `customers`, `activities`, `tags`, `warnings`,
`warningCount`, `integrity`, `entries`, `links` (panel and PDF URL) and, for whoever
may see rates, `amounts` plus rate fields on every entry.

### Client links

Teamlead and up, like the panel.

```
GET    /api/client-reports?state=active&customer=3      list (state: active, revoked, expired)
POST   /api/client-reports                               create, returns url and pdfUrl
GET    /api/client-reports/{id}                          one link with views
GET    /api/client-reports/{id}/data                     the numbers the client will see
DELETE /api/client-reports/{id}                          revoke (row stays)
DELETE /api/client-reports/{id}?purge=1                  delete for good
POST   /api/client-reports/{id}/restore                  undo revoke
```

```
curl -X POST https://kimai.example.com/api/client-reports \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"project": 12, "begin": "2026-10-19", "end": "2026-10-25", "locale": "pl",
       "showNonBillable": true, "title": "Week 43"}'
```

Body fields: `customer` or `project` (exactly one), `begin`, `end`, `locale` (`en`/`pl`),
`showNonBillable`, `title`, `expiresAt`, `users[]`, `activities[]`, `tags[]`,
`excludedProjects[]`. Unknown fields are rejected, so a typo does not create a report
with a filter missing. Panel and API create links through the same
`SharedReportManager`.

## MCP server for agents

`mcp/kimai-mcp.mjs` is an MCP server (stdio, Node 18+, no dependencies) on top of the
API above and Kimai's own API. It is meant for an agent that writes reports for you or
for clients, so it is built to keep the agent from doing arithmetic: totals come
finished from `/api/work-report`, and the first block of every answer is a plain-text
summary to quote.

Tools: `kimai_status`, `kimai_work_report`, `kimai_work_report_pdf`, `kimai_timesheets`
(raw entries, all pages), `kimai_list_users`, `kimai_list_teams`,
`kimai_list_customers`, `kimai_list_projects`, `kimai_list_activities`, `kimai_find`,
`kimai_client_report_create`, `kimai_client_report_list`, `kimai_client_report_data`,
`kimai_client_report_revoke`, `kimai_client_report_restore`.

People, customers, projects and the rest can be given by id or by name. A name has to
match exactly one item (exact match first, then a unique partial one, ignoring case and
accents); otherwise the tool fails and lists the candidates instead of guessing.

```
claude mcp add -s user kimai \
  -e KIMAI_URL=https://kimai.example.com \
  -e KIMAI_TOKEN_FILE=$HOME/.config/kimai-mcp/token \
  -e KIMAI_PDF_DIR=$HOME/Downloads \
  -- node /path/to/kimai-client-report/mcp/kimai-mcp.mjs
```

`KIMAI_TOKEN` works instead of `KIMAI_TOKEN_FILE`; the file keeps the token out of the
client configuration. The agent sees exactly what the token owner may see.

## Tests

Live checks against a Kimai instance (they read data; the contract test creates one
client link and deletes it again):

```
export KIMAI_URL=https://kimai.example.com KIMAI_TOKEN_FILE=~/.config/kimai-mcp/token
node tests/verify-work-report.mjs     # report vs. raw /api/timesheets, every person and period
KIMAI_USER_TOKEN=<plain user token> node tests/verify-api-contract.mjs   # validation, permissions, client links, PDF
node tests/verify-mcp.mjs "Name"      # MCP server end to end
```

`verify-work-report` recomputes every total, per person, per day, per project, each
entry's duration and description from Kimai's raw entries and fails on any difference.

## Installation

Unpack `ClientReportBundle.zip` from the release into `var/plugins/`, or from a working
copy:

```
cp -r kimai-client-report <kimai>/var/plugins/ClientReportBundle
cd <kimai>
bin/console cache:clear --env=prod
bin/console doctrine:migrations:migrate --no-interaction
```

The directory **must** be named `ClientReportBundle`: the Kernel looks for `*Bundle`
directories and builds the class name from that.

`var/plugins/` is outside the Kimai repository, so the plugin survives
`git checkout <tag>`. After a Kimai update, `cache:clear` is enough.

## Usage

Menu **Reporting > Client reports** (Polish: *Raportowanie > Raporty dla klientów*),
visible from the teamlead role upwards. **New link** (*Nowy link*) asks for a project
or customer, a date range, a language and whether to show non-billable entries. The
default range is the current week.

The admin panel follows the language of the logged-in user (English and Polish
translations ship with the plugin, other languages fall back to English). The language
of the report the client sees is set per link.

The billable / non-billable split comes straight from the "Billable" field on the Kimai
entry; the report does not calculate anything on its own. So it has to be set
**before** the link is sent.

## Branding

The logo lives in `Resources/assets/logo.png`, is loaded by `Report/Branding.php` and
inserted as a data URI: the page has to work for someone who is not logged in, and mPDF
draws the PDF from a self-contained HTML string, so a route serving the file would not
help here.

The version from web-systems.pl is white (for the dark background of the company site),
while the report has a light background, so `logo.png` holds the same graphic recoloured
to the report text colour (`#1f2430`; the logo is single-colour, so swapping RGB while
keeping the alpha channel was enough). The original stays next to it as
`logo-white-original.png`.

To change the brand: replace `Resources/assets/logo.png` and the `COMPANY` and
`LOGO_FILE` constants in `Report/Branding.php`.

This logo applies only to the report. The Kimai panel logo is separate and lives in the
system configuration under the `theme.branding.logo` key (System > Settings >
Branding), as a file URL.

## How it works

- **Why the link needs no login.** In `config/packages/security.yaml` the rules cover
  `^/{_locale}/` and `^/api`. The `/share/...` routes are outside both, so there is no
  need to touch the Kimai configuration (which would not survive an update anyway).
- **Why the query has no current user.** `TimesheetRepository` skips team filtering
  when there is no logged-in user ("make sure that all queries without a user see all
  projects"). That is correct here: the person creating the link decided what the
  client sees.
- **The form uses Kimai types** (`ProjectType`, `CustomerType`, `DatePickerType`). The
  Kimai theme attaches its own picker to every date field, and it expects a localised
  text widget; a plain `DateType` renders `<input type="date">` and the picker then
  clears the initial value.
- **`ProjectType` gets `ignore_date: true`**, otherwise projects without a date range
  do not appear in the list.
- **The plugin migration** registers itself: the DI extension adds its directory to
  `doctrine_migrations.migrations_paths`.
- **The PDF** is produced by `App\Pdf\HtmlToPdfConverter` (mPDF) from a separate
  template. mPDF knows neither flexbox nor grid, so the layout there is built on tables.
- **Times are rendered in the entry's timezone** (`|date('H:i', false)`), not in the
  process default. For an anonymous visitor that default is UTC, so without this the
  client would see everything two hours early, even though the page looked right to a
  logged-in user. Every Kimai entry carries its own timezone, so `false` is the right
  answer here.
- **The Export screen button** hooks into the generic theme event
  `ThemeEvent::CONTENT_START`, because the export controller does not set `actionName`,
  so `PageActionsEvent` is never fired for that page. Overriding the template was ruled
  out: the file belongs to the Kimai repository and the patch would be lost on update.
- **Dates from the filter bar** are parsed with the request locale's format, with a list
  of fallback formats; each candidate has to match after formatting it back, so a format
  that parses something only by accident is rejected.

## Structure

```
ClientReportBundle.php          plugin class (getName/getPath are final in Bundle)
DependencyInjection/            service loading + migration directory registration
Entity/SharedReport.php         shared report: token, scope, expiry, counter
Repository/                     database access
Report/ReportBuilder.php        fetching entries and computing totals
Report/ReportData.php           view model
Report/Labels.php               EN/PL strings of the client report, picked per link
Report/Branding.php             logo and company name
Report/ShareNotifier.php        email on first open of a link
Form/SharedReportType.php       link creation form
Controller/ShareController.php  public page and PDF
Controller/SharedReportController.php  panel: list, create, revoke
Controller/WorkReportController.php    panel: work report page and its PDF
Controller/Api/                 /api/work-report and /api/client-reports
Report/SharedReportManager.php  one creation path for panel and API
WorkReport/WorkReportRequestParser.php  strict query string parser (panel, PDF, API)
WorkReport/WorkReportBuilder.php        totals, breakdowns, warnings, integrity check
WorkReport/WorkReportPdf.php            PDF rendering shared by panel and API
Resources/views/work/           work report page and PDF template
mcp/kimai-mcp.mjs               MCP server for agents (not deployed into Kimai)
tests/                          live verification scripts (not deployed into Kimai)
EventSubscriber/MenuSubscriber.php       menu entry
EventSubscriber/ExportPageSubscriber.php button on the Kimai Export screen
Migrations/                     tables: reports + filter tables
Resources/assets/logo.png       logo inserted into the report
Resources/views/public/         client page and PDF template
Resources/views/admin/          list and form in the panel
Resources/translations/         admin panel strings (client_report domain, EN/PL)
```

## Not there yet

- Several projects in one report (it is either one project or a whole customer).
- Brand configuration from the panel: logo and company name live in plugin files.
- Rates and amounts in the client report, which shows time only (the work report API
  returns amounts when rates are set in Kimai).
- A preview of the report from the panel before sending the link (you have to open
  the link).
