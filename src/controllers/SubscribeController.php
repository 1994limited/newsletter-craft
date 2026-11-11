<?php

namespace nineteenninetyfour\newsletter\controllers;

use Craft;
use craft\web\Controller;
use craft\web\View;
use nineteenninetyfour\newsletter\elements\Subscriber;
use nineteenninetyfour\newsletter\models\SubscribeForm;
use nineteenninetyfour\newsletter\Plugin;
use nineteenninetyfour\newsletter\services\SubscriberException;
use yii\web\Response;

/**
 * What visitors do: sign up, follow the link in the confirmation email,
 * and unsubscribe. The form answers JSON when asked for it, or redirects
 * with a flash message ("newsletter").
 *
 * The links open a page with a button, as mail apps and virus checkers
 * open links in emails by themselves: only the button (a POST) does
 * anything. The token in the link stands in for a CSRF token.
 */
class SubscribeController extends Controller
{
    protected array|bool|int $allowAnonymous = ['index', 'confirm', 'unsubscribe'];

    public function beforeAction($action): bool
    {
        // Mail apps' one-click unsubscribe (RFC 8058) can't send a CSRF
        // token, and the confirm page's button needn't: both carry a secret.
        if (in_array($action->id, ['confirm', 'unsubscribe'], true)) {
            $this->enableCsrfValidation = false;
        }

        return parent::beforeAction($action);
    }

    /**
     * newsletter/subscribe: the sign-up form.
     */
    public function actionIndex(): ?Response
    {
        $this->requirePostRequest();
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $request = $this->request;

        // The same answer whether the address was new, already on the list,
        // or the form looked like a bot's: nothing in it says who's
        // subscribed.
        $thanks = Craft::t('newsletter', 'Thanks! Check your inbox for an email to confirm your subscription.');

        $honeypot = $request->getBodyParam($settings->honeypot);

        if (is_string($honeypot) && trim($honeypot) !== '') {
            return $this->succeeded($thanks);
        }

        if ($this->limited($settings->throttle)) {
            $form = new SubscribeForm;
            $form->addError('email', Craft::t('newsletter', 'That’s a lot at once. Please wait a minute and try again.'));
            $this->response->setStatusCode(429);

            return $this->failed($form);
        }

        $form = new SubscribeForm([
            'email' => $this->text('email', 254),
            'name' => $settings->askName ? $this->text('name', 255) : '',
            'consent' => (bool) $request->getBodyParam('consent'),
        ]);

        if (! $form->validate()) {
            return $this->failed($form);
        }

        try {
            $plugin->subscribers->subscribe(
                $form->email,
                $form->name !== '' ? $form->name : null,
                Craft::$app->getSites()->getCurrentSite()->id,
                trim($settings->consentText) !== '' ? trim($settings->consentText) : null,
                $request->getReferrer(),
            );
        } catch (SubscriberException $e) {
            Craft::warning('Newsletter couldn’t save a sign-up: '.$e->getMessage(), Plugin::HANDLE);
            $form->addError('email', Craft::t('newsletter', 'Please enter a valid email address.'));

            return $this->failed($form);
        }

        return $this->succeeded($thanks);
    }

    /**
     * newsletter/subscribe/confirm: the link in the confirmation email.
     */
    public function actionConfirm(): Response
    {
        $service = Plugin::getInstance()->subscribers;
        $token = (string) $this->request->getParam('code', '');
        $expires = (int) $this->request->getParam('expires', 0);
        $signature = (string) $this->request->getParam('signature', '');
        $subscriber = $service->findByToken($token);

        if (! $subscriber || ! $service->confirmLinkValid($subscriber, $expires, $signature)) {
            return $this->page(
                Craft::t('newsletter', 'This link has expired'),
                Craft::t('newsletter', 'Please sign up again, and we’ll send you a new link.'),
                status: 403,
            );
        }

        if ($subscriber->isSubscribed()) {
            return $this->page(Craft::t('newsletter', 'You’re subscribed'), Craft::t('newsletter', 'That’s already confirmed: {email} is on the list.', ['email' => $subscriber->email]));
        }

        if (! $subscriber->isPending()) {
            return $this->page(
                Craft::t('newsletter', 'This link has expired'),
                Craft::t('newsletter', 'Please sign up again, and we’ll send you a new link.'),
                status: 403,
            );
        }

        if (! $this->request->getIsPost()) {
            return $this->page(
                Craft::t('newsletter', 'Confirm your subscription'),
                Craft::t('newsletter', 'Send the newsletter to {email}?', ['email' => $subscriber->email]),
                [
                    'button' => Craft::t('newsletter', 'Yes, subscribe me'),
                    'params' => ['code' => $token, 'expires' => $expires, 'signature' => $signature],
                ],
            );
        }

        $service->confirm($subscriber);

        return $this->landing(
            Plugin::getInstance()->getSettings()->confirmedUrl,
            Craft::t('newsletter', 'You’re subscribed'),
            Craft::t('newsletter', 'Thanks for confirming. {email} is on the list.', ['email' => $subscriber->email]),
        );
    }

