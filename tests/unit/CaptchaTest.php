<?php

namespace nineteenninetyfour\newsletter\tests\unit;

use Craft;
use craft\web\View;
use NineteenNinetyFour\Captcha\Captcha;
use NineteenNinetyFour\Captcha\Http\Client;
use nineteenninetyfour\newsletter\http\CaptchaClient;

class CaptchaTest extends TestCase
{
    protected FakeCaptchaClient $http;

    protected function _before(): void
    {
        parent::_before();

        $this->settings(['captcha' => 'turnstile', 'captchaSiteKey' => 'site-key', 'captchaSecretKey' => '$NEWSLETTER_TEST_SECRET']);
        $_SERVER['NEWSLETTER_TEST_SECRET'] = 'secret-key';
        putenv('NEWSLETTER_TEST_SECRET=secret-key');
        $this->http = new FakeCaptchaClient;
        Craft::$container->set(CaptchaClient::class, fn () => $this->http);
    }

    protected function _after(): void
    {
        unset($_SERVER['NEWSLETTER_TEST_SECRET']);
        putenv('NEWSLETTER_TEST_SECRET');

        parent::_after();
    }

    public function test_the_form_has_the_widget_before_its_button(): void
    {
        $html = Craft::$app->getView()->renderTemplate('signup', [], View::TEMPLATE_MODE_SITE);

        $this->assertStringContainsString('class="cf-turnstile" data-captcha data-sitekey="site-key"', $html);
        $this->assertLessThan(strpos($html, '<button'), strpos($html, 'cf-turnstile'));
        $this->assertStringContainsString('class="cf-turnstile"', Craft::$app->getView()->renderString('{{ craft.newsletter.captcha }}'));
    }

    public function test_without_keys_there_is_no_captcha(): void
    {
        $this->settings(['captchaSecretKey' => '']);

        $this->assertStringNotContainsString('data-captcha', Craft::$app->getView()->renderTemplate('signup', [], View::TEMPLATE_MODE_SITE));
        $this->assertTrue($this->json($this->signUp())['success']);
        $this->assertSame([], $this->http->requests);
    }

    public function test_a_key_set_to_true_or_false_is_no_key(): void
    {
        $_SERVER['NEWSLETTER_TEST_SECRET'] = 'false';
        putenv('NEWSLETTER_TEST_SECRET=false');

        $this->assertTrue($this->json($this->signUp())['success']);
        $this->assertSame([], $this->http->requests);
    }

    public function test_a_passed_check_signs_them_up(): void
    {
        $this->http->replies = [['success' => true]];

        $this->assertTrue($this->json($this->signUp(extra: ['cf-turnstile-response' => 'token']))['success']);
        $this->assertNotNull($this->subscriber());

        $this->assertSame('https://challenges.cloudflare.com/turnstile/v0/siteverify', $this->http->requests[0]['url']);
        $this->assertSame('secret-key', $this->http->requests[0]['form']['secret']);
        $this->assertSame('token', $this->http->requests[0]['form']['response']);
    }

    public function test_a_failed_missing_or_unreachable_check_does_not(): void
    {
        $this->http->replies = [['success' => false], null];

        foreach (['token', 'token', null] as $token) {
            $json = $this->json($this->signUp(extra: array_filter(['cf-turnstile-response' => $token])));
            $this->assertFalse($json['success']);
            $this->assertSame(['We couldn’t check you’re not a robot. Please try again.'], $json['errors']['captcha']);
        }

        $this->assertNull($this->subscriber());
        // The one without a token never reached the provider.
        $this->assertCount(2, $this->http->requests);
    }

    public function test_a_form_with_mistakes_is_not_checked(): void
    {
        $json = $this->json($this->signUp('not an email', ['cf-turnstile-response' => 'token']));

        $this->assertArrayNotHasKey('captcha', $json['errors']);
        $this->assertSame([], $this->http->requests);
    }

    public function test_recaptcha_v3_tokens_must_be_for_this_form(): void
    {
        $this->settings(['captcha' => 'recaptcha_v3']);
        $this->http->replies = [['success' => true, 'score' => 0.9, 'action' => 'login'], ['success' => true, 'score' => 0.9, 'action' => 'newsletter']];

        $this->assertStringContainsString('{action:"newsletter"}', Craft::$app->getView()->renderTemplate('signup', [], View::TEMPLATE_MODE_SITE));
        $this->assertFalse($this->json($this->signUp(extra: ['g-recaptcha-response' => 'a']))['success']);
        $this->assertTrue($this->json($this->signUp(extra: ['g-recaptcha-response' => 'b']))['success']);
    }

    public function test_a_captcha_of_your_own(): void
    {
        $this->settings(['captcha' => OwnCaptcha::class]);

        $this->assertStringContainsString('<span data-captcha>own</span>', Craft::$app->getView()->renderTemplate('signup', [], View::TEMPLATE_MODE_SITE));
        $this->assertFalse($this->json($this->signUp(extra: ['own' => 'no']))['success']);
        $this->assertTrue($this->json($this->signUp(extra: ['own' => 'yes']))['success']);
    }
}

class FakeCaptchaClient implements Client
{
    /** @var array<int, array{url: string, form: array<string, scalar|null>}> */
    public array $requests = [];

    /** @var array<int, array<mixed>|null> */
    public array $replies = [];

    public function postForm(string $url, array $form): ?array
    {
        $this->requests[] = ['url' => $url, 'form' => $form];

        return array_shift($this->replies);
    }
}

class OwnCaptcha implements Captcha
{
    public function widget(): string
    {
        return '<span data-captcha>own</span>';
    }

    public function verify(?string $token, ?string $ip = null): bool
    {
        return $token === 'yes';
    }

    public function field(): string
    {
        return 'own';
    }
}
