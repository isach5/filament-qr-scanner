const test = require('node:test');
const assert = require('node:assert/strict');

const CameraPicker = require('../../resources/dist/camera-picker.js');

// Names as the component passes them in, Spanish side.
const NAMES = {
    front: 'Frontal',
    back: 'Trasera',
    wide: 'Gran angular',
    ultrawide: 'Ultra gran angular',
    telephoto: 'Teleobjetivo',
    macro: 'Macro',
    fallback: 'Cámara',
};

// What a real iPhone reports through Safari with the interface in Spanish —
// the device in the screenshot that started this: one front camera and three
// rear ones that all used to read "Trasera".
const IPHONE_ES = [
    { id: 'f1', label: 'Cámara frontal' },
    { id: 'b1', label: 'Cámara posterior gran angular' },
    { id: 'b2', label: 'Cámara posterior ultra gran angular' },
    { id: 'b3', label: 'Cámara posterior teleobjetivo' },
];

const IPHONE_EN = [
    { id: 'f1', label: 'Front Camera' },
    { id: 'b1', label: 'Back Dual Wide Camera' },
    { id: 'b2', label: 'Back Ultra Wide Camera' },
    { id: 'b3', label: 'Back Telephoto Camera' },
];

const ANDROID = [
    { id: 'a0', label: 'camera2 0, facing back' },
    { id: 'a1', label: 'camera2 1, facing front' },
];

const LAPTOP = [{ id: 'w1', label: 'FaceTime HD Camera (Built-in)' }];

test('classifies the lenses of a spanish iPhone', () => {
    assert.equal(CameraPicker.classify('Cámara frontal'), 'front');
    assert.equal(CameraPicker.classify('Cámara posterior gran angular'), 'wide');
    assert.equal(CameraPicker.classify('Cámara posterior ultra gran angular'), 'ultrawide');
    assert.equal(CameraPicker.classify('Cámara posterior teleobjetivo'), 'telephoto');
    assert.equal(CameraPicker.classify('Cámara posterior'), 'back');
});

test('classifies the lenses of an english iPhone', () => {
    assert.equal(CameraPicker.classify('Front Camera'), 'front');
    assert.equal(CameraPicker.classify('Back Camera'), 'back');
    assert.equal(CameraPicker.classify('Back Dual Wide Camera'), 'wide');
    assert.equal(CameraPicker.classify('Back Ultra Wide Camera'), 'ultrawide');
    assert.equal(CameraPicker.classify('Back Telephoto Camera'), 'telephoto');
    assert.equal(CameraPicker.classify('Back Macro Camera'), 'macro');
});

test('ultra wide is never mistaken for wide', () => {
    // "ultra gran angular" contains "gran angular", and "Ultra Wide" contains
    // "Wide". Order of the patterns is the whole point.
    assert.equal(CameraPicker.classify('Ultra Wide Camera'), 'ultrawide');
    assert.equal(CameraPicker.classify('ultra-wide'), 'ultrawide');
    assert.equal(CameraPicker.classify('Cámara ultra gran angular'), 'ultrawide');
});

test('TrueDepth is a front camera, not a depth sensor', () => {
    assert.equal(CameraPicker.classify('TrueDepth Camera'), 'front');
});

test('classifies android and laptop labels', () => {
    assert.equal(CameraPicker.classify('camera2 0, facing back'), 'back');
    assert.equal(CameraPicker.classify('camera2 1, facing front'), 'front');
    assert.equal(CameraPicker.classify('FaceTime HD Camera (Built-in)'), 'front');
});

test('an empty or missing label is unknown, not a crash', () => {
    assert.equal(CameraPicker.classify(''), 'unknown');
    assert.equal(CameraPicker.classify('   '), 'unknown');
    assert.equal(CameraPicker.classify('null'), 'unknown');
    assert.equal(CameraPicker.classify(null), 'unknown');
    assert.equal(CameraPicker.classify(undefined), 'unknown');
    assert.equal(CameraPicker.classify('a1b2c3d4e5'), 'unknown');
});

test('every rear lens of an iPhone gets its own readable name', () => {
    const described = CameraPicker.describe(IPHONE_ES, NAMES);

    assert.deepEqual(described.map((c) => c.name), [
        'Frontal',
        'Gran angular',
        'Ultra gran angular',
        'Teleobjetivo',
    ]);

    // The bug this replaces: three buttons all reading "Trasera".
    assert.equal(new Set(described.map((c) => c.name)).size, described.length);
});

