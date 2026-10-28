# SAGIPBRO

Modern Bootstrap 5 interface for the **SAGIPBRO Disaster Relief Resource Information System** of Bonuan Binloc, Dagupan City.

## Included screens

- Public website: Home, About, Services, Resources, Contact, and Administrator Login
- Administrator-only login and protected administration dashboard
- Admin operations: Resources, Evacuation Centers, Distributions, Residents, Volunteers, Announcements, Reports, Users, Activity Logs, and Profile
- Reusable PHP components: header, public/admin navigation, sidebar, alerts, and footer
- Responsive tables, search and filters, status badges, accessible forms, confirmation flows, modals, print styles, and CSV table export

## Administrator access

Only users with role `admin` and status `Active` can sign in. Protected pages and
APIs validate the current account and its unrevoked `user_sessions` record on
each request. Existing non-admin sessions are rejected. Account deactivation or
role changes revoke sessions; reactivation or promotion requires a fresh login.
Sessions without a matching database record also require a fresh login.

Public registration is disabled at both `register.php` and
`actions/auth/register.php`. Administrators manage accounts through the protected
Users screen. Existing non-admin accounts and records are preserved.

Run `php tests/admin_auth_http.php` for login, authorization, session revocation,
registration, and CSRF checks using a private temporary database and sessions.
The test requires PDO MySQL, `proc_open`, and CREATE/DROP DATABASE permission;
it never selects the application's database.

New passwords use the same server policy for CLI administrator setup, account
creation, administrator resets, and profile changes: at least 8 Unicode
characters, spaces allowed, and at most 72 UTF-8 bytes to prevent bcrypt from
silently truncating the password. Common patterns and known breached passwords
are rejected. Existing passwords remain usable until changed; there is no
mandatory periodic rotation.

Breach screening uses the free Pwned Passwords range API. Only a five-character
SHA-1 hash prefix is sent, with response padding; plaintext passwords, full
hashes, usernames, and emails are never sent. PHP cURL, a working CA certificate
bundle, and outbound HTTPS access to `api.pwnedpasswords.com` are required.
New password writes return a retry message when screening is unavailable;
normal logins do not depend on this service. Stored credentials still use
`password_hash()` and `password_verify()`.

Run `php tests/password_policy.php` for deterministic policy tests. Set
`SAGIPBRO_PASSWORD_LIVE_TEST=1` when running that test or `tests/admin_auth_http.php`
to include the real range API, using only synthetic test credentials.

Login failure limits are stored in `login_rate_limits`: 5 failed attempts against
one account or 30 failed attempts from one IP within 15 minutes start a 15-minute
cooldown. Unknown usernames follow the same policy. Cookies do not affect the
counters, and blocked retries do not extend the cooldown. Successful login clears
only that account's failures, not the IP counter. Both counters are locked in an
InnoDB transaction through password verification to prevent concurrent bypasses.
Only the connection's `REMOTE_ADDR` is trusted; forwarding headers are ignored.

Before using this version with an existing database, run the additive migration
with the same `SAGIPBRO_DB_*` environment settings as your PHP server:

```powershell
php database/migrate_login_throttle.php
```

Fresh installs include the table in `database/sagipbro.sql`, and the admin CRUD
migration also creates it. The application does not create tables during login;
missing or unavailable throttle storage safely rejects login with a retry message.
Run `php tests/login_throttle.php` for the isolated concurrency and cooldown tests,
and `php tests/admin_auth_http.php` for actual login requests with fresh cookies.

Sessions expire after 20 minutes without user activity or eight hours after
sign-in. Foreground page navigation and CSRF-protected activity requests renew
only the inactivity deadline. Notification, presence, register, and session-status
polling never renew it. A warning appears two minutes before expiry with a
Stay signed in button; the eight-hour cap always requires a new sign-in.

Password resets atomically revoke all existing sessions and require replacement
of the temporary password. Restricted sessions can use only the password-change
form, its password submission, sign-out, and supporting session-status/activity
requests. Protected APIs return `password_change_required` until replacement.
Password changes also revoke all sessions and sign the current browser out.
Choosing the same temporary password again is rejected. Password changes and
session revocations roll back together if the audit or database write fails.

For existing databases, apply the additive session migration using the same
database environment settings as the server:

```powershell
php database/migrate_session_security.php
```

It preserves existing session creation/activity history. Fresh schemas and the
admin CRUD migration include this field. The HTTP authentication tests cover
idle/absolute expiry, polling, explicit renewal, and mandatory password changes;
the optional live-password checks also cover full reset and replacement flows.

## Run locally

The application does not have a fixed web port. From the `Sagipbro-newnew`
project directory, start it on any available port:

```powershell
php -S localhost:3000 -t .
```

Then open `http://localhost:3000`. To use another port, stop the current server
with `Ctrl+C` and change only the port number, for example:

```powershell
php -S localhost:5000 -t .
```

