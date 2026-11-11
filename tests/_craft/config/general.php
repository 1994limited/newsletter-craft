<?php

return [
    'devMode' => true,
    'allowAdminChanges' => true,
    // As on a real site: system messages are rendered in Twig's sandbox.
    'enableTwigSandbox' => true,
    'aliases' => [
        '@webroot' => dirname(__DIR__).'/web',
        '@web' => 'https://www.dogear.test',
    ],
];
