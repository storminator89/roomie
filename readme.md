# Roomie

Roomie is a web application for managing and booking rooms in an office building.

## Features

- **Dashboard:** See your current or next booking, jump to the floor plan, or book several days.
- **Floor plans:** Upload PNG/JPEG plans, draw room zones and find available rooms directly on the plan. The room list and the legacy grid remain available.
- **Bookings:** See upcoming bookings, download an ICS file and cancel when eligible. Book a free time window, plan a weekly series or request approval for rooms that require it.
- **Rooms:** Find rooms by name or number and keep frequently used rooms in Favorites. Room cards and details show an illustration matched to the room type and equipment, or the room’s own photo.
- **Administration:** Manage room closures, booking requests, accounts and room permissions. Account deletion has a separate confirmation.

## Technologies

- **Frontend**: HTML, CSS, JavaScript, Tailwind CSS
- **Backend**: PHP, SQLite

## Screenshots

Older screenshots, for reference:

### Home Page
![Screenshot](screenshot/main.png)

### Bookings
![Booking](screenshot/bookings.png)

### Interactive Room Plan
![Room Plan](screenshot/interaktiver_raumplan.png)

### Room Overview
![Overview](screenshot/rooms.png)

### Permissions Matrix
![Permissions Matrix](screenshot/raumberechtigung.png)

### Room Administration
![Administration](screenshot/room_administration.png)

## Private SQLite deployment (required)

- Set `ROOMIE_DB_PATH` to an absolute SQLite path in a private directory
  outside the application checkout and the web server's document root.
  Create the directory beforehand, restrict it to the app account, and do not
  expose it through any web alias or backup download. On Linux, PHP requires
  POSIX and checks that the app account owns both the directory (e.g. mode
  0700) and the DB file (e.g. mode 0600), without group or other access.
  Use HTTPS, disable
  directory listing and apply `.htaccess` or equivalent server rules.
- For **a new empty deployment only**, set `ROOMIE_ADMIN_NAME`,
  `ROOMIE_ADMIN_EMAIL` and `ROOMIE_ADMIN_PASSWORD_HASH` in a secure local
  environment. Generate the last value with PHP `password_hash` from a strong,
  unique password; do not store the password or hash in this repo or a public
  directory. Set `ROOMIE_DOCUMENT_ROOT` to the actual absolute web document root
  for the CLI check. Run `php init_db.php` as the app account; it creates the schema and
  one configured admin, never demo users or bookings. Clear the temporary
  admin provisioning variables afterwards. Run no setup URL in the browser.
- **Existing deployment:** take it offline, preserve a verified, consistent
  backup privately, and migrate only a vetted runtime database to the new path.
  The setup refuses to overwrite an existing file. Review old accounts for
  demo credentials, revoke sessions and replace any affected credentials before
  reopening; moving the DB alone does not revoke any account.
- Do not deploy or commit SQLite files. The bundled demo database was removed
  from the current branch; older commits and copies are unaffected. Never use
  a database from the Git history for deployment.

## Accounts, visibility and mail

- The administrator provisions accounts. Public registration is disabled.
  Use verified organizational addresses before enabling mail. Only administrators
  can correct an account's address. Password and role changes revoke old sessions.
- Members see availability, not the identity of other people booking a room.
  Only a member with an explicit grant (or an administrator for room management)
  can see a restricted room. Keep at least one administrator. Accounts and rooms
  with booking history cannot be deleted through the app.
- Set `ROOMIE_MAIL_FROM` to an authorized sender address to enable transactional
  mail; otherwise booking and calendar downloads still work without sending mail.
- For an existing private database, take the app offline, preserve a verified
  backup, and run `php schema_hardening.php` with the existing private path and
  document-root environment, using the app account, before starting the new app.
  The check refuses inconsistent references and does not delete existing rows.
  Fresh installs use `init_db.php` only. Existing sessions must sign in again.
- Publish only the application, not `.git`, build tools, private data or old
  uploads. If the server does not honor `.htaccess`, apply equivalent path denies
  and security headers in the web server. Force HTTPS; behind a trusted proxy
  configure PHP to mark the session cookie Secure.
