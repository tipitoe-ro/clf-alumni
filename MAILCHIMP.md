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
Mailchimp itself enforces email unsubscribes immediately; the WordPress consent
display is reconciled on the next sync, not through a real-time webhook.

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