    /**
     * newsletter/subscribe/unsubscribe: each subscriber's own link (it's in
     * the CSV, for the emails you send).
     */
    public function actionUnsubscribe(): Response
    {
        $service = Plugin::getInstance()->subscribers;
        $token = (string) $this->request->getParam('code', '');
        $subscriber = $service->findByToken($token);

        if (! $subscriber) {
            return $this->page(
                Craft::t('newsletter', 'Unsubscribe'),
                Craft::t('newsletter', 'This link isn’t right. Please use the unsubscribe link from one of our emails.'),
                status: 404,
            );
        }

        if (! $this->request->getIsPost()) {
            if ($subscriber->subscriberStatus === Subscriber::STATUS_UNSUBSCRIBED) {
                return $this->page(Craft::t('newsletter', 'You’re unsubscribed'), Craft::t('newsletter', '{email} won’t get the newsletter.', ['email' => $subscriber->email]));
            }

            return $this->page(
                Craft::t('newsletter', 'Unsubscribe'),
                Craft::t('newsletter', 'Stop sending the newsletter to {email}?', ['email' => $subscriber->email]),
                [
                    'button' => Craft::t('newsletter', 'Unsubscribe'),
                    'params' => ['code' => $token],
                ],
            );
        }

        $service->unsubscribe($subscriber);

        // Mail apps' one-click unsubscribe just needs a 200.
        if ($this->request->getBodyParam('List-Unsubscribe') === 'One-Click') {
            return $this->asRaw('');
        }

        return $this->landing(
            Plugin::getInstance()->getSettings()->unsubscribedUrl,
            Craft::t('newsletter', 'You’re unsubscribed'),
            Craft::t('newsletter', '{email} won’t get the newsletter any more. Sorry to see you go.', ['email' => $subscriber->email]),
        );
    }

    /**
     * The site's own page, if one's set, with the message flashed; or the
     * plugin's.
     */
    protected function landing(string $url, string $title, string $message): Response
    {
        if (trim($url) !== '') {
            Craft::$app->getSession()->setFlash(Plugin::HANDLE, $message);

            return $this->redirect(trim($url));
        }

        return $this->page($title, $message);
    }

    /**
     * @param  array{button: string, params: array<string, mixed>}|null  $form
     */
    protected function page(string $title, string $message, ?array $form = null, int $status = 200): Response
    {
        $site = Craft::$app->getSites()->getCurrentSite();

        $this->response->setStatusCode($status);
        $this->response->getHeaders()
            ->set('Cache-Control', 'no-store, private')
            ->set('X-Robots-Tag', 'noindex');

        return $this->renderTemplate('newsletter/_page', [
            'title' => $title,
            'message' => $message,
            'form' => $form,
            'siteName' => $site->getName(),
            'siteUrl' => $site->getBaseUrl() ?? '/',
            'language' => $site->language,
        ], View::TEMPLATE_MODE_CP);
    }

    protected function text(string $name, int $max): string
    {
        $value = $this->request->getBodyParam($name);

        return is_string($value) ? mb_substr(trim($value), 0, $max) : '';
    }

    /**
     * Whether this visitor has signed up too often in the last minute:
     * counted by their address, hashed, in the cache.
     */
    protected function limited(int $max): bool
    {
        $key = 'newsletter:limit:'.hash_hmac('sha256', (string) $this->request->getUserIP(), (string) Craft::$app->getConfig()->getGeneral()->securityKey);
        $cache = Craft::$app->getCache();
        $hits = $cache->get($key);
        $hits = is_array($hits) && $hits['until'] > time() ? $hits : ['count' => 0, 'until' => time() + 60];
        $hits['count']++;
        $cache->set($key, $hits, max(1, $hits['until'] - time()));

        return $hits['count'] > max(1, $max);
    }

    protected function succeeded(string $message): ?Response
    {
        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true, 'message' => $message]);
        }

        Craft::$app->getSession()->setFlash(Plugin::HANDLE, $message);

        return $this->redirectToPostedUrl(null, $this->request->getReferrer() ?? '/');
    }

    protected function failed(SubscribeForm $form): ?Response
    {
        if ($this->request->getAcceptsJson()) {
            $status = $this->response->getStatusCode() === 429 ? 429 : 400;
            $first = array_values($form->getFirstErrors())[0] ?? null;

            return $this->asJson(['success' => false, 'message' => $first, 'errors' => $form->getErrors()])->setStatusCode($status);
        }

        Craft::$app->getUrlManager()->setRouteParams(['newsletterForm' => $form]);

        return null;
    }
}