Then open `http://localhost:5000`. Links, redirects, assets, and API requests
use the current browser origin, so no PHP configuration needs to change when
the port changes. If `php` is not on PATH, use XAMPP's executable instead:

```powershell
& 'C:\xampp\php\php.exe' -S localhost:3000 -t .
```

Run these commands inside `Sagipbro-newnew`; otherwise PHP will serve the wrong
folder. Only one process can use a given port at a time.

Public Services and the homepage availability snapshot now read MySQL. They show a clear unavailable message (HTTP 503) if the database cannot be reached; they never fall back to sample quantities. The static About, Services landing, and Contact pages remain accessible without MySQL.

Bootstrap and Bootstrap Icons are loaded from jsDelivr, so an internet connection is required for those vendor assets unless they are downloaded locally for deployment.

## Functional public Services

All directories work without login or JavaScript. Search and filters use GET forms with shareable URLs.

- `evacuation-centers.php`: actual centers, location, capacity, occupants, available spaces, Available / Full / Closed status; available centers first.
- `resources.php`: actual supply quantities, database categories, Available / Low Stock / Out of Stock; search, category and status filters.
- `distributions.php`: published upcoming/active schedules and anonymous completed distribution summaries, locations, date/time, planned and distributed supplies.
- `announcements.php`: published, non-future, unexpired announcements only; urgent first, searchable, filterable by priority.
- `reports.php`: public supply and evacuation availability, recent completed distributions, print and CSV export. Exports never include residents, recipients, staff names or private notes.

Public directories use database-level pagination fixed at 6 records per page, without a records-per-page selector. Search and filters apply before counting and paging. Reports paginate their three tables independently; print and CSV include all matching records. Admin pagination is fixed at 10 records per page. Run `php tests/public_pagination_http.php` for isolated HTTP regression checks.

The Services landing page uses a compact Disaster Relief Services hero and five service cards beside the announcement panel in an approximately 70/30 desktop layout. The panel stays in normal page flow at every screen size and does not follow scrolling or stretch to the Reports card. In two-column layouts its height is limited to 30rem (or the available viewport height, if smaller); long notices scroll internally while the header and View All link remain inside the card. On mobile it takes its natural height above the service cards. It displays up to five current publications, urgent first, with an urgent count across all current publications. Selecting a notice shows its full text in the panel; Back restores the list and reading position. Native expandable details remain available without JavaScript. The five service tabs include Announcements. Its service card, the panel, and the footer open the existing searchable, filterable, paginated `announcements.php` page, which displays full notice text, priority, publication time, and optional expiry.

The homepage keeps the short urgent advisory and preparedness reminder without the large Latest Announcements card section. Both the hero's View announcements action and the small floating announcement card open `announcements.php#announcement-directory`, showing the published announcement list with search, priority filters, and pagination. The floating card displays the newest current title. Its minus button minimizes it across the public website, leaving a small green announcement button on the right edge to restore it. The preference is saved in browser local storage and stays in sync across page visits, refreshes, and tabs. Read Guidance still opens the latest active urgent notice when available. Hidden, expired, scheduled, archived, and missing notices stay unavailable publicly. Homepage Quick Information cards, navigation, footer, and readiness calculations are preserved.

Announcement priorities support Urgent (red), High (orange), Medium (yellow), and Low (green), while retaining existing Normal records. Multiple announcements may be Urgent simultaneously. The admin create/edit forms support priority and optional expiry; saves verify that the selected priority was retained, rolling back with a clear error if an outdated database silently changes it. On existing installations run `php database/migrate_public_services.php` to extend the priority enum without replacing records. Published notices must have reached their publication time and must not have expired. Archiving or deleting a notice removes it from the public panel on refresh.

Records are queried again on every request. The directories omit introductory headings and successful database-retrieval notices; database error messages and record timestamps remain available. Times are Philippine time (UTC+8). Planned quantities are not presented as guaranteed remaining stock. Historical date-only distributions explicitly say that the time was not recorded.

### Existing XAMPP setup

This workspace uses the existing **`sagipbro`** database selected by the user. Its alternate column names are supported without renaming or replacing existing tables. The public migration has been applied; it is safe to run again:

```powershell
& 'C:\xampp\php\php.exe' database/migrate_public_services.php
& 'C:\xampp\php\php.exe' -S localhost:3000 -t .
```

Open `http://localhost:3000/services.php`. You may replace `3000` with any
available port, such as `5000`. Start MySQL in XAMPP first. Use XAMPP's PHP: the
separate PHP on this machine's PATH currently has no `pdo_mysql` extension.
Keep the server terminal open; `Ctrl+C` stops it.

Connection settings are environment variables read by `config/connection.php`: `SAGIPBRO_DB_HOST` (127.0.0.1), `SAGIPBRO_DB_PORT` (3306), `SAGIPBRO_DB_NAME` (sagipbro), `SAGIPBRO_DB_USER` (root), `SAGIPBRO_DB_PASSWORD` (empty). Use a restricted account in deployment; never publish database passwords or expose the PHP development server to the internet.

