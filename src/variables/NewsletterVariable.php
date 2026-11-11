<?php

namespace nineteenninetyfour\newsletter\variables;

use Craft;
use craft\web\View;
use nineteenninetyfour\newsletter\elements\db\SubscriberQuery;
use nineteenninetyfour\newsletter\elements\Subscriber;
use nineteenninetyfour\newsletter\models\SubscribeForm;
use nineteenninetyfour\newsletter\Plugin;
use Twig\Markup;

/**
 * craft.newsletter
 */
class NewsletterVariable
{
    /**
     * {{ craft.newsletter.form() }}: the ready-made sign-up form
     * (templates/_newsletter/form.twig in your site replaces it).
     *
     * @param  array<string, mixed>  $options  redirect, button, placeholder, class, id
     */
    public function form(array $options = []): Markup
    {
        $html = Craft::$app->getView()->renderTemplate('_newsletter/form', [
            'newsletter' => $this,
            'options' => $options,
        ], View::TEMPLATE_MODE_SITE);

        return new Markup($html, 'UTF-8');
    }

    /**
     * The form handed back with its errors after a failed sign-up, or a
     * fresh one.
     */
    public function getForm(): SubscribeForm
    {
        $form = Craft::$app->getUrlManager()->getRouteParams()['newsletterForm'] ?? null;

        return $form instanceof SubscribeForm ? $form : new SubscribeForm;
    }

    /**
     * The message after signing up (or after confirming, when that lands on
     * a page of your own). Read once.
     */
    public function getSuccess(): ?string
    {
        $message = Craft::$app->getSession()->getFlash(Plugin::HANDLE);

        return is_string($message) && $message !== '' ? $message : null;
    }

    public function getConsentText(): string
    {
        return trim(Plugin::getInstance()->getSettings()->consentText);
    }

    public function getAskName(): bool
    {
        return Plugin::getInstance()->getSettings()->askName;
    }

    public function getRequireName(): bool
    {
        return Plugin::getInstance()->getSettings()->requireName;
    }

    public function getHoneypot(): string
    {
        return Plugin::getInstance()->getSettings()->honeypot;
    }

    /**
     * Confirmed subscribers, unless asked otherwise: subscribers().status(null).
     *
     * @param  array<string, mixed>  $criteria
     */
    public function subscribers(array $criteria = []): SubscriberQuery
    {
        return Craft::configure(Subscriber::find(), $criteria);
    }

    /**
     * How many confirmed subscribers there are.
     */
    public function count(): int
    {
        return (int) Subscriber::find()->count();
    }
}
