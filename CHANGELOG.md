# Changelog

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
