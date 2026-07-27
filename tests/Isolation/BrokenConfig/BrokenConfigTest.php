<?php

use Illuminate\Support\Facades\Blade;

it('boots without aliases when the components config is not a list', function () {
    // A stale published config or a bad env override should not take the whole
    // panel down on boot; the namespaced components keep working.
    expect(Blade::getClassComponentAliases())->not->toHaveKey('qr-camera-scanner');

    expect(Blade::render('<x-filament-qr-scanner::qr-camera-scanner />'))
        ->toContain('qr-scanner-modal-');
});
