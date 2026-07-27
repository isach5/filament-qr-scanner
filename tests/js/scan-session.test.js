const test = require('node:test');
const assert = require('node:assert/strict');

const ScanSession = require('../../resources/dist/scan-session.js');

const WINDOW = 1500;
const THROTTLE = ScanSession.FRAME_THROTTLE_MS;

function session(options) {
    return new ScanSession(Object.assign({ duplicateWindow: WINDOW }, options));
}

test('a brand new code is accepted', () => {
    const s = session();

    assert.deepEqual(s.evaluate('PIEZA-1', 1000), { action: 'accept', code: 'PIEZA-1' });
    assert.equal(s.count, 1);
    assert.equal(s.has('PIEZA-1'), true);
});

test('blank reads never reach the server', () => {
    const s = session();

    for (const blank of ['', '   ', '\n\t', null, undefined]) {
        assert.equal(s.evaluate(blank, 1000).action, 'ignore');
    }

    assert.equal(s.count, 0);
});

test('surrounding whitespace is stripped before anything else', () => {
    const s = session();

    assert.equal(s.evaluate('  PIEZA-1  ', 1000).code, 'PIEZA-1');
    // Same code, so the padded and the clean form must not both count.
    assert.equal(s.evaluate('PIEZA-1', 1000 + WINDOW + 1).action, 'reject');
    assert.equal(s.count, 1);
});

test('decoder repeats inside the frame throttle are dropped', () => {
    const s = session();

    s.evaluate('PIEZA-1', 1000);

    // html5-qrcode fires ~10x/s. A different code arriving 100ms later is the
    // decoder being fast, not the operator being fast.
    assert.equal(s.evaluate('PIEZA-2', 1000 + THROTTLE - 1).action, 'ignore');
    assert.equal(s.has('PIEZA-2'), false);

    assert.equal(s.evaluate('PIEZA-2', 1000 + THROTTLE).action, 'accept');
});

test('a code held in front of the lens stays silent', () => {
    const s = session();

    s.evaluate('PIEZA-1', 0);

    // Every re-detection refreshes the code's timestamp, so holding it there
    // for a whole minute never trips the duplicate window.
    let now = 0;
    for (let i = 0; i < 120; i++) {
        now += 500;
        assert.equal(s.evaluate('PIEZA-1', now).action, 'ignore', `at ${now}ms`);
    }
});

test('re-presenting a code after a real gap is rejected', () => {
    const s = session();

    s.evaluate('PIEZA-1', 0);

    assert.deepEqual(s.evaluate('PIEZA-1', WINDOW + 1), { action: 'reject', code: 'PIEZA-1' });
    assert.equal(s.count, 1, 'a rejection is not a read');
});

test('the duplicate window is exclusive: exactly at the limit is already a re-scan', () => {
    const s = session();

    s.evaluate('PIEZA-1', 0);

    assert.equal(s.evaluate('PIEZA-1', WINDOW - 1).action, 'ignore');

    const later = session();
    later.evaluate('PIEZA-1', 0);
    assert.equal(later.evaluate('PIEZA-1', WINDOW).action, 'reject');
});

test('two labels alternating in frame are not duplicates of each other', () => {
    // The regression that started all of this: a single lastScannedCode made
    // adjacent labels on the same board reject each other.
    const s = session();
    let now = 0;

    assert.equal(s.evaluate('PIEZA-A', now).action, 'accept');
    assert.equal(s.evaluate('PIEZA-B', (now += THROTTLE)).action, 'accept');

    for (let i = 0; i < 20; i++) {
        assert.equal(s.evaluate('PIEZA-A', (now += THROTTLE)).action, 'ignore', `A at ${now}ms`);
        assert.equal(s.evaluate('PIEZA-B', (now += THROTTLE)).action, 'ignore', `B at ${now}ms`);
    }

    assert.equal(s.count, 2);
});

test('a code the server refused cannot be scanned through', () => {
    const s = session();

    s.remember('PIEZA-MALA');

    assert.equal(s.has('PIEZA-MALA'), true);
    assert.equal(s.evaluate('PIEZA-MALA', 5000).action, 'reject');
    assert.equal(s.count, 0);
});

