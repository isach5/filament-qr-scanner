/**
 * ScanSession — the decision layer of the camera scanner.
 *
 * Everything that decides whether a decoded code is a real read, the same code
 * still sitting in the camera frame, or a deliberate re-scan lives here, apart
 * from Alpine and the DOM so it can be tested directly. Two production bugs
 * came out of this logic, so it earns its own file.
 *
 * Plain script, no build step: it defines a global and is loaded on demand
 * alongside html5-qrcode.
 */
(function (global) {
    'use strict';

    // While a code stays in frame the decoder fires ~fps times a second. This
    // is the floor between any two decisions, whatever the code.
    var FRAME_THROTTLE_MS = 400;

    function ScanSession(options) {
        options = options || {};

        this.duplicateWindow = typeof options.duplicateWindow === 'number'
            ? options.duplicateWindow
            : 1500;

        this.reset();
    }

    ScanSession.FRAME_THROTTLE_MS = FRAME_THROTTLE_MS;

    /**
     * Decide what to do with a decoded string, and record the decision.
     *
     * Returns one of:
     *   'ignore' — nothing happened: empty read, decoder repeating itself, or
     *              the same code still in front of the lens.
     *   'accept' — a genuinely new code. Hand it to the server.
     *   'reject' — a code this session already consumed, presented again after
     *              a real gap. The operator meant it, and it is a mistake.
     */
    ScanSession.prototype.evaluate = function (text, now) {
        var code = String(text == null ? '' : text).trim();

        if (code === '') {
            return { action: 'ignore', code: '' };
        }

        if (this.lastDecisionAt && (now - this.lastDecisionAt) < FRAME_THROTTLE_MS) {
            return { action: 'ignore', code: code };
        }

        if (this.seen.has(code)) {
            // Refresh before deciding: holding one code in frame must not age
            // out another code sitting next to it on the same board.
            var lastSeenAt = this.seenAt.has(code) ? this.seenAt.get(code) : 0;
            var sinceLastSeen = now - lastSeenAt;

            this.seenAt.set(code, now);
            this.lastDecisionAt = now;

            if (sinceLastSeen < this.duplicateWindow) {
                return { action: 'ignore', code: code };
            }

            return { action: 'reject', code: code };
        }

        this.seen.add(code);
        this.seenAt.set(code, now);
        this.lastDecisionAt = now;
        this.count++;

        return { action: 'accept', code: code };
    };

    /**
     * Record a code the server refused, so the operator cannot get it through
     * by scanning it again. No timestamp: the next sighting should be treated
     * as a deliberate re-scan, not as the code still being in frame.
     */
    ScanSession.prototype.remember = function (code) {
        code = String(code == null ? '' : code).trim();

        if (code === '') {
            return false;
        }

        this.seen.add(code);

        return true;
    };

    /**
     * Treat every known code as if it were seen right now.
     *
     * Called when the camera reopens after a rejection. Without it, the gap
     * between rejection, acknowledgement and reopening exceeds the duplicate
     * window, and whatever is still in front of the lens fires a second
     * rejection the instant the camera comes back.
     */
    ScanSession.prototype.refresh = function (now) {
        var seenAt = this.seenAt;

        this.seen.forEach(function (code) {
            seenAt.set(code, now);
        });

        this.lastDecisionAt = 0;
    };

    /**
     * Forget everything. The working context changed — a different station, a
     * new inspection — so codes that were spent here may be valid there.
     */
    ScanSession.prototype.reset = function () {
        this.seen = new Set();
        this.seenAt = new Map();
        this.lastDecisionAt = 0;
        this.count = 0;
    };

    /** Has this session already consumed this code? */
    ScanSession.prototype.has = function (code) {
        return this.seen.has(String(code == null ? '' : code).trim());
    };

    global.EmuniqScanSession = ScanSession;

    // The only branch the test suite cannot reach: in Node the CommonJS arm is
    // always taken, in the browser never. Every browser that can run Alpine 3
    // and navigator.mediaDevices has globalThis, so no fallback is needed.
    if (typeof module === 'object') {
        module.exports = ScanSession;
    }
})(globalThis);
