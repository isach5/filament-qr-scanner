{{-- Photo Camera Capture Component --}}
{{-- Usage: <x-photo-camera-capture wire-model="photoUpload" /> --}}
{{-- Captures the current frame as a base64 JPEG data URL and writes it into
     the given Livewire property. The server-side handler decodes it with the
     HasBase64PhotoCapture trait. Avoids the complexity of Livewire/Filament
     temporary uploads inside modals. --}}
@props([
    'wireModel' => 'photoUpload',
    'buttonLabel' => null,
    'buttonColor' => 'primary',
    'buttonSize' => 'lg',
    'modalHeading' => null,
    'jpegQuality' => null,
    'maxDimension' => null,
])

@php
    use Filament\Support\Facades\FilamentAsset;
    use Illuminate\Support\Js;

    $modalId = 'photo-camera-modal-' . uniqid();
    $videoId = 'photo-camera-video-' . uniqid();
    $canvasId = 'photo-camera-canvas-' . uniqid();

    $buttonLabel = $buttonLabel ?? __('filament-qr-scanner::photo.button');
    $modalHeading = $modalHeading ?? __('filament-qr-scanner::photo.modal_heading');
    $jpegQuality = $jpegQuality ?? config('filament-qr-scanner.photos.jpeg_quality', 0.8);
    $maxDimension = $maxDimension ?? config('filament-qr-scanner.photos.max_dimension', 1280);

    $pickerUrl = FilamentAsset::getScriptSrc('camera-picker', 'emuniq/filament-qr-scanner');

    $cameraNames = [
        'front' => __('filament-qr-scanner::scanner.camera_front'),
        'back' => __('filament-qr-scanner::scanner.camera_back'),
        'wide' => __('filament-qr-scanner::scanner.camera_wide'),
        'ultrawide' => __('filament-qr-scanner::scanner.camera_ultrawide'),
        'telephoto' => __('filament-qr-scanner::scanner.camera_telephoto'),
        'macro' => __('filament-qr-scanner::scanner.camera_macro'),
        'fallback' => __('filament-qr-scanner::photo.camera'),
    ];
@endphp