test('names that would still collide get numbered', () => {
    const described = CameraPicker.describe(
        [
            { id: '1', label: 'Cámara posterior' },
            { id: '2', label: 'Cámara posterior' },
            { id: '3', label: 'Cámara frontal' },
        ],
        NAMES,
    );

    assert.deepEqual(described.map((c) => c.name), ['Trasera 1', 'Trasera 2', 'Frontal']);
});

test('an unrecognised but short label is shown as-is', () => {
    const described = CameraPicker.describe([{ id: '1', label: 'Logitech C920' }], NAMES);

    assert.equal(described[0].name, 'Logitech C920');
    assert.equal(described[0].kind, 'unknown');
});

test('an opaque identifier falls back to a numbered name', () => {
    const described = CameraPicker.describe(
        [{ id: '1', label: 'e8f4a1c09b2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f' }, { id: '2', label: '' }],
        NAMES,
    );

    assert.deepEqual(described.map((c) => c.name), ['Cámara 1', 'Cámara 2']);
});

test('describe survives an empty device list', () => {
    assert.deepEqual(CameraPicker.describe([], NAMES), []);
    assert.deepEqual(CameraPicker.describe(null, NAMES), []);
    assert.deepEqual(CameraPicker.describe([{ id: '1', label: 'Back Camera' }], null), [
        { id: '1', label: 'Back Camera', kind: 'back', name: 'Back Camera' },
    ]);
});

test('opens on the main wide lens, not on whatever came last', () => {
    // The regression: devices[devices.length - 1] handed the operator the
    // telephoto, which cannot focus at the distance a label is held.
    assert.equal(CameraPicker.pickDefault(IPHONE_ES), 'b1');
    assert.equal(CameraPicker.pickDefault(IPHONE_EN), 'b1');
});

test('prefers a plain rear camera over the extra lenses', () => {
    const devices = [
        { id: 'ultra', label: 'Back Ultra Wide Camera' },
        { id: 'plain', label: 'Back Camera' },
        { id: 'tele', label: 'Back Telephoto Camera' },
    ];

    assert.equal(CameraPicker.pickDefault(devices), 'plain');
});

test('falls back through the preference order', () => {
    assert.equal(
        CameraPicker.pickDefault([
            { id: 'tele', label: 'Back Telephoto Camera' },
            { id: 'ultra', label: 'Back Ultra Wide Camera' },
        ]),
        'ultra',
        'ultra wide beats telephoto when there is no plain rear lens',
    );

    assert.equal(
        CameraPicker.pickDefault([{ id: 'front', label: 'Front Camera' }]),
        'front',
        'a front camera is better than nothing',
    );
});

test('takes the rear camera on android', () => {
    assert.equal(CameraPicker.pickDefault(ANDROID), 'a0');
});

test('takes the only camera a laptop has', () => {
    assert.equal(CameraPicker.pickDefault(LAPTOP), 'w1');
});

test('unidentifiable devices fall back to the last one', () => {
    const devices = [{ id: 'x', label: '' }, { id: 'y', label: 'abcdef0123456789' }];

    assert.equal(CameraPicker.pickDefault(devices), 'y');
});

test('a remembered choice always wins', () => {
    // The operator knows their workstation better than the heuristic does.
    assert.equal(CameraPicker.pickDefault(IPHONE_ES, 'b3'), 'b3');
    assert.equal(CameraPicker.pickDefault(IPHONE_ES, 'f1'), 'f1');
});

test('a remembered camera that is gone is ignored', () => {
    assert.equal(CameraPicker.pickDefault(IPHONE_ES, 'unplugged'), 'b1');
});

test('picking from nothing returns nothing', () => {
    assert.equal(CameraPicker.pickDefault([]), null);
    assert.equal(CameraPicker.pickDefault(null), null);
    assert.equal(CameraPicker.pickDefault(undefined, 'whatever'), null);
});

test('the global is exported for the browser too', () => {
    assert.equal(globalThis.EmuniqCameraPicker, CameraPicker);
});

test('a device with no label at all is handled', () => {
    // Some platforms report devices before camera permission is granted, and
    // then the label is missing rather than empty.
    const described = CameraPicker.describe(
        [{ id: '1' }, { id: '2', label: null }, { id: '3', label: 'null' }],
        NAMES,
    );

    assert.deepEqual(described.map((c) => c.kind), ['unknown', 'unknown', 'unknown']);
    assert.deepEqual(described.map((c) => c.name), ['Cámara 1', 'Cámara 2', 'Cámara 3']);
    assert.equal(CameraPicker.pickDefault([{ id: '1' }, { id: '2' }]), '2');
});
