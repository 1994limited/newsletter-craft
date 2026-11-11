<?php

namespace nineteenninetyfour\newsletter\http;

use Craft;
use NineteenNinetyFour\Captcha\Http\Client;
use Throwable;

/**
 * The captcha check's one request, made with Craft's Guzzle client.
 */
class CaptchaClient implements Client
{
    public function postForm(string $url, array $form): ?array
    {
        try {
            $response = Craft::createGuzzleClient(['timeout' => 10])->post($url, ['form_params' => $form, 'http_errors' => false]);
        } catch (Throwable $e) {
            Craft::warning('Newsletter couldn’t check a captcha: '.$e->getMessage(), 'newsletter');

            return null;
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return null;
        }

        $json = json_decode((string) $response->getBody(), true);

        return is_array($json) ? $json : null;
    }
}
