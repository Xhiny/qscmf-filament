@php
    /** @var \Quansitech\Cmf\Media\Filament\Forms\Components\MediaPicker $field */
    $selected = $getSelectedMedia();
    $statePath = $getStatePath();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        class="cmf-media-picker"
        data-cmf-media-picker
        x-data
        x-init="window.cmfMediaPickerInit && window.cmfMediaPickerInit($el)"
        data-state-path="{{ $statePath }}"
        data-multiple="{{ $isMultiple() ? '1' : '0' }}"
        data-check-url="{{ route('cmf-media.check') }}"
        data-sign-url="{{ route('cmf-media.sign') }}"
        data-callback-url="{{ route('cmf-media.callback') }}"
        data-library-url="{{ route('cmf-media.library') }}"
        data-worker-url="{{ \Filament\Support\Facades\FilamentAsset::getScriptSrc('cmf-media-hash-worker', 'quansitech/cmf-module-media') }}"
        data-spark-url="{{ \Filament\Support\Facades\FilamentAsset::getScriptSrc('cmf-media-spark-md5', 'quansitech/cmf-module-media') }}"
        data-max-size="{{ (int) config('cmf-media.max_size') }}"
    >
        <div class="flex items-center gap-2">
            <label class="fi-btn fi-btn-color-gray fi-btn-size-sm inline-flex cursor-pointer items-center rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-gray-600">
                <span data-cmf-media-upload-label>选择文件上传</span>
                <input type="file" class="hidden" data-cmf-media-input @if($isMultiple()) multiple @endif />
            </label>
            <button type="button" data-cmf-media-library-btn class="fi-btn fi-btn-color-gray fi-btn-size-sm inline-flex items-center rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-gray-600">
                从媒体库选择
            </button>
        </div>

        <div class="mt-2 hidden" data-cmf-media-progress-wrap>
            <div class="text-xs text-gray-500" data-cmf-media-progress-label>正在校验文件指纹…</div>
            <div class="mt-1 h-2 w-full overflow-hidden rounded bg-gray-200 dark:bg-gray-700">
                <div class="h-2 rounded bg-primary-500 transition-all" style="width: 0%" data-cmf-media-progress-bar></div>
            </div>
        </div>

        <div class="mt-1 text-sm text-danger-600 hidden" data-cmf-media-error></div>

        <ul class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4" data-cmf-media-items>
            @foreach ($selected as $media)
                <li class="relative rounded-lg border border-gray-200 p-2 text-xs dark:border-gray-700" data-cmf-media-id="{{ $media->id }}">
                    @if ($media->thumbUrl())
                        <img src="{{ $media->thumbUrl() }}" alt="{{ $media->original_name }}" class="mb-1 h-16 w-full rounded object-cover" />
                    @endif
                    <div class="truncate" title="{{ $media->original_name }}">{{ $media->original_name }}</div>
                    <button type="button" class="absolute right-1 top-1 rounded bg-white/80 px-1 text-gray-500 hover:text-danger-600 dark:bg-gray-800/80" data-cmf-media-remove="{{ $media->id }}">×</button>
                </li>
            @endforeach
        </ul>

        <div class="fixed inset-0 z-40 hidden items-center justify-center bg-gray-950/50" data-cmf-media-modal>
            <div class="max-h-[80vh] w-full max-w-3xl overflow-auto rounded-xl bg-white p-4 dark:bg-gray-900">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-medium">媒体库</h3>
                    <button type="button" data-cmf-media-modal-close class="text-gray-400 hover:text-gray-600">×</button>
                </div>
                <ul class="grid grid-cols-3 gap-3 sm:grid-cols-6" data-cmf-media-library-list></ul>
            </div>
        </div>
    </div>
</x-dynamic-component>
