{{-- QR Camera Scanner Component --}}
{{-- Usage: <x-qr-camera-scanner wire-model="scanInput" wire-action="processScan" /> --}}
@props([
    'wireModel' => 'scanInput',
    'wireAction' => 'processScan',
    'buttonLabel' => null,
    'buttonColor' => 'primary',
    'buttonSize' => 'lg',
    'modalHeading' => null,
    'fps' => 10,
    'qrboxSize' => 250,
])

@php
    $modalId = 'qr-scanner-modal-' . uniqid();
    $buttonLabel = $buttonLabel ?? __('filament-qr-scanner::scanner.button');
    $modalHeading = $modalHeading ?? __('filament-qr-scanner::scanner.modal_heading');
@endphp

<div
    x-load-js="['https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js']"
    x-on:close-modal.window="if ($event.detail?.id === '{{ $modalId }}') stopScanning()"
    x-data="{
        scanner: null,
        cameras: [],
        cameraId: null,
        error: null,
        permissionState: 'prompt',
        loading: false,
        active: false,

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

            this.scanner = new Html5Qrcode(readerId);
            this.active = true;

            try {
                await this.scanner.start(
                    this.cameraId,
                    { fps: {{ $fps }}, qrbox: { width: {{ $qrboxSize }}, height: {{ $qrboxSize }} }, aspectRatio: 1.0 },
                    (text) => {
                        $wire.set('{{ $wireModel }}', text);
                        $wire.call('{{ $wireAction }}');
                        this.stopScanning();
                        $dispatch('close-modal', { id: '{{ $modalId }}' });
                    },
                    () => {}
                );
                localStorage.setItem('qr-camera-id', this.cameraId);
            } catch (e) {
                this.error = '{{ __('filament-qr-scanner::scanner.error_start') }} ' + (e.message || e);
                this.active = false;
            }
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

            {{-- Scanner viewport --}}
            <div id="qr-reader-{{ $modalId }}" class="rounded-lg overflow-hidden bg-black" style="width: 100%; min-height: 300px;"></div>

            {{-- Error inside modal --}}
            <div x-show="error" x-cloak class="p-3 rounded-lg bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-300 text-sm" x-text="error"></div>
        </div>

        <x-slot name="footerActions">
            <x-filament::button color="danger" @click="stopScanning(); $dispatch('close-modal', { id: '{{ $modalId }}' })">
                {{ __('filament-qr-scanner::scanner.close') }}
            </x-filament::button>
        </x-slot>
    </x-filament::modal>
</div>
