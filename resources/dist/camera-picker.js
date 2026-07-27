/**
 * CameraPicker — naming and choosing among the cameras a device exposes.
 *
 * A modern phone reports several rear cameras, and the browser hands them over
 * as free-form labels that differ per platform and per interface language.
 * Two things go wrong if you treat them as interchangeable:
 *
 *   - Picking arbitrarily lands on the telephoto, which cannot focus at the
 *     distance an operator holds a label, or on the ultra wide, which spends
 *     its resolution on everything except the code.
 *   - Collapsing every rear lens to one name leaves the operator staring at
 *     three identical buttons with no way to tell them apart.
 *
 * Plain script, no build step: defines a global, also requireable in Node.
 */
(function (global) {
    'use strict';

    // Order matters. "ultra gran angular" contains "gran angular", and Apple's
    // front camera is called TrueDepth, so the specific patterns are tested
    // before the general ones.
    var KINDS = [
        { kind: 'front', pattern: /front|frontal|delantera|user facing|facing user|selfie|facetime|truedepth/i },
        { kind: 'macro', pattern: /macro/i },
        { kind: 'ultrawide', pattern: /ultra[\s-]?wide|ultra gran angular|gran angular ultra/i },
        { kind: 'telephoto', pattern: /telephoto|teleobjetivo|tele[\s-]?photo|\btele\b/i },
        { kind: 'wide', pattern: /\bwide\b|gran angular|angular/i },
        { kind: 'back', pattern: /back|rear|trasera|posterior|environment|facing back/i },
    ];

    // What we actually want pointed at a label, best first. A plain rear camera
    // outranks a "dual wide" style virtual device because the latter can hand
    // the ultra wide lens to a close-up.
    //
    // 'unknown' is deliberately absent: when nothing can be identified — labels
    // are empty until camera permission is granted on some platforms — the last
    // enumerated device is the better bet, since Android tends to list the rear
    // camera last. That fallback lives at the end of pickDefault().
    var PREFERENCE = ['wide', 'back', 'ultrawide', 'macro', 'telephoto', 'front'];

    function classify(label) {
        var text = String(label == null ? '' : label).trim();

        if (text === '' || text === 'null') {
            return 'unknown';
        }

        for (var i = 0; i < KINDS.length; i++) {
            if (KINDS[i].pattern.test(text)) {
                return KINDS[i].kind;
            }
        }

        return 'unknown';
    }

    /**
     * Turn the raw device list into something displayable.
     *
     * `names` maps a kind to its translated label; anything missing falls back
     * to the raw device label, and finally to "Camera N". Names are made unique
     * afterwards, because two buttons reading the same thing are worse than a
     * clumsy name.
     *
     * @param devices  [{ id, label }]  as returned by Html5Qrcode.getCameras()
     * @param names    { front, back, wide, ultrawide, telephoto, macro, fallback }
     * @return [{ id, label, kind, name }]
     */
    function describe(devices, names) {
        names = names || {};

        var described = (devices || []).map(function (device, index) {
            var label = String(device.label == null ? '' : device.label).trim();
            var kind = classify(label);
            var name = names[kind];

            if (!name) {
                // No translation for this kind: show the device's own label
                // unless it is one of the long opaque identifiers some Android
                // builds report.
                name = label !== '' && label !== 'null' && label.length <= 30
                    ? label
                    : (names.fallback || 'Camera') + ' ' + (index + 1);
            }

            return { id: device.id, label: label, kind: kind, name: name };
        });

        return disambiguate(described);
    }

    /** Number any names that ended up identical: "Trasera" -> "Trasera 1", "Trasera 2". */
    function disambiguate(described) {
        var counts = {};

        described.forEach(function (camera) {
            counts[camera.name] = (counts[camera.name] || 0) + 1;
        });

        var seen = {};

        return described.map(function (camera) {
            if (counts[camera.name] < 2) {
                return camera;
            }

            seen[camera.name] = (seen[camera.name] || 0) + 1;

            return {
                id: camera.id,
                label: camera.label,
                kind: camera.kind,
                name: camera.name + ' ' + seen[camera.name],
            };
        });
    }

    /**
     * Which camera to open with.
     *
     * A remembered choice always wins — the operator knows their workstation
     * better than any heuristic. Otherwise take the best lens by preference,
     * and only fall back to the last enumerated device when nothing at all can
     * be identified.
     *
     * @return string|null  device id
     */
    function pickDefault(devices, savedId) {
        if (!devices || devices.length === 0) {
            return null;
        }

        if (savedId) {
            var remembered = devices.filter(function (device) {
                return device.id === savedId;
            })[0];

            if (remembered) {
                return remembered.id;
            }
        }

        var described = describe(devices, {});

        for (var i = 0; i < PREFERENCE.length; i++) {
            var match = described.filter(function (camera) {
                return camera.kind === PREFERENCE[i];
            })[0];

            if (match) {
                return match.id;
            }
        }

        return devices[devices.length - 1].id;
    }

    var CameraPicker = {
        classify: classify,
        describe: describe,
        pickDefault: pickDefault,
        KINDS: KINDS.map(function (entry) { return entry.kind; }),
        PREFERENCE: PREFERENCE,
    };

    global.EmuniqCameraPicker = CameraPicker;

    if (typeof module === 'object') {
        module.exports = CameraPicker;
    }
})(globalThis);
