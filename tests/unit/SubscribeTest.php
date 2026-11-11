<?php

namespace nineteenninetyfour\newsletter\tests\unit;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use nineteenninetyfour\newsletter\elements\Subscriber;
use nineteenninetyfour\newsletter\events\SubscriberEvent;
use nineteenninetyfour\newsletter\migrations\Install;
use nineteenninetyfour\newsletter\Plugin;
use nineteenninetyfour\newsletter\services\Subscribers;
use yii\base\Event;

class SubscribeTest extends TestCase
{
    public function test_signing_up_waits_for_the_link_in_the_email(): void
    {
        $data = $this->json($this->signUp(' Reader@Example.com '));

        $this->assertTrue($data['success']);
        $this->assertSame('Thanks! Check your inbox for an email to confirm your subscription.', $data['message']);

        $subscriber = $this->subscriber();
        $this->assertNotNull($subscriber);
        $this->assertSame('reader@example.com', $subscriber->email);
        $this->assertSame(Subscriber::STATUS_PENDING, $subscriber->subscriberStatus);
        $this->assertSame('Email me the newsletter. I can unsubscribe at any time.', $subscriber->consent);
        $this->assertSame(Craft::$app->getSites()->getPrimarySite()->id, $subscriber->subscriberSiteId);
        $this->assertNotNull($subscriber->dateConfirmationSent);

        // Not on the list until they confirm.
        $this->assertSame(0, (int) Subscriber::find()->count());

        $this->tester->seeEmailIsSent(1);
        $email = $this->tester->grabLastSentEmail();
        $this->assertArrayHasKey('reader@example.com', $email->getTo());
        $this->assertSame('Please confirm your subscription', $email->getSubject());
        $this->assertStringContainsString('actions/newsletter/subscribe/confirm', $email->toString());
    }

    public function test_the_links_leave_craft_s_own_query_parameters_alone(): void
    {
        // Craft answers 400 to any request whose "token" isn't one of its own.
        $subscriber = $this->subscribed();
        $reserved = Craft::$app->getConfig()->getGeneral()->tokenParam;

        foreach ([$subscriber->getConfirmUrl(), $subscriber->getUnsubscribeUrl()] as $url) {
            $this->assertArrayNotHasKey($reserved, $this->query($url));
            $this->assertArrayHasKey('code', $this->query($url));
        }
    }

    public function test_the_link_opens_a_page_whose_button_confirms(): void
    {
        $subscriber = $this->pending();
        $query = $this->query($subscriber->getConfirmUrl());

        // Opening it (as a mail app's link checker does) changes nothing.
        $html = $this->html($this->action('newsletter/subscribe/confirm', $query, 'GET', json: false));
        $this->assertStringContainsString('Send the newsletter to reader@example.com?', $html);
        $this->assertStringContainsString('Yes, subscribe me', $html);
        $this->assertSame(Subscriber::STATUS_PENDING, $this->subscriber()->subscriberStatus);

        // The button does.
        $html = $this->html($this->action('newsletter/subscribe/confirm', $query, 'POST', json: false));
        $this->assertStringContainsString('You’re subscribed', $html);

        $subscriber = $this->subscriber();
        $this->assertSame(Subscriber::STATUS_SUBSCRIBED, $subscriber->subscriberStatus);
        $this->assertNotNull($subscriber->dateConfirmed);
        $this->assertSame(1, (int) Subscriber::find()->count());

        $html = $this->html($this->action('newsletter/subscribe/confirm', $query, 'GET', json: false));
        $this->assertStringContainsString('That’s already confirmed', $html);
    }

    public function test_a_changed_or_expired_link_does_not_confirm(): void
    {
        $subscriber = $this->pending();
        $query = $this->query($subscriber->getConfirmUrl());

        foreach ([
            ['signature' => str_repeat('a', 64)] + $query,
            ['expires' => (string) ($query['expires'] + 3600)] + $query,
            ['code' => str_repeat('b', 32)] + $query,
        ] as $changed) {
            $response = $this->action('newsletter/subscribe/confirm', $changed, 'POST', json: false);
            $this->assertSame(403, $response->getStatusCode());
            $this->assertStringContainsString('This link has expired', $this->html($response));
        }

        $expired = $this->query($this->plugin()->subscribers->confirmUrl($subscriber, time() - 1));
        $this->assertSame(403, $this->action('newsletter/subscribe/confirm', $expired, 'POST', json: false)->getStatusCode());

        $this->assertSame(Subscriber::STATUS_PENDING, $this->subscriber()->subscriberStatus);
    }

    public function test_confirming_can_land_on_a_page_of_the_site(): void
    {
        $this->settings(['confirmedUrl' => '/newsletter/thanks']);
        $query = $this->query($this->pending()->getConfirmUrl());

        $response = $this->action('newsletter/subscribe/confirm', $query, 'POST', json: false);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/newsletter/thanks', $response->getHeaders()->get('Location'));
        $this->assertStringContainsString('reader@example.com is on the list', (string) Craft::$app->getSession()->getFlash('newsletter'));
    }

