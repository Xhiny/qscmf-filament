<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * disposition 纳入去重维度：同一内容哈希按访问行为（inline / attachment / 无）
 * 各存一个对象，上传时写入对应 Content-Disposition 元数据（云厂商 response-*
 * URL 覆盖实测不可靠：TOS 匿名 GET 直接 400，且默认域名强制 attachment）。
 *
 * 列用空字符串而非 NULL 表示"无 disposition"：MySQL 唯一索引中 NULL 互不
 * 相等，并发兜底会失效；空字符串是普通值，唯一索引照常工作。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cmf_media', function (Blueprint $table): void {
            $table->string('disposition', 20)->default('')->after('hash')
                ->comment('访问行为：inline / attachment / 空=无（去重维度之一）');

            $table->dropUnique(['hash']);
            $table->unique(['hash', 'disposition']);
        });
    }

    public function down(): void
    {
        Schema::table('cmf_media', function (Blueprint $table): void {
            $table->dropUnique(['hash', 'disposition']);
            $table->unique(['hash']);
            $table->dropColumn('disposition');
        });
    }
};
