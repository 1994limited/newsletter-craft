<?php

namespace nineteenninetyfour\newsletter\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use nineteenninetyfour\newsletter\elements\Subscriber;
use nineteenninetyfour\newsletter\migrations\Install;

/**
 * Finds subscribers. Only confirmed ones unless asked otherwise, so
 * `craft.newsletter.subscribers()` never lists an address that hasn't said
 * yes.
 *
 * @template TKey of array-key
 * @template TElement of Subscriber
 *
 * @extends ElementQuery<TKey,TElement>
 */
class SubscriberQuery extends ElementQuery
{
    public array|string|null $status = [Subscriber::STATUS_SUBSCRIBED];

    public mixed $email = null;

    public mixed $token = null;

    public mixed $subscriberSiteId = null;

    protected array $defaultOrderBy = ['elements.dateCreated' => SORT_DESC, 'elements.id' => SORT_DESC];

    public function email(mixed $value): static
    {
        $this->email = is_string($value) ? Subscriber::normaliseEmail($value) : $value;

        return $this;
    }

    public function token(mixed $value): static
    {
        $this->token = $value;

        return $this;
    }

    /**
     * The site they signed up on.
     */
    public function subscriberSiteId(mixed $value): static
    {
        $this->subscriberSiteId = $value;

        return $this;
    }

    protected function statusCondition(string $status): mixed
    {
        return match ($status) {
            Subscriber::STATUS_PENDING, Subscriber::STATUS_SUBSCRIBED, Subscriber::STATUS_UNSUBSCRIBED => ['newsletter_subscribers.subscriberStatus' => $status],
            default => parent::statusCondition($status),
        };
    }

    protected function beforePrepare(): bool
    {
        if (! parent::beforePrepare()) {
            return false;
        }

        $this->joinElementTable(Install::SUBSCRIBERS);

        $this->query->addSelect([
            'newsletter_subscribers.email',
            'newsletter_subscribers.name',
            'newsletter_subscribers.subscriberStatus',
            'newsletter_subscribers.subscriberSiteId',
            'newsletter_subscribers.consent',
            'newsletter_subscribers.sourceUrl',
            'newsletter_subscribers.token',
            'newsletter_subscribers.dateConfirmationSent',
            'newsletter_subscribers.dateConfirmed',
            'newsletter_subscribers.dateUnsubscribed',
        ]);

        foreach (['email', 'token', 'subscriberSiteId'] as $param) {
            if ($this->$param !== null) {
                $this->subQuery->andWhere(Db::parseParam('newsletter_subscribers.'.$param, $this->$param));
            }
        }

        return true;
    }
}