For a **new installation**, import `database/sagipbro.sql` into MySQL, set `$env:SAGIPBRO_DB_NAME = 'sagipbro_db'`, and run the server. The full schema is for a fresh install only, not for re-importing over existing tables. For an existing install, run the additive migration instead; it creates no demo records.

### Maintaining public records

The public directories are read-only. Authorized staff can maintain publications through the existing admin Announcements create/edit/archive forms, including priority and expiry. The administrative APIs target the original schema; installations using alternate legacy columns need the admin compatibility migration noted below or authorized database tooling.

- In the existing `sagipbro` schema, supplies use `resources.resource_name`, `quantity`, `minimum_stock`, `category`, `unit`; centers use `center_name`, `location`, `current_occupants`, `capacity`, `status`. The original repository equivalents are also supported. Closed/under-maintenance/full centers report zero available spaces.
- Announcements use `content` (or original `body`), `status=published`, `published_at` at or before the current time, `priority=Urgent`, `High`, `Medium`, `Low`, or legacy `Normal`, and optional `expires_at`. Draft, archived, future-dated, expired, and undated publications stay hidden.
- Create public schedules in `distribution_events`: `title`, `location`, `details` (public text only), `starts_at`, optional `ends_at`, `status` (Upcoming/Active/Completed/Cancelled), `publication_status` (Draft/Published). Only Published events appear. Staff explicitly controls active status; an elapsed end time removes stale active/upcoming notices by displaying Completed.
- Link planned supplies in `distribution_event_resources` (`event_id`, `resource_id`, `planned_quantity`). Link actual recipient transactions through `distributions.event_id`; public totals are calculated from transaction quantities. No transaction recipients or staff identifiers are returned to public pages. Private transaction `notes` are never public.
- Existing unlinked transactions appear as completed summaries grouped by day and location, with resource totals. Missing locations/times are explicitly labeled rather than invented.
- The database is dedicated to Barangay Bonuan Binloc; directory queries do not treat unrelated barangay data as Binloc data. Use separate databases if serving multiple barangays.

### Verification

```powershell
& 'C:\xampp\php\php.exe' tests/public_services_integration.php
```

Tests create and remove a uniquely named `sagipbro_test_*` database; they never select or overwrite your application database. Run on a healthy local test MySQL server with CREATE/DROP DATABASE permissions. Set `SAGIPBRO_DB_PORT` to use an isolated test server. The suite covers both schemas, repeat migrations, empty states, status boundaries, literal searches, SQL injection input, publication rules, anonymous totals, and report counts.

Run `php tests/public_announcements_panel_http.php` for isolated announcement-panel and admin publishing checks. Set `$env:SAGIPBRO_PANEL_BROWSER = '1'` to include Chrome checks for compact non-sticky service layout, in-panel details, Back, and desktop/tablet/mobile responsiveness.

Run `php tests/floating_announcement_http.php` for isolated floating-notice visibility, directory navigation, homepage section removal, minimize/restore controls, and database failure checks. Set `$env:SAGIPBRO_FLOATING_BROWSER = '1'` to include desktop/tablet/mobile Chrome checks for persistent preferences and side-button accessibility.

## Admin table pagination

The admin registers, dashboard activity table, report history, and resource report preview use MySQL `LIMIT` / `OFFSET` pagination. They show a fixed 10 rows per page without a records-per-page selector. Search and filters run before counting and paging; record changes preserve the current selection and clamp pages that become empty. CSV exports and print views include every matching record.

The Reports center previews Resource stock, Distribution summary, Evacuation capacity, and Resident summary independently. Each selection loads its own columns and summary metrics, clears the previous report while loading, and updates the print/export actions. Date filters apply to all four reports; resource category filters apply to stock and distributions. Distribution previews group transaction totals by resource, while resident previews summarize active residents and recorded vulnerabilities. Run `$env:SAGIPBRO_REPORT_PREVIEW_BROWSER = '1'; php tests/admin_pagination_http.php` for focused desktop/mobile preview-switching, print/export-target, and failure-recovery checks using private fixtures.

Authenticated list endpoints accept `paginate=1`, `page`, `per_page`, and `q`, plus the table's filter fields. Responses include `data` and `pagination` (`page`, `per_page`, `total`, `total_pages`, `from`, `to`). Requested page sizes are ignored; paginated results always contain at most 10 records. Existing unpaginated lookups remain compatible. Shared code lives in `api/pagination.php` and `assets/js/admin-pagination.js`.

Run `php tests/admin_pagination_http.php` to exercise all registers with over 100 fixtures per list in an isolated temporary database. Set `$env:SAGIPBRO_PAGINATION_BROWSER = '1'` to include Chrome checks for the actual desktop/mobile controls. The test never selects the application's database for fixture writes.

The authenticated administrative APIs target the original schema in `database/sagipbro.sql`. Installations using the alternate legacy schema described above need an admin compatibility migration; pagination does not rename existing tables or columns.
