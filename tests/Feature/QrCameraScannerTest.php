<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\View\ViewException;

it('wires the whole page-level rejection protocol', function () {
    $html = Blade::render('<x-qr-camera-scanner />');

    // Host pages dispatch these three window events. A missing listener is a
    // silent failure at runtime: the operator loses the station reset and the
    // camera never reopens after acknowledging a rejection.
    expect($html)
        ->toContain('x-on:scan-rejected.window')
        ->toContain('x-on:scanner-reset.window')
        ->toContain('x-on:scan-resume.window')
        ->toContain('x-on:close-modal.window');

    expect($html)
        ->toContain('handleRejection')
        ->toContain('resetSession')
        ->toContain('resumeAfterRejection');
});

it('tracks the last-seen time per code, not just the last code', function () {
    // Two labels alternating in the camera frame must not read as duplicates.
    expect(Blade::render('<x-qr-camera-scanner />'))
        ->toContain('scannedCodeTimes')
        ->toContain('duplicateWindow');
});

it('binds the given livewire property and action', function () {
    $html = Blade::render('<x-qr-camera-scanner wire-model="badgeCode" wire-action="loginWithBadge" />');

    expect($html)
        ->toContain("\$wire.set('badgeCode', text)")
        ->toContain("\$wire.call('loginWithBadge')");
});

it('passes a scalar action argument through to the livewire call', function () {
    $html = Blade::render('<x-qr-camera-scanner wire-action="processOpScan" :wire-action-args="123" />');

    expect($html)->toContain("\$wire.call('processOpScan', ...JSON.parse('[123]'))");
});

it('spreads a list of action arguments', function () {
    $html = Blade::render('<x-qr-camera-scanner wire-action="processScan" :wire-action-args="[7, \'cortes\']" />');

    expect($html)->toContain("\$wire.call('processScan', ...JSON.parse('[7,\\u0022cortes\\u0022]'))");
});

it('calls the action with no arguments when none are given', function () {
    $html = Blade::render('<x-qr-camera-scanner wire-action="processScan" />');

    expect($html)
        ->toContain("\$wire.call('processScan');")
        ->not->toContain("\$wire.call('processScan', ");
});

it('closes the modal after each scan only in lookup mode', function () {
    expect(Blade::render('<x-qr-camera-scanner :close-on-scan="true" />'))
        ->toContain('Single-shot / lookup mode');

    expect(Blade::render('<x-qr-camera-scanner />'))
        ->not->toContain('Single-shot / lookup mode');
});

it('honours the button and heading overrides', function () {
    $html = Blade::render('<x-qr-camera-scanner button-label="Escanear gafete" button-color="gray" modal-heading="Escanea tu gafete" />');

    expect($html)
        ->toContain('Escanear gafete')
        ->toContain('Escanea tu gafete');
});

it('falls back to the translated labels', function () {
    $html = Blade::render('<x-qr-camera-scanner />');

    expect($html)
        ->toContain(__('filament-qr-scanner::scanner.button'))
        ->toContain(__('filament-qr-scanner::scanner.modal_heading'));
});

it('takes the camera tuning from config and lets props win', function () {
    config()->set('filament-qr-scanner.scanner.fps', 4);
    config()->set('filament-qr-scanner.scanner.qrbox', 180);

    expect(Blade::render('<x-qr-camera-scanner />'))
        ->toContain('fps: 4')
        ->toContain('width: 180, height: 180');

    expect(Blade::render('<x-qr-camera-scanner :fps="25" :qrbox-size="320" />'))
        ->toContain('fps: 25')
        ->toContain('width: 320, height: 320');
});

/**
 * Read back the symbology list the component handed to Alpine. Js::from emits
 * either a bare [] or JSON.parse('…') with quotes escaped as \u0022, so decode
 * rather than string-match the encoding.
 *
 * @return list<string>
 */
function renderedFormats(string $blade): array
{
    preg_match('/^\s*formats: (.+),$/m', Blade::render($blade), $matches);

    $expression = $matches[1] ?? null;

    if ($expression === null || $expression === '[]') {
        return [];
    }

    preg_match("/JSON\.parse\('(.*)'\)/", $expression, $json);

    return json_decode(str_replace('\\u0022', '"', $json[1]), true);
}

it('decodes every symbology unless told otherwise', function () {
    expect(renderedFormats('<x-qr-camera-scanner />'))->toBe([]);
    expect(Blade::render('<x-qr-camera-scanner />'))->toContain('this.formats.length');
});

it('narrows the decoder to the requested symbologies', function () {
    expect(renderedFormats('<x-qr-camera-scanner :formats="[\'QR_CODE\', \'code_128\']" />'))
        ->toBe(['QR_CODE', 'CODE_128']);
});

it('takes the symbology list from config too', function () {
    config()->set('filament-qr-scanner.scanner.formats', ['QR_CODE']);

    expect(renderedFormats('<x-qr-camera-scanner />'))->toBe(['QR_CODE']);
});

it('lets a prop override the configured symbologies', function () {
    config()->set('filament-qr-scanner.scanner.formats', ['QR_CODE']);

    expect(renderedFormats('<x-qr-camera-scanner :formats="[\'EAN_13\']" />'))->toBe(['EAN_13']);
});

it('refuses to render with an unknown symbology', function () {
    // Blade wraps it, but the message has to survive: a typo here otherwise
    // reaches the decoder as undefined and the camera decodes nothing.
    expect(fn () => Blade::render('<x-qr-camera-scanner :formats="[\'NOT_A_FORMAT\']" />'))
        ->toThrow(ViewException::class, 'Unknown barcode format [NOT_A_FORMAT]');
});

it('serves the bundled html5-qrcode copy by default', function () {
    config()->set('filament-qr-scanner.scanner.script_url', null);

    expect(Blade::render('<x-qr-camera-scanner />'))->toContain('html5-qrcode');
});

it('lets an operator point the library at another url', function () {
    config()->set('filament-qr-scanner.scanner.script_url', 'https://cdn.example.test/h5q.js');

    expect(Blade::render('<x-qr-camera-scanner />'))
        ->toContain('cdn.example.test')
        ->toContain('x-load-js');
});

it('merges extra attributes onto the wrapper', function () {
    expect(Blade::render('<x-qr-camera-scanner class="text-center" />'))->toContain('text-center');
});

it('gives every instance its own modal id', function () {
    preg_match('/qr-scanner-modal-\w+/', Blade::render('<x-qr-camera-scanner />'), $first);
    preg_match('/qr-scanner-modal-\w+/', Blade::render('<x-qr-camera-scanner />'), $second);

    expect($first[0])->not->toBe($second[0]);
});
