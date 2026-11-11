<?php

namespace nineteenninetyfour\newsletter\tests\unit;

use Craft;
use craft\web\View;
use nineteenninetyfour\newsletter\models\SubscribeForm;

class TwigTest extends TestCase
{
    protected function render(string $twig): string
    {
        return Craft::$app->getView()->renderString($twig, [], View::TEMPLATE_MODE_SITE);
    }

    public function test_the_ready_made_form(): void
    {
        $html = $this->render('{{ craft.newsletter.form({ button: "Sign me up", redirect: "/thanks" }) }}');

        $this->assertStringContainsString('name="action" value="newsletter/subscribe"', $html);
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('name="consent"', $html);
        $this->assertStringContainsString('Email me the newsletter. I can unsubscribe at any time.', $html);
        $this->assertStringContainsString('name="website"', $html);
        $this->assertStringContainsString('name="redirect"', $html);
        $this->assertStringContainsString('Sign me up', $html);
        $this->assertStringNotContainsString('name="name"', $html);

        $this->settings(['askName' => true, 'consentText' => '']);
        $html = $this->render('{{ craft.newsletter.form() }}');
        $this->assertStringContainsString('name="name"', $html);
        $this->assertStringNotContainsString('name="consent"', $html);
    }

    public function test_the_form_shows_mistakes_and_keeps_what_was_typed(): void
    {
        $form = new SubscribeForm(['email' => '<b>oops']);
        $form->validate();
        Craft::$app->getUrlManager()->setRouteParams(['newsletterForm' => $form]);

        // From a site template, as Twig escapes them.
        $html = Craft::$app->getView()->renderTemplate('signup', [], View::TEMPLATE_MODE_SITE);

        $this->assertStringContainsString('value="&lt;b&gt;oops"', $html);
        $this->assertStringNotContainsString('<b>oops', $html);
        $this->assertStringContainsString('Please enter a valid email address.', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);

        // And from a string template, which Craft renders without escaping.
        $this->assertStringContainsString('value="&lt;b&gt;oops"', $this->render('{{ craft.newsletter.form() }}'));
    }

    public function test_the_success_message(): void
    {
        Craft::$app->getSession()->setFlash('newsletter', 'Thanks!');

        $this->assertStringContainsString('Thanks!', $this->render('{{ craft.newsletter.form() }}'));
    }

    public function test_counts_and_queries(): void
    {
        $this->subscribed('ada@example.com');
        $this->pending('waiting@example.com');

        $this->assertSame('1', $this->render('{{ craft.newsletter.count() }}'));
        $this->assertSame('ada@example.com', $this->render('{% for s in craft.newsletter.subscribers() %}{{ s.email }}{% endfor %}'));
        $this->assertSame('2', $this->render('{{ craft.newsletter.subscribers({ status: null }).count() }}'));
    }
}
