<?php

namespace Emuniq\FilamentQrScanner\Tests;

/**
 * Same boot as TestCase, but with a host app that already ships its own
 * resources/views/components/qr-camera-scanner.blade.php. Used to prove the
 * plugin steps aside instead of silently shadowing the app's component.
 */
class AppOwnedComponentTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('view.paths', array_merge(
            [__DIR__ . '/fixtures/app-views'],
            $app['config']->get('view.paths', []),
        ));
    }
}
