<?php

use Emuniq\FilamentQrScanner\QrScannerPlugin;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;

it('registers the short aliases for both components', function () {
    $aliases = Blade::getClassComponentAliases();

    expect($aliases)
        ->toHaveKey('qr-camera-scanner')
        ->toHaveKey('photo-camera-capture');

    expect($aliases['qr-camera-scanner'])->toBe('filament-qr-scanner::components.qr-camera-scanner');
    expect($aliases['photo-camera-capture'])->toBe('filament-qr-scanner::components.photo-camera-capture');
});

it('exposes the components under the package view namespace too', function () {
    expect(View::exists('filament-qr-scanner::components.qr-camera-scanner'))->toBeTrue();
    expect(View::exists('filament-qr-scanner::components.photo-camera-capture'))->toBeTrue();
});

it('auto-registers the plugin on every panel', function () {
    $panel = filament()->getPanel('testing');

    expect($panel->hasPlugin('qr-scanner'))->toBeTrue();
    expect($panel->getPlugin('qr-scanner'))->toBeInstanceOf(QrScannerPlugin::class);
});

it('ships a config file with the documented defaults', function () {
    expect(config('filament-qr-scanner.auto_register_panels'))->toBeTrue();
    expect(config('filament-qr-scanner.scanner.fps'))->toBe(10);
    expect(config('filament-qr-scanner.scanner.qrbox'))->toBe(250);
    expect(config('filament-qr-scanner.photos.disk'))->toBe('public');
});
