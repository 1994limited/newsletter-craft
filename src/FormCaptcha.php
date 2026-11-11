<?php

namespace nineteenninetyfour\newsletter;

use Craft;
use craft\helpers\App;
use InvalidArgumentException;
use NineteenNinetyFour\Captcha\Captcha;
use NineteenNinetyFour\Captcha\Captchas;
use nineteenninetyfour\newsletter\http\CaptchaClient;

/**
 * The captcha on the sign-up form, from the plugin's settings: one of
 * Turnstile, hCaptcha or reCAPTCHA (v2 or v3), or a class of your own
 * implementing NineteenNinetyFour\Captcha\Captcha (set in
 * config/newsletter.php). None until a captcha and both keys are set.
 */
class FormCaptcha
{
    public const ACTION = 'newsletter';

    public static function make(): ?Captcha
    {
        $settings = Plugin::getInstance()->getSettings();
        $driver = trim($settings->captcha);

        if ($driver === '') {
            return null;
        }

        if (Captchas::builtIn($driver)) {
            return Captchas::make(
                $driver,
                self::key($settings->captchaSiteKey),
                self::key($settings->captchaSecretKey),
                Craft::$container->get(CaptchaClient::class),
                ['action' => self::ACTION, 'score' => $settings->captchaScore],
            );
        }

        if (class_exists($driver) && is_subclass_of($driver, Captcha::class)) {
            return Craft::createObject($driver);
        }

        throw new InvalidArgumentException("Unknown newsletter captcha [{$driver}]. Use turnstile, hcaptcha, recaptcha, recaptcha_v3, or a class implementing ".Captcha::class.'.');
    }

    /**
     * A key, or its environment variable's value. Anything but a string
     * (an env var set to true or false) is no key.
     */
    protected static function key(string $value): ?string
    {
        $value = App::parseEnv($value);

        return is_string($value) ? $value : null;
    }
}
