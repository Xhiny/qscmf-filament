# cmf-module-media

QS CMF 媒体模块：TOS / OSS / COS 浏览器直传（亦支持 local 本地磁盘上传）、内容哈希去重（秒传）、引用计数、归零自动清理对象、Filament 后台媒体管理。

## 功能

- **浏览器直传**：服务器只做「签发 + 建档」，文件流量不经过应用服务器；凭证限定单 key、短有效期（默认 10 分钟）
- **local 本地驱动**：单机/内网场景可选 `CMF_MEDIA_DRIVER=local`，文件落服务器磁盘（默认 `public/cmf-media`），上传经应用服务器接收、服务端计算真实内容 hash 落盘建档（防伪更强）；秒传去重与引用计数逻辑与云驱动一致
- **内容哈希去重（秒传）**：前端 Web Worker 分片（8MB）+ spark-md5 增量计算 MD5，内存占用恒定、不阻塞 UI；后端 `hash + disposition` 唯一索引兜底，对象 key 即 hash 路径（`{hash前2位}/{hash}[.{disposition}].{ext}`）
- **回调防伪**：建档前 headObject 校验对象存在与 size 一致，单 PUT 对象的 ETag（即内容 MD5）与上报 hash 比对
- **按入口上传规则**：多套规则按入口生效（类型 / 大小），服务端 check / sign / upload / callback 全链路强制，规则只能比全局白名单更严；文件访问行为（缓存时长、预览或下载、原始文件名下载）随入口生效（见「按入口上传规则与访问行为」）
- **引用计数**：`media_usages` 关联表为准，`ref_count` 冗余加速；归零软删 + 延迟 Job 复查后删对象（可开关，见「归零删除队列」），竞态安全
- **RichEditor 接管**：实现 Filament v5 `FileAttachmentProvider`，`HasMediaRichContent` 一行接入，富文本图片/附件上传自动去重建档、按内容 diff 同步引用、移除归零清理（见「RichEditor 富文本接管」）
- **后台管理**：媒体列表（缩略图走云厂商 URL 图片处理参数，local 直接用原图；软删记录不展示）、详情页按类型预览（图片点击放大 / 视频音频在线播放 / 其他文件下载）、筛选/排序、引用明细、有引用禁删
- **权限**：Shield 权限点自动登记，`MediaPolicy` 控制 viewAny / view / create / delete

## 安装

```bash
composer require quansitech/cmf-module-media
```

按使用的云厂商安装对应 Flysystem adapter（local 驱动无需安装；未装时解析 disk 会抛出明确异常）：

| 驱动 | adapter |
| --- | --- |
| tos（火山引擎，S3 兼容） | `composer require league/flysystem-aws-s3-v3` |
| oss（阿里云） | `composer require xxtime/flysystem-aliyun-oss` |
| cos（腾讯云） | `composer require overtrue/flysystem-cos` |
| local（服务器本地磁盘） | 无需 adapter |

配置（`php artisan vendor:publish --tag=cmf-config` 落出 `config/cmf-media.php`）：

```dotenv
CMF_MEDIA_DRIVER=tos          # tos / oss / cos / local
TOS_ACCESS_KEY=...
TOS_SECRET_KEY=...
TOS_REGION=cn-beijing
TOS_BUCKET=...
TOS_ENDPOINT=tos-s3-cn-beijing.volces.com   # 必须是 S3 兼容域名（tos-s3-{region}.volces.com）；
                                            # 原生域名 tos-{region}.volces.com 只认 TOS4 签名，会 403
# OSS_* / COS_* 同理，见配置文件

# local 驱动（可选，默认值如下）：
# CMF_MEDIA_LOCAL_ROOT=...    # 默认 public_path('cmf-media')，文件直接可访问
# CMF_MEDIA_LOCAL_URL=...     # 默认 /cmf-media
```

`cmf:install` 会自动发布配置并执行迁移（`cmf_media` / `cmf_media_usages`）。

## 业务模型引用媒体

```php
use Quansitech\Cmf\Media\Concerns\HasMedia;

class Post extends Model
{
    use HasMedia;
}

// 表单里使用 MediaPicker 字段，保存后同步引用：
$post->syncMedia($mediaIds, 'cover');
$post->attachMedia($mediaId, 'cover');
$post->detachMedia($mediaId, 'cover');
```

```php
use Quansitech\Cmf\Media\Filament\Forms\Components\MediaPicker;

MediaPicker::make('cover')           // 单选，state 为 media id
MediaPicker::make('gallery')->multiple()  // 多选，state 为 id 数组
```

「从媒体库选择」入口（按钮 + 媒体库弹窗）**默认隐藏**，避免普通用户直接复用全站
媒体库文件；需要时在 config 开启：

```php
// config/cmf-media.php
'picker_library' => true,
```

已选媒体支持点击查看：**图片**弹窗预览大图、**视频/音频**内嵌播放、**其他类型**
弹窗给出新窗口下载链接（是否落盘由对象的 Content-Disposition 元数据决定，
见「按入口上传规则与访问行为」）。

