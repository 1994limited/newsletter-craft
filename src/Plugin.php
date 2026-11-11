<?php

namespace nineteenninetyfour\newsletter;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterEmailMessagesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\services\Elements;
use craft\services\Gc;
use craft\services\SystemMessages;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use NineteenNinetyFour\Captcha\Captchas;
use nineteenninetyfour\newsletter\elements\Subscriber;
use nineteenninetyfour\newsletter\events\SubscriberEvent;
use nineteenninetyfour\newsletter\models\Settings;
use nineteenninetyfour\newsletter\services\Subscribers;
use nineteenninetyfour\newsletter\variables\NewsletterVariable;
use Throwable;
use yii\base\Event;

/**
 * Newsletter for Craft CMS: a sign-up form, double opt-in by email, and the
 * list of subscribers in the control panel, ready to export as CSV.
 *
 * @property-read Subscribers $subscribers
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const HANDLE = 'newsletter';

    /** Seeing the list and downloading it. */
    public const PERMISSION_VIEW = 'newsletter-view';

    /** Deleting subscribers. */
    public const PERMISSION_DELETE = 'newsletter-delete';

    /** The confirmation email, in Utilities → System Messages. */
    public const MESSAGE_CONFIRM = 'newsletter_confirm';

    /** The email to the site's team about each confirmed sign-up. */
    public const MESSAGE_NOTIFY = 'newsletter_notify';

    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = true;

    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'subscribers' => Subscribers::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Event::on(Elements::class, Elements::EVENT_REGISTER_ELEMENT_TYPES, function (RegisterComponentTypesEvent $event) {
            $event->types[] = Subscriber::class;
        });

        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function (Event $event) {
            $event->sender->set('newsletter', NewsletterVariable::class);
        });

        // The ready-made form, at "_newsletter/form". A site's own
        // templates/_newsletter folder takes its place.
        Event::on(View::class, View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS, function (RegisterTemplateRootsEvent $event) {
            $event->roots['_newsletter'] = __DIR__.'/templates/_site';
        });

        // Always, not only in the control panel: saving permissions anywhere
        // keeps only those registered.
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function (RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t(self::HANDLE, 'Newsletter'),
                'permissions' => [
                    self::PERMISSION_VIEW => [
                        'label' => Craft::t(self::HANDLE, 'See and export the newsletter list'),
                        'nested' => [
                            self::PERMISSION_DELETE => ['label' => Craft::t(self::HANDLE, 'Delete subscribers')],
                        ],
                    ],
                ],
            ];
        });

        $this->registerEmails();

        // Sign-ups nobody confirmed are deleted after a while.
        Event::on(Gc::class, Gc::EVENT_RUN, function () {
            try {
                $this->subscribers->prune();
            } catch (Throwable $e) {
                Craft::warning('Newsletter couldn’t delete old unconfirmed sign-ups: '.$e->getMessage(), self::HANDLE);
            }
        });

        Craft::$app->onInit(function () {
            if (Craft::$app->getRequest()->getIsCpRequest()) {
                Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function (RegisterUrlRulesEvent $event) {
                    $event->rules[self::HANDLE] = ['template' => 'newsletter/_index'];
                    $event->rules[self::HANDLE.'/export'] = 'newsletter/export/csv';
                });
            }
        });
    }

    public function getCpNavItem(): ?array
    {
        if (! Craft::$app->getUser()->checkPermission(self::PERMISSION_VIEW)) {
            return null;
        }

        $item = parent::getCpNavItem();
        $item['label'] = Craft::t(self::HANDLE, 'Newsletter');
        $item['icon'] = __DIR__.'/icon-mask.svg';

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings;
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('newsletter/_settings', [
            'settings' => $this->getSettings(),
            'overridden' => array_keys(Craft::$app->getConfig()->getConfigFromFile(self::HANDLE)),
            'captchaOptions' => $this->captchaOptions(),
        ]);
    }

    /**
     * The captchas to choose from, and a class of your own when the config sets one.
     *
     * @return array<string, string>
     */
    protected function captchaOptions(): array
    {
        $options = ['' => Craft::t(self::HANDLE, 'None')] + Captchas::LABELS;
        $current = $this->getSettings()->captcha;

        if ($current !== '' && ! isset($options[$current])) {
            $options[$current] = $current;
        }

        return $options;
    }

    protected function registerEmails(): void
    {
        Event::on(SystemMessages::class, SystemMessages::EVENT_REGISTER_MESSAGES, function (RegisterEmailMessagesEvent $event) {
            $event->messages[] = [
                'key' => self::MESSAGE_CONFIRM,
                'heading' => Craft::t(self::HANDLE, 'When someone signs up to the newsletter'),
                'subject' => Craft::t(self::HANDLE, 'Please confirm your subscription'),
                'body' => Craft::t(self::HANDLE, "Hi{% if name %} {{ name }}{% endif %},\n\nPlease confirm you’d like the {{ siteName }} newsletter at {{ email }}:\n\n[Yes, subscribe me]({{ confirmUrl }})\n\nThe link works for {{ hours }} hours. If you didn’t ask for this, ignore this email and you won’t hear from us.\n\n{{ siteName }}"),
            ];
            $event->messages[] = [
                'key' => self::MESSAGE_NOTIFY,
                'heading' => Craft::t(self::HANDLE, 'When a newsletter sign-up is confirmed'),
                'subject' => Craft::t(self::HANDLE, 'New newsletter subscriber: {{ email }}'),
                'body' => Craft::t(self::HANDLE, "{{ email }}{% if name %} ({{ name }}){% endif %} confirmed their newsletter sign-up.\n\n{{ count }} confirmed subscribers in all: {{ listUrl }}"),
            ];
        });

        Event::on(Subscribers::class, Subscribers::EVENT_AFTER_CONFIRM, function (SubscriberEvent $event) {
            $notify = array_values(array_filter(array_map('trim', $this->getSettings()->notify)));

            if ($notify === []) {
                return;
            }

            try {
                Craft::$app->getMailer()
                    ->composeFromKey(self::MESSAGE_NOTIFY, [
                        'subscriber' => $event->subscriber,
                        'email' => $event->subscriber->email,
                        'name' => $event->subscriber->name,
                        'count' => (int) Subscriber::find()->count(),
                        'listUrl' => UrlHelper::cpUrl(self::HANDLE),
                    ])
                    ->setTo($notify)
                    ->send();
            } catch (Throwable $e) {
                Craft::warning('Newsletter couldn’t send the new subscriber email: '.$e->getMessage(), self::HANDLE);
            }
        });
    }
}
