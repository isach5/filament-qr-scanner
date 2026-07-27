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
    config()->set('filament-qr-scanner.scanner.qrbox_ratio', null);

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

it('sizes the scan window against the viewfinder by default', function () {
    // A fixed pixel box comes out as a rectangle the moment the rendered aspect
    // differs from the requested one, which is exactly what Safari does.
    expect(Blade::render('<x-qr-camera-scanner />'))
        ->toContain('Math.min(w, h) * 0.7');
});

it('draws its own square aiming guide and hides the library one', function () {
    // The library sizes its overlay from the frame it asked for rather than the
    // one it got. aspect-ratio:1 is square by construction on every device.
    expect(Blade::render('<x-qr-camera-scanner />'))
        ->toContain('aspect-ratio:1')
        ->toContain('#qr-shaded-region { display: none !important; }');
});

it('can go back to a fixed pixel scan window', function () {
    config()->set('filament-qr-scanner.scanner.qrbox_ratio', null);

    expect(Blade::render('<x-qr-camera-scanner />'))
        ->toContain('qrbox: { width: 250, height: 250 }');
});

it('lets a prop set the scan window ratio', function () {
    $html = Blade::render('<x-qr-camera-scanner :qrbox-ratio="0.5" />');

    expect($html)
        ->toContain('Math.min(w, h) * 0.5')
        ->not->toContain('qrbox: { width: 250');
});

it('takes the scan window ratio from config too', function () {
    config()->set('filament-qr-scanner.scanner.qrbox_ratio', 0.4);

    expect(Blade::render('<x-qr-camera-scanner />'))->toContain('Math.min(w, h) * 0.4');
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
        ->toContain('EmuniqCameraPicker.resolveActive(this.cameras, running, facing)')
        ->not->toContain('devices[devices.length - 1]');
});

it('hands the picker a translated name for every lens kind', function () {
    $html = Blade::render('<x-qr-camera-scanner />');

    foreach (['camera_front', 'camera_back', 'camera_wide', 'camera_ultrawide', 'camera_telephoto', 'camera_macro'] as $key) {
        expect($html)->toContain(__("filament-qr-scanner::scanner.{$key}"));
    }
});

it('switches camera through a select, not a row of pills', function () {
    // A flex row of buttons hands the dialog its full intrinsic width however
    // it is clipped, which pushed the modal off the side of a phone. A select
    // has a width of its own and truncates its own text.
    $html = Blade::render('<x-qr-camera-scanner />');

    expect($html)
        ->toContain('<select')
        ->toContain('x-model="cameraId"')
        ->toContain('switchCamera($event.target.value)')
        ->toContain('min-w-0');
});

it('lets every row inside the modal shrink below its content', function () {
    // min-w-0 is what stops a flex child from forcing the dialog wider.
    expect(Blade::render('<x-qr-camera-scanner />'))
        ->toContain('class="w-full min-w-0 space-y-3"');
});

it('closes with a neutral button, not a destructive one', function () {
    $html = Blade::render('<x-qr-camera-scanner />');

    expect($html)->toContain(__('filament-qr-scanner::scanner.close'));

    // The close button must not carry danger styling: nothing is destroyed and
    // red reads as abort on a shop floor.
    preg_match('/<button[^>]*>\s*[^<]*' . preg_quote(__('filament-qr-scanner::scanner.close'), '/') . '/s', $html, $m);
    expect($m[0] ?? '')->not->toContain('danger');
});

it('takes the camera frame as it comes by default', function () {
    // Forcing a shape makes the browser crop and scale to reach it: a 640x480
    // sensor became 480x480 with resizeMode crop-and-scale, for less picture
    // and a viewfinder 78px taller.
    expect(Blade::render('<x-qr-camera-scanner />'))->not->toContain('aspectRatio:');
});

it('can still be pinned to a fixed aspect ratio', function () {
    config()->set('filament-qr-scanner.scanner.aspect_ratio', 1.777);

    expect(Blade::render('<x-qr-camera-scanner />'))->toContain('aspectRatio: 1.777');
});

it('sizes the aiming guide from the short side of the feed', function () {
    // The feed is normally landscape, so height is the side that has to hold
    // the box — sizing from the width put its corners outside the picture.
    expect(Blade::render('<x-qr-camera-scanner />'))
        ->toContain('height:70%;width:auto;max-width:70%;aspect-ratio:1');
});

