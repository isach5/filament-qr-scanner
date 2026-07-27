<?php

namespace Emuniq\FilamentQrScanner\Tests;

/**
 * Host app whose components config is not a list at all — a stale published
 * config file, a bad env override. Booting has to survive it.
 */
class BrokenConfigTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('filament-qr-scanner.components', 'qr-camera-scanner');
    }
}
