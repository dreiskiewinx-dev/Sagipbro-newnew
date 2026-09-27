# SAGIPBRO

Modern Bootstrap 5 interface for the **SAGIPBRO Disaster Relief Resource Information System** of Bonuan Binloc, Dagupan City.

## Included screens

- Public website: Home, About, Services, Resources, Contact, Login, and Registration
- Role-aware portals: Administrator, Volunteer, and Resident dashboards
- Admin operations: Resources, Evacuation Centers, Distributions, Residents, Volunteers, Announcements, Reports, Users, Activity Logs, and Profile
- Reusable PHP components: header, public/admin navigation, sidebar, alerts, and footer
- Responsive tables, search and filters, status badges, accessible forms, confirmation flows, modals, print styles, and CSV table export

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

Records are queried again on every request. Retrieval time is shown separately from each record's update time. Times are Philippine time (UTC+8). Planned quantities are not presented as guaranteed remaining stock. Historical date-only distributions explicitly say that the time was not recorded.

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

The public directories are read-only. Maintain the selected MySQL database through authorized database tooling (for example local phpMyAdmin) or a backend writer. Existing admin preview forms have not been converted into working save/publish forms by this Services task.

- In the existing `sagipbro` schema, supplies use `resources.resource_name`, `quantity`, `minimum_stock`, `category`, `unit`; centers use `center_name`, `location`, `current_occupants`, `capacity`, `status`. The original repository equivalents are also supported. Closed/under-maintenance/full centers report zero available spaces.
- Announcements use `content` (or original `body`), `status=published`, `published_at` at or before the current time, `priority=Normal` or `Urgent`, and optional `expires_at`. Draft, archived, future-dated, expired, and undated publications stay hidden.
- Create public schedules in `distribution_events`: `title`, `location`, `details` (public text only), `starts_at`, optional `ends_at`, `status` (Upcoming/Active/Completed/Cancelled), `publication_status` (Draft/Published). Only Published events appear. Staff explicitly controls active status; an elapsed end time removes stale active/upcoming notices by displaying Completed.
- Link planned supplies in `distribution_event_resources` (`event_id`, `resource_id`, `planned_quantity`). Link actual recipient transactions through `distributions.event_id`; public totals are calculated from transaction quantities. No transaction recipients or staff identifiers are returned to public pages. Private transaction `notes` are never public.
- Existing unlinked transactions appear as completed summaries grouped by day and location, with resource totals. Missing locations/times are explicitly labeled rather than invented.
- The database is dedicated to Barangay Bonuan Binloc; directory queries do not treat unrelated barangay data as Binloc data. Use separate databases if serving multiple barangays.

### Verification

```powershell
& 'C:\xampp\php\php.exe' tests/public_services_integration.php
```

Tests create and remove a uniquely named `sagipbro_test_*` database; they never select or overwrite your application database. Run on a healthy local test MySQL server with CREATE/DROP DATABASE permissions. Set `SAGIPBRO_DB_PORT` to use an isolated test server. The suite covers both schemas, repeat migrations, empty states, status boundaries, literal searches, SQL injection input, publication rules, anonymous totals, and report counts.

## Remaining UI previews

The admin/volunteer/resident management screens still include presentation data and preview forms; these are separate from the database-backed public Services implemented here. Preview forms do not persist changes. The older authenticated API handlers target the original repository schema and are not the public directory endpoints. Connecting all administrative CRUD workflows to the alternate existing schema is separate work.
