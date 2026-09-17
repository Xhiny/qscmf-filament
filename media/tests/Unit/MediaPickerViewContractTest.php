<?php

declare(strict_types=1);

/**
 * 视图 ↔ JS 接线契约（无 node 依赖的轻量回归）。
 *
 * 弹窗（Filament Action / Modal）里的 picker 不触发 Livewire 的 morph.added，
 * 也不在首屏 boot() 的扫描范围内，而是靠视图上的 x-init 声明式调用 JS 暴露的
 * 初始化入口。这条接线断掉时前端表现为「选完文件毫无反应」（无进度、无报错、
 * 零请求），PHP 侧不会抛任何错，故在此固化；真实弹窗行为见 README 的宿主集成
 * checklist 第 5 条。
 */
it('wires the picker view to the JS init entry point', function (): void {
    $view = file_get_contents(__DIR__.'/../../resources/views/forms/components/media-picker.blade.php');
    $script = file_get_contents(__DIR__.'/../../resources/js/direct-upload.js');

    expect($view)
        ->toContain('data-cmf-media-picker')
        ->toContain('x-init')
        ->toContain('cmfMediaPickerInit')
        ->and($script)->toContain('window.cmfMediaPickerInit = function');
});

/**
 * 入口规则（uploadRule）的视图 ↔ JS 接线契约：
 * 视图输出 data-rule，JS 读取后经 withRule 透传给 check / sign / callback
 * 三端点；断掉任一环，前端约束与服务端校验会脱节。
 */
it('wires the upload rule from the view through JS to the endpoints', function (): void {
    $view = file_get_contents(__DIR__.'/../../resources/views/forms/components/media-picker.blade.php');
    $script = file_get_contents(__DIR__.'/../../resources/js/direct-upload.js');

    expect($view)
        ->toContain('data-rule=')
        ->toContain('getUploadRule')
        ->and($script)
        ->toContain('el.dataset.rule')
        ->toContain('withRule(')
        // local 驱动 upload 端点的 rule 由 sign 返回的 fields 透传（服务端）
        ->and(file_get_contents(__DIR__.'/../../src/Http/Controllers/MediaUploadController.php'))
        ->toContain("'rule' => \$rule?->name");
});

/**
 * 裁剪的比例从字段 → 视图 data 属性 → JS 三处接力，任一环断掉都表现为
 * 「选了图但没弹出裁剪层」，PHP 侧同样不会报错。
 */
it('passes the crop ratio from the field down to the crop layer', function (): void {
    $view = file_get_contents(__DIR__.'/../../resources/views/forms/components/media-picker.blade.php');
    $uploader = file_get_contents(__DIR__.'/../../resources/js/direct-upload.js');
    $cropper = file_get_contents(__DIR__.'/../../resources/js/crop.js');

    expect($view)
        ->toContain('getCropAspectRatio()')
        ->toContain('data-crop-aspect-ratio')
        ->toContain('data-crop-max-width')
        ->toContain('data-crop-quality')
        ->and($uploader)
        ->toContain('dataset.cropAspectRatio')
        ->toContain('cropIfNeeded')
        // 裁剪必须在算 hash 之前完成，否则上报的 hash 与上传字节对不上
        ->toContain('window.cmfMediaCropFile')
        ->and($cropper)->toContain('window.cmfMediaCropFile = function');
});

/**
 * Escape 冒泡路径是 document → window，而 Filament 弹窗在 window 上监听
 * Escape 关弹窗。裁剪层不拦事件，用户在表单弹窗里按 Esc 取消裁剪会连底层
 * 表单一起关掉、填了一半的内容全丢——只能靠这行 stopPropagation 兜住。
 */
it('keeps the escape key from closing the underlying modal', function (): void {
    $cropper = file_get_contents(__DIR__.'/../../resources/js/crop.js');

    expect($cropper)
        ->toContain('event.stopPropagation()')
        ->toContain('event.key !== \'Escape\'');
});

/**
 * 无裁剪字段的多选上传应保持并行；串行只服务于「裁剪层一次只显示一张」。
 */
it('serializes uploads only when cropping is on', function (): void {
    $uploader = file_get_contents(__DIR__.'/../../resources/js/direct-upload.js');

    expect($uploader)
        ->toContain('if (! cropOptions)')
        ->toContain('files.forEach(')
        ->toContain('files.reduce(');
});

/**
 * Cropper.js 用 UMD 版本：模块没有构建步骤，靠 script 标签直接加载，
 * ESM 版本在浏览器里会直接报语法错误。
 */
it('ships a browser-loadable cropper without a build step', function (): void {
    $asset = file_get_contents(__DIR__.'/../../resources/js/cropper.min.js');

    expect($asset)
        ->toContain('window.Cropper')
        ->and(file_exists(__DIR__.'/../../resources/css/cropper.min.css'))->toBeTrue();
});