- Avatar uploads use PHP GD and private storage next to the configured database.
  Old public uploads are not reused.
- Administrators can add, replace, or remove a room photo in the room editor.
  Photos use private SQLite storage (included in private backups), respect room
  visibility, and are resized and stripped of metadata with PHP GD. Uploads
  accept JPEG, PNG and WebP where those formats are enabled in GD. Allow at least
  5 MiB for a file and a slightly larger multipart request in PHP/web server limits.

## Room plan

Open `raumplan.php` from the Roomie navigation. The old `raumplan.html`
bookmark forwards to the integrated page. No new database schema is needed.

- The authenticated page renders the application shell and initial session state.
- `assets/roomplan/api.js` handles HTTP, legacy grid normalization and CSRF.
- `assets/roomplan/view.js` renders the room plan and booking controls.
- `assets/roomplan/editor.js` loads only when an administrator opens edit mode.
  Room changes are saved separately from layout changes; the new editor
  detects concurrent layout saves rather than silently overwriting them.
- Existing PHP endpoints remain authoritative for room visibility, user roles,
  bookings and inventory. Layout dimensions and existing placements are retained.

### Find and book a time

Pick a date, search by name or number, or select **Nur frei**. For a
specific window, equipment or a different view, open **Filter und Ansicht**.
If a floor plan image cannot load, switch to the room list without losing the
date or active filters. Admins open the legacy grid in a separate edit mode.

Custom slots are for one day, between 09:00 and 17:00 (Europe/Berlin), in
15-minute increments. An ordinary preset can be booked until its end; a
custom time or approval request must still start in the future. For a
multi-day booking, a completed first slot rejects the whole request.
Availability counts old seat-only bookings against their room as well.
Permissions and places are checked again on save; filters do not reserve rooms.

Under **Buchungen → Optionen → Verschieben**, change the date and time of an
upcoming booking in the same room. If the new time is taken or the booking has
changed since opening the form, the attempted move does not change the booking.
Multi-day bookings are listed as separate reservations; this action moves only
the selected day. The calendar download reflects the new time, but an earlier
import into Outlook or another calendar is not synchronized. No migration is
required.

On a room's details page, choose **Wöchentlich buchen**, set the start date,
time and number of appointments (2–12), then **Termine prüfen**. Every date
is displayed before saving. Weekly occurrences keep the same Berlin local
time, including across a clock change. On confirmation Roomie checks every
date again; it saves all appointments together or none. The 90-day booking
horizon still applies. Each saved occurrence can be moved, cancelled or
exported separately; Roomie does not yet edit the series as a group.

The [read-only Exchange pilot](docs/exchange-pilot.md) has an offline-tested
availability parser. It is not connected to Roomie, and no Exchange account,
UI, or booking behavior is enabled.

Run `node --test build-files/*.test.mjs` to check the frontend data
contract without a running PHP server. Run `php build-files/room-search.php`
for the room-directory search fixtures.

### Optional room check-in

Check-in is off by default. A Roomie administrator can turn it on for an
individual room in **Room details → Check-in**. Under **Bookings**, the owner
with current room access can confirm a valid reservation from ten minutes
before its start until ten minutes after it starts, ending earlier if the
reservation ends. Times use Europe/Berlin; previously created future bookings
qualify if their start is not before activation.

The administrator sees today's aggregate of eligible reservations already
started and how many were self-confirmed. This is not measured presence, and
a missing confirmation never cancels or releases a booking. Check-in stores
only a marker for the booking and its room in private SQLite, without identity
duplication, check-in time, device, IP address or location. Cancelling, moving
the reservation, changing its owner or disabling the room policy removes
the corresponding markers. While enabled, markers remain with their bookings
until a change, deletion or opt-out; private backup retention still applies.

No manual schema step is needed: the private tables are created at the first
explicit opt-in. Behind an HTTPS reverse proxy, configure the trusted web
server to provide PHP with the original Host and HTTPS scheme; the endpoint
does not trust arbitrary forwarded headers. Run
`php build-files/room-checkin-test.php` for the offline policy and time-window
checks.

