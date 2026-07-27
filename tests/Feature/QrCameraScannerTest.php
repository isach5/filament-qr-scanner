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

it('delegates every dedup decision to the scan session module', function () {
    // The state machine itself is covered by tests/js/scan-session.test.js;
    // what has to hold here is that the component loads it and routes the
    // three outcomes through it.
    $html = Blade::render('<x-qr-camera-scanner />');

    expect($html)
        ->toContain('scan-session')
        ->toContain('new EmuniqScanSession({ duplicateWindow: 1500 })')
        ->toContain("this.session().evaluate(text, Date.now())")
        ->toContain('this.session().remember(')
        ->toContain('this.session().refresh(')
        ->toContain('this.session().reset()');
});

it('passes the configured duplicate window to the session', function () {
    config()->set('filament-qr-scanner.scanner.duplicate_window', 4000);

    expect(Blade::render('<x-qr-camera-scanner />'))
        ->toContain('new EmuniqScanSession({ duplicateWindow: 4000 })');
});

it('loads both browser scripts on demand', function () {
    expect(Blade::render('<x-qr-camera-scanner />'))
        ->toContain('html5-qrcode')
        ->toContain('scan-session');
});

it('binds the given livewire property and action', function () {
    $html = Blade::render('<x-qr-camera-scanner wire-model="badgeCode" wire-action="loginWithBadge" />');

    expect($html)
        ->toContain("\$wire.set('badgeCode', code)")
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

it('offers torch and zoom only when the running camera has them', function () {
    $html = Blade::render('<x-qr-camera-scanner />');

    // Both are track capabilities, unknown until the camera is running, so the
    // controls have to be gated on what readCameraCapabilities() found.
    expect($html)
        ->toContain('readCameraCapabilities')
        ->toContain('x-show="torchSupported || zoomSupported"')
        ->toContain('toggleTorch()')
        ->toContain('applyZoom(parseFloat($event.target.value))')
        ->toContain(__('filament-qr-scanner::scanner.zoom'));
});

it('remembers the torch and zoom the operator chose', function () {
    expect(Blade::render('<x-qr-camera-scanner />'))
        ->toContain("localStorage.getItem('qr-scanner-torch')")
        ->toContain("localStorage.setItem('qr-scanner-torch'")
        ->toContain("localStorage.getItem('qr-scanner-zoom')")
        ->toContain("localStorage.setItem('qr-scanner-zoom'");
});

it('uses a fixed scan window by default', function () {
    expect(Blade::render('<x-qr-camera-scanner />'))
        ->toContain('qrbox: { width: 250, height: 250 }');
});

it('sizes the scan window against the viewfinder when given a ratio', function () {
    $html = Blade::render('<x-qr-camera-scanner :qrbox-ratio="0.7" />');

    expect($html)
        ->toContain('Math.min(w, h) * 0.7')
        ->not->toContain('qrbox: { width: 250');
});

it('takes the scan window ratio from config too', function () {
    config()->set('filament-qr-scanner.scanner.qrbox_ratio', 0.5);

    expect(Blade::render('<x-qr-camera-scanner />'))->toContain('Math.min(w, h) * 0.5');
});

it('refuses a scan window ratio outside its range', function (float $ratio) {
    expect(fn () => Blade::render("<x-qr-camera-scanner :qrbox-ratio=\"{$ratio}\" />"))
        ->toThrow(ViewException::class, 'qrbox ratio must be greater than 0 and at most 1');
})->with([0.0, -0.5, 1.5, 2.0]);

it('prefers the browser native decoder by default', function () {
    expect(Blade::render('<x-qr-camera-scanner />'))
        ->toContain('useBarCodeDetectorIfSupported: true');
});

it('can be forced onto the javascript decoder', function () {
    config()->set('filament-qr-scanner.scanner.native_decoder', false);

    expect(Blade::render('<x-qr-camera-scanner />'))
        ->toContain('useBarCodeDetectorIfSupported: false');
});

it('announces the last read to assistive technology and respects reduced motion', function () {
    expect(Blade::render('<x-qr-camera-scanner />'))
        ->toContain('aria-live="polite"')
        ->toContain('motion-reduce:transition-none')
        ->toContain(':aria-pressed');
});

it('delegates camera naming and choice to the picker module', function () {
    // devices[devices.length - 1] used to hand an iPhone operator the
    // telephoto, which cannot focus at the distance a label is held.
    $html = Blade::render('<x-qr-camera-scanner />');

    expect($html)
        ->toContain('camera-picker')
        ->toContain('EmuniqCameraPicker.describe(devices, this.cameraNames)')
        ->toContain('EmuniqCameraPicker.pickDefault(')
        ->not->toContain('devices[devices.length - 1]');
});

it('hands the picker a translated name for every lens kind', function () {
    $html = Blade::render('<x-qr-camera-scanner />');

    foreach (['camera_front', 'camera_back', 'camera_wide', 'camera_ultrawide', 'camera_telephoto', 'camera_macro'] as $key) {
        expect($html)->toContain(__("filament-qr-scanner::scanner.{$key}"));
    }
});

it('keeps the camera switcher on one scrollable row', function () {
    // Four lenses wrapped across two rows steal height from the viewfinder for
    // a control the operator touches once.
    expect(Blade::render('<x-qr-camera-scanner />'))
        ->toContain('overflow-x-auto')
        ->not->toContain('flex flex-wrap items-center gap-2');
});

it('closes with a neutral button, not a destructive one', function () {
    $html = Blade::render('<x-qr-camera-scanner />');

    expect($html)->toContain(__('filament-qr-scanner::scanner.close'));

    // The close button must not carry danger styling: nothing is destroyed and
    // red reads as abort on a shop floor.
    preg_match('/<button[^>]*>\s*[^<]*' . preg_quote(__('filament-qr-scanner::scanner.close'), '/') . '/s', $html, $m);
    expect($m[0] ?? '')->not->toContain('danger');
});

it('asks the camera for a square frame by default', function () {
    // Not cosmetic: letting the camera hand over its native landscape frame
    // pushed the whole modal off the side of an iPhone, close button included.
    expect(Blade::render('<x-qr-camera-scanner />'))->toContain('aspectRatio: 1');
});

it('can be pointed at another aspect ratio, or none at all', function () {
    config()->set('filament-qr-scanner.scanner.aspect_ratio', 1.777);
    expect(Blade::render('<x-qr-camera-scanner />'))->toContain('aspectRatio: 1.777');

    config()->set('filament-qr-scanner.scanner.aspect_ratio', null);
    expect(Blade::render('<x-qr-camera-scanner />'))->not->toContain('aspectRatio:');
});

it('never lets the camera preview grow past the modal', function () {
    // The guard that keeps a wide stream from dragging the dialog off screen.
    expect(Blade::render('<x-qr-camera-scanner />'))
        ->toContain('max-width: 100%; min-height: 300px; overflow: hidden;');
});

it('refuses a nonsensical aspect ratio', function () {
    expect(fn () => Blade::render('<x-qr-camera-scanner :aspect-ratio="0" />'))
        ->toThrow(ViewException::class, 'aspect ratio must be greater than 0');
});

it('asks for the camera exactly once per open', function () {
    // Probing for permission and then enumerating cameras meant three separate
    // getUserMedia calls, and iOS Safari treats each one as a reason to ask the
    // operator again. Only start() may request the camera now.
    $html = Blade::render('<x-qr-camera-scanner />');

    expect($html)
        ->not->toContain('requestPermission')
        ->not->toContain('await Html5Qrcode.getCameras()')
        ->toContain('navigator.mediaDevices.enumerateDevices()')
        ->toContain("{ facingMode: 'environment' }");
});

it('falls back to the platform default when no camera is remembered', function () {
    expect(Blade::render('<x-qr-camera-scanner />'))
        ->toContain("const target = this.cameraId || { facingMode: 'environment' }");
});

it('forgets a remembered camera that no longer starts', function () {
    // Otherwise a device id left over from another phone locks the operator out
    // of the scanner for good.
    expect(Blade::render('<x-qr-camera-scanner />'))
        ->toContain("localStorage.removeItem('qr-camera-id')");
});

it('maps camera failures to the translated explanations', function () {
    $html = Blade::render('<x-qr-camera-scanner />');

    expect($html)
        ->toContain('describeCameraError')
        ->toContain(__('filament-qr-scanner::scanner.error_denied'))
        ->toContain(__('filament-qr-scanner::scanner.error_not_found'))
        ->toContain(__('filament-qr-scanner::scanner.error_in_use'));
});
