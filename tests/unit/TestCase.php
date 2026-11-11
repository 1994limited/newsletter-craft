<?php

namespace nineteenninetyfour\newsletter\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\elements\User;
use craft\web\TemplateResponseBehavior;
use nineteenninetyfour\newsletter\elements\Subscriber;
use nineteenninetyfour\newsletter\Plugin;
use UnitTester;
use yii\web\Response;

abstract class TestCase extends Unit
{
    protected UnitTester $tester;

    protected function _before(): void
    {
        parent::_before();

        Craft::$app->getCache()->flush();
        Craft::$app->getRequest()->enableCsrfValidation = false;
        $this->plugin()->controllerNamespace = 'nineteenninetyfour\\newsletter\\controllers';

        $this->settings([
            'confirmHours' => 72,
            'askName' => false,
            'requireName' => false,
            'consentText' => 'Email me the newsletter. I can unsubscribe at any time.',
            'honeypot' => 'website',
            'throttle' => 100,
            'perEmail' => 3,
            'confirmedUrl' => '',
            'unsubscribedUrl' => '',
            'pruneDays' => 30,
            'notify' => [],
        ]);
    }

    protected function _after(): void
    {
        Craft::$app->getUser()->setIdentity(null);
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_ACCEPT']);

        parent::_after();
    }

    protected function plugin(): Plugin
    {
        return Plugin::getInstance();
    }

    /**
     * Settings for this test, without saving them.
     *
     * @param  array<string, mixed>  $values
     */
    protected function settings(array $values): void
    {
        $settings = $this->plugin()->getSettings();

        foreach ($values as $key => $value) {
            $settings->$key = $value;
        }
    }

    /**
     * Run a controller action as a web request would.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     */
    protected function action(string $route, array $query = [], string $method = 'POST', array $body = [], bool $json = true): ?Response
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['HTTP_ACCEPT'] = $json ? 'application/json' : 'text/html';
        $request = Craft::$app->getRequest();
        $request->setBodyParams($body);
        $request->setQueryParams($query);
        $request->getHeaders()->set('Accept', $json ? 'application/json' : 'text/html');
        $request->setAcceptableContentTypes(null);
        Craft::$app->getResponse()->clear();
        Craft::$app->getResponse()->detachBehavior(TemplateResponseBehavior::NAME);

        $result = Craft::$app->runAction($route, $query);

        return $result instanceof Response ? $result : null;
    }

    /**
     * The sign-up form, posted.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function signUp(string $email = 'reader@example.com', array $extra = [], bool $json = true): ?Response
    {
        return $this->action('newsletter/subscribe', body: ['email' => $email, 'consent' => '1', ...$extra], json: $json);
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(?Response $response): array
    {
        $this->assertNotNull($response);

        return is_array($response->data) ? $response->data : (json_decode((string) $response->content, true) ?? []);
    }

    /**
     * A page an action rendered with renderTemplate().
     */
    protected function html(?Response $response): string
    {
        $this->assertNotNull($response);
        $behavior = $response->getBehavior(TemplateResponseBehavior::NAME);
        $this->assertInstanceOf(TemplateResponseBehavior::class, $behavior);

        return Craft::$app->getView()->renderTemplate($behavior->template, $behavior->variables, $behavior->templateMode);
    }

    protected function subscriber(string $email = 'reader@example.com'): ?Subscriber
    {
        return Subscriber::find()->email($email)->status(null)->one();
    }

    /**
     * Someone who has signed up but not confirmed yet.
     */
    protected function pending(string $email = 'reader@example.com', ?string $name = null): Subscriber
    {
        return $this->plugin()->subscribers->subscribe($email, $name, null, 'Email me the newsletter.');
    }

    /**
     * Someone on the list.
     */
    protected function subscribed(string $email = 'reader@example.com', ?string $name = null): Subscriber
    {
        $subscriber = $this->pending($email, $name);
        $this->plugin()->subscribers->confirm($subscriber);

        return $subscriber;
    }

    /**
     * The query string of a link, as an array.
     *
     * @return array<string, string>
     */
    protected function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

        return $params;
    }

    /**
     * A user with these permissions (an admin with none given), signed in.
     *
     * @param  array<int, string>|null  $permissions
     */
    protected function actingAs(?array $permissions = null): User
    {
        $user = new User(['username' => 'editor'.bin2hex(random_bytes(2)), 'email' => bin2hex(random_bytes(3)).'@example.com', 'admin' => $permissions === null]);
        $this->assertTrue(Craft::$app->getElements()->saveElement($user), json_encode($user->getErrors()));

        if ($permissions !== null) {
            Craft::$app->getUserPermissions()->saveUserPermissions($user->id, ['accesscp', ...$permissions]);
        }

        Craft::$app->getUser()->setIdentity($user);

        return $user;
    }
}
