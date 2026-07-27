<?php

namespace Emuniq\FilamentQrScanner\Concerns;

use Illuminate\Support\Facades\Storage;

/**
 * Decodes the base64 data URL ("data:image/jpeg;base64,XXXX") produced by
 * <x-photo-camera-capture> and stores it on a filesystem disk.
 *
 * Use it on any Livewire component, Filament page, resource or relation
 * manager that accepts photos through the native camera instead of a
 * FileUpload field:
 *
 *     use Emuniq\FilamentQrScanner\Concerns\HasBase64PhotoCapture;
 *
 *     public ?string $damagePhotoUpload = null;
 *
 *     $path = $this->saveBase64Photo($this->damagePhotoUpload, 'damage-photos');
 *
 * Override writeBase64Photo() when storing needs to do more than a single
 * put() — staging the file locally and shipping it to remote storage from a
 * queued job, for instance.
 */
trait HasBase64PhotoCapture
{
    /**
     * Decode and store a captured photo. Returns the stored path, or null when
     * the value is empty or not a valid image data URL.
     */
    protected function saveBase64Photo(?string $dataUrl, string $directory, ?string $disk = null): ?string
    {
        $decoded = $this->decodeBase64Image($dataUrl);

        if ($decoded === null) {
            return null;
        }

        [$binary, $extension] = $decoded;

        $disk ??= config('filament-qr-scanner.photos.disk', 'public');
        $path = $this->base64PhotoPath($directory, $extension);

        $this->writeBase64Photo($path, $binary, $disk);

        return $path;
    }

    /**
     * Split a data URL into its decoded binary and file extension.
     *
     * @return array{0: string, 1: string}|null
     */
    protected function decodeBase64Image(?string $dataUrl): ?array
    {
        if (! is_string($dataUrl) || $dataUrl === '' || ! str_starts_with($dataUrl, 'data:image/')) {
            return null;
        }

        if (! preg_match('#^data:image/([a-zA-Z0-9.+-]+);base64,(.+)$#s', $dataUrl, $matches)) {
            return null;
        }

        $extension = strtolower($matches[1]);
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;

        $binary = base64_decode($matches[2], true);

        if ($binary === false || $binary === '') {
            return null;
        }

        return [$binary, $extension];
    }

    /**
     * Build the storage path for a captured photo. Timestamp keeps files
     * browsable in order; uniqid() keeps two operators shooting in the same
     * second from colliding.
     */
    protected function base64PhotoPath(string $directory, string $extension): string
    {
        return trim($directory, '/') . '/' . now()->format('Ymd_His') . '_' . uniqid() . '.' . $extension;
    }

    /**
     * Write the decoded photo. Override to customise where and how it lands.
     */
    protected function writeBase64Photo(string $path, string $binary, string $disk): void
    {
        Storage::disk($disk)->put($path, $binary);
    }
}
