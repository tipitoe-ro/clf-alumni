# Mailchimp setup

Mailchimp runs on the external WordPress server, independently of Replit.
The integration is **off by default**. Installing this update does not subscribe
anyone or send campaigns. Event invitations, RSVP messages and Gravity SMTP
delivery remain separate.

## Connect

1. Update the plugin, then open **CLF Alumni → Mailchimp**.
2. In your Mailchimp account, create an API key and find the desired audience ID.
3. Enter them on the WordPress settings page, not in chat.
   The password field never displays a saved key. Keys are stored encrypted using
   WordPress's authentication salt; rotating salts requires entering the key again.
   Alternatively configure `CLFA_MAILCHIMP_API_KEY` on the WordPress server.
4. Save and click **Test saved connection**. This only reads the audience name;
   it does not enroll anyone.
5. Enable syncing and keep **Require email confirmation** checked unless you have
   decided to use single opt-in. New pending contacts receive Mailchimp's own
   confirmation email. They will not receive campaigns until they confirm.

The audience needs the standard `FNAME` and `LNAME` merge fields. Additional
required fields or audience-specific marketing-permissions requirements may need
configuration before syncing. Errors appear per contact instead of silently
skipping failed requests. This checkbox records general newsletter consent; it
does not automatically fill custom Mailchimp marketing-permission categories.

## Member opt-in and opt-out

The profile form adds **Send me CLF announcements and the monthly newsletter**,
with a disclosure that name, email, class and leadership tags go to Mailchimp.
Saving the profile queues the preference. Unchecking queues an unsubscribe for
the subscription previously synced by this integration.

Consent records store the latest change's source, timestamp and actor.
Already-unsubscribed, cleaned, and non-subscribed contacts are not automatically
resubscribed. Use the Mailchimp audience's signup form to opt back in.
Mailchimp itself enforces email unsubscribes immediately. With the webhook below,
WordPress reflects verified changes on the next profile load after delivery,
without waiting for WP-Cron. Already-open pages are not live-updated.

## Immediate updates: WordPress-side webhook setup

1. Enable the integration and pass **Test saved connection** first.
2. In **CLF Alumni → Mailchimp → Immediate subscription updates**, copy the
   generated callback URL. It uses WordPress REST (`clf-alumni/v1/mailchimp`)
   and a private random key, separate from the Mailchimp API key.
3. The external WordPress site must have a publicly reachable **HTTPS** URL.
   Allow GET and form-encoded POST to this route through security plugins,
   firewalls and caching rules. Do not require a WordPress login or nonce.
   Exclude this endpoint from caches. Mailchimp checks the URL with a GET;
   a valid authenticated probe returns HTTP 200 without changing profiles.
4. In Mailchimp's selected audience, open **Settings → Webhooks**, create a
   webhook and paste the complete URL. Select **subscribes**, **unsubscribes**
   and **cleaned addresses**, with changes made by subscribers, account admins
   and the API enabled. Confirmation is delivered as a subscribe event.
5. Treat the callback URL as a credential. Restrict access to this admin page,
   redact its query string from hosting/access/proxy logs, and do not paste it
   into chat. If exposed, click **Rotate webhook URL** and replace the URL in
   Mailchimp immediately. Old URLs return 403. No live webhook is registered
   automatically by this plugin.

Mailchimp audience webhooks do not supply a cryptographic payload signature.
The handler requires the private URL key (constant-time comparison), validates
the audience and payload, matches only a previously synced current email, and
reads the contact's current state through the authenticated Mailchimp API.
It never trusts a supplied status or delivery timestamp. Unknown users, old
emails, unsynced contacts and unsupported event types cannot enroll anyone.
Unsubscribed, cleaned or removed contacts have local consent cleared; a verified
confirmation updates the displayed result only when local consent still exists.
A remote subscription **never** grants website consent or cancels a pending
local opt-out, including delayed/duplicate events. To opt back in, use the
Mailchimp signup form and explicitly re-enable the website preference.

Invalid credentials return 403; malformed data or the wrong audience return
400. API failures, paused connections and concurrent sync return 503 so Mailchimp
can retry. Delivery/retries depend on Mailchimp and hosting availability, not a
guaranteed instant push. Routine sync remains a fallback; use **Sync selected /
retry** if a webhook was missed. Pending tag updates/opt-outs are preserved.
Neither webhook handling nor its GET probe sends event emails or campaigns.

On the external server, test with a consenting test member: sync to pending,
confirm the Mailchimp email, reload the profile and verify the confirmed result;
unsubscribe in Mailchimp and verify the checkbox clears after delivery. Then
uncheck locally before a confirmation arrives and confirm it stays unchecked.
Check that a callback with a wrong key returns 403, and test a duplicate delivery.
Do not use real members for these checks.

## Webhook health and troubleshooting

Open **CLF Alumni → Mailchimp → Webhook health** and refresh after a test
delivery. This administrator-only section shows:

- **Last accepted delivery (UTC):** the last valid POST that returned success.
  This includes safely ignored events (unknown contacts, unsupported events or
  unchanged consent). It proves the endpoint handled a delivery, not that every
  profile is current. GET URL checks, rejected requests and retryable failures
  do not advance it.