it('never lets the camera preview grow past the modal', function () {
    // The guard that keeps a wide stream from dragging the dialog off screen.
    expect(Blade::render('<x-qr-camera-scanner />'))
        ->toContain('max-width: 100%; min-height: 240px; max-height: 52vh; overflow: hidden;');
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

it('forgets a remembered camera that is no longer present', function () {
    expect(Blade::render('<x-qr-camera-scanner />'))
        ->toContain("localStorage.removeItem('qr-camera-id')");
});

it('never opens by asking for a specific device', function () {
    // A deviceId constraint fails with OverconstrainedError the moment the
    // remembered id goes stale — Safari reissues them every session — and a
    // failed getUserMedia is a wasted permission prompt. facingMode always
    // resolves to something.
    expect(Blade::render('<x-qr-camera-scanner />'))
        ->toContain("const target = deviceId || { facingMode: 'environment' }")
        ->toContain('async startCamera(deviceId = null)')
        ->toContain('applyRememberedCamera');
});

it('parks the camera when the modal closes instead of releasing it', function () {
    // Reopening then costs no getUserMedia at all, which on iOS Safari is what
    // an operator experiences as being asked for the camera again and again.
    $html = Blade::render('<x-qr-camera-scanner />');

    expect($html)
        ->toContain('suspendScanning()')
        ->toContain('this.scanner.pause(true)')
        ->toContain('this.scanner.resume()')
        ->toContain('keepAliveMs: 45000');

    // And it is released for real afterwards, so the recording indicator does
    // not sit on for the rest of the shift.
    expect($html)->toContain('setTimeout(() => this.stopScanning(), this.keepAliveMs)');
});

it('can be told to release the camera immediately', function () {
    config()->set('filament-qr-scanner.scanner.keep_alive', 0);

    expect(Blade::render('<x-qr-camera-scanner />'))->toContain('keepAliveMs: 0');
});

it('keeps the close button reachable however tall the body gets', function () {
    expect(Blade::render('<x-qr-camera-scanner />'))
        ->toContain('sticky-footer')
        ->toContain('max-height: 52vh');
});

it('maps camera failures to the translated explanations', function () {
    $html = Blade::render('<x-qr-camera-scanner />');

    expect($html)
        ->toContain('describeCameraError')
        ->toContain(__('filament-qr-scanner::scanner.error_denied'))
        ->toContain(__('filament-qr-scanner::scanner.error_not_found'))
        ->toContain(__('filament-qr-scanner::scanner.error_in_use'));
});

it('keeps the sound toggle in the toolbar and one action in the footer', function () {
    // The footer used to carry two buttons; on a phone the dialog needs the
    // height more than it needs a second row of chrome.
    $html = Blade::render('<x-qr-camera-scanner />');

    expect($html)
        ->toContain('toggleSound()')
        ->toContain(':aria-pressed="soundOn')
        ->toContain('fi-btn fi-size-md  w-full');
});

it('overlays torch and zoom on the feed instead of adding a row', function () {
    expect(Blade::render('<x-qr-camera-scanner />'))
        ->toContain('position:absolute;left:0;right:0;bottom:0')
        ->toContain('toggleTorch()')
        ->toContain('applyZoom(parseFloat($event.target.value))');
});

it('shows torch state with colour, never with changing text', function () {
    // A label that flips between "Luz" and "Luz encendida" resizes the button
    // under the operator's thumb, and the colour already says it.
    $html = Blade::render('<x-qr-camera-scanner />');

    expect($html)
        ->toContain('aria-label="' . __('filament-qr-scanner::scanner.torch') . '"')
        ->toContain(':aria-pressed="torchOn')
        ->toContain("background: torchOn ? '#fbbf24'")
        ->toContain('<span>' . __('filament-qr-scanner::scanner.torch') . '</span>')
        ->not->toContain('torch_on')
        ->not->toContain('torch_off');
});

it('shows the reading count on the viewfinder', function () {
    expect(Blade::render('<x-qr-camera-scanner />'))
        ->toContain('x-show="scanCount > 0"')
        ->toContain(__('filament-qr-scanner::scanner.reading_plural'));
});

it('never emits a raw double quote inside the x-data attribute', function () {
    // x-data is a double-quoted HTML attribute. One stray " anywhere inside it
    // — a comment, a string, a translation — closes the attribute early and the
    // browser renders the rest of the component's javascript as page text. It
    // costs a production page, and nothing else catches it.
    //
    // Parsed the way a browser parses it: the value ends at the FIRST quote
    // after x-data=", not at the one the author meant.
    $html = Blade::render('<x-qr-camera-scanner />');

    $open = strpos($html, ' x-data="');
    expect($open)->not->toBeFalse('x-data attribute not found');

    $open += strlen(' x-data="');
    $value = substr($html, $open, strpos($html, '"', $open) - $open);

    // The last things defined in x-data. If a stray quote truncated it, the
    // browser would never see these.
    expect($value)
        ->toContain('onDetected')
        ->toContain('resetSession')
        ->toContain('resumeAfterRejection');
});

it('keeps every window listener free of raw double quotes too', function () {
    // Same failure mode for the x-on: attributes, which are also double quoted.
    $html = Blade::render('<x-qr-camera-scanner />');

    $listeners = [
        'scan-rejected' => 'handleRejection($event.detail)',
        'scanner-reset' => 'resetSession()',
        'scan-resume' => 'resumeAfterRejection()',
    ];

    foreach ($listeners as $event => $expected) {
        preg_match('/x-on:' . preg_quote($event, '/') . '\.window="([^"]*)"/', $html, $matches);

        expect($matches[1] ?? null)->toBe($expected, "listener for {$event}");
    }
});

it('styles the overlay inline so it does not need the host app tailwind build', function () {
    // The package ships no CSS and the host's Tailwind never scans it, so a
    // class like sr-only or bg-gray-950/60 may simply not exist there — which
    // is how the hidden zoom label ended up as visible black text over the
    // camera feed.
    $html = Blade::render('<x-qr-camera-scanner />');

    expect($html)
        ->toContain('background:linear-gradient(to top,rgba(3,7,18,.85),rgba(3,7,18,0))')
        ->toContain('clip:rect(0,0,0,0)')
        ->toContain('accent-color:#fff')
        ->not->toContain('class="sr-only"');
});

it('binds inline styles as an object so the static ones survive', function () {
    // x-bind:style with a STRING replaces the style attribute outright. That
    // stripped the torch button of its layout and left the icon and its label
    // stacked in a narrow box. An object merges instead.
    expect(Blade::render('<x-qr-camera-scanner />'))->toContain(':style="{');
});

it('draws the guide corners with widths that survive the shorthand', function () {
    // border-width:0 written AFTER the per-side longhands resets them, and the
    // aiming square renders as nothing at all.
    $html = Blade::render('<x-qr-camera-scanner />');

    preg_match_all('/<span style="position:absolute;[^"]*border-width:0;([^"]*)"/', $html, $matches);

    expect($matches[1])->toHaveCount(4);

    foreach ($matches[1] as $corner) {
        expect($corner)->toContain('3px');
    }
});

it('waits for the viewfinder to lay out instead of a fixed delay', function () {
    // The flat 200ms wait was most of the time between the tap and the camera
    // appearing; a modal is usually laid out within one frame.
    $html = Blade::render('<x-qr-camera-scanner />');

    expect($html)
        ->toContain('viewfinderReady')
        ->toContain('el.clientWidth > 0')
        ->not->toContain('setTimeout(r, 200)');
});

it('does not restart the camera when the remembered lens is the one already running', function () {
    // Restarting costs a second camera negotiation, which on a phone is the
    // difference between a quick open and a visibly slow one.
    expect(Blade::render('<x-qr-camera-scanner />'))
        ->toContain('running.kind === wanted.kind');
});

it('says the camera is starting instead of showing a mute black box', function () {
    expect(Blade::render('<x-qr-camera-scanner />'))
        ->toContain(__('filament-qr-scanner::scanner.starting'));
});

it('gives the viewfinder its background inline, not through a utility class', function () {
    // bg-black is a Tailwind utility the host app's build has no reason to have
    // compiled — the package ships no CSS and nothing scans its views. Without
    // it the viewfinder is a transparent hole until the video paints.
    expect(Blade::render('<x-qr-camera-scanner />'))
        ->toContain('background: #030712')
        ->not->toContain('rounded-xl bg-black');
});

it('says it is starting from the moment the modal opens', function () {
    // The camera only starts once the modal is laid out, so keying this off the
    // scanner being active left a mute empty box during the open animation.
    $html = Blade::render('<x-qr-camera-scanner />');

    expect($html)
        ->toContain('x-show="starting && ! videoReady"')
        ->toContain('this.starting = true;');
});

it('asks for continuous autofocus where the device offers it', function () {
    // A label held at arm's length in front of a camera parked on a fixed focus
    // distance never resolves, and the operator ends up waving the phone about.
    expect(Blade::render('<x-qr-camera-scanner />'))
        ->toContain('requestContinuousFocus')
        ->toContain("focusMode: 'continuous'");
});
