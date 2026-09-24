# ClientReport - time reports under a public link

A plugin for Kimai 2.x. It does what Kimai core does not: **a URL where a client opens
a report of hours without logging in**, as a web page and as a PDF.

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
  selected users, activities or tags.
- **Email notification on first open** of the link by the client, sent to the person
  who created it. Later visits show up in the counter, without an email.
- **"Share with client" button on the Kimai Export screen**, carrying over the filters
  set there (date range, project or customer, billable, users, activities).
- Company logo on the page and in the PDF.

Repository: <https://github.com/websystemspl/kimai-client-report> (public, MIT).
Release with a ZIP package: `releases/tag/v1.0.0`.

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

Menu **Reporting > Raporty dla klientów** ("Client reports", visible from the teamlead
role upwards). "Nowy link" ("New link") asks for a project or customer, a date range,
a language and whether to show non-billable entries. The default range is the current
week.

The report the client sees is available in English and Polish. The admin panel of the
plugin (list, form, Export screen button) is Polish only for now; see
[Not there yet](#not-there-yet).

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
Report/Labels.php               EN/PL strings (too few for a translation catalogue)
Report/Branding.php             logo and company name
Report/ShareNotifier.php        email on first open of a link
Form/SharedReportType.php       link creation form
Controller/ShareController.php  public page and PDF
Controller/SharedReportController.php  panel: list, create, revoke
EventSubscriber/MenuSubscriber.php       menu entry
EventSubscriber/ExportPageSubscriber.php button on the Kimai Export screen
Migrations/                     tables: reports + three filter tables
Resources/assets/logo.png       logo inserted into the report
Resources/views/public/         client page and PDF template
Resources/views/admin/          list and form in the panel
```

## Not there yet

- Translated admin panel: its labels are hard-coded in Polish, only the client-facing
  report is available in English.
- Several projects in one report (it is either one project or a whole customer).
- Brand configuration from the panel: logo and company name live in plugin files.
- Rates and amounts; the report shows time only.
- A preview of the report from the panel before sending the link (you have to open
  the link).
