# Changelog

## v1.9.2 — 2026-07-27

### Changed

- The plain rear camera leads the switcher. It is what an operator means by
  "the back camera", and on a phone whose browser only reports generic labels
  it is the only rear entry there is. The named lenses follow; the front camera
  still goes last.

## v1.9.1 — 2026-07-27

### Fixed

- The new aiming guide rendered as nothing at all: `border-width: 0` was written
  after the per-side longhands in the same declaration, and the shorthand reset
  them. Order corrected, with a test that reads the four corners back.

## v1.9.0 — 2026-07-27

### Fixed

- **The aiming box was a rectangle.** html5-qrcode sizes its overlay from the
  frame it asked for rather than the frame it got, so the moment a browser
  ignores the requested aspect ratio — Safari does — the square comes out
  stretched. The component draws its own guide now, `aspect-ratio: 1`, square by
  construction on every device, and hides the library's.
- **The switcher could still name the wrong camera.** Safari does not always
  report a device id that appears in `enumerateDevices()`. When it does not, the
  running track's `facingMode` now decides front versus rear instead of a
  heuristic.

### Changed

- `scanner.qrbox_ratio` defaults to `0.7`: the scan window is sized against the
  shorter side of the live viewfinder instead of a fixed pixel count. A fixed
  box is a small target on a monitor, an oversized one on a phone, and
  rectangular whenever the rendered aspect differs from the requested one. Set
  it to `null` for the old fixed `qrbox`.

## v1.8.2 — 2026-07-27

### Fixed

- The torch label sat under its icon instead of beside it, and the zoom slider
  fell onto a second line. Cause: **Alpine's `x-show` shows an element by
  REMOVING its inline `display`**, so `display:flex` and `display:inline-flex`
  written in the `style` attribute were silently dropped the moment the
  controls became visible. `x-show` now only ever lands on wrapper elements;
  the elements that need a display of their own no longer carry it.

## v1.8.1 — 2026-07-27

### Fixed

- The torch button lost its layout: `x-bind:style` with a string REPLACES the
  static `style` attribute rather than merging into it, so the button kept only
  its colours and rendered as a narrow box with the icon and label stacked. It
  binds an object now, and the label sits beside the icon on the same row as
  the zoom.

## v1.8.0 — 2026-07-27

### Fixed

- **The close button could scroll out of reach on a phone.** The viewfinder
  plus the controls pushed the footer past the bottom of the screen. The modal
  footer is sticky now and the viewfinder is capped at 52vh, so closing the
  scanner is always one tap away.
- **A stale remembered camera cost a second permission prompt.** Safari hands
  out fresh device ids every session, so the id saved last time is routinely
  invalid on the next visit: the open failed, the operator tapped again, and
  that second attempt was a second `getUserMedia`. It now falls back to the
  platform's rear camera by itself, in the same attempt.

### Changed

- The torch button carries its label again, next to the icon and on the same
  row as the zoom. Only the state text is gone — the colour says that.

## v1.7.1 — 2026-07-27

### Fixed

- The controls overlaid on the camera feed are styled inline instead of with
  Tailwind utilities. The package ships no CSS and a host app's Tailwind never
  scans its views, so classes like `sr-only` or `bg-gray-950/60` may simply not
  exist there — which is how the hidden zoom label rendered as visible black
  text over the feed, and the gradient behind the controls did not render at
  all. Nothing about the component now depends on the host's build.

## v1.7.0 — 2026-07-27

### Fixed

- **The switcher named the front camera while the rear one was streaming.** A
  select bound to a device id that matches no option silently displays its
  first one. The active camera is now resolved against the list that is
  actually rendered, so what the operator reads is what is running.
- **The camera menu was in the browser's order**, which puts the front camera
  first. It is ordered by how useful the lens is for scanning now: wide →
  rear → ultra wide → macro → telephoto → front.

### Changed

- The torch button no longer changes its label from "Light" to "Light on". It
  resized under the operator's thumb, and the colour and `aria-pressed`
  already carry the state. `scanner.torch_on` and `scanner.torch_off` are
  replaced by a single `scanner.torch` string.
- Modal redesign: camera select and sound toggle share one toolbar, torch and
  zoom sit on the feed the way a camera app puts them, the reading counter is a
  badge on the viewfinder, and the footer carries one full-width action. The
  dialog is shorter, which is what a phone was short of.

### Added

- A test that parses `x-data` the way a browser does — value ends at the first
  quote — so a stray `"` in a comment or a translation can never again close
  the attribute early and dump the component's javascript onto the page.

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
