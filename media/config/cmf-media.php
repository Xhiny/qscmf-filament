<?php

declare(strict_types=1);

use Quansitech\Cmf\Media\Filament\Resources\Media\MediaResource;
use Quansitech\Cmf\Media\Models\Media;
use Quansitech\Cmf\Media\Policies\MediaPolicy;

return [

    /*
    |--------------------------------------------------------------------------
    | 默认云存储驱动
    |--------------------------------------------------------------------------
    |
    | 浏览器直传与媒体读写使用的存储：tos（火山引擎）/ oss（阿里云）/
    | cos（腾讯云）/ local（服务器本地磁盘，文件流量经过应用服务器）。
    |
    */

    'default' => env('CMF_MEDIA_DRIVER', 'tos'),

    /*
    |--------------------------------------------------------------------------
    | 云存储凭证
    |--------------------------------------------------------------------------
    |
    | 各驱动凭证留空时对应 disk 不注册。所需 Flysystem adapter 通过 composer
    | 按需安装（见 composer.json suggest），未安装时解析 disk 会抛出明确异常。
    |
    | thumb_suffix 用于后台列表缩略图：追加在云厂商图片处理 URL 之后。
    |
    */

    'disks' => [
        'tos' => [
            'key' => env('TOS_ACCESS_KEY'),
            'secret' => env('TOS_SECRET_KEY'),
            'region' => env('TOS_REGION', 'cn-beijing'),
            'bucket' => env('TOS_BUCKET'),
            'endpoint' => env('TOS_ENDPOINT'), // 如 tos-s3-cn-beijing.volces.com（必须用 S3 兼容域名，勿用原生域名）
            // 自定义访问域名（访问 URL 的 host）。TOS 默认域名投递层对所有 GET
            // 强制返回 Content-Disposition: attachment（实测，元数据无效），
            // disposition=inline 的预览场景必须绑定自定义域名才能生效
            'url' => env('TOS_URL'),
            'thumb_suffix' => env('TOS_THUMB_SUFFIX', '?x-tos-process=image/resize,w_200'),
        ],
        'oss' => [
            'key' => env('OSS_ACCESS_KEY_ID'),
            'secret' => env('OSS_ACCESS_KEY_SECRET'),
            'bucket' => env('OSS_BUCKET'),
            'endpoint' => env('OSS_ENDPOINT'), // 如 oss-cn-hangzhou.aliyuncs.com
            // 自定义访问域名（OSS 默认域名对部分类型强制下载，inline 预览需绑定）
            'url' => env('OSS_URL'),
            'thumb_suffix' => env('OSS_THUMB_SUFFIX', '?x-oss-process=image/resize,w_200'),
        ],
        'cos' => [
            'secret_id' => env('COS_SECRET_ID'),
            'secret_key' => env('COS_SECRET_KEY'),
            'region' => env('COS_REGION', 'ap-guangzhou'),
            'bucket' => env('COS_BUCKET'), // 含 appid，如 example-1250000000
            // 自定义访问域名（COS 默认域名有安全下载策略，inline 预览需绑定）
            'url' => env('COS_URL'),
            'thumb_suffix' => env('COS_THUMB_SUFFIX', '?imageMogr2/thumbnail/200x200'),
        ],
        // 本地磁盘：文件落在应用服务器（默认 public/cmf-media，直接可访问），
        // 上传走 POST /{route_prefix}/upload（服务器接收并计算 hash 落盘），
        // 秒传去重与引用计数逻辑与云驱动一致。适合单机部署 / 内网场景。
        'local' => [
            'root' => env('CMF_MEDIA_LOCAL_ROOT'),   // 默认 public_path('cmf-media')
            'url' => env('CMF_MEDIA_LOCAL_URL'),     // 默认 /cmf-media
            'thumb_suffix' => '', // 无云图片处理，缩略图直接用原图
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 上传策略
    |--------------------------------------------------------------------------
    |
    | max_size：单文件上限（字节），默认 500MB；直传为单 PUT/POST，超大文件
    | 分片上传列为后续迭代。
    | allowed_mimes：MIME 白名单，支持 "image/*" 通配。
    | sign_expires：直传凭证有效期（秒），限定单 key 短时效。
    | verify_etag：callback 建档时比对对象 ETag 与客户端上报的内容 MD5，
    | 防止恶意用户用他人 hash 冒领文件归属（分片上传 ETag 算法不同，届时跳过）。
    |
    */

    'max_size' => env('CMF_MEDIA_MAX_SIZE', 500 * 1024 * 1024),

    'allowed_mimes' => [
        'image/*',
        'video/*',
        'audio/*',
        'application/pdf',
        'application/zip',
        'application/x-zip-compressed',
        'text/plain',
    ],

    'sign_expires' => 600,

    'verify_etag' => env('CMF_MEDIA_VERIFY_ETAG', true),

    /*
    |--------------------------------------------------------------------------
    | MediaPicker「从媒体库选择」开关
    |--------------------------------------------------------------------------
    |
    | 默认隐藏：MediaPicker 只保留「选择文件上传」，避免普通用户直接复用
    | 全站媒体库文件。开启后字段渲染「从媒体库选择」按钮与媒体库弹窗
    | （数据来自 GET /{route_prefix}/library，仍受上传端点中间件约束）。
    |
    */

    'picker_library' => false,

    /*
    |--------------------------------------------------------------------------
    | 按入口上传规则（多套）
    |--------------------------------------------------------------------------
    |
    | 每个上传入口（MediaPicker::uploadRule('名') / API 请求的 rule 参数）可声明
    | 使用哪套规则；未声明的入口沿用上方全局限制，存量行为不变。规则只能比全局
    | 白名单更严：mimes 必须是 allowed_mimes 的子集（解析时校验），max_size
    | 超过全局时按全局收紧（取最小值），因此客户端声明哪套规则不会放大权限。
    |
    | mimes：允许的文件类型（支持 "image/*" 通配），同时作为前端文件选择的
    |         accept 过滤（选择阶段即生效）；服务端在 check / sign / upload /
    |         callback 全链路强制校验，绕过前端改参数无效。
    | max_size：单文件上限（字节）。
    | cache_seconds：访问缓存时长（可选）。上传时写入对象 Cache-Control 元数据；
    |         不纳入去重维度，同一内容多入口时以先上传者为准。
    | disposition：访问行为 inline（浏览器内预览）/ attachment（强制下载，
    |         文件名用上传时的原始文件名；可选）。上传时写入对象
    |         Content-Disposition 元数据，且纳入去重维度——同一内容按
    |         inline / attachment / 无 各存一个对象，互不干扰。
    |         注意：TOS/OSS/COS 默认域名投递层会强制 attachment（安全策略），
    |         inline 预览必须给 disk 配置自定义访问域名（disks.*.url）才生效；
    |         云厂商 response-* URL 覆盖参数实测不可靠（TOS 匿名 GET 返回 400），
    |         本模块不依赖该机制。
    |
    | 例：
    | 'rules' => [
    |     'nurse-cert' => ['mimes' => ['image/*'], 'max_size' => 10 * 1024 * 1024],
    |     'activity-image' => ['mimes' => ['image/*'], 'max_size' => 20 * 1024 * 1024],
    |     'doc-preview' => ['mimes' => ['application/pdf'], 'disposition' => 'inline', 'cache_seconds' => 86400],
    |     'export' => ['mimes' => ['application/zip'], 'disposition' => 'attachment'],
    | ],
    |
    */

    'rules' => [],

    /*
    |--------------------------------------------------------------------------
    | 归零自动删除开关
    |--------------------------------------------------------------------------
    |
    | 开启：ref_count 归零即软删并延迟派发 DeleteMediaJob 清理云端对象；
    | 关闭：归零仅保留记录（后续被重新引用时正常计数），孤儿文件可在后台
    | 手动删除（手动删除仍会排期清理云端对象）。适合有合规留存要求的场景。
    |
    */

    'auto_delete' => env('CMF_MEDIA_AUTO_DELETE', true),

    /*
    |--------------------------------------------------------------------------
    | 归零删除缓冲（分钟）
    |--------------------------------------------------------------------------
    |
    | ref_count 归零后软删并延迟派发 DeleteMediaJob，执行前复查引用计数，
    | 防止"删的同时又被引用"的竞态误删。需运行队列 worker（见 README「归零删除队列」）。
    |
    */

    'delete_delay_minutes' => env('CMF_MEDIA_DELETE_DELAY', 60),

    /*
    |--------------------------------------------------------------------------
    | 孤儿清理宽限（小时）
    |--------------------------------------------------------------------------
    |
    | 「上传后未保存表单」的孤儿文件由每日调度的 cmf-media:prune-orphans 兜底：
    | 上传超过该小时数仍零引用的记录软删并排期清理云端对象。宽限需覆盖
    | 用户填写长表单的耗时。仅 auto_delete 开启时注册调度；需宿主 crontab
    | 配置 schedule:run。
    |
    */

    'orphan_cleanup_after_hours' => env('CMF_MEDIA_ORPHAN_CLEANUP_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | 上传端点路由
    |--------------------------------------------------------------------------
    */

    'route_prefix' => env('CMF_MEDIA_ROUTE_PREFIX', 'cmf-media'),

    'middleware' => ['web', 'auth'],

    /*
    |--------------------------------------------------------------------------
    | local 文件访问路由中间件
    |--------------------------------------------------------------------------
    |
    | GET /{route_prefix}/file/{media}：local 驱动声明了访问行为（缓存时长 /
    | 预览或下载 / 下载文件名）的规则经此路由输出对应响应头；云驱动媒体访问
    | 此路由会重定向到拼好 response-* 参数的公开 URL。媒体文件按公开可读
    | 设计（同云桶公开读），默认仅 web；需要收口时自行加 auth 等中间件。
    |
    */

    'file_middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | 模型 / Resource / Policy
    |--------------------------------------------------------------------------
    |
    | 深度定制时在 app/ 下继承对应类并替换这里的配置。
    |
    */

    'model' => Media::class,

    'resource' => MediaResource::class,

    'policy' => MediaPolicy::class,

    /*
    |--------------------------------------------------------------------------
    | Shield 权限点（写入 filament-shield.resources.manage）
    |--------------------------------------------------------------------------
    */

    'permissions' => ['viewAny', 'view', 'create', 'delete'],

    /*
    |--------------------------------------------------------------------------
    | 审计集成
    |--------------------------------------------------------------------------
    |
    | 开启后 Media 模型切换为 AuditableMedia（挂 owen-it/laravel-auditing），
    | 需宿主已安装 quansitech/cmf-module-auditing（或 owen-it/laravel-auditing）。
    |
    */

    'audit' => env('CMF_MEDIA_AUDIT', false),

];
