<?php

namespace nineteenninetyfour\newsletter\elements;

use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\web\twig\AllowedInSandbox;
use DateTime;
use nineteenninetyfour\newsletter\elements\db\SubscriberQuery;
use nineteenninetyfour\newsletter\elements\exporters\SubscribersCsv;
use nineteenninetyfour\newsletter\migrations\Install;
use nineteenninetyfour\newsletter\Plugin;

/**
 * One email address on the list. It's pending until the link in the
 * confirmation email is followed, then subscribed, until it unsubscribes.
 */
class Subscriber extends Element
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUBSCRIBED = 'subscribed';

    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    #[AllowedInSandbox]
    public string $email = '';

    #[AllowedInSandbox]
    public ?string $name = null;

    #[AllowedInSandbox]
    public string $subscriberStatus = self::STATUS_PENDING;

    /** The site they signed up on. */
    public ?int $subscriberSiteId = null;

    /** The consent wording they agreed to, as it was then. */
    public ?string $consent = null;

    /** The page the form was on. */
    public ?string $sourceUrl = null;

    /** The secret in their confirm and unsubscribe links. */
    public string $token = '';

    public ?DateTime $dateConfirmationSent = null;

    #[AllowedInSandbox]
    public ?DateTime $dateConfirmed = null;

    public ?DateTime $dateUnsubscribed = null;

    public static function displayName(): string
    {
        return Craft::t('newsletter', 'Subscriber');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('newsletter', 'subscriber');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('newsletter', 'Subscribers');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('newsletter', 'subscribers');
    }

    public static function refHandle(): ?string
    {
        return 'subscriber';
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_SUBSCRIBED => ['label' => Craft::t('newsletter', 'Subscribed'), 'color' => 'green'],
            self::STATUS_PENDING => ['label' => Craft::t('newsletter', 'Waiting to confirm'), 'color' => 'orange'],
            self::STATUS_UNSUBSCRIBED => ['label' => Craft::t('newsletter', 'Unsubscribed'), 'color' => 'gray'],
        ];
    }

    public static function find(): SubscriberQuery
    {
        return new SubscriberQuery(static::class);
    }

    public static function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function getStatus(): ?string
    {
        return $this->subscriberStatus;
    }

    public function isSubscribed(): bool
    {
        return $this->subscriberStatus === self::STATUS_SUBSCRIBED;
    }

    public function isPending(): bool
    {
        return $this->subscriberStatus === self::STATUS_PENDING;
    }

    public function getConfirmUrl(): string
    {
        return Plugin::getInstance()->subscribers->confirmUrl($this);
    }

    public function getUnsubscribeUrl(): string
    {
        return Plugin::getInstance()->subscribers->unsubscribeUrl($this);
    }

    public function getUiLabel(): string
    {
        return $this->email;
    }

    // Saving.

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['email', 'token'], 'required'];
        $rules[] = [['email'], 'email'];
        $rules[] = [['email'], 'string', 'max' => 254];
        $rules[] = [['name'], 'string', 'max' => 255];
        $rules[] = [['subscriberStatus'], 'in', 'range' => array_keys(self::statuses())];

        return $rules;
    }

    public function beforeValidate(): bool
    {
        $this->email = self::normaliseEmail($this->email);

        return parent::beforeValidate();
    }

    public function afterSave(bool $isNew): void
    {
        $columns = [
            'email' => self::normaliseEmail($this->email),
            'name' => $this->name !== null && trim($this->name) !== '' ? trim($this->name) : null,
            'subscriberStatus' => $this->subscriberStatus,
            'subscriberSiteId' => $this->subscriberSiteId,
            'consent' => $this->consent,
            'sourceUrl' => $this->sourceUrl !== null ? mb_substr($this->sourceUrl, 0, 500) : null,
            'token' => $this->token,
            'dateConfirmationSent' => Db::prepareDateForDb($this->dateConfirmationSent),
            'dateConfirmed' => Db::prepareDateForDb($this->dateConfirmed),
            'dateUnsubscribed' => Db::prepareDateForDb($this->dateUnsubscribed),
        ];

        if ($isNew) {
            Db::insert(Install::SUBSCRIBERS, ['id' => $this->id] + $columns);
        } else {
            Db::update(Install::SUBSCRIBERS, $columns, ['id' => $this->id]);
        }

        parent::afterSave($isNew);
    }

    // The control panel.

    public function canView(User $user): bool
    {
        return parent::canView($user) || $user->can(Plugin::PERMISSION_VIEW);
    }

    public function canSave(User $user): bool
    {
        return false;
    }

    public function canDelete(User $user): bool
    {
        return parent::canDelete($user) || $user->can(Plugin::PERMISSION_DELETE);
    }

    public function canDuplicate(User $user): bool
    {
        return false;
    }

    protected function metadata(): array
    {
        $site = $this->subscriberSiteId ? Craft::$app->getSites()->getSiteById($this->subscriberSiteId) : null;
        $format = fn (?DateTime $date) => $date ? Craft::$app->getFormatter()->asDatetime($date, 'short') : '—';

        return [
            Craft::t('newsletter', 'Name') => Html::encode($this->name ?? '—'),
            Craft::t('newsletter', 'Site') => Html::encode($site?->getName() ?? '—'),
            Craft::t('newsletter', 'Signed up') => $format($this->dateCreated),
            Craft::t('newsletter', 'Confirmed') => $format($this->dateConfirmed),
            Craft::t('newsletter', 'Unsubscribed') => $format($this->dateUnsubscribed),
            Craft::t('newsletter', 'Agreed to') => Html::encode($this->consent ?? '—'),
            Craft::t('newsletter', 'Form page') => $this->sourceUrl ? Html::a(Html::encode($this->sourceUrl), $this->sourceUrl, ['target' => '_blank', 'rel' => 'noopener']) : '—',
        ];
    }

    protected static function defineSources(string $context): array
    {
        return [
            [
                'key' => '*',
                'label' => Craft::t('newsletter', 'All'),
                'criteria' => ['status' => null],
                'defaultSort' => ['dateCreated', 'desc'],
            ],
            [
                'key' => self::STATUS_SUBSCRIBED,
                'label' => Craft::t('newsletter', 'Subscribed'),
                'criteria' => ['status' => self::STATUS_SUBSCRIBED],
                'defaultSort' => ['dateConfirmed', 'desc'],
            ],
            [
                'key' => self::STATUS_PENDING,
                'label' => Craft::t('newsletter', 'Waiting to confirm'),
                'criteria' => ['status' => self::STATUS_PENDING],
                'defaultSort' => ['dateCreated', 'desc'],
            ],
            [
                'key' => self::STATUS_UNSUBSCRIBED,
                'label' => Craft::t('newsletter', 'Unsubscribed'),
                'criteria' => ['status' => self::STATUS_UNSUBSCRIBED],
                'defaultSort' => ['dateUnsubscribed', 'desc'],
            ],
        ];
    }

    protected static function defineActions(string $source): array
    {
        return [Delete::class];
    }

    protected static function includeSetStatusAction(): bool
    {
        return false;
    }

    protected static function defineExporters(string $source): array
    {
        return [SubscribersCsv::class, ...parent::defineExporters($source)];
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['email', 'name'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'email' => Craft::t('newsletter', 'Email'),
            'name' => Craft::t('newsletter', 'Name'),
            [
                'label' => Craft::t('newsletter', 'Signed up'),
                'orderBy' => 'elements.dateCreated',
                'attribute' => 'dateCreated',
                'defaultDir' => 'desc',
            ],
            [
                'label' => Craft::t('newsletter', 'Confirmed'),
                'orderBy' => 'newsletter_subscribers.dateConfirmed',
                'attribute' => 'dateConfirmed',
                'defaultDir' => 'desc',
            ],
            [
                'label' => Craft::t('newsletter', 'Unsubscribed'),
                'orderBy' => 'newsletter_subscribers.dateUnsubscribed',
                'attribute' => 'dateUnsubscribed',
                'defaultDir' => 'desc',
            ],
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'name' => ['label' => Craft::t('newsletter', 'Name')],
            'subscriberSite' => ['label' => Craft::t('newsletter', 'Site')],
            'dateCreated' => ['label' => Craft::t('newsletter', 'Signed up')],
            'dateConfirmed' => ['label' => Craft::t('newsletter', 'Confirmed')],
            'dateUnsubscribed' => ['label' => Craft::t('newsletter', 'Unsubscribed')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return match ($source) {
            self::STATUS_UNSUBSCRIBED => ['name', 'dateConfirmed', 'dateUnsubscribed'],
            self::STATUS_PENDING => ['name', 'dateCreated'],
            default => ['name', 'dateCreated', 'dateConfirmed'],
        };
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'name' => Html::encode($this->name ?? ''),
            'subscriberSite' => Html::encode(($this->subscriberSiteId ? Craft::$app->getSites()->getSiteById($this->subscriberSiteId)?->getName() : null) ?? ''),
            'dateConfirmed', 'dateUnsubscribed' => $this->$attribute ? Html::tag('span', Craft::$app->getFormatter()->asDatetime($this->$attribute, 'short'), ['title' => Craft::$app->getFormatter()->asDatetime($this->$attribute, 'long')]) : '',
            default => parent::attributeHtml($attribute),
        };
    }
}
