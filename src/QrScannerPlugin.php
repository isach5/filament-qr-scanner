<?php

namespace Emuniq\FilamentQrScanner;

use Filament\Contracts\Plugin;
use Filament\Panel;

class QrScannerPlugin implements Plugin
{
    public function getId(): string
    {
        return 'qr-scanner';
    }

    public function register(Panel $panel): void
    {
        //
    }

    public function boot(Panel $panel): void
    {
        //
    }

    public static function make(): static
    {
        return new static();
    }
}
