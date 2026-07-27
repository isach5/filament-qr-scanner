<?php

use Illuminate\Support\Facades\Blade;

it('writes the captured data url into the given livewire property', function () {
    $html = Blade::render('<x-photo-camera-capture wire-model="damagePhotoUpload" />');

    // The third argument keeps the set deferred: an immediate roundtrip would
    // re-render the parent action modal and wipe text the operator typed.
    expect($html)->toContain("\$wire.set('damagePhotoUpload', this.captured, false)");
});

it('takes quality and downscaling from config and lets props win', function () {
    config()->set('filament-qr-scanner.photos.jpeg_quality', 0.5);
    config()->set('filament-qr-scanner.photos.max_dimension', 640);

    expect(Blade::render('<x-photo-camera-capture />'))
        ->toContain('const MAX = 640')
        ->toContain("toDataURL('image/jpeg', 0.5)");

    expect(Blade::render('<x-photo-camera-capture :jpeg-quality="0.9" :max-dimension="2048" />'))
        ->toContain('const MAX = 2048')
        ->toContain("toDataURL('image/jpeg', 0.9)");
});

it('honours the button and heading overrides', function () {
    $html = Blade::render('<x-photo-camera-capture button-label="Tomar foto del daño" modal-heading="Evidencia" />');

    expect($html)
        ->toContain('Tomar foto del daño')
        ->toContain('Evidencia');
});

it('falls back to the translated labels', function () {
    $html = Blade::render('<x-photo-camera-capture />');

    expect($html)
        ->toContain(__('filament-qr-scanner::photo.button'))
        ->toContain(__('filament-qr-scanner::photo.capture'))
        ->toContain(__('filament-qr-scanner::photo.use_photo'));
});

it('stops the camera stream when the modal closes', function () {
    expect(Blade::render('<x-photo-camera-capture />'))
        ->toContain('x-on:close-modal.window')
        ->toContain('stopStream()');
});

it('gives every instance its own modal, video and canvas ids', function () {
    $html = Blade::render('<x-photo-camera-capture /><x-photo-camera-capture />');

    preg_match_all('/photo-camera-modal-\w+/', $html, $modals);

    expect(array_unique($modals[0]))->toHaveCount(2);
});