- **Mailchimp API verification failures:** cumulative failed API contact lookups,
  with the last failure time in UTC. Retries count separately. A verified missing
  contact (404) is not a failure. Invalid callback keys, malformed/wrong-audience
  requests, disabled integrations and busy sync locks are not included.

Tracking starts with this plugin update; earlier deliveries are not backfilled.
“None recorded yet” is normal before the first delivery. History is retained
across disabling/re-enabling, key rotation and configuration changes. A nonzero
failure count is historical, not proof of a current outage. Health storage is
limited to timestamps and one aggregate count; no callback keys, emails, payloads
or raw API errors are recorded. No scheduled alert or heartbeat is sent.

If a subscription change is expected but the timestamp does not advance:

1. Confirm syncing is enabled and **Test saved connection** succeeds. That test
   checks outgoing API access only, not incoming webhook delivery. Failures can
   indicate a revoked API key, incorrect audience, outbound network/TLS problems,
   rate limits or a Mailchimp outage.
2. Check the selected audience's webhook URL and subscribed event/source types.
   After rotating the callback URL, replace it in Mailchimp. A successful GET
   check alone cannot verify POST routing.
3. Ask the host to check route reachability and HTTP status codes, with the
   callback query string and request bodies redacted. Check HTTPS, redirects,
   REST restrictions, security plugins, firewalls and cache exclusions. Requests
   blocked before reaching WordPress cannot appear in these health counters.
   Never paste the full callback URL or contact data into support logs.
4. Interpret status codes: 403 means an invalid callback key; 400 means malformed
   data or a different audience; 503 means paused/unconfigured syncing, a busy
   lock, a contact changing during processing, or API verification failure.
   A rising verification count specifically points to the outgoing contact
   lookup failing. Resolve the connection issue and let Mailchimp retry.
5. After fixing the cause, use a consenting test member to trigger a fresh
   subscribe/unsubscribe event and check both the timestamp and profile result.
   Use **Sync selected / retry** for possibly missed contacts and verify WP-Cron
   is running. A later accepted event alone does not prove earlier failures
   recovered. Handle urgent unsubscribes directly in Mailchimp.

Mailchimp sends events on changes, not on a fixed schedule. An old timestamp on
a quiet audience is not itself an outage; compare it with a known test change.

## Admin enrollment

- **Enroll selected:** select contacts and confirm they have already consented.
- **Sync selected / retry:** update already-consented contacts or retry failures;
  does not grant consent to unconsented contacts.
- **Unsubscribe selected:** revoke website consent and queue an unsubscribe.
- **Automatic enrollment:** applies only to new accounts created using the plugin's
  Add Member and CSV Import tools. An administrator must confirm these people have
  already consented. It does not retroactively enroll existing accounts.

The contact list is paginated in groups of 50 and supports name/email, class year,
and leader filters. Leader-only contacts appear with **Leaders only**.

## Data and tags

Synced: email, first name, last name, and these managed tags:

- `CLF:Website`
- `CLF:Class of 2026` (person's own class)
- `CLF:Class leader`
- `CLF:Leads 2026`, `CLF:Leads 2027`, etc.

Keep the `CLF:` tag prefix reserved for this integration. It removes obsolete
tags with that prefix but leaves other Mailchimp tags alone. Changing names,
class year or leadership assignments automatically queues an update for
consented accounts.

Changing a previously synced email retires the old email subscription and requires
fresh opt-in for the new address. The integration blocks changing audiences once
contacts have synced: a deliberate migration is needed to avoid abandoning old
subscriptions or opt-outs.

## Operations

WP-Cron processes up to five queued users every five minutes, with a runtime
budget and an overlap lock. Low-traffic sites should use a server cron to invoke
WordPress cron reliably. API failures retry up to five times with backoff; after
fixing a configuration error, use **Sync selected / retry**.

Disabling the integration pauses all operations, including queued opt-outs.
Existing Mailchimp subscriptions are not removed. Manage urgent unsubscribes
directly in Mailchimp if the connection is paused or failing.

No API responses or credentials are written to logs. No existing people are
automatically enrolled on upgrade. Compose and send newsletters inside Mailchimp.

## Verification

`php clf-alumni/tests/mailchimp-test.php` runs mocked API regression checks without
credentials, network requests or emails. Real account setup must pass
**Test saved connection** on the WordPress server. No live audience was connected
or altered during development.

`php clf-alumni/tests/mailchimp-webhook-test.php` additionally checks webhook
credentials, malformed requests, audience/identity isolation, confirmations,
opt-out precedence, duplicate/stale events, API failures, sync locking and
privacy-safe health counters. `php clf-alumni/tests/mailchimp-health-admin-test.php`
checks health rendering and administrator-only access with synthetic data.

`bash clf-alumni/tests/run-wordpress-integration.sh` creates and removes a
disposable WordPress 6.8.3 installation and MariaDB database (requires PHP with
mysqli, curl and OpenSSL, curl, tar and MariaDB tools). It runs real WordPress
admin-handler and REST integration checks, using only synthetic credentials and
intercepted Mailchimp HTTP. Admin checks cover capabilities, missing/invalid/
wrong-user nonces, encrypted credential persistence and replacement, blank-key
preservation, private rendering, connection testing and webhook rotation.
Admin form submissions run through the production page callback with PHP-style
superglobals; REST coverage also includes actual loopback HTTP ingress.
