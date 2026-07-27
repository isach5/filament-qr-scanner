{{-- QR Camera Scanner Component --}}
{{-- Usage: <x-qr-camera-scanner wire-model="scanInput" wire-action="processScan" /> --}}
@props([
    'wireModel' => 'scanInput',
    'wireAction' => 'processScan',
    'wireActionArgs' => null,
    'buttonLabel' => null,
    'buttonColor' => 'primary',
    'buttonSize' => 'lg',
    'modalHeading' => null,
    'fps' => null,
    'qrboxSize' => null,
    'qrboxRatio' => null,
    'aspectRatio' => null,
    'keepAlive' => null,
    'formats' => null,
    'closeOnScan' => false,
])

@php
    use Emuniq\FilamentQrScanner\SupportedFormats;
    use Filament\Support\Facades\FilamentAsset;
    use Illuminate\Support\Js;

    $modalId = 'qr-scanner-modal-' . uniqid();
    $buttonLabel = $buttonLabel ?? __('filament-qr-scanner::scanner.button');
    $modalHeading = $modalHeading ?? __('filament-qr-scanner::scanner.modal_heading');
    $fps = $fps ?? config('filament-qr-scanner.scanner.fps', 10);
    $qrboxSize = $qrboxSize ?? config('filament-qr-scanner.scanner.qrbox', 250);
    $qrboxRatio = $qrboxRatio ?? config('filament-qr-scanner.scanner.qrbox_ratio');
    $aspectRatio = $aspectRatio ?? config('filament-qr-scanner.scanner.aspect_ratio');

    if ($aspectRatio !== null) {
        $aspectRatio = (float) $aspectRatio;

        if ($aspectRatio <= 0.0) {
            throw new InvalidArgumentException("aspect ratio must be greater than 0, got [{$aspectRatio}].");
        }
    }
    $duplicateWindow = config('filament-qr-scanner.scanner.duplicate_window', 1500);
    $nativeDecoder = (bool) config('filament-qr-scanner.scanner.native_decoder', true);
    $keepAlive = max(0, (int) ($keepAlive ?? config('filament-qr-scanner.scanner.keep_alive', 45)));

    // The viewfinder reserves its height from this ratio before the stream
    // exists, so the dialog never grows when the picture arrives. Follows
    // aspect_ratio when one is pinned; 4/3 is what facingMode hands over on
    // essentially every phone otherwise.
    $viewfinderRatio = $aspectRatio !== null ? $aspectRatio : '4 / 3';

    if ($qrboxRatio !== null) {
        $qrboxRatio = (float) $qrboxRatio;

        if ($qrboxRatio <= 0.0 || $qrboxRatio > 1.0) {
            throw new InvalidArgumentException(
                "qrbox ratio must be greater than 0 and at most 1, got [{$qrboxRatio}]."
            );
        }
    }

    // A ratio sizes the scan window against the live viewfinder, which a fixed
    // pixel box cannot do across a phone and a workstation monitor.
    $qrboxExpression = $qrboxRatio !== null
        ? '(w, h) => { const s = Math.round(Math.min(w, h) * ' . $qrboxRatio . '); return { width: s, height: s }; }'
        : '{ width: ' . (int) $qrboxSize . ', height: ' . (int) $qrboxSize . ' }';

    // Empty means "decode every symbology", which is the library's own default.
    $formats = SupportedFormats::normalise($formats ?? config('filament-qr-scanner.scanner.formats'));

    $scriptUrl = config('filament-qr-scanner.scanner.script_url')
        ?: FilamentAsset::getScriptSrc('html5-qrcode', 'emuniq/filament-qr-scanner');

    $sessionUrl = FilamentAsset::getScriptSrc('scan-session', 'emuniq/filament-qr-scanner');
    $pickerUrl = FilamentAsset::getScriptSrc('camera-picker', 'emuniq/filament-qr-scanner');

    $cameraNames = [
        'front' => __('filament-qr-scanner::scanner.camera_front'),
        'back' => __('filament-qr-scanner::scanner.camera_back'),
        'wide' => __('filament-qr-scanner::scanner.camera_wide'),
        'ultrawide' => __('filament-qr-scanner::scanner.camera_ultrawide'),
        'telephoto' => __('filament-qr-scanner::scanner.camera_telephoto'),
        'macro' => __('filament-qr-scanner::scanner.camera_macro'),
        'fallback' => __('filament-qr-scanner::scanner.camera'),
    ];

    // Normalised to an array so a single scalar and a list of arguments both
    // spread cleanly into $wire.call().
    $callArgs = $wireActionArgs === null
        ? []
        : (is_array($wireActionArgs) ? array_values($wireActionArgs) : [$wireActionArgs]);
