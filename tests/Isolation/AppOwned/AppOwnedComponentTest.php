<?php

use Illuminate\Support\Facades\Blade;

it('does not claim an alias the host app already owns', function () {
    // The fixture app ships resources/views/components/qr-camera-scanner.blade.php.
    // A Blade alias outranks an anonymous component, so claiming the name would
    // leave the app editing a file that no longer renders — the exact failure
    // this guard exists to prevent.
    expect(Blade::getClassComponentAliases())->not->toHaveKey('qr-camera-scanner');

    expect(Blade::render('<x-qr-camera-scanner />'))->toContain('app-owned-scanner');
});

it('still registers the aliases the app has not taken', function () {
    expect(Blade::getClassComponentAliases())->toHaveKey('photo-camera-capture');
});

it('keeps the namespaced component reachable regardless', function () {
    $html = Blade::render('<x-filament-qr-scanner::qr-camera-scanner />');

    expect($html)
        ->toContain('qr-scanner-modal-')
        ->not->toContain('app-owned-scanner');
});
