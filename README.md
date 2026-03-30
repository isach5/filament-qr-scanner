# Filament QR Scanner

A Filament v3 & v4 plugin for HTML5 QR/barcode camera scanning. Opens a Filament modal with live camera feed, supports multi-camera switching, handles permissions gracefully, and integrates directly with Livewire.

## Features

- **Zero configuration** - works out of the box
- **Multi-camera support** - switch between front/back cameras
- **Permission handling** - friendly help messages when camera is denied
- **Livewire integration** - scanned value sent directly to your component
- **Filament native** - uses Filament modals, buttons, and styling
- **Dark mode** support
- **Localized** - English and Spanish included, easily extendable
- **Mobile ready** - iOS Safari, Android Chrome, desktop browsers
- **Camera persistence** - remembers last selected camera via localStorage

## Requirements

- PHP 8.1+
- Laravel 10.0+
- Filament 3.0+ or 4.0+

## Installation

```bash
composer require emuniq/filament-qr-scanner
```

That's it! The plugin auto-registers to all Filament panels.

## Usage

### Basic

Add the component to any Livewire/Filament page:

```blade
<x-qr-camera-scanner />
```

Then in your Livewire component:

```php
public string $scanInput = '';

public function processScan(): void
{
    $qr = trim($this->scanInput);
    $this->scanInput = '';

    // Do something with $qr...
}
```

### Custom Props

```blade
<x-qr-camera-scanner
    wire-model="myProperty"
    wire-action="myMethod"
    button-label="Scan Product"
    button-color="success"
    button-size="md"
    modal-heading="Scan Product QR"
    :fps="15"
    :qrbox-size="300"
/>
```

### Available Props

| Prop | Default | Description |
|------|---------|-------------|
| `wire-model` | `scanInput` | Livewire property to receive scanned value |
| `wire-action` | `processScan` | Livewire method called after scan |
| `button-label` | `Scan QR` | Button text (auto-translated) |
| `button-color` | `primary` | Filament button color |
| `button-size` | `lg` | Filament button size |
| `modal-heading` | `Scan QR Code` | Modal title (auto-translated) |
| `fps` | `10` | Scanner frames per second |
| `qrbox-size` | `250` | QR scanning area in pixels |

## Translations

English and Spanish are included. To add your own:

```bash
php artisan vendor:publish --tag=filament-qr-scanner-translations
```

Then edit `lang/vendor/filament-qr-scanner/{locale}/scanner.php`.

## How It Works

1. User clicks the camera button
2. Requests camera permission (with fallback constraints)
3. Opens a Filament modal with live camera feed
4. On successful scan, sets the Livewire property and calls the action
5. Automatically stops camera and closes modal

Uses [html5-qrcode](https://github.com/mebjas/html5-qrcode) v2.3.8 loaded on demand via `x-load-js`.

## Browser Support

- Chrome/Chromium (Desktop & Android)
- Safari (iOS & macOS)
- Firefox
- Edge

## License

MIT
