<?php

namespace nineteenninetyfour\newsletter\events;

use nineteenninetyfour\newsletter\elements\Subscriber;
use yii\base\Event;

/**
 * A subscriber signed up, confirmed or unsubscribed. On "before sign-up",
 * set $isValid to false to turn the sign-up away (quietly: the visitor is
 * told it worked).
 */
class SubscriberEvent extends Event
{
    public Subscriber $subscriber;

    public bool $isValid = true;
}