test('remembering ignores empty input', () => {
    const s = session();

    assert.equal(s.remember(''), false);
    assert.equal(s.remember('   '), false);
    assert.equal(s.remember(null), false);
    assert.equal(s.remember(undefined), false);
    assert.equal(s.remember(' PIEZA-1 '), true);
    assert.equal(s.has('PIEZA-1'), true);
});

test('reopening the camera after a rejection does not fire a second one', () => {
    const s = session();

    s.evaluate('PIEZA-1', 0);

    // Rejected at 2s, operator reads the overlay, taps "got it" at 10s. The
    // piece never left the frame, so the camera must come back quiet.
    assert.equal(s.evaluate('PIEZA-1', 2000).action, 'reject');

    s.refresh(10000);

    assert.equal(s.evaluate('PIEZA-1', 10100).action, 'ignore');
});

test('refresh clears the frame throttle so the first read after reopening lands', () => {
    const s = session();

    s.evaluate('PIEZA-1', 0);
    s.refresh(10000);

    // Without clearing lastDecisionAt this would be inside the throttle of a
    // decision taken before the camera was even closed.
    assert.equal(s.evaluate('PIEZA-2', 10001).action, 'accept');
});

test('refresh on an untouched session is harmless', () => {
    const s = session();

    s.refresh(10000);

    assert.equal(s.evaluate('PIEZA-1', 10001).action, 'accept');
});

test('a context change frees every code again', () => {
    const s = session();

    s.evaluate('PIEZA-1', 0);
    s.evaluate('PIEZA-2', THROTTLE);
    assert.equal(s.count, 2);

    s.reset();

    assert.equal(s.count, 0);
    assert.equal(s.has('PIEZA-1'), false);
    // Same instant it was rejected at before the reset — now accepted.
    assert.equal(s.evaluate('PIEZA-1', THROTTLE * 2).action, 'accept');
});

test('reset also drops server-side rejections', () => {
    const s = session();

    s.remember('PIEZA-MALA');
    s.reset();

    assert.equal(s.evaluate('PIEZA-MALA', 1000).action, 'accept');
});

test('the duplicate window is configurable and defaults to 1500ms', () => {
    assert.equal(new ScanSession().duplicateWindow, 1500);
    assert.equal(new ScanSession({}).duplicateWindow, 1500);
    assert.equal(new ScanSession({ duplicateWindow: 0 }).duplicateWindow, 0);
    assert.equal(new ScanSession({ duplicateWindow: 'nope' }).duplicateWindow, 1500);

    const strict = session({ duplicateWindow: 0 });
    strict.evaluate('PIEZA-1', 0);
    // Window of zero: anything past the frame throttle is a deliberate re-scan.
    assert.equal(strict.evaluate('PIEZA-1', THROTTLE).action, 'reject');
});

test('has() normalises the same way evaluate() does', () => {
    const s = session();

    s.evaluate('PIEZA-1', 0);

    assert.equal(s.has(' PIEZA-1 '), true);
    assert.equal(s.has('PIEZA-2'), false);
    assert.equal(s.has(null), false);
    assert.equal(s.has(undefined), false);
});

test('numbers and other non-strings are coerced, not crashed on', () => {
    const s = session();

    assert.deepEqual(s.evaluate(12345, 0), { action: 'accept', code: '12345' });
    assert.equal(s.has('12345'), true);
});

test('a long shift accumulates without confusing itself', () => {
    const s = session();
    let now = 0;

    for (let i = 0; i < 2000; i++) {
        now += THROTTLE;
        assert.equal(s.evaluate(`PIEZA-${i}`, now).action, 'accept');
    }

    assert.equal(s.count, 2000);
    assert.equal(s.evaluate('PIEZA-0', (now += THROTTLE)).action, 'reject');
});

test('the global is exported for the browser too', () => {
    assert.equal(globalThis.EmuniqScanSession, ScanSession);
});
