<?php

namespace Emuniq\FilamentQrScanner;

use Filament\Panel;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class QrScannerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/filament-qr-scanner.php', 'filament-qr-scanner');

        // Has to happen in register(): panel providers build their Panel in
        // their own register(), and Panel::make() runs the configureUsing
        // callbacks right then. Registering this in boot() would be too late
        // for any panel whose provider was registered first.
        if (config('filament-qr-scanner.auto_register_panels', true)) {
            Panel::configureUsing(function (Panel $panel): void {
                $panel->plugin(QrScannerPlugin::make());
            });
        }
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'filament-qr-scanner');
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'filament-qr-scanner');

        FilamentAsset::register([
            // loadedOnRequest() keeps the 370 KB library out of every panel
            // page load — the scanner component pulls it in with x-load-js the
            // first time an operator opens the camera.
            Js::make('html5-qrcode', __DIR__ . '/../resources/dist/html5-qrcode.min.js')
                ->loadedOnRequest(),
            Js::make('scan-session', __DIR__ . '/../resources/dist/scan-session.js')
                ->loadedOnRequest(),
            Js::make('camera-picker', __DIR__ . '/../resources/dist/camera-picker.js')
                ->loadedOnRequest(),
        ], 'emuniq/filament-qr-scanner');

        $this->registerBladeComponents();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/filament-qr-scanner.php' => config_path('filament-qr-scanner.php'),
            ], 'filament-qr-scanner-config');

            $this->publishes([
                __DIR__ . '/../resources/lang' => $this->app->langPath('vendor/filament-qr-scanner'),
            ], 'filament-qr-scanner-translations');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/filament-qr-scanner'),
            ], 'filament-qr-scanner-views');
        }
    }

    /**
     * Register the short aliases (<x-qr-camera-scanner />) that make the
     * components drop-in anywhere Blade renders.
     *
     * An alias is skipped when the host app already owns that name — either as
     * an alias someone registered first, or as its own anonymous component in
     * resources/views/components. A Blade alias silently outranks an app's
     * anonymous component, so claiming a taken name would leave the app editing
     * a file that no longer renders. The namespaced form
     * (<x-filament-qr-scanner::qr-camera-scanner />) always works.
     */
    protected function registerBladeComponents(): void
    {
        $aliases = config('filament-qr-scanner.components', []);

        if (! is_array($aliases)) {
            return;
        }

        $views = $this->app->make(ViewFactory::class);
        $taken = Blade::getClassComponentAliases();

        foreach ($aliases as $view => $alias) {
            if (! is_string($alias) || $alias === '') {
                continue;
            }

            if (isset($taken[$alias]) || $views->exists("components.{$alias}")) {
                continue;
            }

            Blade::component("filament-qr-scanner::components.{$view}", $alias);
        }
    }
}
