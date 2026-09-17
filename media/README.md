# cmf-module-media

QS CMF 媒体模块：浏览器直传 TOS / OSS / COS（可选 local 本地磁盘）、内容哈希去重（秒传）、引用计数、归零自动清理、Filament 后台媒体管理与表单字段。

## 功能特性

- **浏览器直传**：服务器只做「签发 + 建档」，文件流量不经过应用服务器
- **内容哈希去重（秒传）**：同一内容全站只存一份对象，重复上传直接复用已有 media 记录
- **local 本地驱动**：`CMF_MEDIA_DRIVER=local`，文件落服务器磁盘，去重与引用逻辑与云驱动一致，适合单机 / 内网场景
- **按入口上传规则**：不同表单入口可声明各自的类型 / 大小限制与访问行为（预览或下载），服务端全链路强制
- **引用计数**：业务模型按字段引用媒体，归零自动软删并延迟清理云端对象
- **RichEditor 接管**：富文本图片 / 附件自动接入媒体库（去重 + 引用闭环）
- **后台管理**：媒体列表 / 详情预览 / 引用明细 / 有引用禁删，Shield 权限点自动登记

## 安装

```bash
composer require quansitech/cmf-module-media
```

按使用的云厂商安装对应 Flysystem adapter（local 驱动无需安装）：

| 驱动 | adapter |
| --- | --- |
| tos（火山引擎，S3 兼容） | `composer require league/flysystem-aws-s3-v3` |
| oss（阿里云） | `composer require xxtime/flysystem-aliyun-oss` |
| cos（腾讯云） | `composer require overtrue/flysystem-cos` |
| local（服务器本地磁盘） | 无需 adapter |

`cmf:install` 会自动发布配置（`config/cmf-media.php`）并执行迁移（`cmf_media` / `cmf_media_usages`）。

环境配置：

```dotenv
CMF_MEDIA_DRIVER=tos          # tos / oss / cos / local
TOS_ACCESS_KEY=...
TOS_SECRET_KEY=...
TOS_REGION=cn-beijing
TOS_BUCKET=...
TOS_ENDPOINT=tos-s3-cn-beijing.volces.com   # 必须是 S3 兼容域名；
                                            # 原生域名 tos-{region}.volces.com 只认 TOS4 签名，会 403
# OSS_* / COS_* 同理，见配置文件注释
```

local 驱动无需凭证，文件默认落 `public/cmf-media`（直接可访问）。

## 使用示例

### 1. 模型引用媒体

```php
use Illuminate\Database\Eloquent\Model;
use Quansitech\Cmf\Media\Concerns\HasMedia;

class Post extends Model
{
    use HasMedia;
}
```

```php
$post->syncMedia([1, 2, 3], 'gallery'); // 以 id 列表覆盖某字段的引用（差集增删）
$post->attachMedia(1, 'cover');         // 引用单个媒体
$post->detachMedia(1, 'cover');         // 解除引用
```

### 2. 表单字段：MediaPicker

```php
use Filament\Schemas\Schema;
use Quansitech\Cmf\Media\Filament\Forms\Components\MediaPicker;

class PostResource extends Resource
{
    public static function form(Schema $form): Schema
    {
        return $form->components([
            MediaPicker::make('cover'),               // 单选，state 为 media id（int|null）
            MediaPicker::make('gallery')->multiple(), // 多选，state 为 id 数组
        ]);
    }
}
```

MediaPicker 只负责上传与选中（state 即 media id），**引用关系需在保存后同步**。在
Create / Edit 页面钩子中调用 `syncMedia`：

```php
// CreatePost 页面
class CreatePost extends CreateRecord
{
    protected static string $resource = PostResource::class;

    protected function afterCreate(): void
    {
        $this->record->syncMedia(array_filter((array) $this->data['cover']), 'cover');
        $this->record->syncMedia($this->data['gallery'] ?? [], 'gallery');
    }
}

// EditPost 页面：在 afterSave() 中写同样的两行
```

已选媒体在表单内支持点击查看：图片弹窗预览大图、视频/音频内嵌播放、其他类型给出下载链接。

「从媒体库选择」入口默认隐藏（避免普通用户复用全站媒体库文件），需要时在配置开启：

```php
// config/cmf-media.php
'picker_library' => true,
```

### 3. 读取与展示

```php
use Quansitech\Cmf\Media\Models\Media;

// 取某字段引用的媒体
$coverId = $post->mediaUsages()->where('field', 'cover')->value('media_id');
$cover = $coverId ? Media::find($coverId) : null;

$cover?->url();       // 访问 URL（公开 URL，无公开 URL 时回退临时签名 URL）
$cover?->thumbUrl();  // 图片缩略图（云厂商图片处理参数；local 为原图）
$cover?->isImage();
```

## 按入口上传规则与访问行为

