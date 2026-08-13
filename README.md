# CLF Alumni Network (WordPress plugin)

Private, members-only alumni network for the Charlotte Leadership Forum. Bold Conviction design.

## What it provides
- **CLF Alumni role** — members can sign in but have no wp-admin access.
- **Members-only pages** (created automatically on activation):
  - `/alumni-login/` — themed sign-in page
  - `/alumni/` — searchable member directory (name, class year, industry) + individual profiles
  - `/alumni-profile/` — the member's own profile editor (photo, spouse, class year, bio, work, links, contact visibility)
- **Privacy** — everything is invisible to logged-out visitors; members choose whether email/phone are shown to other members.
- **Admin → CLF Alumni** — member list (deactivate/reactivate, resend invites), add single member, CSV import.
- **Events & RSVPs** — admins create Alumni Events (date, location, capacity, RSVP deadline), invite members (all / by class year / hand-picked), and emails go out with one-click tokenized RSVP links (no login needed). The RSVP form captures name, spouse attendance, total participants, and notes. Members also see upcoming events at `/alumni-events/`. Every event offers "Add to Google Calendar" and an `.ics` download. Admins get a per-event RSVP dashboard with CSV export, a manual "remind non-responders" button, and automatic reminders 3 days before the deadline.
- **Google Workspace email** — CLF Alumni → Email Settings configures SMTP (Gmail app password) so invitations come from the real CLF address; includes a test-email button.

## Install
1. Install via Git Updater: repo `tipitoe-ro/clf-alumni`, branch `main` (or upload a zip of this folder).
2. Activate — pages and the role are created automatically.
3. Add members one by one or import the alumni CSV (columns: `email` required, `first_name`, `last_name`, `spouse`, `class_year`). One row per person; existing emails are skipped so re-imports are safe.
4. Invite emails contain a set-password link. For reliable delivery, configure SMTP (e.g. through Google Workspace).

## Notes
- Site admins automatically have access to the members area.
- Add the "Alumni Network" page to the site menu for signed-in visibility, or share the link directly with alumni.
- Deactivated members can't sign in and disappear from the directory; their data is preserved.
