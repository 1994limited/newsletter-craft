# Changelog

## Unreleased

### Changed
- The confirm, unsubscribe and expired-link pages can be replaced with `templates/_newsletter/page.twig`, like the form.

## 1.0.0 - 2026-11-11

### Added
- A sign-up form, `{{ craft.newsletter.form() }}`, or your own posting to `newsletter/subscribe` (JSON too).
- Double opt-in: a signed confirmation link, good for 72 hours, opening a page with a button.
- Subscribers as elements: subscribed, waiting to confirm and unsubscribed, searchable, with bulk delete.
- Download CSV (by status), and a Subscribers exporter in the element index's Export… menu, with each subscriber's unsubscribe link.
- Unsubscribe links, with one-click unsubscribe (RFC 8058).
- The consent wording, the form's page and the dates kept with each sign-up.
- A honeypot, a limit on sign-ups per visitor and on confirmation emails per address.
- Unconfirmed sign-ups deleted after 30 days, when Craft collects garbage.
- System messages for the confirmation email and for telling the team about new subscribers.
- `craft.newsletter.count()`, `craft.newsletter.subscribers()`, and events before and after signing up, confirming and unsubscribing.
