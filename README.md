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
| `aspect-ratio` | `null` | Pin the camera feed to a shape; unset takes it as it comes |
| `viewfinder-ratio` | `1 / 1` | Shape of the preview box, independent of the feed |
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

The camera frame is taken as it comes. Forcing a shape makes the browser crop and scale to reach one the sensor was not built for: measured on an emulated iPhone, asking for a square turned a 640×480 sensor into 480×480 with `resizeMode: crop-and-scale`, costing a third of the field of view and making the viewfinder 78 px taller for less picture. Set `aspect-ratio` to a number only if you need a fixed shape.

The aiming guide is sized from the **short** side of the box, the same basis the scan window uses, so it stays inside the picture whichever way round the frame arrives.

How tall the preview is, though, is a separate decision from what the camera sends. `viewfinder-ratio` shapes the box: a square is a third taller than the 4:3 frame a phone hands over, and the picture fills it by cropping the sides instead of sitting in dark bars. `3 / 4` is taller still, `4 / 3` matches the frame exactly and is the shortest. The crop is display only — the decoder still gets the whole frame — and it is centred, as are the scan window and the guide.

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
        'aspect_ratio' => null,    // null = the camera's native frame
        'viewfinder_ratio' => '1 / 1',
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

## Field notes

Everything below came out of a real shop floor, on real phones. Each one is either handled for you, or is a browser policy the package cannot reach — in which case what to do about it is spelled out.

### iOS Safari asks for the camera again after every reload

**Handled as far as code can.** Opening makes exactly one `getUserMedia` call, and it never asks for a specific `deviceId` — that constraint fails the moment a remembered id goes stale, which Safari guarantees by reissuing them every session, and a failed request is a wasted prompt. Closing the modal parks the camera instead of releasing it, so reopening costs nothing at all.

**The actual rule**, as stated by a WebKit engineer on [bug 215884](https://bugs.webkit.org/show_bug.cgi?id=215884):

> If capture is ongoing, prompt should not happen again until 24 hours. If capture is not ongoing, prompt should happen again after 1 minute of inactivity.

So the grant is not tied to the camera, or to which lens you pick — it is per origin, and it is on a timer. While a stream is live you have 24 hours. The moment capture stops, a one-minute clock starts, and after that the next request prompts again. A reload destroys the stream, so a reload always lands you in the second branch.

That is why parking the camera instead of releasing it matters for more than the current page: it keeps capture ongoing, which keeps you in the 24-hour branch. Raising `scanner.keep_alive` widens the prompt-free window at the cost of the recording indicator staying on longer.

**What no code can do:** nothing survives the page context being destroyed. There is no web API to request a persistent grant, and `navigator.permissions.query({ name: 'camera' })` is not even supported in Safari, so the state cannot be read either.

**What actually stops the asking**, both one-time per device:

- **Add the site to the Home Screen.** iOS treats a Home Screen web app as its own app and keeps the camera grant between launches. Your app needs `<meta name="apple-mobile-web-app-capable" content="yes">` in its head — iOS ignores the manifest's `display: standalone`. As a bonus the browser chrome disappears, which is real height back for the modal.
- **Settings → Safari → Camera → Allow**, or per site through the `aA` menu → Website Settings → Camera.

On Chrome and Android neither is needed; the grant persists on its own.

### The scanner opens on the wrong lens

**Handled.** Taking whatever camera the browser enumerated last is the obvious implementation and it is wrong on any modern phone: that is usually the telephoto, which cannot focus at the distance an operator holds a label, or the ultra wide, which spends its resolution on everything except the code. The package prefers the plain wide lens and falls back through generic rear → ultra wide → macro → telephoto → front. A camera the operator picked themselves always wins over that, and is forgotten if it stops existing.

### Every rear camera is called the same thing

**Handled.** A phone with three rear cameras used to offer three buttons all reading "Back". Each lens is named from its label, in whatever language the browser reports it, and names that would still collide are numbered.

Worth knowing: **Safari is inconsistent about what it reports.** The same iPhone may expose "Wide / Ultra wide / Telephoto" on one visit and a single generic "Back" on the next, and it does not always report a device id that appears in `enumerateDevices()`. When the id is unknown the running track's `facingMode` decides front versus rear, so the switcher never claims a camera that is not the one streaming.

### The aiming square renders as a rectangle

**Handled.** html5-qrcode sizes its overlay from the frame it *asked* for rather than the frame it *got*, so the moment a browser ignores the requested aspect ratio — Safari does — the square comes out stretched. The package draws its own guide with `aspect-ratio: 1`, square by construction, and hides the library's. The scan window itself is sized against the shorter side of the live viewfinder (`qrbox_ratio`) rather than a fixed pixel count, which is the only form that survives a different rendered aspect.

### The modal is wider than the phone screen

**Handled.** A row of camera buttons hands the dialog its full intrinsic width however it is clipped — `overflow-x-auto`, `min-width: 0` and `max-width` do not change that — so four lenses with long names pushed the modal, and its close button, off the side of the screen. The switcher is a `<select>`: it has a width of its own and truncates its own text. The camera preview is clamped so a stream wider than the dialog cannot drag it either.

### The modal grows when the camera picture arrives

**Handled.** A viewfinder with no height of its own takes one from the video, and the video does not exist until the stream paints — so the dialog resizes under the operator's thumb, mid-tap. The box reserves its height from an aspect ratio up front and the camera fills it. A frame whose shape differs is fitted rather than allowed to resize anything.

### The close button scrolls out of reach

**Handled.** The viewfinder plus the controls used to push the footer past the bottom of a phone screen. The footer is sticky and the viewfinder is capped at 52vh.

### The same code scans twice, or two codes scan as duplicates

**Handled.** While a code sits in frame the decoder fires about ten times a second. The package tracks a last-seen timestamp **per code**, so a code held in front of the lens stays silent, a code re-presented after a real gap is rejected as a deliberate re-scan, and two labels alternating in frame — adjacent on the same board — are not mistaken for each other. Reopening the camera after a rejection does not immediately fire a second one.

### Editing the component changes nothing

**Handled, and worth knowing why.** A Blade alias registered by a package outranks an app's own anonymous component of the same name, silently. If your app has `resources/views/components/qr-camera-scanner.blade.php`, this package leaves that name alone and you keep yours; the namespaced form still works. Finding that out the hard way costs an afternoon.

### The browser serves an old copy of the scripts

Assets are versioned with the package version, so a release busts the cache. If you develop against a `path` repository the version is stuck at `dev-main`, the URL never changes, and a CDN in front of your app will happily serve a year-old copy — `curl -sI <asset-url> | grep age` tells you. Tag a version, or bypass the cache, before concluding a fix did not work.

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
