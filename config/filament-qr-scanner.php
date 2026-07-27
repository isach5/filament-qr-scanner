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
    */

    'scanner' => [
        'script_url' => null,
        'fps' => 10,
        'qrbox' => 250,
        'duplicate_window' => 1500,
        'formats' => null,
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
