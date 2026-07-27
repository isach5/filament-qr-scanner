<?php

namespace Emuniq\FilamentQrScanner\Tests;

use Emuniq\FilamentQrScanner\QrScannerServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\SupportServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

/**
 * Boots a minimal Filament panel with the plugin installed so the Blade
 * components render the same way they do inside a real panel.
 */
class TestCase extends OrchestraTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Blade caches compiled output by source hash, not by which app
        // compiled it. Without this, a suite that boots a different host app
        // (see AppOwnedComponentTestCase) would reuse the other suite's
        // compiled component and assert against the wrong markup.
        $this->artisan('view:clear');
    }

    protected function getPackageProviders($app): array
    {
        return [
            \BladeUI\Icons\BladeIconsServiceProvider::class,
            \BladeUI\Heroicons\BladeHeroiconsServiceProvider::class,
            \RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider::class,
            LivewireServiceProvider::class,
            SupportServiceProvider::class,
            FilamentServiceProvider::class,
            // Filament submodules the Panel needs to boot:
            \Filament\Actions\ActionsServiceProvider::class,
            \Filament\Forms\FormsServiceProvider::class,
            \Filament\Notifications\NotificationsServiceProvider::class,
            \Filament\Schemas\SchemasServiceProvider::class,
            \Filament\Tables\TablesServiceProvider::class,
            \Filament\Widgets\WidgetsServiceProvider::class,
            QrScannerServiceProvider::class,
            TestPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }
}

/**
 * Minimal panel. The plugin auto-registers itself through
 * Panel::configureUsing(), so nothing is wired here on purpose — that the
 * panel boots at all is part of what the suite asserts.
 */
class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('testing')
            ->path('testing');
    }
}
