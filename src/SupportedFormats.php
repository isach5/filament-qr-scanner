<?php

namespace Emuniq\FilamentQrScanner;

use InvalidArgumentException;

/**
 * The barcode symbologies html5-qrcode can decode.
 *
 * By default the decoder tries all of them on every frame. Narrowing the list
 * to what your labels actually use is the cheapest way to buy frame rate on the
 * low-end tablets that usually end up mounted at a workstation.
 */
final class SupportedFormats
{
    /** @var list<string> */
    public const ALL = [
        'QR_CODE',
        'AZTEC',
        'CODABAR',
        'CODE_39',
        'CODE_93',
        'CODE_128',
        'DATA_MATRIX',
        'MAXICODE',
        'ITF',
        'EAN_13',
        'EAN_8',
        'PDF_417',
        'RSS_14',
        'RSS_EXPANDED',
        'UPC_A',
        'UPC_E',
        'UPC_EAN_EXTENSION',
    ];

    /**
     * Normalise a caller-supplied format list into upper-case names the
     * javascript side can look up on Html5QrcodeSupportedFormats.
     *
     * Returns an empty list when nothing was requested, which means "decode
     * everything" — the library's own default.
     *
     * @param  string|iterable<string>|null  $formats
     * @return list<string>
     *
     * @throws InvalidArgumentException on an unknown symbology. Failing here
     *         beats shipping an `undefined` into the decoder config, where the
     *         camera just silently stops recognising anything.
     */
    public static function normalise(string|iterable|null $formats): array
    {
        if ($formats === null) {
            return [];
        }

        if (is_string($formats)) {
            $formats = preg_split('/[\s,]+/', $formats, flags: PREG_SPLIT_NO_EMPTY) ?: [];
        }

        $normalised = [];

        foreach ($formats as $format) {
            $name = strtoupper(trim((string) $format));

            if ($name === '') {
                continue;
            }

            if (! in_array($name, self::ALL, true)) {
                throw new InvalidArgumentException(
                    "Unknown barcode format [{$name}]. Supported: " . implode(', ', self::ALL) . '.'
                );
            }

            if (! in_array($name, $normalised, true)) {
                $normalised[] = $name;
            }
        }

        return $normalised;
    }
}
