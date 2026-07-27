<?php

namespace Emuniq\FilamentQrScanner\Tests;

/**
 * Host app that switched the short aliases off in config — the documented way
 * to keep the component names free for something else.
 */
class DisabledAliasesTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('filament-qr-scanner.components', [
            'qr-camera-scanner' => null,
            'photo-camera-capture' => '',
        ]);
    }
}