<div
    wire:ignore
    x-load-js="{{ Js::from([$pickerUrl]) }}"
    x-data="{
        cameraNames: {{ Js::from($cameraNames) }},
        stream: null,
        cameras: [],
        cameraId: null,
        error: null,
        permissionState: 'prompt',
        loading: false,
        captured: null,
        uploaded: false,

        async openCameraModal() {
            this.error = null;
            this.captured = null;
            this.uploaded = false;
            this.loading = true;

            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                this.error = '{{ __('filament-qr-scanner::photo.error_no_support') }}';
                this.loading = false;
                return;
            }

            this.loading = false;
            this.$dispatch('open-modal', { id: '{{ $modalId }}' });
            await this.$nextTick();
            await new Promise(r => setTimeout(r, 200));
            await this.startStream();
        },

        describeCameraError(e) {
            const name = e?.name || '';

            if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
                this.permissionState = 'denied';
                return '{{ __('filament-qr-scanner::photo.error_denied_short') }}';
            }
            if (name === 'NotFoundError' || name === 'DevicesNotFoundError' || name === 'OverconstrainedError') {
                return '{{ __('filament-qr-scanner::photo.error_not_found') }}';
            }
            if (name === 'NotReadableError' || name === 'TrackStartError') {
                return '{{ __('filament-qr-scanner::photo.error_in_use') }}';
            }

            return '{{ __('filament-qr-scanner::photo.error_start') }} ' + (e?.message || e);
        },

        /**
         * Opening never asks for a specific device: a deviceId constraint fails
         * as soon as the remembered id goes stale — Safari reissues them every
         * session — and a failed getUserMedia is a wasted permission prompt.
         * One request per open, and the camera list comes from the stream that
         * is already running rather than from a second one.
         */
        async startStream(deviceId = null) {
            if (this.stream) this.stopStream();

            try {
                this.stream = await navigator.mediaDevices.getUserMedia({
                    video: deviceId ? { deviceId: { exact: deviceId } } : { facingMode: 'environment' },
                });
                this.permissionState = 'granted';
                this.error = null;

                const video = document.getElementById('{{ $videoId }}');
                if (video) {
                    video.srcObject = this.stream;
                    await video.play();
                }

                await this.loadCameraList();

                if (deviceId === null) await this.applyRememberedCamera();
            } catch (e) {
                this.error = this.describeCameraError(e);
            }
        },

        /** Name the lenses properly and say which one is really streaming. */
        async loadCameraList() {
            try {
                const devices = (await navigator.mediaDevices.enumerateDevices())
                    .filter(d => d.kind === 'videoinput')
                    .map(d => ({ id: d.deviceId, label: d.label }));

                this.cameras = EmuniqCameraPicker.describe(devices, this.cameraNames);

                const settings = this.stream?.getVideoTracks?.()[0]?.getSettings?.() ?? {};

                this.cameraId = EmuniqCameraPicker.resolveActive(
                    this.cameras,
                    settings.deviceId ?? null,
                    settings.facingMode ?? null,
                );
            } catch (e) {}
        },

        async applyRememberedCamera() {
            const remembered = localStorage.getItem('photo-camera-id');

            if (! remembered || remembered === this.cameraId) return;
            if (! this.cameras.some(camera => camera.id === remembered)) {
                localStorage.removeItem('photo-camera-id');
                return;
            }

            this.cameraId = remembered;
            await this.startStream(remembered);
        },

        stopStream() {
            if (this.stream) {
                this.stream.getTracks().forEach(t => t.stop());
                this.stream = null;
            }
            const video = document.getElementById('{{ $videoId }}');
            if (video) video.srcObject = null;
        },

        async switchCamera(newId) {
            this.cameraId = newId;
            localStorage.setItem('photo-camera-id', newId);
            await this.startStream(newId);
        },

        capture() {
            const video = document.getElementById('{{ $videoId }}');
            const canvas = document.getElementById('{{ $canvasId }}');
            if (!video || !canvas) return;
            if (!video.videoWidth || !video.videoHeight) {
                this.error = '{{ __('filament-qr-scanner::photo.error_not_ready') }}';
                return;
            }
            // Downscale past maxDimension to keep the base64 payload small.
            // Aspect ratio is preserved.
            const MAX = {{ (int) $maxDimension }};
            let w = video.videoWidth;
            let h = video.videoHeight;
            if (w > MAX || h > MAX) {
                if (w >= h) {
                    h = Math.round(h * (MAX / w));
                    w = MAX;
                } else {
                    w = Math.round(w * (MAX / h));
                    h = MAX;
                }
            }
            canvas.width = w;
            canvas.height = h;
            canvas.getContext('2d').drawImage(video, 0, 0, w, h);
            this.captured = canvas.toDataURL('image/jpeg', {{ (float) $jpegQuality }});
            this.stopStream();
        },

        retake() {
            this.captured = null;
            this.uploaded = false;
            this.error = null;
            this.startStream(this.cameraId);
        },

        save() {
            if (!this.captured) return;
            try {
                // Deferred set (live=false): the data URL stays in client state
                // and rides along with the next request (the submit of the
                // action hosting this component). An immediate roundtrip would
                // re-render the parent action modal and wipe whatever text the
                // operator had already typed.
                $wire.set('{{ $wireModel }}', this.captured, false);
                this.uploaded = true;
                this.stopStream();
                this.$dispatch('close-modal', { id: '{{ $modalId }}' });
            } catch (e) {
                console.error('photo-camera-capture save error:', e);
                this.error = '{{ __('filament-qr-scanner::photo.error_save') }} ' + (e?.message || e);
            }
        },

        cancel() {
            this.stopStream();
            this.captured = null;
            this.$dispatch('close-modal', { id: '{{ $modalId }}' });
        }
    }"
    x-on:close-modal.window="if ($event.detail?.id === '{{ $modalId }}') stopStream()"
    {{ $attributes->merge(['class' => 'space-y-3']) }}
