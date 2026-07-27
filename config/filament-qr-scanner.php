<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Panel auto-registration
    |--------------------------------------------------------------------------
    |
    | When true the plugin registers itself on every Filament panel through
    | Panel::configureUsing(), so the components are available everywhere with
    | zero setup. Set to false to register QrScannerPlugin::make() by hand on
    | the panels that need it.
    |
    */

    'auto_register_panels' => true,

    /*
    |--------------------------------------------------------------------------
    | Blade component aliases
    |--------------------------------------------------------------------------
    |
    | Global aliases so the components can be dropped anywhere a Blade view is
    | rendered — custom pages, resource forms, relation managers, infolists:
    |
    |     <x-qr-camera-scanner wire-model="scanInput" wire-action="processScan" />
    |     <x-photo-camera-capture wire-model="photoUpload" />
    |
    | Keys are the package view names, values the alias to register. Set a value
    | to null to skip that alias — the namespaced form always works:
    |
    |     <x-filament-qr-scanner::qr-camera-scanner />
    |
    | Aliases are only registered when the name is still free, so an app-level
    | component with the same name always wins and never gets shadowed.
    |
    */

    'components' => [
        'qr-camera-scanner' => 'qr-camera-scanner',
        'photo-camera-capture' => 'photo-camera-capture',
    ],

    /*
    |--------------------------------------------------------------------------
    | Scanner defaults
    |--------------------------------------------------------------------------
    |
    | 'script_url' overrides where html5-qrcode is loaded from. Leave null to
    | serve the copy bundled with this package (published to public/js by
    | `php artisan filament:assets`), which is what you want on shop floors
    | without reliable internet. Point it at a CDN only if you prefer that.
    |
    | 'duplicate_window' is the gap in ms after which re-detecting a code that
    | was already scanned counts as a deliberate re-scan instead of the same
    | code still sitting in the camera frame.
    |
    | 'formats' limits which symbologies the decoder attempts. null (or an
    | empty list) tries all seventeen on every frame, which is the library's
    | default and the slowest option. Narrowing it to what your labels actually
    | carry is the cheapest frame rate you will ever buy on a cheap tablet:
    |
    |     'formats' => ['QR_CODE'],
    |     'formats' => ['QR_CODE', 'CODE_128', 'EAN_13'],
    |
    | See Emuniq\FilamentQrScanner\SupportedFormats::ALL for the full list.
    |
    | 'qrbox_ratio' (0.1–1.0) sizes the scan window against the shorter side of
    | the live viewfinder, which is the only way it stays square whatever
    | frame the browser hands over — a fixed 'qrbox' in pixels comes out as a
    | rectangle the moment the rendered aspect differs from the requested one.
    | Set 'qrbox_ratio' to null to go back to the fixed 'qrbox' box.
    |
    | 'aspect_ratio' forces the shape of the camera feed. Leave it null: the
    | camera hands over its native frame, the browser does no cropping or
    | scaling to reach a shape it was not built for, you get the full field of
    | view, and the preview is shorter — which on a phone is the scarcest thing
    | in the dialog. Measured: forcing a square turned a 640x480 sensor into
    | 480x480 with resizeMode 'crop-and-scale' and made the viewfinder 78px
    | taller for less picture. Set a number only if you need a fixed shape.
    |
    | 'viewfinder_ratio' is the shape of the preview box, reserved before any
    | video exists so the dialog never grows when the picture arrives. It is a
    | display choice and does not touch what the camera sends: a square box is
    | taller than the usual 4:3 landscape frame and the picture fills it by
    | cropping the sides. '3 / 4' is taller still; '4 / 3' matches the frame
    | exactly and is the shortest.
    |
    | 'keep_alive' is how many seconds the camera stays parked after the modal
    | closes. Reopening within that window resumes the same stream, so it costs
    | no getUserMedia — which on iOS Safari is what an operator experiences as
    | being asked for the camera over and over. The recording indicator stays on
    | while parked, so it is released after the window passes. Set 0 to release
    | the camera the moment the modal closes.
    |
    | 'native_decoder' uses the browser's own BarcodeDetector where it exists —
    | Chrome and Edge, including Android — and falls back to the bundled
    | javascript decoder elsewhere. Native decoding is faster and reads
    | tired labels better. This mirrors the library's own default; set it to
    | false to always take the javascript path.
    |
    */

    'scanner' => [
        'script_url' => null,
        'fps' => 10,
        'qrbox' => 250,
        'qrbox_ratio' => 0.7,
        'aspect_ratio' => null,
        'viewfinder_ratio' => '1 / 1',
        'duplicate_window' => 1500,
        'formats' => null,
        'native_decoder' => true,
        'keep_alive' => 45,
    ],

    /*
    |--------------------------------------------------------------------------
    | Photo capture defaults
    |--------------------------------------------------------------------------
    |
    | Defaults for <x-photo-camera-capture> and the HasBase64PhotoCapture
    | trait. 'disk' is where saveBase64Photo() writes the decoded JPEG.
    |
    */

    'photos' => [
        'disk' => 'public',
        'jpeg_quality' => 0.8,
        'max_dimension' => 1280,
    ],

];
