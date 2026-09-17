<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Quansitech\Cmf\Media\Models\Media;
use Quansitech\Cmf\Media\Support\UploadRule;

beforeEach(function (): void {
    config(['cmf-media.rules' => [
        'img-10' => ['mimes' => ['image/*'], 'max_size' => 10 * 1024 * 1024],
        'img-20' => ['mimes' => ['image/*'], 'max_size' => 20 * 1024 * 1024],
        'pdf-inline' => ['mimes' => ['application/pdf'], 'disposition' => 'inline', 'cache_seconds' => 86400],
        'export-zip' => ['mimes' => ['application/zip'], 'disposition' => 'attachment'],
        'loose-size' => ['mimes' => ['image/*'], 'max_size' => 1024 * 1024 * 1024],
    ]]);
});

it('resolves rule config into a value object', function (): void {
    $rule = UploadRule::resolve('img-10');

    expect($rule->name)->toBe('img-10')
        ->and($rule->mimes)->toBe(['image/*'])
        ->and($rule->maxSize)->toBe(10 * 1024 * 1024)
        ->and($rule->cacheSeconds)->toBeNull()
        ->and($rule->disposition)->toBeNull();
});

it('clamps rule max_size to the global limit （只能更严）', function (): void {
    // loose-size 配了 1GB，全局上限 500MB → 按全局收紧
    expect(UploadRule::resolve('loose-size')->maxSize)
        ->toBe((int) config('cmf-media.max_size'));
});

it('throws for unknown rule names', function (): void {
    UploadRule::resolve('ghost');
})->throws(InvalidArgumentException::class, '未知的媒体上传规则');

it('throws for illegal rule names', function (): void {
    UploadRule::resolve('a.b');
})->throws(InvalidArgumentException::class, '非法的媒体上传规则名');

it('throws when rule mime exceeds the global whitelist （只能更严）', function (): void {
    config(['cmf-media.rules.bad' => ['mimes' => ['application/x-msdownload']]]);

    UploadRule::resolve('bad');
})->throws(InvalidArgumentException::class, '只能比全局更严');

it('find returns null for empty rule names （沿用全局）', function (): void {
    expect(UploadRule::find(null))->toBeNull()
        ->and(UploadRule::find(''))->toBeNull();
});

it('fromRequest turns unknown names into 422', function (): void {
    UploadRule::fromRequest('ghost');
})->throws(ValidationException::class);

it('allows mime only when both global and rule whitelist pass （交集）', function (): void {
    $rule = UploadRule::resolve('img-10');

    expect($rule->allowsMime('image/jpeg'))->toBeTrue()
        // 全局白名单含 video/*，但规则只放行 image/* → 交集拒绝
        ->and($rule->allowsMime('video/mp4'))->toBeFalse()
        ->and($rule->allowsMime('application/pdf'))->toBeFalse();
});

it('checks size against the rule limit', function (): void {
    $rule = UploadRule::resolve('img-10');

    expect($rule->allowsSize(10 * 1024 * 1024))->toBeTrue()
        ->and($rule->allowsSize(10 * 1024 * 1024 + 1))->toBeFalse()
        ->and($rule->allowsSize(0))->toBeFalse();
});

it('validateUpload without rule behaves like the former global checks', function (): void {
    UploadRule::validateUpload(null, 'image/jpeg', 2048);

    expect(true)->toBeTrue();

    UploadRule::validateUpload(null, 'application/x-msdownload', 2048);
})->throws(ValidationException::class);

it('validateUpload enforces the rule limit when present', function (): void {
    $rule = UploadRule::resolve('img-10');

    try {
        UploadRule::validateUpload($rule, 'image/jpeg', 15 * 1024 * 1024);
        $this->fail('应抛 ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('size');
    }

    try {
        UploadRule::validateUpload($rule, 'application/zip', 1024);
        $this->fail('应抛 ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('mime');
    }
});

it('builds the accept attribute from rule mimes', function (): void {
    expect(UploadRule::resolve('img-10')->acceptAttribute())->toBe('image/*');

    config(['cmf-media.rules.multi' => ['mimes' => ['image/*', 'application/pdf']]]);
    expect(UploadRule::resolve('multi')->acceptAttribute())->toBe('image/*,application/pdf');
});

it('builds attachment disposition with the original filename （RFC 5987）', function (): void {
    $media = new Media(['original_name' => '资质 照片.pdf']);

    $disposition = UploadRule::resolve('export-zip')->contentDisposition($media);

    expect($disposition)->toStartWith('attachment; filename="')
        ->toContain("filename*=UTF-8''".rawurlencode('资质 照片.pdf'));
});

it('builds disposition from a bare original name （sign 阶段无 Media 记录）', function (): void {
    $disposition = UploadRule::resolve('export-zip')->contentDispositionForName('资质 照片.pdf');

    expect($disposition)->toStartWith('attachment; filename="')
        ->toContain("filename*=UTF-8''".rawurlencode('资质 照片.pdf'));

    expect(UploadRule::resolve('pdf-inline')->contentDispositionForName('doc.pdf'))->toBe('inline')
        ->and(UploadRule::resolve('img-10')->contentDispositionForName('a.jpg'))->toBeNull();
});

it('builds inline disposition without filename', function (): void {
    $media = new Media(['original_name' => 'doc.pdf']);

    expect(UploadRule::resolve('pdf-inline')->contentDisposition($media))->toBe('inline')
        ->and(UploadRule::resolve('img-10')->contentDisposition($media))->toBeNull();
});

it('reports access behavior presence', function (): void {
    expect(UploadRule::resolve('img-10')->hasAccessBehavior())->toBeFalse()
        ->and(UploadRule::resolve('pdf-inline')->hasAccessBehavior())->toBeTrue()
        ->and(UploadRule::resolve('export-zip')->hasAccessBehavior())->toBeTrue();
});
