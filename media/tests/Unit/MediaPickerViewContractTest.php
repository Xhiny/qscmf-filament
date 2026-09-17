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
