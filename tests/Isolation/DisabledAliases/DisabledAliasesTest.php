<?php

use Illuminate\Support\Facades\Blade;

it('registers no alias when the config disables them', function () {
    // Documented in the README: set an alias to null to skip it. null and ''
    // both mean "leave this name alone".
    expect(Blade::getClassComponentAliases())
        ->not->toHaveKey('qr-camera-scanner')
        ->not->toHaveKey('photo-camera-capture');
});

it('still renders both components through their namespace', function () {
    expect(Blade::render('<x-filament-qr-scanner::qr-camera-scanner />'))
        ->toContain('qr-scanner-modal-');

    expect(Blade::render('<x-filament-qr-scanner::photo-camera-capture />'))
        ->toContain('photo-camera-modal-');
});