### Room closures

Admins can choose **Sperrzeiten** on a room's details page and block a future
time range, for example during maintenance. Use 09:00–17:00 for a whole-day
closure. Roomie will not override an existing booking to add a closure.
The blocked window is removed from online availability, including weekly
series and rescheduling. Existing bookings and calendar files are unaffected.

For an existing installation, back up SQLite and run the additive CLI
`php schema_hardening.php` with the normal environment before using closures.
Do not roll back to older application code while room closures are active:
older booking paths do not enforce them. Fresh installs create the table
through `init_db.php`.

### Optional room booking approval

Room bookings are instant by default. For an individual room, an admin can
enable **Freigabe nötig** in the room details. People then request a single
appointment and track its status under **Meine Buchungen**. A pending request
does not hold a place or create a calendar entry. Existing bookings are kept.

An administrator can approve or decline from the request list. Roomie checks
the user's current room access, the time, any closure, and the remaining
capacity when approving. Only approval creates the actual booking. Until then
the requester can withdraw it. Recurring or multi-day bookings and moves for
approval-only rooms are not supported. The app sends no email for these
request or approval actions.

For existing installations, back up SQLite before running the additive CLI
`php schema_hardening.php` with the usual private database configuration.
Fresh installations create the tables through `init_db.php`. Do not roll
back to code without approval enforcement while any room requires it.

## PHP 8.6 runtime

The deployment target is PHP **8.6.x** (`.php-version`). Select it separately
for both the web handler (for example, PHP-FPM) and CLI. Changing the CLI version
alone does not switch the web app. Use the latest available 8.6 security release.

- Enable `pdo_sqlite`, `session` and `filter`; Linux installations also
  require `posix` for the private database checks. Enable `gd` with JPEG, GIF
  and PNG support if avatar uploads are used.
- Keep the private SQLite path, application user and writable session directory
  configured as before. A PHP-only upgrade does **not** change the SQLite schema.
- Back up the database and test under the new handler in staging. After selecting
  the new handler, verify login, dashboard, room plan, booking, calendar download
  and avatars if used. Check the web error log. If needed, restore the previous
  handler; keep the database unchanged.

Run the isolated application check with Python 3.9+ and the selected PHP CLI:

```sh
PHP_BIN=/path/to/php8.6 python3 build-files/php-smoke.py --expect-minor 8.6
```

It checks lint, session roles, dashboard room selection, booking, and ICS export.
When GD PNG is enabled it also checks private avatar upload and delivery. The
test uses temporary data and localhost only and sends no email. It does not
check an Apache/PHP-FPM installation or change the host's PHP selection.

### Uploaded floorplans

Admins open **Raumplan → Räume & Grundrisse verwalten** (also linked from
**Räume → Räume verwalten**) to create a room or edit its details without
opening the legacy grid. A new room is selected immediately for drawing its
zone. Use **+ Grundriss** to drop a PNG/JPEG
or select it from their device (up to 5 MiB, 2400 px per side and 3 megapixels).
Select a room and draw its zone with the mouse or tap two corners on a
touchscreen. Use **Position & Größe** for precise keyboard edits, then save the zones.
Use the plan's rename action to update its title without replacing the image
or its zones. Each plan may have up to 200 marked rooms; up to 12 plans are supported.

Use **Raum suchen** to find a marked room. Select a room to open its booking
options. The older grid and the room list remain available.

GD must be enabled. Roomie decodes the upload, stores a new PNG in the
private SQLite database and serves it only to signed-in users. Do not upload
a drawing with labels or details that should be hidden from any signed-in user:
room permissions filter interactive zones, not text baked into the image.
The normal private SQLite backup also includes floorplans and zones.

For an existing database, back up and run the additive `php schema_hardening.php`
before using the new editor. It does not alter bookings or the existing grid.
Allow enough room for multipart overhead in PHP `post_max_size` and set
`upload_max_filesize` to at least 5 MiB. A new installation gets the tables
through `init_db.php`.

