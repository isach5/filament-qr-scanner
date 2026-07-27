<?php

use Emuniq\FilamentQrScanner\SupportedFormats;

it('treats null as decode everything', function () {
    expect(SupportedFormats::normalise(null))->toBe([]);
});

it('accepts a list of names', function () {
    expect(SupportedFormats::normalise(['QR_CODE', 'CODE_128']))->toBe(['QR_CODE', 'CODE_128']);
});

it('accepts a single name and a delimited string', function () {
    expect(SupportedFormats::normalise('QR_CODE'))->toBe(['QR_CODE']);
    expect(SupportedFormats::normalise('QR_CODE, EAN_13'))->toBe(['QR_CODE', 'EAN_13']);
    expect(SupportedFormats::normalise("QR_CODE\n CODE_39"))->toBe(['QR_CODE', 'CODE_39']);
});

it('upper-cases and trims', function () {
    expect(SupportedFormats::normalise([' qr_code ', 'Code_128']))->toBe(['QR_CODE', 'CODE_128']);
});

it('drops duplicates while keeping the given order', function () {
    expect(SupportedFormats::normalise(['CODE_128', 'QR_CODE', 'code_128']))->toBe(['CODE_128', 'QR_CODE']);
});

it('rejects an unknown symbology instead of passing undefined to the decoder', function () {
    // Html5QrcodeSupportedFormats['CODE_1234'] is undefined, and the camera
    // then quietly decodes nothing at all. Fail where it can be read.
    expect(fn () => SupportedFormats::normalise(['QR_CODE', 'CODE_1234']))
        ->toThrow(InvalidArgumentException::class, 'Unknown barcode format [CODE_1234]');
});

it('covers every symbology the bundled library knows', function () {
    $library = file_get_contents(__DIR__ . '/../../resources/dist/html5-qrcode.min.js');

    foreach (SupportedFormats::ALL as $format) {
        expect($library)->toContain("\"{$format}\"");
    }

    expect(SupportedFormats::ALL)->toHaveCount(17);
});