>
    <div class="flex items-center gap-3">
        <x-filament::button
            :color="$buttonColor"
            icon="heroicon-o-camera"
            :size="$buttonSize"
            x-on:click="openCameraModal()"
            x-bind:disabled="loading"
        >
            {{ $buttonLabel }}
        </x-filament::button>

        <span x-show="uploaded" x-cloak class="inline-flex items-center gap-1 text-sm font-medium text-green-700 dark:text-green-300">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
            </svg>
            {{ __('filament-qr-scanner::photo.attached') }}
        </span>
    </div>

    {{-- Permission denied help --}}
    <div x-show="permissionState === 'denied'" x-cloak class="p-3 rounded-lg bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-700 text-sm text-amber-800 dark:text-amber-200">
        {{ __('filament-qr-scanner::photo.error_denied') }}
    </div>
    <div x-show="error && permissionState !== 'denied'" x-cloak class="p-3 rounded-lg bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-300 text-sm" x-text="error"></div>

    <x-filament::modal id="{{ $modalId }}" width="lg" :close-by-clicking-away="false" sticky-footer>
        <x-slot name="heading">{{ $modalHeading }}</x-slot>

        <div class="space-y-3">
            {{-- Camera switcher. A select, not a row of pills: a row hands the
                 dialog its full intrinsic width however it is clipped, and four
                 lenses with long names push a modal off a phone screen. --}}
            <div x-show="cameras.length > 1 && !captured" x-cloak class="flex w-full min-w-0 items-center gap-2">
                <label
                    :for="'photo-camera-select-{{ $modalId }}'"
                    class="shrink-0 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400"
                >{{ __('filament-qr-scanner::photo.camera') }}</label>
                <select
                    :id="'photo-camera-select-{{ $modalId }}'"
                    x-model="cameraId"
                    @change="switchCamera($event.target.value)"
                    class="min-w-0 flex-1 truncate rounded-lg border-gray-300 bg-white py-1.5 pl-3 pr-8 text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white"
                >
                    <template x-for="cam in cameras" :key="cam.id">
                        <option :value="cam.id" x-text="cam.name"></option>
                    </template>
                </select>
            </div>

            <div class="relative rounded-lg overflow-hidden bg-black" style="min-height: 320px;">
                <video
                    id="{{ $videoId }}"
                    x-show="!captured"
                    autoplay
                    playsinline
                    muted
                    style="width: 100%; max-height: 60vh; object-fit: contain;"
                ></video>
                <img
                    x-show="captured"
                    x-cloak
                    :src="captured"
                    style="width: 100%; max-height: 60vh; object-fit: contain;"
                    alt="{{ __('filament-qr-scanner::photo.captured_alt') }}"
                />
                <canvas id="{{ $canvasId }}" style="display:none;"></canvas>
            </div>

            <div x-show="error" x-cloak class="p-3 rounded-lg bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-300 text-sm" x-text="error"></div>
        </div>

        <x-slot name="footerActions">
            <template x-if="!captured">
                <x-filament::button color="primary" icon="heroicon-o-camera" @click="capture()">
                    {{ __('filament-qr-scanner::photo.capture') }}
                </x-filament::button>
            </template>
            <template x-if="captured">
                <div class="flex gap-2">
                    <x-filament::button color="gray" icon="heroicon-o-arrow-path" @click="retake()">
                        {{ __('filament-qr-scanner::photo.retake') }}
                    </x-filament::button>
                    <x-filament::button color="success" icon="heroicon-o-check" @click="save()">
                        {{ __('filament-qr-scanner::photo.use_photo') }}
                    </x-filament::button>
                </div>
            </template>
            <x-filament::button color="danger" @click="cancel()">
                {{ __('filament-qr-scanner::photo.close') }}
            </x-filament::button>
        </x-slot>
    </x-filament::modal>
</div>