## Read-only MCP endpoint

Roomie exposes `/mcp.php` over **Streamable HTTP** (JSON responses, no stdio,
no session setup on the server). The endpoint is disabled until configured.
It does not use browser login cookies and cannot create, change, or cancel
bookings.

1. Create a dedicated **non-admin** Roomie account in **Konto → Benutzerverwaltung**.
   Use **Berechtigungen** for any restricted room the integration should see.
   It can read bookings for unrestricted rooms as well. Do not use the account
   for day-to-day login.
2. As an administrator, open **Konto → MCP-Zugang**, choose that account and
   select **API-Key erstellen**. Copy the key immediately: Roomie shows it
   once and saves only its hash in the private SQLite database. Use
   **API-Key erneuern** to replace it or **Zugang sperren** to disable it.
   After either action, the previous key no longer works for new requests.
3. Serve the endpoint only over HTTPS (TLS enforced at the web server or reverse
   proxy). Preserve the `Authorization` header when forwarding to PHP, and apply
   a per-client rate limit on the endpoint, for example 30 requests per minute
   with a small burst. Keep web-server denial of `mcp_booking_data.php` and
   `mcp_key_store.php` when not using the included Apache rules. Do not log tokens
   or set CORS headers for this route. Leave `ROOMIE_MCP_ORIGIN` unset for normal
   server-side MCP clients; requests with an `Origin` are rejected by default.
   If needed, set it to exactly the published Roomie origin (scheme plus hostname
   and optional port, no slash).

Existing setups using `ROOMIE_MCP_API_KEY` and `ROOMIE_MCP_USER_ID` keep working
until an admin sets or revokes a key in the web interface. The database then
takes precedence; revoking a managed key does not reactivate the old environment
key. No new environment variables or manual schema step are needed for Web UI setup.

In an MCP client that supports custom headers, set the transport to
**Streamable HTTP**, URL to `https://<roomie-host>/mcp.php` and header to
`Authorization: Bearer <API-key>`. The key is configured directly in the
client; the endpoint does not offer OAuth discovery or issue tokens.

Tool: `list_room_bookings`. Specify `from` and `to` as YYYY-MM-DD, inclusive
(up to 31 calendar days). Optionally specify numeric `room_id` and `limit`
(1–200, default 200). Results show confirmed bookings with room ID/name, date,
start and end times (Europe/Berlin), not who booked. Pending requests and
maintenance blocks are not bookings. If `truncated` is true, repeat with a
narrower interval or a room filter. An ungranted or unknown room ID returns
the same empty result.

Local regression on a disposable SQLite database:

```sh
PHP_BIN=php python3 build-files/mcp-http-smoke.py
```

### Room QR signs

Set `ROOMIE_PUBLIC_BASE_URL` to Roomie's stable HTTPS base URL (for example
`https://rooms.example.org/roomie` for a subdirectory installation). Configure
this in the app environment, not through a request header. Use an ASCII
hostname and ordinary path segments, without credentials, queries or fragments.
Without a valid base URL, no QR code is generated.

As an administrator, open a room and choose **Raum-QR drucken**. Test the
printed code on a phone before putting it up. The QR embeds only the canonical
room URL and numeric room ID. Users still sign in and need their usual room
access. Room data is never sent to an external QR service.

The mobile page shows current availability without booking names and offers
the next 30-minute slot today, if one fits. The slot starts on the next
eligible 15-minute boundary in Europe/Berlin. You must confirm explicitly;
the room's approval settings and all normal booking checks still apply.

Keep the base URL and room ID stable while signs are in use. If the base URL
changes, or this feature is rolled back, existing signs do not update. Replace
the signs, or preserve the old route to the correct HTTPS installation when
moving it. Do not reuse a sign for a different room.

The print page uses Project Nayuki's MIT-licensed [QR Code generator v1.8.0](https://github.com/nayuki/QR-Code-generator/tree/v1.8.0),
vendored locally as `assets/rooms/vendor/qrcodegen-1.8.0.js`.
