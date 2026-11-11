<?php

namespace nineteenninetyfour\newsletter\services;

use Craft;
use craft\base\Component;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use DateTime;
use nineteenninetyfour\newsletter\elements\db\SubscriberQuery;
use nineteenninetyfour\newsletter\elements\Subscriber;
use nineteenninetyfour\newsletter\events\SubscriberEvent;
use nineteenninetyfour\newsletter\migrations\Install;
use nineteenninetyfour\newsletter\Plugin;
use Throwable;

/**
 * Signing up, confirming and unsubscribing, and the list as rows for a
 * spreadsheet.
 */
class Subscribers extends Component
{
    /** Before a sign-up is saved. Set $isValid to false to turn it away. */
    public const EVENT_BEFORE_SUBSCRIBE = 'beforeSubscribe';

    /** After a sign-up is saved and its confirmation email sent. */
    public const EVENT_AFTER_SUBSCRIBE = 'afterSubscribe';

    /** After someone follows the link in the confirmation email. */
    public const EVENT_AFTER_CONFIRM = 'afterConfirm';

    /** After someone unsubscribes. */
    public const EVENT_AFTER_UNSUBSCRIBE = 'afterUnsubscribe';

    /**
     * Someone asking to be on the list. A new address (or one that had
     * unsubscribed) waits to confirm, and is sent the link; an address
     * that's already subscribed is left as it is and sent nothing.
     */
    public function subscribe(string $email, ?string $name = null, ?int $siteId = null, ?string $consent = null, ?string $sourceUrl = null): Subscriber
    {
        $email = Subscriber::normaliseEmail($email);
        $subscriber = Subscriber::find()->email($email)->status(null)->one() ?? new Subscriber([
            'email' => $email,
            'token' => Craft::$app->getSecurity()->generateRandomString(32),
        ]);

        if ($subscriber->isSubscribed()) {
            if ($name !== null && trim($name) !== '' && ! $subscriber->name) {
                $subscriber->name = trim($name);
                Craft::$app->getElements()->saveElement($subscriber, false);
            }

            return $subscriber;
        }

        $subscriber->subscriberStatus = Subscriber::STATUS_PENDING;
        $subscriber->name = $name !== null && trim($name) !== '' ? trim($name) : $subscriber->name;
        $subscriber->subscriberSiteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
        $subscriber->consent = $consent;
        $subscriber->sourceUrl = $sourceUrl;

        if ($this->hasEventHandlers(self::EVENT_BEFORE_SUBSCRIBE)) {
            $event = new SubscriberEvent(['subscriber' => $subscriber]);
            $this->trigger(self::EVENT_BEFORE_SUBSCRIBE, $event);

            if (! $event->isValid) {
                return $subscriber;
            }
        }

        if (! Craft::$app->getElements()->saveElement($subscriber)) {
            throw new SubscriberException(implode(' ', $subscriber->getFirstErrors()) ?: 'The sign-up couldn’t be saved.');
        }

        $this->sendConfirmation($subscriber);

        if ($this->hasEventHandlers(self::EVENT_AFTER_SUBSCRIBE)) {
            $this->trigger(self::EVENT_AFTER_SUBSCRIBE, new SubscriberEvent(['subscriber' => $subscriber]));
        }

        return $subscriber;
    }