@endphp

<div
    wire:ignore
    x-load-js="{{ Js::from([$scriptUrl, $sessionUrl, $pickerUrl]) }}"
    x-on:close-modal.window="if ($event.detail?.id === '{{ $modalId }}') suspendScanning()"
    x-on:scan-rejected.window="handleRejection($event.detail)"
    x-on:scanner-reset.window="resetSession()"
    x-on:scan-resume.window="resumeAfterRejection()"
    x-data="{
        scanner: null,
        cameras: [],
        cameraId: null,
        error: null,
        permissionState: 'prompt',
        loading: false,
        active: false,
        lastScannedCode: '',
        scanCount: 0,
        flashing: false,
        soundOn: localStorage.getItem('qr-scanner-sound') !== '0',
        wasOpenWhenRejected: false,
        paused: false,
        videoReady: false,
        starting: false,
        keepAliveMs: {{ (int) $keepAlive * 1000 }},
        _releaseTimer: null,
        formats: {{ Js::from($formats) }},

        torchSupported: false,
        torchOn: localStorage.getItem('qr-scanner-torch') === '1',
        zoomSupported: false,
        zoomMin: 1,
        zoomMax: 1,
        zoomStep: 0.1,
        zoomValue: 1,
        _torch: null,
        _zoom: null,

        // Set lazily: EmuniqScanSession arrives with x-load-js, which resolves
        // after x-data is evaluated.
        _session: null,
        session() {
            return this._session
                ??= new EmuniqScanSession({ duplicateWindow: {{ (int) $duplicateWindow }} });
        },

        cameraNames: {{ Js::from($cameraNames) }},

        async openScannerModal() {
            this.error = null;
            this.loading = true;
            this.starting = true;

            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                this.error = '{{ __('filament-qr-scanner::scanner.error_no_support') }}';
                this.loading = false;
                return;
            }

            // One getUserMedia per open, and only the one that actually starts
            // the camera. Probing for permission first and enumerating cameras
            // second meant three separate requests, and iOS Safari treats every
            // one of them as a reason to ask the operator again.
            this.loading = false;
            $dispatch('open-modal', { id: '{{ $modalId }}' });

            await this.$nextTick();
            await this.viewfinderReady();

            clearTimeout(this._releaseTimer);

            // Still parked from the last open: resuming touches no permission.
            if (this.scanner && this.active && this.paused) {
                try {
                    this.scanner.resume();
                    this.paused = false;
                    return;
                } catch (e) {
                    await this.stopScanning();
                }
            }

            await this.startCamera();
        },

        /**
         * The library measures the reader element when it starts, so it has to
         * be laid out first. This used to be a flat 200ms wait, which was most
         * of the time between the tap and the camera appearing — on a phone the
         * modal is usually laid out within one frame. Poll instead, with a cap
         * so a modal that never lays out cannot hang the open.
         */
        async viewfinderReady(timeoutMs = 400) {
            const el = document.getElementById('qr-reader-{{ $modalId }}');
            const deadline = performance.now() + timeoutMs;

            while (performance.now() < deadline) {
                if (el && el.clientWidth > 0) return;
                await new Promise(r => requestAnimationFrame(r));
            }
        },

        describeCameraError(e) {
            const name = e?.name || '';

            if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
                this.permissionState = 'denied';
                return '{{ __('filament-qr-scanner::scanner.error_denied') }}';
            }
            if (name === 'NotFoundError' || name === 'DevicesNotFoundError' || name === 'OverconstrainedError') {
                return '{{ __('filament-qr-scanner::scanner.error_not_found') }}';
            }
            if (name === 'NotReadableError' || name === 'TrackStartError') {
                return '{{ __('filament-qr-scanner::scanner.error_in_use') }}';
            }

            return '{{ __('filament-qr-scanner::scanner.error_start') }} ' + (e?.message || e);
        },

        /**
         * Fill the camera switcher from the stream that is already running.
         * enumerateDevices() only returns usable labels once permission has
         * been granted, and unlike Html5Qrcode.getCameras() it does not open a
         * second stream to get them.
         */
        async loadCameraList() {
            try {
                const devices = (await navigator.mediaDevices.enumerateDevices())
                    .filter(d => d.kind === 'videoinput')
                    .map(d => ({ id: d.deviceId, label: d.label }));

                this.cameras = EmuniqCameraPicker.describe(devices, this.cameraNames);

                // Ask the running track which device the browser actually
                // handed us. resolveActive() only trusts that id if it really
                // is in the list: a select bound to an unknown id silently
                // shows its first option, which had the switcher naming the
                // front camera while the rear one was streaming.
                let running = this.cameraId;
                let facing = null;
                try {
                    const settings = this.scanner.getRunningTrackSettings() ?? {};
                    running = running || settings.deviceId || null;
                    facing = settings.facingMode ?? null;
                } catch (e) {}

                this.cameraId = EmuniqCameraPicker.resolveActive(this.cameras, running, facing);
            } catch (e) {}
        },

        async startCamera(deviceId = null) {
            if (this.active) await this.stopScanning();

            const readerId = 'qr-reader-{{ $modalId }}';
            const el = document.getElementById(readerId);
            if (!el) return;

            // An empty list leaves the decoder on its default: try every
            // symbology on every frame.
            this.videoReady = false;
            this.scanner = this.formats.length
                ? new Html5Qrcode(readerId, { formatsToSupport: this.formats.map(name => Html5QrcodeSupportedFormats[name]) })
                : new Html5Qrcode(readerId);
            this.active = true;

            // Opening never asks for a specific device. A deviceId constraint
            // fails with OverconstrainedError as soon as the remembered id has
            // gone stale — Safari reissues them every session — and a failed
            // getUserMedia is a wasted permission prompt. facingMode always
            // resolves to something. A remembered camera is applied afterwards
            // by applyRememberedCamera(), on a permission already granted.
            const target = deviceId || { facingMode: 'environment' };

            try {
                await this.scanner.start(
                    target,
                    {
                        fps: {{ (int) $fps }},
                        qrbox: {!! $qrboxExpression !!},
                        @if($aspectRatio !== null)
                            aspectRatio: {{ $aspectRatio }},
                        @endif
                        // Browser-native decoding where it exists (Chrome and
                        // Edge, Android included), bundled javascript decoder
                        // everywhere else.
                        useBarCodeDetectorIfSupported: {{ $nativeDecoder ? 'true' : 'false' }},
                    },
                    (text) => this.onDetected(text),
                    () => {}
                );
                this.permissionState = 'granted';
                this.paused = false;
                this.videoReady = true;
                this.starting = false;
                this.requestContinuousFocus();
                this.readCameraCapabilities();
                await this.loadCameraList();

                if (deviceId === null) await this.applyRememberedCamera();
            } catch (e) {
                this.active = false;
                this.starting = false;
                this.error = this.describeCameraError(e);
            }
        },

        /**
         * Switch to the camera the operator chose last time, if it is still
         * here. Deliberately after the stream is up: by now the permission is
         * granted for this session, so this costs no prompt even though it is
         * a second getUserMedia.
         */
        async applyRememberedCamera() {
            const remembered = localStorage.getItem('qr-camera-id');

            if (! remembered || remembered === this.cameraId) return;

            const wanted = this.cameras.find(camera => camera.id === remembered);

            if (! wanted) {
                localStorage.removeItem('qr-camera-id');
                return;
            }

            // Restarting costs a second camera negotiation, which on a phone is
            // the difference between a quick open and a visibly slow one. The
            // platform's rear camera is normally the very lens the operator
            // picked, so only pay for it when the kind actually differs.
            const running = this.cameras.find(camera => camera.id === this.cameraId);

            if (running && running.kind === wanted.kind) {
                this.cameraId = remembered;
                return;
            }

            this.cameraId = remembered;
            await this.startCamera(remembered);
        },

        /**
         * Closing the modal parks the camera instead of releasing it, so
         * reopening costs no getUserMedia at all — which on iOS Safari is what
         * an operator experiences as being asked for the camera again. After
         * keepAliveMs with the modal shut it is released for real, so the
         * recording indicator does not sit on for the rest of the shift.
         */
        suspendScanning() {
            if (! this.scanner || ! this.active || this.paused) return this.stopScanning();

            try {
                this.scanner.pause(true);
                this.paused = true;
            } catch (e) {
                return this.stopScanning();
            }

            clearTimeout(this._releaseTimer);

            if (this.keepAliveMs > 0) {
                this._releaseTimer = setTimeout(() => this.stopScanning(), this.keepAliveMs);
            } else {
                this.stopScanning();
            }
        },

        onDetected(text) {
            // Every dedup decision lives in EmuniqScanSession, unit tested on
            // its own. Here we only carry out what it decided.
            const { action, code } = this.session().evaluate(text, Date.now());

            if (action === 'ignore') return;

            if (action === 'reject') {
                this.lastScannedCode = code;
                this.closeWithError(code, '{{ __('filament-qr-scanner::scanner.duplicate_local') }}');
                return;
            }

            this.lastScannedCode = code;
            this.scanCount = this.session().count;
            this.flashOk();
            if (this.soundOn) this.beep();

            $wire.set('{{ $wireModel }}', code);
            $wire.call('{{ $wireAction }}'@if($callArgs !== []), ...{{ Js::from($callArgs) }}@endif);

            @if($closeOnScan)
                // Single-shot / lookup mode: close after each scan.
                this.suspendScanning();
                this.$dispatch('close-modal', { id: '{{ $modalId }}' });
            @endif
        },

        async switchCamera(newId) {
            this.cameraId = newId;
            localStorage.setItem('qr-camera-id', newId);
            await this.startCamera(newId);
        },

        /**
         * Torch and zoom belong to the video track, so they can only be read
         * once the camera is running, and they differ per device: most laptop
         * webcams have neither, most phone back cameras have both.
         */
        readCameraCapabilities() {
            this.torchSupported = false;
            this.zoomSupported = false;
            this._torch = null;
            this._zoom = null;

            let capabilities;

            try {
                capabilities = this.scanner.getRunningTrackCameraCapabilities();
            } catch (e) {
                return;
            }

            try {
                const torch = capabilities.torchFeature();
                if (torch.isSupported()) {
                    this._torch = torch;
                    this.torchSupported = true;
                }
            } catch (e) {}

            try {
                const zoom = capabilities.zoomFeature();
                if (zoom.isSupported()) {
                    this._zoom = zoom;
                    this.zoomSupported = true;
                    this.zoomMin = zoom.min();
                    this.zoomMax = zoom.max();
                    this.zoomStep = zoom.step() || 0.1;
                    this.zoomValue = zoom.value() ?? this.zoomMin;
                }
            } catch (e) {}

            // Re-apply what the operator chose last time. A station in a dark
            // corner should not need the torch switched on at every scan.
            if (this.torchSupported && this.torchOn) this.applyTorch(true);

            if (this.zoomSupported) {
                const saved = parseFloat(localStorage.getItem('qr-scanner-zoom'));
                if (!isNaN(saved) && saved >= this.zoomMin && saved <= this.zoomMax) {
                    this.applyZoom(saved);
                }
            }
        },

        /**
         * Ask for continuous autofocus. A label held at arm's length in front of
         * a camera parked on a fixed focus distance simply never resolves, and
         * the operator ends up waving the phone about. Not every device exposes
         * it; the ones that do not are no worse off.
         */
        requestContinuousFocus() {
            try {
                const capabilities = this.scanner.getRunningTrackCapabilities?.() ?? {};

                if (Array.isArray(capabilities.focusMode) && capabilities.focusMode.includes('continuous')) {
                    this.scanner.applyVideoConstraints({ advanced: [{ focusMode: 'continuous' }] });
                }
            } catch (e) {}
        },

        async applyTorch(on) {
            if (!this._torch) return;
            try {
                await this._torch.apply(on);
            } catch (e) {
                // Some devices advertise the capability and refuse it anyway.
                this.torchSupported = false;
            }
        },

        toggleTorch() {
            this.torchOn = !this.torchOn;
            localStorage.setItem('qr-scanner-torch', this.torchOn ? '1' : '0');
            this.applyTorch(this.torchOn);
        },

        async applyZoom(value) {
            if (!this._zoom) return;
            this.zoomValue = value;
            localStorage.setItem('qr-scanner-zoom', String(value));
            try {
                await this._zoom.apply(value);
            } catch (e) {
                this.zoomSupported = false;
            }
        },

        async stopScanning() {
            clearTimeout(this._releaseTimer);
            this.paused = false;

            if (this.scanner && this.active) {
                try { await this.scanner.stop(); } catch (e) {}
                try { this.scanner.clear(); } catch (e) {}
            }
            this.scanner = null;
            this.active = false;
            this.videoReady = false;
            this.starting = false;
        },

        flashOk() {
            this.flashing = true;
            setTimeout(() => { this.flashing = false; }, 350);
        },

        beep() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.frequency.value = 880;
                osc.type = 'sine';
                gain.gain.setValueAtTime(0.15, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.15);
                osc.connect(gain).connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 0.15);
            } catch (e) {}
        },

        toggleSound() {
            this.soundOn = !this.soundOn;
            localStorage.setItem('qr-scanner-sound', this.soundOn ? '1' : '0');
        },

        closeWithError(qrCode, message) {
            // Client-side rejection (duplicate within the session): the server
            // was never called, so fire scan-rejected ourselves and let the
            // page-level overlay show the error.
            this.wasOpenWhenRejected = true;
            this.suspendScanning();
            this.$dispatch('close-modal', { id: '{{ $modalId }}' });
            this.$dispatch('scan-rejected', { message, qrCode });
        },

        handleRejection(detail) {
            if (!detail || !detail.qrCode) return;
            // Remember the code locally so the operator cannot bypass a
            // server-side rejection by scanning it again.
            this.session().remember(detail.qrCode);
            if (this.active) {
                this.wasOpenWhenRejected = true;
                this.suspendScanning();
                this.$dispatch('close-modal', { id: '{{ $modalId }}' });
            }
        },

        resumeAfterRejection() {
            if (!this.wasOpenWhenRejected) return;
            this.wasOpenWhenRejected = false;
            this.session().refresh(Date.now());
            this.openScannerModal();
        },

        resetSession() {
            // Called when the operating context changes (a station switch, a
            // new inspection). Old codes are no longer relevant, so clear the
            // dedup memory and let the operator scan pieces that were valid
            // somewhere else.
            this.session().reset();
            this.scanCount = 0;
            this.lastScannedCode = '';
            this.wasOpenWhenRejected = false;
        }
    }"
    {{ $attributes->merge(['class' => 'space-y-3']) }}
