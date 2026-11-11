<?php

namespace nineteenninetyfour\newsletter\models;

use Craft;
use craft\base\Model;
use nineteenninetyfour\newsletter\Plugin;

/**
 * What the sign-up form posts. When it doesn't validate, it's handed back
 * to the template as `newsletterForm`, with its errors and what was typed.
 */
class SubscribeForm extends Model
{
    public string $email = '';

    public string $name = '';

    public bool $consent = false;

    protected function defineRules(): array
    {
        $settings = Plugin::getInstance()->getSettings();

        $rules = [
            [['email'], 'trim'],
            [['email'], 'required', 'message' => Craft::t('newsletter', 'Please enter your email address.')],
            [['email'], 'string', 'max' => 254, 'tooLong' => Craft::t('newsletter', 'Please enter a valid email address.')],
            [['email'], 'email', 'message' => Craft::t('newsletter', 'Please enter a valid email address.')],
            [['name'], 'trim'],
            [['name'], 'string', 'max' => 255],
        ];

        if ($settings->askName && $settings->requireName) {
            $rules[] = [['name'], 'required', 'message' => Craft::t('newsletter', 'Please enter your name.')];
        }

        if (trim($settings->consentText) !== '') {
            $rules[] = [['consent'], 'compare', 'compareValue' => true, 'message' => Craft::t('newsletter', 'Please tick the box to say you’d like the newsletter.')];
        }

        return $rules;
    }
}
