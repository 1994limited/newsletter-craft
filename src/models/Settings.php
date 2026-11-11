<?php

namespace nineteenninetyfour\newsletter\models;

use craft\base\Model;

/**
 * Newsletter's settings: edited under Settings → Plugins → Newsletter, or
 * fixed in config/newsletter.php.
 */
class Settings extends Model
{
    /** Hours a confirmation link works for. */
    public int $confirmHours = 72;

    /** Ask for a name as well as an email address. */
    public bool $askName = false;

    /** And make it required. */
    public bool $requireName = false;

    /**
     * The wording next to the tick box. Saved with each sign-up, as a record
     * of what people agreed to. Empty: no tick box (the confirmation email
     * is their consent).
     */
    public string $consentText = 'Email me the newsletter. I can unsubscribe at any time.';

    /** A hidden field that only bots fill in. */
    public string $honeypot = 'website';

    /** Sign-ups a minute from one visitor (by IP address). */
    public int $throttle = 10;

    /** Confirmation emails an hour to one address. */
    public int $perEmail = 3;

    /** Where people land after confirming: a URL on the site, or empty for the plugin's own page. */
    public string $confirmedUrl = '';

    /** Where people land after unsubscribing: a URL on the site, or empty for the plugin's own page. */
    public string $unsubscribedUrl = '';

    /** Days before sign-ups that were never confirmed are deleted. 0 keeps them. */
    public int $pruneDays = 30;

    /** Email addresses told about each confirmed sign-up. */
    public array $notify = [];

    /**
     * "Tell these people" comes from the settings page as one line.
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        if (isset($values['notify']) && is_string($values['notify'])) {
            $values['notify'] = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $values['notify']) ?: [])));
        }

        parent::setAttributes($values, $safeOnly);
    }

    protected function defineRules(): array
    {
        return [
            [['confirmHours', 'throttle', 'perEmail'], 'integer', 'min' => 1],
            [['pruneDays'], 'integer', 'min' => 0],
            [['honeypot'], 'match', 'pattern' => '/^[A-Za-z][A-Za-z0-9_-]*$/'],
            [['confirmedUrl', 'unsubscribedUrl', 'consentText'], 'string'],
            [['askName', 'requireName'], 'boolean'],
            [['notify'], 'each', 'rule' => ['email']],
        ];
    }
}
