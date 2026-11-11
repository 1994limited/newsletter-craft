<?php

namespace nineteenninetyfour\newsletter\tests\unit;

use Craft;
use craft\helpers\FileHelper;
use craft\web\View;

/**
 * A site's own templates/_newsletter/form.twig and page.twig take the place
 * of the plugin's.
 */
class OverridesTest extends TestCase
{
    protected string $folder;

    protected function _before(): void
    {
        parent::_before();

        $this->folder = Craft::$app->getPath()->getSiteTemplatesPath().'/_newsletter';
        FileHelper::createDirectory($this->folder);
    }

    protected function _after(): void
    {
        FileHelper::removeDirectory($this->folder);

        parent::_after();
    }

    public function test_the_site_s_own_form(): void
    {
        file_put_contents($this->folder.'/form.twig', '<form class="ours">{{ actionInput("newsletter/subscribe") }}{{ options.button ?? "Join" }}</form>');

        $html = Craft::$app->getView()->renderTemplate('signup', [], View::TEMPLATE_MODE_SITE);

        $this->assertStringContainsString('<form class="ours">', $html);
        $this->assertStringContainsString('Join', $html);
        $this->assertStringNotContainsString('name="consent"', $html);
    }

    public function test_the_site_s_own_page(): void
    {
        file_put_contents($this->folder.'/page.twig', '<main class="ours"><h1>{{ title }}</h1><p>{{ message }}</p>{% if form %}<form method="post">{% for name, value in form.params %}<input type="hidden" name="{{ name }}" value="{{ value }}">{% endfor %}<button>{{ form.button }}</button></form>{% endif %}</main>');

        $query = $this->query($this->pending()->getConfirmUrl());
        $html = $this->html($this->action('newsletter/subscribe/confirm', $query, 'GET', json: false));

        $this->assertStringContainsString('<main class="ours"><h1>Confirm your subscription</h1>', $html);
        $this->assertStringContainsString('<button>Yes, subscribe me</button>', $html);
        $this->assertStringContainsString('name="code"', $html);
    }
}
