# Changelog

## v1.6.0 — 2026-07-27

### Fixed

- **The modal was wider than a phone screen, and the camera row was why.** A
  flex row of buttons hands its full intrinsic width to the dialog no matter
  how it is clipped, so four lenses with names like "Ultra gran angular"
  pushed the modal — and its close button — off the side of the screen. The
  switcher is a `<select>` now: it has a width of its own and truncates its own
  text. Measured on an emulated iPhone with four cameras: dialog 361 px inside
  a 393 px screen, no horizontal overflow, close button reachable.
- `min-w-0` on the modal body and on the torch/zoom row, so no future control
  can force the dialog wide again.

## v1.5.0 — 2026-07-27

### Fixed

- **Opening the scanner asked for the camera three times.** It probed for
  permission with one `getUserMedia`, enumerated cameras with a second, and
  started the camera with a third. iOS Safari treats every one of them as a
  reason to ask the operator again. There is now exactly one request — the one
  that actually starts the camera — measured, not assumed.
- A remembered camera that no longer exists used to fail the open every time.
  It is forgotten on failure so the next attempt asks the platform for whatever
  rear camera it has.

### Changed

- With no remembered camera the scanner starts from a `facingMode: environment`
  constraint and lets the platform pick its default rear lens, then fills the
  switcher from `enumerateDevices()` — which needs no stream of its own.
- Camera errors are mapped to the translated explanations from a single place
  regardless of which call failed.

## v1.4.0 — 2026-07-27

### Fixed

- **The scanner opened on the wrong lens.** It took whatever camera the browser
  enumerated last, which on an iPhone is one of the extra rear lenses: the
  telephoto cannot focus at the distance an operator holds a label, and the
  ultra wide spends its resolution on everything except the code. It now picks
  the main wide lens — plain wide → generic rear → ultra wide → macro →
  telephoto → front — and a remembered choice still wins over all of it.
- **Every rear lens was called the same thing.** A phone with three rear
  cameras showed three buttons reading "Back", so the operator had to try all
  three. Each lens now gets its own name, and names that would still collide
  are numbered.
- The close button was styled as destructive. Closing the scanner destroys
  nothing, and on a shop floor red reads as stop / abort / something broke.

### Changed

- Camera switcher is one row that scrolls sideways instead of wrapping. Four
  lenses used to take two rows of height from the viewfinder for a control the
  operator touches once.
- The camera preview can no longer grow past the modal. A stream wider than the
  dialog used to drag it off the side of a phone screen, close button included.
- `aspect-ratio` prop and `scanner.aspect_ratio` config. The square default is
  unchanged; `null` gives the sensor's native frame, which is more scanning
  area but is what caused the overflow above on iOS Safari.

### Added

- `resources/dist/camera-picker.js` — naming and choosing among lenses, apart
  from Alpine and the DOM, with 22 tests at 100% line, branch and function
  coverage against the labels real iPhones, Android phones and laptops report
  in both English and Spanish.

## v1.3.0 — 2026-07-27

### Added

- Torch and zoom controls inside the scanner modal, shown only when the running
  video track reports them — a laptop webcam has neither, a phone back camera
  has both. Both choices are remembered: a station in a dark corner should not
  need the torch switched on at every scan.
- `qrbox-ratio` prop and `scanner.qrbox_ratio` config: size the scan window
  against the shorter side of the live viewfinder instead of pinning it to a
  pixel count that is small on a monitor and huge on a phone. A ratio wins over
  `qrbox-size`; out-of-range values throw at render time.
- `scanner.native_decoder` config, making explicit what the library already did
  silently: decode through the browser's own `BarcodeDetector` where it exists
  (Chrome and Edge, Android included) and fall back to the bundled JavaScript
  decoder elsewhere. Set it to `false` to always take the JavaScript path.
- `aria-live` on the last-read banner, `aria-pressed` on the torch toggle, and
  `motion-reduce` on the success flash. The flash and the beep were the only
  feedback a scan had.

## v1.2.0 — 2026-07-27

### Changed

- The scanner's decision layer moved out of the inline `x-data` blob into
  `resources/dist/scan-session.js`, loaded on demand next to html5-qrcode.
  Deciding whether a decoded code is a new read, the same code still in frame,
  or a deliberate re-scan is where both duplicate-detection bugs came from; as
  a plain module apart from Alpine and the DOM it can be tested directly. The
  Blade component now only carries out what it decides. Behaviour is unchanged.

### Added

- 20 tests for the scan session (`npm test`, no dependencies — `node --test`),
  at 100% line, branch and function coverage. They pin the things that broke
  before: two labels alternating in frame, a code held in front of the lens for
  a minute, reopening after a rejection without firing a second one.
- Coverage of the PHP side taken to 100%, with the paths that had none: aliases
  disabled through config, a `components` config that is not a list, blank
  entries in a symbology list.
- `composer test` / `composer test:coverage` (gated at 100%) and
  `npm run test` / `npm run test:js:coverage`.
- `<source>` in `phpunit.xml`, without which `pest --coverage` cannot collect.

## v1.1.0 — 2026-07-27

### Added

- `formats` prop and `scanner.formats` config to limit which symbologies the
  decoder attempts. The default is still all seventeen, which is also the
  slowest; narrowing it to what your labels carry is the cheapest frame rate
  you can buy on a low-end tablet. Unknown names throw at render time instead
  of reaching the decoder as `undefined`, where the camera silently stops
  recognising anything.

### Fixed

- Corrected the v1.0.0 note about `Panel::configureUsing`. See below.

## v1.0.0 — 2026-07-27

First stable release. The pre-release `dev-main` snapshot from March was missing
most of what production had grown since; this is the whole thing.

### Added

- `<x-photo-camera-capture>` — camera photo capture that hands the server a
  downscaled base64 JPEG, plus the `HasBase64PhotoCapture` trait to decode and
  store it. `writeBase64Photo()` is overridable for two-phase / queued uploads.
- `scanner-reset` and `scan-resume` window events, so a hosting page can clear
  the scanned-code memory when the working context changes and reopen the
  camera after the operator acknowledges a rejection.
- `wire-action-args` prop — a scalar or list spread into the Livewire call, for
  actions that need to know what the scan is being checked against.
- Config file (`filament-qr-scanner.php`): panel auto-registration, component
  aliases, decoder tuning, duplicate window, photo disk and encoding.
- `html5-qrcode` is bundled and served from the app's own domain through
  `FilamentAsset`, loaded on demand. Panels no longer depend on a CDN reachable
  from the shop floor. `scanner.script_url` restores the old behaviour.
- Test suite (Pest + Testbench), run locally with `vendor/bin/pest`.
- `NOTICE` documenting the bundled Apache-2.0 library.

### Changed

- Duplicate detection tracks a last-seen timestamp **per code** instead of a
  single `lastScannedCode`. Two labels alternating in the camera frame no
  longer read as a duplicate.
- Component aliases are skipped when the host app already owns that name. A
  Blade alias silently outranks an app's anonymous component, which made an
  app-level file look editable while the package's copy was what rendered.
- Panel auto-registration moved to the provider's `register()`. It worked from
  `boot()` in a real application — `Filament::registerPanel()` defers panel
  construction until `PanelRegistry` is first resolved, which is after every
  provider has booted — but not under Orchestra Testbench, where the registry
  resolves during setup. `register()` is correct in both.
- Scanned text is trimmed and empty reads are dropped.
- Requires Filament v4, PHP 8.2+. The v3 claim was never covered by tests and
  has been dropped rather than left as an untested promise.