## 按入口上传规则与访问行为

全局限制（`max_size` / `allowed_mimes`）只有一套时，不同入口（护理员资质照片、活动配图、
文档预览、导出下载……）要求各不相同。`cmf-media.rules` 支持配置多套规则，入口声明使用
哪套，**规则只能比全局白名单更严**（mimes 必须是 `allowed_mimes` 子集，配超出即抛配置错误；
`max_size` 超过全局时按全局收紧），因此客户端声明哪套规则都不会放大权限。

```php
// config/cmf-media.php
'rules' => [
    'nurse-cert' => [      // 护理员资质照片：仅图片 ≤10MB
        'mimes' => ['image/*'],
        'max_size' => 10 * 1024 * 1024,
    ],
    'activity-image' => ['mimes' => ['image/*'], 'max_size' => 20 * 1024 * 1024],
    'doc-preview'    => ['mimes' => ['application/pdf'], 'disposition' => 'inline', 'cache_seconds' => 86400],
    'export'         => ['mimes' => ['application/zip'], 'disposition' => 'attachment'],
],
```

后台表单入口声明规则：

```php
MediaPicker::make('cert_photos')->uploadRule('nurse-cert')->multiple()
```

声明后：

- **服务端强制**：`check`（秒传）/ `sign`（签名）/ `upload`（local 中转）/ `callback`（建档）
  全链路按规则校验类型与大小，绕开前端直接调接口同样 422；local 中转与浏览器直传对同一
  入口执行同一套规则（rule 随签名字段透传）。API 调用方在各端点传 `rule` 参数即可。
- **前端选择行为同步约束**：`accept` 类型过滤在文件选择阶段生效、单文件大小上限按规则收紧。
- **未声明规则的入口沿用全局限制**，存量表单行为完全不变。

访问行为（`cache_seconds` / `disposition`）随入口生效：

```php
$media->url();                      // 默认公开 URL
$media->urlForEntry('doc-preview'); // 预览入口：inline + 缓存
$media->urlForEntry('export');      // 下载入口：attachment + 原始文件名（非对象名）
```

注意 `urlForEntry` 的实际效果因驱动而异：**云驱动（TOS/OSS/COS）下它返回的 URL
与 `url()` 完全相同**——访问行为已在上传时写入对象元数据，公开 URL 天然携带对应行为，
此处只起"声明这是哪个入口的链接"的语义作用；**只有 local 驱动才会真正改变 URL**
（改走 file 路由由服务端输出响应头）。写业务代码时仍建议用 `urlForEntry` 表达场景，
local（开发）与云（生产）环境差异由方法内部抹平。

实现说明：访问行为在**上传时写入对象元数据**（`Content-Disposition` / `Cache-Control`），
且 `disposition` 纳入去重维度——同一内容按 inline / attachment / 无各存一个对象
（key 形如 `{hash前2位}/{hash}.inline.pdf`），各自元数据正确、互不干扰；`cache_seconds`
不纳入去重（同内容多入口时以先上传者为准）。attachment 的文件名取自上传时的原始文件名
（RFC 5987 编码，中文名兼容），同样**先到先得**：同一内容被不同文件名的上传去重命中时，
下载拿到的是第一个上传者的文件名。云厂商 response-* URL 期覆盖实测不可靠
（TOS 匿名 GET 带 `response-*` 参数返回 400），本模块不依赖该机制；local 驱动经
`GET /{route_prefix}/file/{media}?rule=名` 由服务端输出对应响应头（该路由默认仅 `web`
中间件、文件按公开可读设计，收口用 `cmf-media.file_middleware` 配置）。

**inline 预览需要自定义访问域名**：TOS/OSS/COS 的默认 bucket 域名出于安全策略对 GET
强制返回 `attachment`（对象元数据也救不了），需绑定自定义域名并配置
`disks.{driver}.url`（如 `TOS_URL`）后 `inline` 才生效；`attachment`（含下载文件名）
在默认域名下即可生效。

## RichEditor 富文本接管

`RichEditor` 的图片/附件上传（拖入、粘贴、附件工具）默认落在 Filament 配置的 disk 上，无去重与引用管理。
挂载 `HasMediaRichContent` 后，编辑器附件整体接入媒体库：

- **上传即建档去重**：服务端计算内容 MD5，同一文件重复插入/上传复用同一 media 记录（软删记录自动恢复）；
- **保存时同步引用**：按当前内容中的 media id 集合对字段 `syncMedia` 差集增删（新增 +1 / 移除 -1）；
- **移除即清零删除**：图片从内容中移除并保存后引用归零，走既有软删 + 延迟 Job 清理链路；
- **记录删除闭环**：宿主记录物理删除时自动清理富文本字段的引用（软删保留，恢复后引用仍在）；
- **孤儿兜底**：上传后未保存表单的文件仍由每日 `cmf-media:prune-orphans` 清理。

