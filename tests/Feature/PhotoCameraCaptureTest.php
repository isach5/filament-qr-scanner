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

it('asks for the camera exactly once per open', function () {
    // Same fix the scanner got: probing for permission and then enumerating
    // meant three getUserMedia calls, and iOS Safari answers each with a prompt.
    $html = Blade::render('<x-photo-camera-capture />');

    expect($html)
        ->not->toContain('requestPermission')
        ->toContain("video: deviceId ? { deviceId: { exact: deviceId } } : { facingMode: 'environment' }")
        ->toContain('async startStream(deviceId = null)')
        ->toContain('applyRememberedCamera');
});

it('names the lenses through the picker instead of showing raw labels', function () {
    $html = Blade::render('<x-photo-camera-capture />');

    expect($html)
        ->toContain('camera-picker')
        ->toContain('EmuniqCameraPicker.describe(devices, this.cameraNames)')
        ->toContain('EmuniqCameraPicker.resolveActive(')
        ->not->toContain('this.cameras[this.cameras.length - 1]');
});

it('switches camera through a select and keeps the close button reachable', function () {
    $html = Blade::render('<x-photo-camera-capture />');

    expect($html)
        ->toContain('<select')
        ->toContain('x-model="cameraId"')
        ->toContain('switchCamera($event.target.value)')
        ->toContain('sticky-footer');
});

it('maps camera failures to the translated explanations', function () {
    $html = Blade::render('<x-photo-camera-capture />');

    expect($html)
        ->toContain('describeCameraError')
        ->toContain(__('filament-qr-scanner::photo.error_denied_short'))
        ->toContain(__('filament-qr-scanner::photo.error_in_use'));
});
