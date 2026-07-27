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
    $duplicateWindow = config('filament-qr-scanner.scanner.duplicate_window', 1500);

    // Empty means "decode every symbology", which is the library's own default.
    $formats = SupportedFormats::normalise($formats ?? config('filament-qr-scanner.scanner.formats'));

    $scriptUrl = config('filament-qr-scanner.scanner.script_url')
        ?: FilamentAsset::getScriptSrc('html5-qrcode', 'emuniq/filament-qr-scanner');

    $sessionUrl = FilamentAsset::getScriptSrc('scan-session', 'emuniq/filament-qr-scanner');

    // Normalised to an array so a single scalar and a list of arguments both
    // spread cleanly into $wire.call().
    $callArgs = $wireActionArgs === null
        ? []
        : (is_array($wireActionArgs) ? array_values($wireActionArgs) : [$wireActionArgs]);
@endphp

<div
    wire:ignore
    x-load-js="{{ Js::from([$scriptUrl, $sessionUrl]) }}"
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

        // Set lazily: EmuniqScanSession arrives with x-load-js, which resolves
        // after x-data is evaluated.
        _session: null,
        session() {
            return this._session
                ??= new EmuniqScanSession({ duplicateWindow: {{ (int) $duplicateWindow }} });
        },

        friendlyName(cam, index) {
            const label = (cam.label || '').trim();

            if (!label || label === 'null') {
                return '{{ __('filament-qr-scanner::scanner.camera') }} ' + (index + 1);
            }

            const lower = label.toLowerCase();
            const isFront = /front|user|facetime|selfie|delantera|frontal|isight.*front/i.test(lower);
            const isBack  = /back|rear|environment|trasera|posterior|wide|main|isight(?!.*front)/i.test(lower);

            if (isFront) return '{{ __('filament-qr-scanner::scanner.camera_front') }}';
            if (isBack)  return '{{ __('filament-qr-scanner::scanner.camera_back') }}';

            if (label.length > 30) {
                return '{{ __('filament-qr-scanner::scanner.camera') }} ' + (index + 1);
            }

            return label;
        },

        async openScannerModal() {
            this.error = null;
            this.loading = true;

            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                this.error = '{{ __('filament-qr-scanner::scanner.error_no_support') }}';
                this.loading = false;
                return;
            }

            let granted = await this.requestPermission({ video: { facingMode: 'environment' } });
            if (!granted) {
                granted = await this.requestPermission({ video: true });
            }
            if (!granted) {
                this.loading = false;
                return;
            }

            try {
                const devices = await Html5Qrcode.getCameras();
                this.cameras = devices;
                if (devices.length === 0) {
                    this.error = '{{ __('filament-qr-scanner::scanner.error_no_camera') }}';
                    this.loading = false;
                    return;
                }
                const saved = localStorage.getItem('qr-camera-id');
                if (saved && devices.find(d => d.id === saved)) {
                    this.cameraId = saved;
                } else {
                    this.cameraId = devices[devices.length - 1].id;
                }
            } catch (e) {
                this.error = '{{ __('filament-qr-scanner::scanner.error_detecting') }} ' + (e.message || e);
                this.loading = false;
                return;
            }

            this.loading = false;
            $dispatch('open-modal', { id: '{{ $modalId }}' });

            await this.$nextTick();
            await new Promise(r => setTimeout(r, 200));
            this.startCamera();
        },

        async requestPermission(constraints) {
            try {
                const stream = await navigator.mediaDevices.getUserMedia(constraints);
                stream.getTracks().forEach(t => t.stop());
                this.permissionState = 'granted';
                return true;
            } catch (e) {
                const name = e.name || '';
                if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
                    this.permissionState = 'denied';
                    this.error = '{{ __('filament-qr-scanner::scanner.error_denied') }}';
                } else if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
                    this.error = '{{ __('filament-qr-scanner::scanner.error_not_found') }}';
                } else if (name === 'NotReadableError' || name === 'TrackStartError') {
                    this.error = '{{ __('filament-qr-scanner::scanner.error_in_use') }}';
                } else if (name === 'OverconstrainedError') {
                    return false;
                } else {
                    this.error = '{{ __('filament-qr-scanner::scanner.error_generic') }} ' + (e.message || e);
                }
                return false;
            }
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

            try {
                await this.scanner.start(
                    this.cameraId,
                    { fps: {{ (int) $fps }}, qrbox: { width: {{ (int) $qrboxSize }}, height: {{ (int) $qrboxSize }} }, aspectRatio: 1.0 },
                    (text) => this.onDetected(text),
                    () => {}
                );
                localStorage.setItem('qr-camera-id', this.cameraId);
            } catch (e) {
                this.error = '{{ __('filament-qr-scanner::scanner.error_start') }} ' + (e.message || e);
                this.active = false;
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

        <div class="space-y-3">
            {{-- Camera switcher (only when multiple cameras) --}}
            <div x-show="cameras.length > 1" x-cloak class="flex flex-wrap items-center gap-2">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide mr-1">{{ __('filament-qr-scanner::scanner.camera') }}:</span>
                <template x-for="(cam, idx) in cameras" :key="cam.id">
                    <button
                        type="button"
                        @click="switchCamera(cam.id)"
                        :class="cameraId === cam.id
                            ? 'bg-primary-600 text-white shadow-sm'
                            : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium transition-all duration-150 min-h-[32px]"
                    >
                        {{-- Front camera icon --}}
                        <template x-if="friendlyName(cam, idx) === '{{ __('filament-qr-scanner::scanner.camera_front') }}'">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd" />
                            </svg>
                        </template>
                        {{-- Back/other camera icon --}}
                        <template x-if="friendlyName(cam, idx) !== '{{ __('filament-qr-scanner::scanner.camera_front') }}'">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M4 5a2 2 0 00-2 2v8a2 2 0 002 2h12a2 2 0 002-2V7a2 2 0 00-2-2h-1.586a1 1 0 01-.707-.293l-1.121-1.121A2 2 0 0011.172 3H8.828a2 2 0 00-1.414.586L6.293 4.707A1 1 0 015.586 5H4zm6 9a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd" />
                            </svg>
                        </template>
                        <span x-text="friendlyName(cam, idx)"></span>
                    </button>
                </template>
            </div>

            {{-- Scanner viewport with flash overlay --}}
            <div class="relative rounded-lg overflow-hidden bg-black" style="width: 100%; min-height: 300px;">
                <div id="qr-reader-{{ $modalId }}" style="width: 100%; min-height: 300px;"></div>
                <div
                    x-show="flashing"
                    x-cloak
                    class="absolute inset-0 pointer-events-none bg-green-400/60 transition-opacity duration-300"
                ></div>
            </div>

            {{-- Last scan banner --}}
            <div
                x-show="lastScannedCode"
                x-cloak
                class="flex items-center justify-between gap-3 p-3 rounded-lg bg-green-100 text-green-900 dark:bg-green-900/50 dark:text-green-100 text-sm"
            >
                <div class="flex items-center gap-2 min-w-0">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                    <div class="min-w-0">
                        <p class="text-xs uppercase tracking-wide opacity-75">{{ __('filament-qr-scanner::scanner.last_scan') }}</p>
                        <p class="font-mono truncate" x-text="lastScannedCode"></p>
                    </div>
                </div>
                <span class="shrink-0 px-2 py-1 rounded-full bg-green-200 dark:bg-green-800 text-xs font-semibold" x-text="scanCount + (scanCount === 1 ? ' {{ __('filament-qr-scanner::scanner.reading_singular') }}' : ' {{ __('filament-qr-scanner::scanner.reading_plural') }}')"></span>
            </div>

            {{-- Hint --}}
            <p x-show="!lastScannedCode" x-cloak class="text-xs text-gray-500 dark:text-gray-400 text-center">
                {{ __('filament-qr-scanner::scanner.continuous_hint') }}
            </p>

            {{-- Error inside modal --}}
            <div x-show="error" x-cloak class="p-3 rounded-lg bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-300 text-sm" x-text="error"></div>
        </div>

        <x-slot name="footerActions">
            <span x-show="soundOn">
                <x-filament::button color="gray" icon="heroicon-o-speaker-wave" @click="toggleSound()">
                    {{ __('filament-qr-scanner::scanner.sound_on') }}
                </x-filament::button>
            </span>
            <span x-show="!soundOn" x-cloak>
                <x-filament::button color="gray" icon="heroicon-o-speaker-x-mark" @click="toggleSound()">
                    {{ __('filament-qr-scanner::scanner.sound_off') }}
                </x-filament::button>
            </span>
            <x-filament::button color="danger" @click="stopScanning(); $dispatch('close-modal', { id: '{{ $modalId }}' })">
                {{ __('filament-qr-scanner::scanner.close') }}
            </x-filament::button>
        </x-slot>
    </x-filament::modal>
</div>