    public function test_signing_up_again_once_subscribed_sends_nothing_and_says_the_same(): void
    {
        $this->subscribed();
        $this->tester->seeEmailIsSent(1);

        $data = $this->json($this->signUp('READER@example.com'));

        $this->assertTrue($data['success']);
        $this->assertSame('Thanks! Check your inbox for an email to confirm your subscription.', $data['message']);
        $this->tester->seeEmailIsSent(1);
        $this->assertSame(Subscriber::STATUS_SUBSCRIBED, $this->subscriber()->subscriberStatus);
        $this->assertSame(1, (int) Subscriber::find()->status(null)->count());
    }

    public function test_the_form_s_mistakes_are_handed_back(): void
    {
        $response = $this->signUp('not an email');
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Please enter a valid email address.', $this->json($response)['errors']['email'][0]);

        $response = $this->action('newsletter/subscribe', body: ['email' => 'reader@example.com']);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertArrayHasKey('consent', $this->json($response)['errors']);

        // Without JavaScript: back to the template, with the form.
        $this->assertNull($this->signUp('nope', json: false));
        $form = Craft::$app->getUrlManager()->getRouteParams()['newsletterForm'];
        $this->assertSame('nope', $form->email);
        $this->assertTrue($form->hasErrors('email'));

        $this->assertNull($this->subscriber());
        $this->tester->seeEmailIsSent(0);
    }

    public function test_no_tick_box_when_there_s_no_consent_wording(): void
    {
        $this->settings(['consentText' => '']);

        $this->assertTrue($this->json($this->action('newsletter/subscribe', body: ['email' => 'reader@example.com']))['success']);
        $this->assertNull($this->subscriber()->consent);
    }

    public function test_a_name_can_be_asked_for_and_required(): void
    {
        $this->signUp('reader@example.com', ['name' => 'Ada Reader']);
        $this->assertNull($this->subscriber()->name, 'Not kept unless asked for.');

        $this->settings(['askName' => true]);
        $this->signUp('other@example.com', ['name' => '  Ada Reader ']);
        $this->assertSame('Ada Reader', $this->subscriber('other@example.com')->name);

        $this->settings(['requireName' => true]);
        $response = $this->signUp('third@example.com');
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Please enter your name.', $this->json($response)['errors']['name'][0]);
    }

    public function test_bots_that_fill_in_the_hidden_field_are_told_it_worked(): void
    {
        $data = $this->json($this->signUp('bot@example.com', ['website' => 'https://spam.example']));

        $this->assertTrue($data['success']);
        $this->assertNull($this->subscriber('bot@example.com'));
        $this->tester->seeEmailIsSent(0);
    }

    public function test_one_visitor_can_only_sign_up_so_often(): void
    {
        $this->settings(['throttle' => 2]);

        $this->assertSame(200, $this->signUp('one@example.com')->getStatusCode());
        $this->assertSame(200, $this->signUp('two@example.com')->getStatusCode());
        $response = $this->signUp('three@example.com');

        $this->assertSame(429, $response->getStatusCode());
        $this->assertNull($this->subscriber('three@example.com'));
    }

    public function test_one_address_gets_only_so_many_confirmation_emails(): void
    {
        foreach (range(1, 5) as $i) {
            $this->signUp('reader@example.com');
        }

        $this->tester->seeEmailIsSent(3);
    }