>
    {{-- Open camera button --}}
    <x-filament::button
        :color="$buttonColor"
        icon="heroicon-o-camera"
        icon-size="lg"
        :size="$buttonSize"
        x-on:click="openScannerModal()"
        x-bind:disabled="loading"
    >
        <span x-show="!loading" x-cloak>{{ $buttonLabel }}</span>
        <span x-show="loading" x-cloak>
            <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
        </span>
    </x-filament::button>

    {{-- Permission denied help --}}
    <div x-show="permissionState === 'denied'" x-cloak class="p-4 rounded-xl bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-700 text-sm space-y-2">
        <p class="font-semibold text-amber-800 dark:text-amber-200">{{ __('filament-qr-scanner::scanner.permission_title') }}</p>
        <ul class="list-disc list-inside text-amber-700 dark:text-amber-300 space-y-1">
            <li><strong>iPhone/iPad (Safari):</strong> {{ __('filament-qr-scanner::scanner.permission_ios') }}</li>
            <li><strong>Android (Chrome):</strong> {{ __('filament-qr-scanner::scanner.permission_android') }}</li>
            <li><strong>Chrome (PC/Mac):</strong> {{ __('filament-qr-scanner::scanner.permission_chrome') }}</li>
            <li><strong>Firefox:</strong> {{ __('filament-qr-scanner::scanner.permission_firefox') }}</li>
        </ul>
        <p class="text-amber-600 dark:text-amber-400">{{ __('filament-qr-scanner::scanner.permission_reload') }}</p>
    </div>

    {{-- Error (non-permission) --}}
    <div x-show="error && permissionState !== 'denied'" x-cloak class="p-3 rounded-xl bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-300 text-sm" x-text="error"></div>

    {{-- Filament Modal --}}
    {{-- sticky-footer: en un telefono el visor mas los controles empujaban el
         boton de cerrar fuera de la pantalla. El cuerpo se desplaza, el pie no. --}}
    <x-filament::modal
        id="{{ $modalId }}"
        width="lg"
        :close-by-clicking-away="false"
        sticky-footer
    >
        <x-slot name="heading">
            {{ $modalHeading }}
        </x-slot>

        <div class="w-full min-w-0 space-y-3">
            {{-- Toolbar. The camera select is a select and not a row of pills
                 on purpose: a flex row of buttons hands the dialog its full
                 intrinsic width however it is clipped, and four lenses with
                 long names pushed the modal off the side of a phone. --}}
            <div class="flex w-full min-w-0 items-center gap-2">
                <label
                    x-show="cameras.length > 1"
                    x-cloak
                    :for="'qr-camera-{{ $modalId }}'"
                    class="shrink-0 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400"
                >{{ __('filament-qr-scanner::scanner.camera') }}</label>
                <select
                    x-show="cameras.length > 1"
                    x-cloak
                    :id="'qr-camera-{{ $modalId }}'"
                    x-model="cameraId"
                    @change="switchCamera($event.target.value)"
                    class="min-w-0 flex-1 truncate rounded-lg border-gray-300 bg-white py-1.5 pl-3 pr-8 text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white"
                >
                    <template x-for="cam in cameras" :key="cam.id">
                        <option :value="cam.id" x-text="cam.name"></option>
                    </template>
                </select>

                <div class="ml-auto shrink-0">
                    <button
                        type="button"
                        @click="toggleSound()"
                        :aria-pressed="soundOn ? 'true' : 'false'"
                        :aria-label="soundOn
                            ? '{{ __('filament-qr-scanner::scanner.sound_on') }}'
                            : '{{ __('filament-qr-scanner::scanner.sound_off') }}'"
                        :title="soundOn
                            ? '{{ __('filament-qr-scanner::scanner.sound_on') }}'
                            : '{{ __('filament-qr-scanner::scanner.sound_off') }}'"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 transition hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/5"
                    >
                        <svg x-show="soundOn" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M9.383 3.076A1 1 0 0110 4v12a1 1 0 01-1.707.707L4.586 13H2a1 1 0 01-1-1V8a1 1 0 011-1h2.586l3.707-3.707a1 1 0 011.09-.217zM14.657 2.929a1 1 0 011.414 0A9.972 9.972 0 0119 10a9.972 9.972 0 01-2.929 7.071 1 1 0 01-1.414-1.414A7.971 7.971 0 0017 10c0-2.21-.894-4.208-2.343-5.657a1 1 0 010-1.414zm-2.829 2.828a1 1 0 011.415 0A5.983 5.983 0 0115 10a5.984 5.984 0 01-1.757 4.243 1 1 0 01-1.415-1.415A3.984 3.984 0 0013 10a3.983 3.983 0 00-1.172-2.828 1 1 0 010-1.415z" clip-rule="evenodd" />
                        </svg>
                        <svg x-show="!soundOn" x-cloak xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M9.383 3.076A1 1 0 0110 4v12a1 1 0 01-1.707.707L4.586 13H2a1 1 0 01-1-1V8a1 1 0 011-1h2.586l3.707-3.707a1 1 0 011.09-.217zM12.293 7.293a1 1 0 011.414 0L15 8.586l1.293-1.293a1 1 0 111.414 1.414L16.414 10l1.293 1.293a1 1 0 01-1.414 1.414L15 11.414l-1.293 1.293a1 1 0 01-1.414-1.414L13.586 10l-1.293-1.293a1 1 0 010-1.414z" clip-rule="evenodd" />
                        </svg>
                    </button>
                </div>
            </div>

            <style>
                /* html5-qrcode's own overlay is sized from the frame it asked
                   for, not the one it got, so it renders as a rectangle when the
                   browser ignores the requested aspect ratio. Ours replaces it. */
                #qr-reader-{{ $modalId }} #qr-shaded-region { display: none !important; }
                /* contain, not cover: the whole frame stays visible, so what
                   the operator aims at is what gets decoded. A frame whose
                   shape differs from the reserved box shows thin dark bars
                   rather than resizing the dialog. */
                #qr-reader-{{ $modalId }} video {
                    display: block;
                    width: 100% !important;
                    height: 100% !important;
                    object-fit: contain;
                }
                @keyframes spin { to { transform: rotate(360deg); } }
            </style>

            {{-- Viewfinder. max-width plus overflow hidden are a hard stop: the
                 library sizes its own <video> from the camera frame, and a
                 stream wider than the modal used to drag the whole dialog off
                 the side of a phone screen, close button included. --}}
            <div
                class="relative overflow-hidden rounded-xl"
                style="width: 100%; max-width: 100%; aspect-ratio: {{ $viewfinderRatio }}; max-height: 52vh; background: #030712; box-shadow: inset 0 0 0 1px rgba(255,255,255,.08)"
            >
                {{-- Absolutely positioned so it fills the reserved box without
                     contributing to layout. The box's height comes from its
                     aspect ratio, decided before any video exists, so the dialog
                     does not grow the moment the picture arrives. --}}
                <div id="qr-reader-{{ $modalId }}" style="position: absolute; inset: 0; overflow: hidden;"></div>

                {{-- Our own aiming square. The library draws one too, but it
                     sizes it from the frame it asked for rather than the frame
                     it got, so it comes out as a rectangle whenever the browser
                     ignores the requested aspect ratio — which Safari does. A
                     box with aspect-ratio:1 is square by construction, on every
                     device, always. Theirs is hidden in the style block below.

                     Sized from the HEIGHT, not the width: the feed is normally
                     landscape, so the short side is what has to hold the box —
                     the same basis the qrbox uses. max-width covers a portrait
                     feed, where the width becomes the short side. --}}
                <div
                    aria-hidden="true"
                    style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);height:{{ (int) round(($qrboxRatio ?? 0.7) * 100) }}%;width:auto;max-width:{{ (int) round(($qrboxRatio ?? 0.7) * 100) }}%;aspect-ratio:1;pointer-events:none"
                >
                    @foreach ([
                        'top:0;left:0;border-top-width:3px;border-left-width:3px',
                        'top:0;right:0;border-top-width:3px;border-right-width:3px',
                        'bottom:0;left:0;border-bottom-width:3px;border-left-width:3px',
                        'bottom:0;right:0;border-bottom-width:3px;border-right-width:3px',
                    ] as $corner)
                        {{-- border-width:0 first: a shorthand after the
                             per-side longhands would reset them and the guide
                             would render as nothing at all. --}}
                        <span style="position:absolute;width:14%;height:14%;border-color:#fff;border-style:solid;border-width:0;{{ $corner }}"></span>
                    @endforeach
                </div>

                <div
                    x-show="starting && ! videoReady"
                    x-cloak
                    style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;gap:.5rem;color:rgba(255,255,255,.85);font-size:.8125rem"
                >
                    <svg style="width:1.125rem;height:1.125rem;animation:spin 1s linear infinite" fill="none" viewBox="0 0 24 24">
                        <circle style="opacity:.25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path style="opacity:.75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span>{{ __('filament-qr-scanner::scanner.starting') }}</span>
                </div>

                <div
                    x-show="flashing"
                    x-cloak
                    class="pointer-events-none absolute inset-0 bg-green-400/60 transition-opacity duration-300 motion-reduce:transition-none"
                ></div>

                {{-- Reading counter, kept out of the way of the scan window. --}}
                <div
                    x-show="scanCount > 0"
                    x-cloak
                    class="pointer-events-none"
                    style="position:absolute;top:.5rem;right:.5rem;border-radius:9999px;background:rgba(3,7,18,.65);color:#fff;font-size:.75rem;font-weight:600;padding:.25rem .625rem;backdrop-filter:blur(4px)"
                    x-text="scanCount + (scanCount === 1 ? ' {{ __('filament-qr-scanner::scanner.reading_singular') }}' : ' {{ __('filament-qr-scanner::scanner.reading_plural') }}')"
                ></div>

                {{-- Torch and zoom sit on the feed, the way a camera app puts
                     them: where the operator is already looking, and costing no
                     extra height in a dialog that has little to spare. Only
                     rendered when the running track actually has them.

                     x-show is deliberately never on an element that needs a
                     display of its own. Alpine implements show by REMOVING the
                     inline display property, which silently wiped the flex
                     layout here and left the torch icon stacked above its label
                     with the zoom slider pushed onto another line. Wrappers
                     carry x-show; the elements inside carry the layout. --}}
                <div
                    x-show="torchSupported || zoomSupported"
                    x-cloak
                    style="position:absolute;left:0;right:0;bottom:0;padding:1.5rem .75rem .75rem;background:linear-gradient(to top,rgba(3,7,18,.85),rgba(3,7,18,0))"
                >
                    <div style="display:flex;flex-wrap:nowrap;align-items:center;gap:.5rem;min-width:0">
                        <span x-show="torchSupported" style="flex:0 0 auto">
                            <button
                                type="button"
                                @click="toggleTorch()"
                                :aria-pressed="torchOn ? 'true' : 'false'"
                                aria-label="{{ __('filament-qr-scanner::scanner.torch') }}"
                                {{-- An object, not a string: x-bind:style with a
                                     string REPLACES the static style attribute
                                     outright instead of merging into it. --}}
                                :style="{
                                    background: torchOn ? '#fbbf24' : 'rgba(255,255,255,.18)',
                                    color: torchOn ? '#451a03' : '#fff',
                                }"
                                style="display:inline-flex;align-items:center;gap:.375rem;justify-content:center;white-space:nowrap;height:2.25rem;padding:0 .75rem;border-radius:9999px;font-size:.75rem;font-weight:600;backdrop-filter:blur(4px)"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" style="width:1.125rem;height:1.125rem;flex-shrink:0" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd" />
                                </svg>
                                <span>{{ __('filament-qr-scanner::scanner.torch') }}</span>
                            </button>
                        </span>

                        <span x-show="zoomSupported" style="flex:1 1 auto;min-width:0">
                            <span style="display:flex;align-items:center;gap:.5rem;min-width:0">
                                <label
                                    :for="'qr-zoom-{{ $modalId }}'"
                                    style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0"
                                >{{ __('filament-qr-scanner::scanner.zoom') }}</label>
                                <input
                                    :id="'qr-zoom-{{ $modalId }}'"
                                    type="range"
                                    style="min-width:0;flex:1 1 auto;accent-color:#fff"
                                    :min="zoomMin"
                                    :max="zoomMax"
                                    :step="zoomStep"
                                    :value="zoomValue"
                                    :aria-valuetext="zoomValue + 'x'"
                                    @input="applyZoom(parseFloat($event.target.value))"
                                />
                                <span
                                    style="flex-shrink:0;width:2.25rem;text-align:right;font-size:.75rem;font-weight:500;font-variant-numeric:tabular-nums;color:#fff"
                                    x-text="zoomValue + 'x'"
                                ></span>
                            </span>
                        </span>
                    </div>
                </div>
            </div>

            {{-- Last read. aria-live because the flash and the beep are the
                 only other feedback a scan gets. --}}
            <div
                x-show="lastScannedCode"
                x-cloak
                aria-live="polite"
                class="flex min-w-0 items-center gap-2 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-900 ring-1 ring-green-600/20 dark:bg-green-500/10 dark:text-green-200 dark:ring-green-400/20"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                </svg>
                <span class="truncate font-mono" x-text="lastScannedCode"></span>
            </div>

            {{-- Hint --}}
            <p x-show="!lastScannedCode" x-cloak class="text-center text-xs text-gray-500 dark:text-gray-400">
                {{ __('filament-qr-scanner::scanner.continuous_hint') }}
            </p>

            {{-- Error inside modal --}}
            <div x-show="error" x-cloak class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-600/20 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-400/20" x-text="error"></div>
        </div>

        <x-slot name="footerActions">
            {{-- One neutral, full-width action. Closing the scanner destroys
                 nothing, so danger colouring was wrong, and on a shop floor red
                 reads as stop / abort / something broke. --}}
            <x-filament::button
                color="gray"
                class="w-full"
                @click="suspendScanning(); $dispatch('close-modal', { id: '{{ $modalId }}' })"
            >
                {{ __('filament-qr-scanner::scanner.close') }}
            </x-filament::button>
        </x-slot>
    </x-filament::modal>
</div>
