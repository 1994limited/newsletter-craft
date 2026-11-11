# Newsletter for Craft

Collect newsletter subscribers on your own Craft site. Add the form to a template; people who sign up are emailed a link to confirm (double opt-in), and only then join the list. The list is in the control panel, ready to download as a CSV for Mailchimp, Brevo, Campaign Monitor or whatever you send with.

Newsletter doesn't send newsletters. It keeps a clean, consented list, and hands it over.

## Features

- **A form in one line.** `{{ craft.newsletter.form() }}` gives you an accessible sign-up form that works without JavaScript, or write your own and post it to `newsletter/subscribe` (JSON too, for `fetch()`).
- **Double opt-in.** Each sign-up is emailed a signed link that lasts 72 hours. The link opens a page with a button, so mail scanners that open links don't confirm anyone by themselves.
- **A record of consent.** The wording next to the tick box is saved with each sign-up, with when they signed up, when they confirmed and the page the form was on.
- **The list in the control panel.** Subscribed, waiting to confirm and unsubscribed, searchable and sortable, with Craft's own bulk delete.
- **CSV export.** A Download CSV button (subscribed, waiting, unsubscribed or everyone), and the list in Craft's own Export… menu too. Each row carries the subscriber's own unsubscribe link, to merge into the emails you send. Cells can't be read as spreadsheet formulas.
- **Unsubscribe links** that work with mail apps' one-click unsubscribe.
- **No leaks, little spam.** The form says the same thing whether an address is new or already subscribed. A hidden honeypot field, a limit on sign-ups per visitor and a limit on confirmation emails per address.
- **Tidy.** Sign-ups nobody confirmed are deleted after 30 days.
- **Your wording.** The confirmation email is a system message (Utilities → System Messages), in each site's language. Anyone can be told about new subscribers by email.

## Requirements

Craft CMS 5.6 or later, PHP 8.2 or later, and Craft's email settings working (the confirmation email is sent with them).

## Installation

```bash
composer require 1994/newsletter-craft
php craft plugin/install newsletter
```

## The form

The ready-made form:

```twig
{{ craft.newsletter.form() }}

{# Options: #}
{{ craft.newsletter.form({
    button: 'Sign me up',
    redirect: '/newsletter/thanks',
    placeholder: 'you@example.com',
    id: 'footer-newsletter',
    class: 'newsletter',
}) }}
```

To change it, copy `vendor/1994/newsletter-craft/src/templates/_site/form.twig` to `templates/_newsletter/form.twig` and edit it there.

Or write your own:

```twig
{% set form = craft.newsletter.getForm() %}
{% set success = craft.newsletter.success %}

<form method="post">
    {{ csrfInput() }}
    {{ actionInput('newsletter/subscribe') }}

    {% if success %}<p>{{ success }}</p>{% endif %}

    <input type="email" name="email" value="{{ form.email }}" required>
    {% if form.hasErrors('email') %}<p>{{ form.getFirstError('email') }}</p>{% endif %}

    {% if craft.newsletter.consentText %}
        <label><input type="checkbox" name="consent" value="1" required> {{ craft.newsletter.consentText }}</label>
        {% if form.hasErrors('consent') %}<p>{{ form.getFirstError('consent') }}</p>{% endif %}
    {% endif %}

    {# Hidden from people; bots fill it in. #}
    <input type="text" name="{{ craft.newsletter.honeypot }}" tabindex="-1" autocomplete="off" hidden>

    <button>Subscribe</button>
</form>
```

Fields: `email`, `consent` (when there's consent wording), `name` (when **Ask for a name** is on) and an optional `redirect`. With `Accept: application/json`, the answer is `{"success": true, "message": "…"}`, or `{"success": false, "errors": {…}}` with a 400 (429 when a visitor signs up too often).

On a page cached with Blitz or `{% cache %}`, the CSRF token and messages are cached too: load the form with Blitz's dynamic content, or leave it out of the cache.

## Confirming and unsubscribing

The confirmation link and each subscriber's unsubscribe link open a small page with a button (and a "link expired" page when it's too late). To restyle it, copy `vendor/1994/newsletter-craft/src/templates/_site/page.twig` to `templates/_newsletter/page.twig` and edit it there; it can extend your own layout. Its variables are listed at the top of the file.

To send people to pages of your own once they've confirmed or unsubscribed, set **After confirming, go to** and **After unsubscribing, go to** under Settings → Plugins → Newsletter; the message is in `craft.newsletter.success`.

The unsubscribe link is in the CSV (**Unsubscribe link**), and on each subscriber as `subscriber.unsubscribeUrl`. Put it in the emails you send, and in a `List-Unsubscribe` header for one-click unsubscribe.

## The list

**Newsletter** in the control panel. **Download CSV** gets the confirmed list; its menu has the others. The columns: Email, Name, Status, Site, Signed up, Confirmed, Unsubscribed, Agreed to, Form page and Unsubscribe link. **Export…** at the bottom of the list exports what's shown, in CSV, JSON or XML.

Permissions: **See and export the newsletter list**, and under it **Delete subscribers**.

In templates:

```twig
{{ craft.newsletter.count() }} readers get our newsletter.

{% for subscriber in craft.newsletter.subscribers().limit(10) %}
    {{ subscriber.email }}
{% endfor %}
```

`craft.newsletter.subscribers()` finds confirmed subscribers only, unless you ask for `.status(null)` (everyone), `'pending'` or `'unsubscribed'`.

## Settings

Under Settings → Plugins → Newsletter, or in `config/newsletter.php`:

```php
<?php

return [
    'consentText' => 'Email me the newsletter. I can unsubscribe at any time.', // '' for no tick box
    'askName' => false,
    'requireName' => false,
    'confirmHours' => 72,
    'confirmedUrl' => '',     // '' for Newsletter's own page
    'unsubscribedUrl' => '',
    'pruneDays' => 30,        // 0 keeps unconfirmed sign-ups
    'notify' => [],           // emailed about each confirmed sign-up
    'throttle' => 10,         // sign-ups a minute per visitor
    'perEmail' => 3,          // confirmation emails an hour per address
    'honeypot' => 'website',
];
```

## Events

```php
use nineteenninetyfour\newsletter\events\SubscriberEvent;
use nineteenninetyfour\newsletter\services\Subscribers;
use yii\base\Event;

// Send confirmed subscribers on to your email platform.
Event::on(Subscribers::class, Subscribers::EVENT_AFTER_CONFIRM, function (SubscriberEvent $event) {
    // $event->subscriber->email, ->name
});
```

Also `EVENT_BEFORE_SUBSCRIBE` (set `$event->isValid = false` to turn a sign-up away; the visitor is still told it worked), `EVENT_AFTER_SUBSCRIBE` and `EVENT_AFTER_UNSUBSCRIBE`.

## Personal data

Newsletter keeps each address, the name if you ask for one, the site, the form's page, the consent wording and the dates. No IP addresses: visitors' are only used, hashed, to count sign-ups for the limit, and forgotten within a minute. Delete a subscriber in the control panel to forget them.

## Support

Email [hello@1994.co.uk](mailto:hello@1994.co.uk), or open an issue on GitHub.
