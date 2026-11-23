# Exchange Online room availability pilot

**Offline pilot core only.** No live tenant was connected or tested. There is
no runtime integration, route, tenant mapping, credentials, or network
transport. There is no feature switch to enable. The parser and its synthetic
tests live under the web-denied `build-files/` directory; the running app does
not load them.

Run `php build-files/exchange-availability.php` for offline acceptance.
The parser verifies and merges complete sets of already-retrieved synthetic
Graph response pages into UTC busy intervals. It does not establish freshness,
prove the origin or permissions of those pages, follow pagination, or decide
whether a user may see a room. Its known-empty result means only no recorded
blocking event in the tested window. See the
[verification plan](exchange-pilot-test-plan.md) for the remaining gates.

**Future setup design (not instructions to enable this release).** Start with one existing Exchange Online test room mailbox and a dedicated identity. The pilot displays availability only; booking and cancellations for the mapped room happen in Outlook or Teams through the Exchange room calendar. No Teams API, meeting creation, mail, calendar writes, or new paid service is needed for this design. [How Outlook and Teams book Exchange rooms](https://learn.microsoft.com/en-us/microsoft-365/places/room-desk-booking/rooms)

## Permissions and setup proposal

1. After tenant-owner and security approval, an Entra admin creates a dedicated single-tenant app registration. Record the tenant ID, client ID and **Enterprise applications service-principal object ID**, not the App registrations object ID. Use app-only [client credentials](https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-client-creds-grant-flow) with `https://graph.microsoft.com/.default`; prefer a certificate held by an approved server-side secret store, or workload identity federation on a supported host. Never put credential material in the repository, SQLite, browser or logs. Do not grant this app any Microsoft Graph application roles in Entra. Such grants are additive, not restricted by Exchange RBAC.
2. An authorized Exchange Online administrator links the service principal and assigns **only** `Application Calendars.Read`, with a [resource scope](https://learn.microsoft.com/en-us/exchange/permissions-exo/application-rbac) matching one `RoomMailbox` and its exact `ExternalDirectoryObjectId`. This is the narrowest documented Exchange Application RBAC calendar action. The endpoint table labels its minimum `Calendars.ReadBasic`; the [Graph permissions catalog](https://learn.microsoft.com/en-us/graph/permissions-reference#calendarsreadbasicall) calls the application permission `Calendars.ReadBasic.All`. Neither name is listed in Exchange Application RBAC or [legacy Application Access Policies](https://learn.microsoft.com/en-us/exchange/permissions-exo/application-access-policies#supported-permissions-and-other-resources). Do not compensate by consenting to tenant-wide Graph access.

Example for an approved Exchange Online PowerShell session (placeholder variables; not run). Verify the preview returns exactly the confirmed room **before** assignment. The `ExternalDirectoryObjectId` and `RecipientTypeDetails` are [filterable in Exchange Online](https://learn.microsoft.com/en-us/powershell/exchange/recipientfilter-properties?view=exchange-ps#externaldirectoryobjectid).

```powershell
$filter = "(RecipientTypeDetails -eq 'RoomMailbox') -and (ExternalDirectoryObjectId -eq '$roomObjectId')"
Get-Recipient -RecipientPreviewFilter $filter -ResultSize Unlimited
# Continue only after confirming the output is exactly the one approved room.
New-ManagementScope -Name 'Roomie-Pilot-OneRoom' -RecipientRestrictionFilter $filter
New-ServicePrincipal -AppId $clientId -ObjectId $enterpriseSpObjectId -DisplayName 'Roomie Pilot'
New-ManagementRoleAssignment -Name 'Roomie-Pilot-CalendarRead' -App $enterpriseSpObjectId `
  -Role 'Application Calendars.Read' -CustomResourceScope 'Roomie-Pilot-OneRoom'
Test-ServicePrincipalAuthorization -Identity $enterpriseSpObjectId -Resource $roomSmtp
Test-ServicePrincipalAuthorization -Identity $enterpriseSpObjectId -Resource $otherTestMailboxSmtp
```

3. Configure the server-side pilot mapping `{Roomie room ID → room Entra object ID}`. The target is not taken from an HTTP request. Fetch the mapped room's default [calendarView](https://learn.microsoft.com/en-us/graph/api/user-list-calendarview?view=graph-rest-1.0) only:

```http
GET https://graph.microsoft.com/v1.0/users/{roomObjectId}/calendarView?startDateTime={startUtc}&endDateTime={endUtc}&$select=start,end,showAs,isCancelled
Prefer: outlook.timezone="UTC"
```

Use offset-qualified ISO timestamps, a bounded window, and all pages within a bounded time budget; follow a [Graph next link](https://learn.microsoft.com/en-us/graph/paging) only after it passes the future transport's checks below. Discard canceled events; explicit `free` does not block and `tentative`, `busy`, `oof`, or `workingElsewhere` block for the pilot. Treat overlapping `unknown` or unrecognized status as unverified. Roomie should expose only date/time intervals and status to users who may view the room, never meeting subjects, organizers, participants or raw payloads. `$select` minimizes normal responses; it is **not** an authorization restriction. The credential can read calendar event details in the scoped room. If that is unacceptable, leave the pilot off until an independently approved availability-only feed exists.

## Authority and failure behavior

- Exchange is authoritative for the mapped room. Before enabling a mapping, resolve existing future Roomie reservations explicitly. Keep that room display-only in Roomie; omit local booking controls and reject local booking, rescheduling and cancellation for the mapped room at the server, including moves in or out. Do not enable mapping until this protection covers all write paths. Unmapped rooms remain local.
- Missing credentials, denied requests, timeouts, throttling, invalid or incomplete data, permission revocation, or stale data show **Availability unknown**, never free and never a local fallback. Respect Graph's [retry guidance](https://learn.microsoft.com/en-us/graph/throttling) without requiring interactive users to wait. An empty, complete successful response means no recorded blockers in that window, not a reservation guarantee. Exchange can still reject requests based on room policy or a booking made meanwhile.
- Do not use [`getSchedule`](https://learn.microsoft.com/en-us/graph/api/calendar-getschedule?view=graph-rest-1.0) for this pilot. It accepts separate SMTP targets in `schedules`; Microsoft's API and RBAC docs do not establish that the URL mailbox's scope also constrains each body target. A room in the path and an application-side allowlist are not proof of a tenant-side security boundary. `calendarView` avoids this ambiguity by directly targeting the scoped mailbox. If it fails with the approved scope, keep Exchange booking in Outlook/Teams and the Roomie integration off, rather than broadening grants.

## Enablement checklist

- Tenant approval must cover the app registration, credential custody, one named room, the scoped read grant, and approved test mailboxes/fixtures. Check actual Entra and Exchange grants; the Exchange test cmdlet alone excludes separate Entra grants and bypasses the permission cache. Allow for Exchange permission propagation before live checks.
- With the pilot app token, verify `calendarView` succeeds for the one room and is denied for an approved existing mailbox outside the scope (include another test room and a test user mailbox if available). Verify room-only calendar read in effective permissions and no write, mail, directory or Teams grants. If actively testing write rejection, only use a separately approved disposable room/fixture with no recipients; no production mutation or invitation.
- Verify the display against synthetic free, busy, tentative, private, canceled, recurring, and daylight-saving fixtures; exercise pagination, an external booking after a free result, tampered requests, revoked scope and a simulated outage. Confirm both unknown-state rendering and rejection of all Roomie write paths for the mapped room. Record results before enabling. None of these live checks has been performed for this proposal.
- To stop the pilot, hide or mark the mapping unavailable without reopening local writes, remove the scoped assignment, revoke its credential, and retire the dedicated service principal and scope if unused. Return booking authority to Roomie only after an explicit reconciliation decision.
