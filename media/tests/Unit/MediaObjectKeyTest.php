<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Quansitech\Cmf\Media\Models\Media;
use Quansitech\Cmf\Media\Support\MediaManager;

/**
 * 对象 key 目录段拼接：directory 为空时与存量格式逐字节一致（回归红线），
 * 非空时拼 {directory}/ 前缀（多级段即递归目录），disposition 维度组合不变。
 */
it('builds the legacy key when directory is empty （逐字节一致）', function (): void {
    $hash = str_repeat('ab', 16);

    expect(Media::objectKey($hash, 'png'))->toBe('ab/'.$hash.'.png')
        ->and(Media::objectKey($hash, ''))->toBe('ab/'.$hash)
        ->and(Media::objectKey($hash, 'png', 'inline'))->toBe('ab/'.$hash.'.inline.png')
        ->and(Media::objectKey($hash, 'png', '', ''))->toBe('ab/'.$hash.'.png');
});

it('prefixes the directory segment for single and multi-level paths', function (): void {
    $hash = str_repeat('ab', 16);

    expect(Media::objectKey($hash, 'png', '', 't4tstudio'))->toBe('t4tstudio/ab/'.$hash.'.png')
        ->and(Media::objectKey($hash, 'png', '', 'A/B/C'))->toBe('A/B/C/ab/'.$hash.'.png');
});

it('combines directory with the disposition dimension', function (): void {
    $hash = str_repeat('ab', 16);

    expect(Media::objectKey($hash, 'pdf', 'attachment', 'docs'))->toBe('docs/ab/'.$hash.'.attachment.pdf');
});

it('accepts only keys under the cdn path prefix', function (): void {
    config()->set('filesystems.disks.cmf-media-cos.cdn', [
        'url' => 'https://cdn.example.com',
        'path_prefix' => ' t4tstudio/ ',
    ]);

    Media::assertKeyWithinCdnPrefix('t4tstudio/ab/hash.jpg', 'cos');

    expect(fn () => Media::assertKeyWithinCdnPrefix('ab/hash.jpg', 'cos'))
        ->toThrow(ValidationException::class)
        ->and(fn () => Media::assertKeyWithinCdnPrefix('t4tstudiox/ab/hash.jpg', 'cos'))
        ->toThrow(ValidationException::class);
});

it('skips the cdn prefix guard when no prefix is configured', function (): void {
    config()->set('filesystems.disks.cmf-media-cos.cdn', [
        'url' => 'https://cdn.example.com',
        'path_prefix' => '  ',
    ]);

    Media::assertKeyWithinCdnPrefix('ab/hash.jpg', 'cos');

    expect(MediaManager::normalizeCdnPathPrefix('  '))->toBe('');
});
