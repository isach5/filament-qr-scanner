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
    x-on:close-modal.window="if ($event.detail?.id === '{{ $modalId }}') stopScanning()"
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
            await new Promise(r => setTimeout(r, 200));
            await this.startCamera();
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
                try {
                    running = running || (this.scanner.getRunningTrackSettings()?.deviceId ?? null);
                } catch (e) {}

                this.cameraId = EmuniqCameraPicker.resolveActive(this.cameras, running);
            } catch (e) {}
        },

        async startCamera() {
            if (this.active) await this.stopScanning();

            const readerId = 'qr-reader-{{ $modalId }}';
            const el = document.getElementById(readerId);
            if (!el) return;

            // An empty list leaves the decoder on its default: try every
            // symbology on every frame.
            this.scanner = this.formats.length
                ? new Html5Qrcode(readerId, { formatsToSupport: this.formats.map(name => Html5QrcodeSupportedFormats[name]) })
                : new Html5Qrcode(readerId);
            this.active = true;

            // A remembered device wins; otherwise ask for the rear camera by
            // constraint and let the platform hand over its default lens. This
            // is what keeps the whole open to a single permission request:
            // there is no device list to fetch before we can start.
            this.cameraId = this.cameraId || localStorage.getItem('qr-camera-id');
            const target = this.cameraId || { facingMode: 'environment' };

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
                this.readCameraCapabilities();
                await this.loadCameraList();

                if (this.cameraId) {
                    localStorage.setItem('qr-camera-id', this.cameraId);
                }
            } catch (e) {
                this.error = this.describeCameraError(e);
                this.active = false;

                // A remembered camera that no longer exists must not lock the
                // operator out: forget it so the next attempt asks the platform
                // for whatever rear camera it has.
                if (this.cameraId) {
                    localStorage.removeItem('qr-camera-id');
                    this.cameraId = null;
                }
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
                this.stopScanning();
                this.$dispatch('close-modal', { id: '{{ $modalId }}' });
            @endif
        },

        async switchCamera(newId) {
            this.cameraId = newId;
            await this.startCamera();
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
            if (this.scanner && this.active) {
                try { await this.scanner.stop(); } catch (e) {}
                try { this.scanner.clear(); } catch (e) {}
            }
            this.scanner = null;
            this.active = false;
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
            this.stopScanning();
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
                this.stopScanning();
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
    <x-filament::modal id="{{ $modalId }}" width="lg" :close-by-clicking-away="false">
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

            {{-- Viewfinder. max-width plus overflow hidden are a hard stop: the
                 library sizes its own <video> from the camera frame, and a
                 stream wider than the modal used to drag the whole dialog off
                 the side of a phone screen, close button included. --}}
            <div
                class="relative overflow-hidden rounded-xl bg-black ring-1 ring-gray-950/10 dark:ring-white/10"
                style="width: 100%; max-width: 100%; min-height: 300px;"
            >
                <div id="qr-reader-{{ $modalId }}" style="width: 100%; max-width: 100%; min-height: 300px; overflow: hidden;"></div>

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
                     rendered when the running track actually has them. --}}
                <div
                    x-show="torchSupported || zoomSupported"
                    x-cloak
                    style="position:absolute;left:0;right:0;bottom:0;display:flex;align-items:center;gap:.75rem;min-width:0;padding:1.5rem .75rem .75rem;background:linear-gradient(to top,rgba(3,7,18,.85),rgba(3,7,18,0))"
                >
                    <button
                        x-show="torchSupported"
                        type="button"
                        @click="toggleTorch()"
                        :aria-pressed="torchOn ? 'true' : 'false'"
                        aria-label="{{ __('filament-qr-scanner::scanner.torch') }}"
                        title="{{ __('filament-qr-scanner::scanner.torch') }}"
                        :style="torchOn
                            ? 'background:#fbbf24;color:#451a03'
                            : 'background:rgba(255,255,255,.18);color:#fff'"
                        style="display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;width:2.25rem;height:2.25rem;border-radius:9999px;backdrop-filter:blur(4px)"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4.5 w-4.5" style="width:1.125rem;height:1.125rem" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd" />
                        </svg>
                    </button>

                    <div x-show="zoomSupported" style="display:flex;align-items:center;gap:.5rem;min-width:0;flex:1">
                        <label
                            :for="'qr-zoom-{{ $modalId }}'"
                            style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0"
                        >{{ __('filament-qr-scanner::scanner.zoom') }}</label>
                        <input
                            :id="'qr-zoom-{{ $modalId }}'"
                            type="range"
                            style="min-width:0;flex:1;accent-color:#fff"
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
                @click="stopScanning(); $dispatch('close-modal', { id: '{{ $modalId }}' })"
            >
                {{ __('filament-qr-scanner::scanner.close') }}
            </x-filament::button>
        </x-slot>
    </x-filament::modal>
</div>