```php
use Filament\Forms\Components\RichEditor\Models\Contracts\HasRichContent;
use Quansitech\Cmf\Media\Concerns\HasMedia;
use Quansitech\Cmf\Media\Concerns\HasMediaRichContent;

class Post extends Model implements HasRichContent
{
    use HasMedia;
    use HasMediaRichContent;

    protected array $mediaRichContentAttributes = ['content']; // 可配多个字段；省略时默认 ['content']
}
```

表单照常使用 `RichEditor::make('content')`，无需额外配置。

说明：

- 编辑器图片节点的附件 id 即 media id，保存时节点 `src` 自动替换为媒体 URL；
- 附件类型/大小前端校验沿用 RichEditor 配置（`fileAttachmentsAcceptedFileTypes()` / `fileAttachmentsMaxSize()`，默认仅常见图片、12MB），服务端另按本模块 `allowed_mimes` / `max_size` 白名单兜底；
- 新建记录时附件保存与引用挂接延迟到记录创建后自动完成（Filament `FileAttachmentProvider` 标准生命周期）。

## 上传链路

```text
云驱动  : 选文件 → Worker 分片算 MD5 → POST check（命中即秒传）
        → POST sign（签发单 key 凭证）→ 直传云存储 → POST callback（headObject 校验 + 建档）
local   : 选文件 → Worker 分片算 MD5 → POST check（命中即秒传）
        → POST sign（返回同源 upload 端点）→ POST upload（服务器算真实 hash 落盘 + 建档，直接返回 media）
```

端点默认挂在 `/cmf-media/{check,sign,upload,callback,library}`（`web` + `auth` 中间件，可在 config 调整）。

## 归零删除队列

引用计数归零后，记录先软删，再经 Laravel 队列延迟 `CMF_MEDIA_DELETE_DELAY`（分钟，默认 60）执行
`DeleteMediaJob`：删除云端对象并物理删除记录。Job 执行前复查引用计数，延迟窗口内被重新引用的
文件不会误删。

**「上传后未保存」的孤儿文件兜底**（auto_delete 开启时）：每日调度 `cmf-media:prune-orphans`，
将上传超过 `CMF_MEDIA_ORPHAN_CLEANUP_HOURS`（小时，默认 24）仍零引用的记录软删并排期
`DeleteMediaJob` 清理云端对象。宽限期覆盖长表单填写耗时；宽限期内文件正常可用，随时可建立引用。
该调度需宿主 crontab 已配置 Laravel `schedule:run`；auto_delete 关闭时自动跳过。
也可手动执行：

```bash
php artisan cmf-media:prune-orphans
```

删除任务走队列，**必须运行队列 worker 才会真正清理云端对象**：

```bash
php artisan queue:work            # 开发/小规模
# 生产环境建议 Supervisor / Horizon 常驻守护，见 Laravel 队列文档
```

注意：`QUEUE_CONNECTION=sync` 时延迟失效、任务立即同步执行（失去竞态缓冲窗口），生产环境请使用
redis / database 等真实队列驱动。

归零自动删除可通过开关关闭（归零后仅保留记录与云端对象，后台手动删除仍会排期清理）：

```dotenv
CMF_MEDIA_AUTO_DELETE=false
```

## 冒烟测试

```bash
php artisan cmf-media:smoke --disk=tos    # put → exists → get → delete 全流程
php artisan cmf-media:smoke --disk=local  # local 驱动无需凭证，随时可跑
```

## 审计集成（可选）

宿主已安装 `quansitech/cmf-module-auditing`（或 owen-it/laravel-auditing）时：

```dotenv
CMF_MEDIA_AUDIT=true
```

Media 模型切换为可审计的 `AuditableMedia`。

## 宿主集成 checklist（直传人工验证）

1. 直传后浏览器 Network 面板确认文件流量直达云存储域名、不经过应用服务器；
2. 重复上传同一文件，第二次无 PUT/POST 到云存储的请求（秒传生效）；
3. 500MB 上限文件算 hash 期间：内存占用不随文件大小增长、页面可正常交互、进度条持续推进；
4. 云控制台确认对象 key 为 hash 路径。
5. Filament Action / Modal **弹窗内**的 picker 同样能选文件（弹窗内容由 Alpine 挂载、不触发 Livewire 的 morph 钩子，故视图用 `x-init` 调用 `window.cmfMediaPickerInit` 初始化）。
6. 配了入口规则时：声明规则的入口超限文件被直传/中转同时拒绝（422），文件选择框按 accept 过滤；`$media->urlForEntry('规则名')` 的预览/下载/缓存行为符合规则，未声明规则的入口与升级前一致。

## 测试

```bash
composer install
vendor/bin/pest
```

Storage / Queue / Event 使用 Laravel fake；云 SDK 通过 Signer 接口隔离，单测不触网。

弹窗场景的接线（视图 `x-init` ↔ JS 的 `window.cmfMediaPickerInit`）由 `tests/Unit/MediaPickerViewContractTest.php` 固化；真实弹窗行为按上方宿主集成 checklist 第 5 条人工验证。
