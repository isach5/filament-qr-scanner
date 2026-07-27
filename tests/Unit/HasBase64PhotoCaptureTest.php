<?php

use Emuniq\FilamentQrScanner\Concerns\HasBase64PhotoCapture;
use Illuminate\Support\Facades\Storage;

/** Exposes the trait's protected API to the tests. */
class PhotoCaptureHost
{
    use HasBase64PhotoCapture;

    public function save(?string $dataUrl, string $directory, ?string $disk = null): ?string
    {
        return $this->saveBase64Photo($dataUrl, $directory, $disk);
    }
}

/** Same trait, but storing through a queued two-phase flow. */
class StagingPhotoCaptureHost extends PhotoCaptureHost
{
    public array $staged = [];

    protected function writeBase64Photo(string $path, string $binary, string $disk): void
    {
        Storage::disk('local')->put($path, $binary);
        $this->staged[] = [$path, $disk];
    }
}

function dataUrl(string $mime = 'jpeg', string $payload = 'binary-content'): string
{
    return "data:image/{$mime};base64," . base64_encode($payload);
}

it('decodes and stores the captured photo', function () {
    Storage::fake('public');

    $path = (new PhotoCaptureHost)->save(dataUrl(), 'damage-photos');

    expect($path)->toMatch('#^damage-photos/\d{8}_\d{6}_\w+\.jpg$#');
    Storage::disk('public')->assertExists($path);
    expect(Storage::disk('public')->get($path))->toBe('binary-content');
});

it('normalises jpeg to jpg and keeps other extensions', function () {
    Storage::fake('public');

    expect((new PhotoCaptureHost)->save(dataUrl('jpeg'), 'x'))->toEndWith('.jpg');
    expect((new PhotoCaptureHost)->save(dataUrl('png'), 'x'))->toEndWith('.png');
    expect((new PhotoCaptureHost)->save(dataUrl('webp'), 'x'))->toEndWith('.webp');
});

it('writes to the configured disk by default', function () {
    Storage::fake('evidence');
    config()->set('filament-qr-scanner.photos.disk', 'evidence');

    $path = (new PhotoCaptureHost)->save(dataUrl(), 'photos');

    Storage::disk('evidence')->assertExists($path);
});

it('lets the caller override the disk', function () {
    Storage::fake('public');
    Storage::fake('archive');

    $path = (new PhotoCaptureHost)->save(dataUrl(), 'photos', 'archive');

    Storage::disk('archive')->assertExists($path);
    Storage::disk('public')->assertMissing($path);
});

it('strips leading and trailing slashes from the directory', function () {
    Storage::fake('public');

    expect((new PhotoCaptureHost)->save(dataUrl(), '/photos/'))->toStartWith('photos/');
});

it('returns null for anything that is not an image data url', function (?string $value) {
    Storage::fake('public');

    expect((new PhotoCaptureHost)->save($value, 'photos'))->toBeNull();
})->with([
    null,
    '',
    'not a data url',
    'data:text/plain;base64,aGVsbG8=',
    'data:image/jpeg;base64,',
    'https://example.test/photo.jpg',
]);

it('returns null when the payload is not valid base64', function () {
    Storage::fake('public');

    expect((new PhotoCaptureHost)->save('data:image/jpeg;base64,!!!not base64!!!', 'photos'))->toBeNull();
});

it('lets a host app replace the write step', function () {
    Storage::fake('local');
    Storage::fake('public');

    $host = new StagingPhotoCaptureHost;
    $path = $host->save(dataUrl(), 'photos', 'r2');

    expect($host->staged)->toBe([[$path, 'r2']]);
    Storage::disk('local')->assertExists($path);
    Storage::disk('public')->assertMissing($path);
});

it('does not collide when two photos land in the same second', function () {
    Storage::fake('public');

    $host = new PhotoCaptureHost;

    expect($host->save(dataUrl(), 'photos'))->not->toBe($host->save(dataUrl(), 'photos'));
});