    /**
     * Email the confirmation link, unless the address has had too many
     * lately (so the form can't be used to flood someone's inbox).
     */
    public function sendConfirmation(Subscriber $subscriber): bool
    {
        if (! $subscriber->isPending() || $this->tooManyEmails($subscriber->email)) {
            return false;
        }

        $hours = max(1, Plugin::getInstance()->getSettings()->confirmHours);
        $site = $subscriber->subscriberSiteId ? Craft::$app->getSites()->getSiteById($subscriber->subscriberSiteId) : null;

        try {
            $message = Craft::$app->getMailer()->composeFromKey(Plugin::MESSAGE_CONFIRM, [
                'subscriber' => $subscriber,
                'email' => $subscriber->email,
                'name' => $subscriber->name,
                'confirmUrl' => $this->confirmUrl($subscriber),
                'hours' => $hours,
                'siteName' => $site?->getName() ?? Craft::$app->getSystemName(),
            ])->setTo($subscriber->email);

            // In the language of the site they signed up on.
            $message->siteId = $subscriber->subscriberSiteId;

            if (! $message->send()) {
                Craft::warning('Newsletter couldn’t send a confirmation email.', Plugin::HANDLE);

                return false;
            }
        } catch (Throwable $e) {
            Craft::error('Newsletter couldn’t send a confirmation email: '.$e->getMessage(), Plugin::HANDLE);

            return false;
        }

        $subscriber->dateConfirmationSent = DateTimeHelper::now();
        Db::update(Install::SUBSCRIBERS, ['dateConfirmationSent' => Db::prepareDateForDb($subscriber->dateConfirmationSent)], ['id' => $subscriber->id]);

        return true;
    }

    /**
     * Following the confirmation link. False if there was nothing to
     * confirm (already subscribed, or unsubscribed since).
     */
    public function confirm(Subscriber $subscriber): bool
    {
        if (! $subscriber->isPending()) {
            return false;
        }

        $subscriber->subscriberStatus = Subscriber::STATUS_SUBSCRIBED;
        $subscriber->dateConfirmed = DateTimeHelper::now();
        $subscriber->dateUnsubscribed = null;
        Craft::$app->getElements()->saveElement($subscriber, false);

        if ($this->hasEventHandlers(self::EVENT_AFTER_CONFIRM)) {
            $this->trigger(self::EVENT_AFTER_CONFIRM, new SubscriberEvent(['subscriber' => $subscriber]));
        }

        return true;
    }

    /**
     * False if they weren't on the list.
     */
    public function unsubscribe(Subscriber $subscriber): bool
    {
        if ($subscriber->subscriberStatus === Subscriber::STATUS_UNSUBSCRIBED) {
            return false;
        }

        $subscriber->subscriberStatus = Subscriber::STATUS_UNSUBSCRIBED;
        $subscriber->dateUnsubscribed = DateTimeHelper::now();
        Craft::$app->getElements()->saveElement($subscriber, false);

        if ($this->hasEventHandlers(self::EVENT_AFTER_UNSUBSCRIBE)) {
            $this->trigger(self::EVENT_AFTER_UNSUBSCRIBE, new SubscriberEvent(['subscriber' => $subscriber]));
        }

        return true;
    }

    public function findByToken(string $token): ?Subscriber
    {
        if (! preg_match('/^[A-Za-z0-9_-]{32,64}$/', $token)) {
            return null;
        }

        return Subscriber::find()->token($token)->status(null)->one();
    }

    // Links.

    /**
     * The link in the confirmation email: signed, and good for a few days.
     * Its secret is "code", not "token": Craft keeps "token" for itself.
     */
    public function confirmUrl(Subscriber $subscriber, ?int $expires = null): string
    {
        $expires ??= time() + max(1, Plugin::getInstance()->getSettings()->confirmHours) * 3600;

        return $this->siteActionUrl($subscriber, 'newsletter/subscribe/confirm', [
            'code' => $subscriber->token,
            'expires' => $expires,
            'signature' => $this->signature($subscriber->token, $expires),
        ]);
    }

    /**
     * Whether a confirmation link's expiry and signature are right, and it
     * hasn't expired.
     */
    public function confirmLinkValid(Subscriber $subscriber, int $expires, string $signature): bool
    {
        return $expires >= time() && hash_equals($this->signature($subscriber->token, $expires), $signature);
    }

    /**
     * Their own unsubscribe link: for your newsletter emails (it's in the
     * CSV), it doesn't expire.
     */
    public function unsubscribeUrl(Subscriber $subscriber): string
    {
        return $this->siteActionUrl($subscriber, 'newsletter/subscribe/unsubscribe', ['code' => $subscriber->token]);
    }