    public function test_unsubscribing_from_the_link(): void
    {
        $subscriber = $this->subscribed();
        $query = $this->query($subscriber->getUnsubscribeUrl());

        $html = $this->html($this->action('newsletter/subscribe/unsubscribe', $query, 'GET', json: false));
        $this->assertStringContainsString('Stop sending the newsletter to reader@example.com?', $html);
        $this->assertSame(Subscriber::STATUS_SUBSCRIBED, $this->subscriber()->subscriberStatus);

        $html = $this->html($this->action('newsletter/subscribe/unsubscribe', $query, 'POST', json: false));
        $this->assertStringContainsString('You’re unsubscribed', $html);

        $subscriber = $this->subscriber();
        $this->assertSame(Subscriber::STATUS_UNSUBSCRIBED, $subscriber->subscriberStatus);
        $this->assertNotNull($subscriber->dateUnsubscribed);
        $this->assertSame(0, (int) Subscriber::find()->count());

        $response = $this->action('newsletter/subscribe/unsubscribe', ['code' => str_repeat('x', 32)], 'GET', json: false);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_mail_apps_one_click_unsubscribe(): void
    {
        $query = $this->query($this->subscribed()->getUnsubscribeUrl());

        $response = $this->action('newsletter/subscribe/unsubscribe', $query, 'POST', ['List-Unsubscribe' => 'One-Click'], json: false);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', (string) $response->data);
        $this->assertSame(Subscriber::STATUS_UNSUBSCRIBED, $this->subscriber()->subscriberStatus);
    }

    public function test_signing_up_again_after_unsubscribing_needs_confirming_again(): void
    {
        $subscriber = $this->subscribed();
        $this->plugin()->subscribers->unsubscribe($subscriber);

        $this->signUp();

        $subscriber = $this->subscriber();
        $this->assertSame(Subscriber::STATUS_PENDING, $subscriber->subscriberStatus);
        $this->tester->seeEmailIsSent(2);

        $this->plugin()->subscribers->confirm($subscriber);
        $this->assertNull($this->subscriber()->dateUnsubscribed);
        $this->assertSame(1, (int) Subscriber::find()->count());
    }

    public function test_the_team_can_be_told_about_new_subscribers(): void
    {
        $this->settings(['notify' => ['team@dogear.test']]);

        $this->subscribed('reader@example.com', 'Ada Reader');

        $this->tester->seeEmailIsSent(2);
        $email = $this->tester->grabLastSentEmail();
        $this->assertArrayHasKey('team@dogear.test', $email->getTo());
        $this->assertSame('New newsletter subscriber: reader@example.com', $email->getSubject());
    }

    public function test_a_sign_up_can_be_turned_away_by_a_listener(): void
    {
        $handler = function (SubscriberEvent $event) {
            $event->isValid = ! str_ends_with($event->subscriber->email, '@blocked.example');
        };
        Event::on(Subscribers::class, Subscribers::EVENT_BEFORE_SUBSCRIBE, $handler);

        try {
            $this->assertTrue($this->json($this->signUp('someone@blocked.example'))['success']);
            $this->assertNull($this->subscriber('someone@blocked.example'));
            $this->tester->seeEmailIsSent(0);
        } finally {
            Event::off(Subscribers::class, Subscribers::EVENT_BEFORE_SUBSCRIBE, $handler);
        }
    }

    public function test_old_unconfirmed_sign_ups_are_deleted(): void
    {
        $old = $this->pending('old@example.com');
        $recent = $this->pending('recent@example.com');
        $kept = $this->subscribed('kept@example.com');

        $again = $this->pending('again@example.com');

        foreach ([$old, $kept, $again] as $subscriber) {
            Db::update('{{%elements}}', ['dateCreated' => Db::prepareDateForDb(new DateTime('-40 days')), 'dateUpdated' => Db::prepareDateForDb(new DateTime('-40 days'))], ['id' => $subscriber->id]);
        }

        // Signing up again starts the wait again.
        $this->signUp('again@example.com');

        $this->assertSame(1, $this->plugin()->subscribers->prune());

        $this->assertNull($this->subscriber('old@example.com'));
        $this->assertFalse((new Query)->from(Install::SUBSCRIBERS)->where(['id' => $old->id])->exists());
        $this->assertNotNull($this->subscriber('recent@example.com'));
        $this->assertNotNull($this->subscriber('kept@example.com'));
        $this->assertNotNull($this->subscriber('again@example.com'));

        $this->settings(['pruneDays' => 0]);
        $this->assertSame(0, $this->plugin()->subscribers->prune());
        unset($recent);
    }

    public function test_the_emails_render_in_craft_s_sandbox(): void
    {
        // System messages are rendered sandboxed: an element's own
        // properties aren't readable there unless allowed.
        $subscriber = $this->pending('reader@example.com', 'Ada Reader');
        $view = Craft::$app->getView();

        foreach ([Plugin::MESSAGE_CONFIRM, Plugin::MESSAGE_NOTIFY] as $key) {
            $message = Craft::$app->getSystemMessages()->getMessage($key);
            $body = $view->renderSandboxedString($message->body, ['subscriber' => $subscriber, 'email' => $subscriber->email, 'name' => $subscriber->name, 'confirmUrl' => 'https://x.test', 'hours' => 72, 'siteName' => 'Dog-ear Books', 'count' => 1, 'listUrl' => 'https://x.test']);
            $this->assertStringContainsString('reader@example.com', $body);
        }

        // Sites that edit the wording can use the subscriber too.
        $this->assertSame('reader@example.com Ada Reader pending', $view->renderSandboxedString('{{ subscriber.email }} {{ subscriber.name }} {{ subscriber.status }}', ['subscriber' => $subscriber]));
    }

    public function test_the_form_page_is_only_a_link_when_it_s_a_web_address(): void
    {
        // It comes from the visitor's Referer header.
        $subscriber = $this->plugin()->subscribers->subscribe('reader@example.com', sourceUrl: 'javascript:alert(document.cookie)');
        $subscriber = $this->subscriber();
        $html = implode(' ', (fn () => $this->metadata())->call($subscriber));

        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringContainsString('javascript:alert(document.cookie)', $html);

        $this->plugin()->subscribers->subscribe('other@example.com', sourceUrl: 'https://www.dogear.test/books');
        $html = implode(' ', (fn () => $this->metadata())->call($this->subscriber('other@example.com')));
        $this->assertStringContainsString('href="https://www.dogear.test/books"', $html);
    }
}
