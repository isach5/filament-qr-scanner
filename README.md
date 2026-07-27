# Filament Camera — QR scanning and quick photo capture

Two camera components for Filament v4, droppable anywhere Blade renders — a custom page, a resource form, a relation manager, the body of an Action modal:

- **`<x-qr-camera-scanner>`** — live QR/barcode scanning, wired straight into a Livewire property and action.
- **`<x-photo-camera-capture>`** — quick photo capture: shoot, downscale in the browser, hand the server a base64 data URL. No temporary uploads, no file picker, nothing to wire but a property.

Both share the same camera machinery, so both behave the same way on the same device: they open on the main rear lens rather than whichever the browser listed last, they name every lens instead of showing three buttons all reading "Back", and they ask for the camera **once** per open — three separate `getUserMedia` calls is what an operator experiences as being asked for permission over and over.

Built for shop-floor use: torch and zoom where the device has them, permission handling with per-browser instructions, audible feedback, offline-safe assets, and a duplicate-scan protocol that survives an operator holding a code in front of the lens.

## Installation

```bash
composer require emuniq/filament-qr-scanner
```

That is the whole setup. The plugin registers itself on every Filament panel and publishes the scanner library to `public/js` on the next `composer install`/`update` (through Filament's `filament:upgrade` hook). If your app does not run that hook:

```bash
php artisan filament:assets
```

Requires PHP 8.2+, Laravel 11/12 and Filament v4.

## QR / barcode scanner

```blade
<x-qr-camera-scanner wire-model="scanInput" wire-action="processScan" />
```

```php
public string $scanInput = '';

public function processScan(): void
{
    $code = $this->scanInput;
    $this->scanInput = '';

    // ... your logic
}
```

The operator taps the button, grants camera permission once, and every decoded code lands in `$scanInput` before `processScan()` runs. The modal stays open so codes can be scanned back to back.

### Props

| Prop | Default | Purpose |
| --- | --- | --- |
| `wire-model` | `scanInput` | Livewire property that receives the decoded text |
| `wire-action` | `processScan` | Livewire method called after each scan |
| `wire-action-args` | `null` | Scalar or array spread into the call as arguments |
| `close-on-scan` | `false` | Close the modal after each scan (lookup mode) |
| `button-label` | translated | Text on the open-camera button |
| `button-color` / `button-size` | `primary` / `lg` | Filament button styling |
| `modal-heading` | translated | Heading of the scanner modal |
| `fps` / `qrbox-size` | from config | Decoder tuning |
| `qrbox-ratio` | from config | Scan window as a fraction of the viewfinder |
| `aspect-ratio` | `1.0` | Shape asked of the camera; `null` for its native frame |
| `formats` | from config | Symbologies to decode (see below) |

Extra attributes (`class`, `id`, …) are merged onto the wrapper.

### Choosing a camera

A modern phone reports several rear cameras, and the browser hands them over as free-form labels that differ per platform and per interface language. Two things follow from that, and the component handles both:

- **It opens on the main wide lens**, not on whatever the browser happened to enumerate last. The telephoto cannot focus at the distance an operator holds a label, and the ultra wide spends its resolution on everything except the code. Preference order for what it opens with: plain wide → generic rear → ultra wide → macro → telephoto → front. The menu lists them rear-first: generic rear, wide, ultra wide, macro, telephoto, front.
- **Every lens gets a name of its own** — "Wide", "Ultra wide", "Telephoto", "Macro" — instead of three buttons all reading "Back". If two names would still collide they are numbered.

Whatever the operator picks is remembered and always wins over the heuristic on the next open: they know their workstation better than a regex does. A camera that no longer exists is forgotten rather than failing every future open.

The switcher is a `<select>` rather than a row of buttons, deliberately: a row of pills hands the dialog its full intrinsic width however it is clipped, and four lenses with long names were enough to push the modal off the side of a phone screen.

Opening makes exactly one `getUserMedia` call — the one that starts the camera — and it never asks for a specific device: a `deviceId` constraint fails the moment a remembered id goes stale, which Safari guarantees by reissuing them every session, and a failed request is a wasted prompt. A remembered camera is applied afterwards, on a permission already granted.

Closing the modal **parks** the camera rather than releasing it, so reopening costs no request at all. It is released for real after `scanner.keep_alive` seconds (45 by default, `0` to release immediately) so the recording indicator does not sit on for the rest of a shift.

None of that can make iOS Safari remember the permission between page loads — that is a browser policy, and the durable fix is the device setting: **Settings → Safari → Camera → Allow**, or per site through the `aA` menu → Website Settings → Camera.

### Torch and zoom

Both are capabilities of the running video track, so the component can only know about them once the camera is live, and they differ per device: a laptop webcam usually has neither, a phone back camera usually has both. The controls appear inside the modal when the running camera reports them and stay hidden when it does not — nothing to configure.

What the operator picks is remembered. A station in a dark corner should not need the torch switched on at every single scan.

### Sizing the scan window

`qrbox-size` is a fixed pixel box: a small target on a workstation monitor, an oversized one on a phone. `qrbox-ratio` sizes it against the shorter side of the live viewfinder instead, which travels better:

```blade
<x-qr-camera-scanner :qrbox-ratio="0.7" />
```

A ratio wins over `qrbox-size` when both are given. Tightening the window is also the second cheapest way to buy frame rate, after narrowing the symbologies.

The camera is asked for a square frame. Setting `aspect-ratio` to `null` gives you the sensor's native landscape frame — more scanning area, but it has been seen to render a preview wider than the dialog on iOS Safari, pushing the modal and its close button off the side of the screen. Change it with a real phone in your hand, not from a desk.

### Which symbologies to decode

By default the decoder tries all seventeen symbologies it knows on every frame — QR plus every 1D barcode. That is also the slowest setting. If your labels only ever carry one or two, say so:

```blade
<x-qr-camera-scanner :formats="['QR_CODE']" />
<x-qr-camera-scanner :formats="['QR_CODE', 'CODE_128', 'EAN_13']" />
```

or set `scanner.formats` in config to apply it everywhere. It is the cheapest frame rate you can buy on the low-end tablets that usually end up mounted at a workstation. An unknown name throws at render time rather than reaching the decoder as `undefined`, where the camera would just quietly stop recognising anything.

Available: `QR_CODE`, `AZTEC`, `CODABAR`, `CODE_39`, `CODE_93`, `CODE_128`, `DATA_MATRIX`, `MAXICODE`, `ITF`, `EAN_13`, `EAN_8`, `PDF_417`, `RSS_14`, `RSS_EXPANDED`, `UPC_A`, `UPC_E`, `UPC_EAN_EXTENSION`.

### Duplicate handling

While a code sits in the camera frame the decoder fires ~10 times a second. The component tracks a last-seen timestamp **per code**, so:

- The same code still in frame is silently ignored.
- A code re-presented after a real gap (default 1500 ms, configurable) is rejected as a deliberate re-scan.
- Two codes alternating in frame — adjacent labels on a board — are not mistaken for duplicates.

### Page-level events

The component talks to the hosting page over window events, so the page can own the error UI:

| Event | Direction | Meaning |
| --- | --- | --- |
| `scan-rejected` | both ways | A scan was refused. The component fires it for client-side duplicates; your page fires it (`$this->dispatch('scan-rejected', message: ..., qrCode: ...)`) after a server-side check fails. Either way the camera closes and the code is remembered. |
| `scan-resume` | page → component | Reopen the camera after the operator acknowledged a rejection. Only reopens if the camera was open when the rejection landed. |
| `scanner-reset` | page → component | Clear the scanned-code memory. Dispatch it when the working context changes — a different station, a new inspection — so codes that were valid elsewhere can be scanned again. |

```blade
<div
    x-data="{ blocked: false, message: '' }"
    x-on:scan-rejected.window="blocked = true; message = $event.detail?.message"
>
    <x-qr-camera-scanner wire-model="scanInput" wire-action="processScan" />

    <div x-show="blocked" x-cloak>
        <p x-text="message"></p>
        <button x-on:click="blocked = false; $dispatch('scan-resume')">Got it</button>
    </div>
</div>
```

## Quick photo capture

Evidence photos without a file picker or a temporary upload: the operator taps, frames, shoots, and the frame arrives as a base64 JPEG on a Livewire property.

```blade
<x-photo-camera-capture wire-model="damagePhotoUpload" />
```

```php
use Emuniq\FilamentQrScanner\Concerns\HasBase64PhotoCapture;

class InspectionPage extends Page
{
    use HasBase64PhotoCapture;

    public ?string $damagePhotoUpload = null;

    public function save(): void
    {
        $path = $this->saveBase64Photo($this->damagePhotoUpload, 'damage-photos');
    }
}
```

The frame is captured to a canvas, downscaled past `max-dimension`, encoded as a JPEG data URL and written to the Livewire property **without** a roundtrip — an immediate sync would re-render the surrounding Action modal and wipe text the operator had already typed. `saveBase64Photo()` decodes it and stores it on the configured disk, returning the stored path, or `null` when the value is empty or malformed.

| Prop | Default | Purpose |
| --- | --- | --- |
| `wire-model` | `photoUpload` | Livewire property that receives the data URL |
| `button-label` | translated | Text on the open-camera button |
| `button-color` / `button-size` | `primary` / `lg` | Filament button styling |
| `modal-heading` | translated | Heading of the capture modal |
| `jpeg-quality` / `max-dimension` | from config | Encoding and downscaling |

Override `writeBase64Photo()` when storing needs to do more than one `put()` — staging the file locally and shipping it to remote storage from a queued job, say:

```php
protected function writeBase64Photo(string $path, string $binary, string $disk): void
{
    Storage::disk('local')->put($path, $binary);
    UploadStagedPhotoJob::dispatch($path, $disk);
}
```

## Configuration

```bash
php artisan vendor:publish --tag=filament-qr-scanner-config
```

```php
return [
    'auto_register_panels' => true,

    'components' => [
        'qr-camera-scanner' => 'qr-camera-scanner',
        'photo-camera-capture' => 'photo-camera-capture',
    ],

    'scanner' => [
        'script_url' => null,      // null = the bundled copy
        'fps' => 10,
        'qrbox' => 250,
        'qrbox_ratio' => null,     // set it to size against the viewfinder
        'aspect_ratio' => 1.0,     // null = the camera's native frame
        'duplicate_window' => 1500,
        'formats' => null,         // null = decode every symbology
        'native_decoder' => true,
    ],

    'photos' => [
        'disk' => 'public',
        'jpeg_quality' => 0.8,
        'max_dimension' => 1280,
    ],
];
```

**Aliases never shadow your app.** If your app already ships `resources/views/components/qr-camera-scanner.blade.php`, the plugin leaves that name alone — a Blade alias silently outranks an anonymous component, and finding that out the hard way costs an afternoon. Set an alias to `null` to skip it, or reach the component through its namespace, which always works:

```blade
<x-filament-qr-scanner::qr-camera-scanner />
```

Set `auto_register_panels` to `false` to register the plugin only where you want it:

```php
use Emuniq\FilamentQrScanner\QrScannerPlugin;

$panel->plugin(QrScannerPlugin::make());
```

## Offline

`html5-qrcode` ships inside the package and is served from your own domain, lazily, the first time an operator opens the camera. No CDN request, no dead camera when the shop floor's internet drops. Point `scanner.script_url` at a CDN if you prefer that.

## How decoding actually happens

Where the browser has its own `BarcodeDetector` — Chrome and Edge, Android included — decoding runs through it: the operating system's decoder, faster and more forgiving of tired labels than any JavaScript port. Everywhere else (Safari, Firefox) the bundled JavaScript decoder takes over. You get the good path where it exists and a working one where it does not, with no branching in your code.

Set `scanner.native_decoder` to `false` to always take the JavaScript path — useful if you need one behaviour across a mixed fleet, or to rule the native decoder out while debugging a misread.

Worth knowing: the JavaScript decoder underneath is [html5-qrcode](https://github.com/mebjas/html5-qrcode), which wraps zxing-js. Neither has seen active development since 2023. It is bundled here, so nothing can break under you, but on browsers without `BarcodeDetector` that is the code doing the reading.

## Translations

English and Spanish included, and the suite keeps them honest: every language must carry exactly the same keys, no string may be blank, every string a component renders must exist in every language, and no language may carry a string nothing renders. Adding a third language means copying a folder and translating it — if you miss a key, the tests say which one.

```bash
php artisan vendor:publish --tag=filament-qr-scanner-translations
```

Strings live in `lang/vendor/filament-qr-scanner/{locale}/{scanner,photo}.php`. Views can be published too, with `--tag=filament-qr-scanner-views`.

Three strings are shipped for you rather than used by the components — `scanner.rejected_title`, `scanner.rejected_generic` and `scanner.acknowledge`, for the page-level rejection overlay described above.

## Browser support

Chrome/Chromium (desktop and Android), Safari (iOS and macOS), Firefox, Edge. Camera access requires HTTPS (or `localhost`).

## Testing

The PHP side and the browser side are tested separately, and both are held at 100%.

```bash
composer install
composer test               # Pest + Testbench
composer test:coverage      # same, gated at 100% line coverage (needs pcov or xdebug)

npm run test                # node --test, no dependencies
npm run test:js:coverage    # line / branch / function coverage
```

The decision layer of the scanner — what counts as a new read, as the same code still sitting in the camera frame, or as a deliberate re-scan — lives in `resources/dist/scan-session.js`, apart from Alpine and the DOM. Two production bugs came out of that logic, so it is a plain module with its own suite rather than an inline `x-data` blob. The Blade component only carries out what it decides.

## Credits

Camera decoding by [html5-qrcode](https://github.com/mebjas/html5-qrcode) (Apache-2.0, bundled — see `NOTICE`).

## License

MIT. See [LICENSE](LICENSE).
