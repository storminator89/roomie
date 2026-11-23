# Roomie Exchange pilot: offline verification and go/no-go

This branch of work can use synthetic Graph responses only. No tenant, app
registration, permissions, credentials, or cost have been approved or changed.
The parser is not connected to Roomie booking paths and cannot make requests.

## Offline acceptance

- One complete empty response means no recorded conflict inside the queried
  window, not a guarantee a later booking will succeed.
- Use UTC to compare exact half-open intervals; construct local Berlin calendar
  boundaries explicitly before converting to UTC (including 23/25-hour days).
- Missing pages, malformed rows, ambiguous blocking states, stale data, access
  denial and transport errors must never render a room as available. Parser
  tests cover only payloads; freshness, authorization, verified pagination and
  transport remain separate work.
- No meeting details, raw responses, client credentials or pagination URLs
  leave the server. Fixture records are fictional.
- A future transport must decode Graph responses as objects, preserving the
  difference between an empty JSON array and an invalid empty JSON object in
  the `value` field. It must independently validate the target and every
  pagination hop. The offline parser performs no URL or network operation.

## Transport acceptance contract (not implemented)

The future component must be server-side, use only read-only GET on the fixed
HTTPS Microsoft Graph endpoint for the configured room's `calendarView`, and
verify that every next link still addresses that same mailbox and resource.
Preserve Graph's opaque continuation token rather than rebuilding it. Never
follow redirects or send credentials to another origin. Do not source the graph
host, mailbox ID, initial URL, or continuation from a Roomie request.

Each response must be successful, complete and within bounded byte, time and
page limits; decode it in object mode. Supply the complete sequence for the
exact queried UTC window to the core only after verifying the transport and
room binding. Treat missing/stale timestamps, partial results, permission
errors and unusable responses as unknown; the parser alone cannot make any
result fresh. Do not emit URLs, raw bodies, tokens, or event details to users
or logs.

## Gate before any live setup

1. Ask whether an existing, isolated Microsoft 365 developer sandbox or other
   dedicated test tenant is available. Do not infer entitlement from Developer
   Program membership. In the
   [Developer Program dashboard](https://developer.microsoft.com/en-us/microsoft-365/profile),
   the owner can check whether Microsoft offers sandbox setup.
2. Agree on the named test tenant, one disposable room mailbox, separately
   named negative-test mailboxes, owner-approved timing, and the dedicated
   app and credential custody. Never use an employer/production tenant,
   consent broadly, transmit secrets over chat, or open a trial as a fallback
   without separate explicit authorization. Microsoft's
   [Developer Program FAQ](https://learn.microsoft.com/en-us/office/developer-program/microsoft-365-developer-program-faq)
   describes qualifying membership and non-production use. New instant
   sandboxes require a valid billing account even though Microsoft does not
   charge for the sandbox itself. Avoid opening or linking a billing account
   or changing Azure spend limits as a side effect of the pilot. See
   [Microsoft’s setup requirements](https://learn.microsoft.com/en-us/office/developer-program/microsoft-365-developer-program-get-started#billing-account-requirement).
3. Use [the pilot design](exchange-pilot.md) for the read-only scoped role and
   the same-token positive/negative checks. Do not broaden permissions if the
   scoped GET fails. Keep all mappings disabled until the write-path guard,
   permission revocation, stale data and complete-page tests pass. Never send
   calendar invitations to test connectivity.
