<?php

namespace Emuniq\FilamentQrScanner;

use Filament\Panel;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class QrScannerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'filament-qr-scanner');
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'filament-qr-scanner');

        Blade::component('filament-qr-scanner::components.qr-camera-scanner', 'qr-camera-scanner');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../resources/lang' => $this->app->langPath('vendor/filament-qr-scanner'),
            ], 'filament-qr-scanner-translations');
        }

        // Auto-register plugin to all panels
        Panel::configureUsing(function (Panel $panel) {
            $panel->plugin(QrScannerPlugin::make());
        });
    }
}