全局限制（`max_size` / `allowed_mimes`）只有一套，不同入口要求各不相同时，在
`cmf-media.rules` 配置多套规则，入口声明使用哪套。**规则只能比全局白名单更严**
（mimes 必须是 `allowed_mimes` 子集，`max_size` 超过全局时按全局收紧），客户端
声明哪套规则都不会放大权限：

```php
// config/cmf-media.php
'rules' => [
    'cert-photo'   => ['mimes' => ['image/*'], 'max_size' => 10 * 1024 * 1024],
    'doc-preview'  => ['mimes' => ['application/pdf'], 'disposition' => 'inline', 'cache_seconds' => 86400],
    'export'       => ['mimes' => ['application/zip'], 'disposition' => 'attachment'],
],
```

表单入口声明规则：

```php
MediaPicker::make('cert_photos')->uploadRule('cert-photo')->multiple()
```

声明后：服务端在 check / sign / upload / callback 全链路按规则校验（绕过前端直接调
接口同样 422，API 调用方在各端点传 `rule` 参数即可）；前端文件选择框按规则的
mimes / max_size 同步约束。**未声明规则的入口沿用全局限制，存量行为不变。**

访问行为（`disposition` 预览或下载、`cache_seconds` 缓存时长）随入口生效，取 URL 时
声明入口：

```php
$media->urlForEntry('doc-preview'); // 预览入口
$media->urlForEntry('export');      // 下载入口（attachment + 原始文件名）
```

注意事项：

- 云驱动（TOS/OSS/COS）下 `urlForEntry` 返回的 URL 与 `url()` 相同——访问行为已在
  上传时写入对象元数据，此处只起语义声明作用；local 驱动才真正改变 URL（走 file 路由
  由服务端输出响应头）。业务代码统一用 `urlForEntry` 表达场景，环境差异由方法内部抹平。
- **inline 预览需要自定义访问域名**：TOS/OSS/COS 默认 bucket 域名对 GET 强制返回
  `attachment`（安全策略），需绑定自定义域名并配置 `disks.{driver}.url`
  （如 `TOS_URL`）后 `inline` 才生效；`attachment` 在默认域名下即可生效。

## RichEditor 富文本接管

RichEditor 的图片 / 附件上传默认落在 Filament 配置的 disk 上，无去重与引用管理。挂载
`HasMediaRichContent` 后整体接入媒体库：上传即建档去重、保存时按内容中的 media id
差集同步引用、移除即归零走清理链路、宿主记录物理删除时自动清理引用。

```php
use Filament\Forms\Components\RichEditor\Models\Contracts\HasRichContent;
use Illuminate\Database\Eloquent\Model;
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

## 队列与自动清理

引用计数归零后，记录先软删，再经队列延迟 `CMF_MEDIA_DELETE_DELAY`（分钟，默认 60）
执行 `DeleteMediaJob` 删除云端对象并物理删除记录；Job 执行前复查引用计数，延迟窗口内
被重新引用的文件不会误删。

**必须运行队列 worker 才会真正清理云端对象**：

```bash
php artisan queue:work            # 开发/小规模
# 生产环境建议 Supervisor / Horizon 常驻守护
```

注意 `QUEUE_CONNECTION=sync` 时延迟失效（任务立即同步执行，失去竞态缓冲窗口），生产
环境请使用 redis / database 等真实队列驱动。

「上传后未保存表单」的孤儿文件由每日调度的 `cmf-media:prune-orphans` 兜底：上传超过
`CMF_MEDIA_ORPHAN_CLEANUP_HOURS`（小时，默认 24）仍零引用的记录软删并排期清理。该
调度需宿主 crontab 已配置 Laravel `schedule:run`；也可手动执行：

```bash
php artisan cmf-media:prune-orphans
```

归零自动删除可整体关闭（归零后仅保留记录与云端对象，后台手动删除仍会排期清理）：

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

## 集成自检（直传人工验证）

1. 直传后浏览器 Network 面板确认文件流量直达云存储域名、不经过应用服务器；
2. 重复上传同一文件，第二次无 PUT/POST 到云存储的请求（秒传生效）；
3. 云控制台确认对象已写入（key 为 hash 路径）；
4. 配了入口规则时：超限文件被拒绝（422）、文件选择框按 accept 过滤；
   `$media->urlForEntry('规则名')` 的预览 / 下载行为符合规则，未声明规则的入口与升级前一致。

## 测试

```bash
composer install
vendor/bin/pest
```

## 深入实现

以下实现细节不在本 README 展开，以代码注释为准，需要时直接阅读：

- 全部配置项说明（凭证、上传策略、规则、路由、中间件等）：`config/cmf-media.php`
- 对象 key 规则、URL 生成、软删排期逻辑：`src/Models/Media.php`
- 上传链路（check / sign / upload / callback）与回调防伪：`src/Http/Controllers/MediaUploadController.php`
- 入口规则解析与校验：`src/Support/UploadRule.php`
- 各云厂商签名实现：`src/Signers/`