    protected function signature(string $token, int $expires): string
    {
        return hash_hmac('sha256', 'newsletter-confirm|'.$token.'|'.$expires, (string) Craft::$app->getConfig()->getGeneral()->securityKey);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function siteActionUrl(Subscriber $subscriber, string $action, array $params): string
    {
        $trigger = Craft::$app->getConfig()->getGeneral()->actionTrigger;
        $siteId = $subscriber->subscriberSiteId && Craft::$app->getSites()->getSiteById($subscriber->subscriberSiteId)
            ? $subscriber->subscriberSiteId
            : Craft::$app->getSites()->getPrimarySite()->id;

        return UrlHelper::siteUrl($trigger.'/'.$action, $params, null, $siteId);
    }

    // Housekeeping.

    /**
     * Delete sign-ups that were never confirmed, after the days in the
     * settings.
     */
    public function prune(): int
    {
        $days = Plugin::getInstance()->getSettings()->pruneDays;

        if ($days < 1) {
            return 0;
        }

        $before = (new DateTime)->modify("-{$days} days");
        $deleted = 0;

        foreach (Subscriber::find()->status(Subscriber::STATUS_PENDING)->dateCreated('< '.Db::prepareDateForDb($before))->each() as $subscriber) {
            /** @var Subscriber $subscriber */
            if (Craft::$app->getElements()->deleteElement($subscriber, true)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    protected function tooManyEmails(string $email): bool
    {
        $max = max(1, Plugin::getInstance()->getSettings()->perEmail);
        $key = 'newsletter:confirm:'.hash('sha256', Subscriber::normaliseEmail($email));
        $cache = Craft::$app->getCache();
        $hits = $cache->get($key);
        $hits = is_array($hits) && $hits['until'] > time() ? $hits : ['count' => 0, 'until' => time() + 3600];

        if ($hits['count'] >= $max) {
            return true;
        }

        $hits['count']++;
        $cache->set($key, $hits, max(1, $hits['until'] - time()));

        return false;
    }

    // The spreadsheet.

    /**
     * @return array<int, string>
     */
    public function csvHeadings(): array
    {
        return [
            Craft::t('newsletter', 'Email'),
            Craft::t('newsletter', 'Name'),
            Craft::t('newsletter', 'Status'),
            Craft::t('newsletter', 'Site'),
            Craft::t('newsletter', 'Signed up'),
            Craft::t('newsletter', 'Confirmed'),
            Craft::t('newsletter', 'Unsubscribed'),
            Craft::t('newsletter', 'Agreed to'),
            Craft::t('newsletter', 'Form page'),
            Craft::t('newsletter', 'Unsubscribe link'),
        ];
    }

    /**
     * One row per subscriber, a few hundred at a time, each cell safe to
     * open in a spreadsheet (nothing that would be read as a formula).
     *
     * @return iterable<int, array<int, string>>
     */
    public function csvRows(SubscriberQuery $query): iterable
    {
        $sites = [];
        $date = fn (?DateTime $value) => $value ? $value->format('Y-m-d H:i:s') : '';

        foreach ($query->each(500) as $subscriber) {
            /** @var Subscriber $subscriber */
            $siteId = $subscriber->subscriberSiteId;
            $sites[$siteId] ??= $siteId ? (Craft::$app->getSites()->getSiteById($siteId)->handle ?? '') : '';

            yield array_map([self::class, 'csvCell'], [
                $subscriber->email,
                $subscriber->name ?? '',
                $subscriber->subscriberStatus,
                $sites[$siteId],
                $date($subscriber->dateCreated),
                $date($subscriber->dateConfirmed),
                $date($subscriber->dateUnsubscribed),
                $subscriber->consent ?? '',
                $subscriber->sourceUrl ?? '',
                $this->unsubscribeUrl($subscriber),
            ]);
        }
    }

    /**
     * A spreadsheet cell that can't be read as a formula.
     */
    public static function csvCell(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
