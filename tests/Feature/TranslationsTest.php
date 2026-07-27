<?php

/**
 * Strings the package ships for a host page to use rather than for its own
 * components — the page-level rejection overlay documented in the README.
 * Everything else must actually be rendered by a component.
 */
const HOST_PAGE_STRINGS = [
    'scanner.rejected_title',
    'scanner.rejected_generic',
    'scanner.acknowledge',
];

function locales(): array
{
    return array_map(
        fn (string $path): string => basename($path),
        glob(__DIR__ . '/../../resources/lang/*'),
    );
}

function strings(string $locale, string $file): array
{
    return require __DIR__ . "/../../resources/lang/{$locale}/{$file}.php";
}

it('ships more than one language', function () {
    expect(locales())->toContain('en')->toContain('es');
});

it('gives every language exactly the same keys', function (string $file) {
    // A key added in one language and forgotten in the other renders as the raw
    // dotted path — "filament-qr-scanner::scanner.torch" on a button — which is
    // the sort of thing that ships unnoticed because the developer's own locale
    // is fine.
    $reference = array_keys(strings('en', $file));
    $drift = [];

    foreach (locales() as $locale) {
        $keys = array_keys(strings($locale, $file));

        foreach (array_diff($reference, $keys) as $missing) {
            $drift[] = "{$locale} is missing {$file}.{$missing}";
        }

        foreach (array_diff($keys, $reference) as $extra) {
            $drift[] = "{$locale} has an untranslated extra {$file}.{$extra}";
        }
    }

    expect($drift)->toBe([]);
})->with(['scanner', 'photo']);

it('leaves no string empty in any language', function (string $file) {
    foreach (locales() as $locale) {
        foreach (strings($locale, $file) as $key => $value) {
            expect($value)->toBeString();
            expect(trim($value))->not->toBe('', "[{$locale}] {$file}.{$key} is empty");
        }
    }
})->with(['scanner', 'photo']);

it('translates every string the components actually render', function (string $file) {
    $views = implode(' ', array_map(
        file_get_contents(...),
        glob(__DIR__ . '/../../resources/views/components/*.blade.php'),
    ));

    preg_match_all('/filament-qr-scanner::(' . $file . '\.[a-z_]+)/', $views, $matches);

    $missing = [];

    foreach (array_unique($matches[1]) as $used) {
        [, $key] = explode('.', $used, 2);

        foreach (locales() as $locale) {
            if (! array_key_exists($key, strings($locale, $file))) {
                $missing[] = "{$locale} has no string for {$used}";
            }
        }
    }

    expect($missing)->toBe([]);
})->with(['scanner', 'photo']);

it('carries no string that nothing renders', function (string $file) {
    // Dead strings are a tax on every translator who adds a language.
    $views = implode(' ', array_map(
        file_get_contents(...),
        glob(__DIR__ . '/../../resources/views/components/*.blade.php'),
    ));

    foreach (array_keys(strings('en', $file)) as $key) {
        if (in_array("{$file}.{$key}", HOST_PAGE_STRINGS, true)) {
            continue;
        }

        expect($views)->toContain("filament-qr-scanner::{$file}.{$key}");
    }
})->with(['scanner', 'photo']);

it('keeps the two languages actually different', function () {
    // A copy-pasted file that was never translated passes every check above.
    $en = strings('en', 'scanner');
    $es = strings('es', 'scanner');

    $identical = array_keys(array_filter(
        $en,
        fn (string $value, string $key): bool => $value === $es[$key],
        ARRAY_FILTER_USE_BOTH,
    ));

    // Zoom and Macro are the same word in both, and that is fine.
    expect(count($identical))->toBeLessThan(5);
});
