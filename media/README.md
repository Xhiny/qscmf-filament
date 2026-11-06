# cmf-module-media

QS CMF 媒体模块：浏览器直传 TOS / OSS / COS（可选 local 本地磁盘）、内容哈希去重（秒传）、引用计数、归零自动清理、Filament 后台媒体管理与表单字段。

## 功能特性

- **浏览器直传**：服务器只做「签发 + 建档」，文件流量不经过应用服务器
- **内容哈希去重（秒传）**：同一内容全站只存一份对象，重复上传直接复用已有 media 记录
- **local 本地驱动**：`CMF_MEDIA_DRIVER=local`，文件落服务器磁盘，去重与引用逻辑与云驱动一致，适合单机 / 内网场景
- **按入口上传规则**：不同表单入口可声明各自的类型 / 大小限制与访问行为（预览或下载），服务端全链路强制
- **选图前裁剪（可选）**：字段声明比例后，图片在浏览器里按该比例裁好再上传，裁剪先于内容指纹计算
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

环境变量见下方「环境配置（.env）」。local 驱动无需凭证，文件默认落 `public/cmf-media`（直接可访问）。

## 环境配置（.env）

配置项均可用环境变量覆盖（对应 `config/cmf-media.php` 中的 `env()` 调用）。改完 `.env` 后需
`php artisan config:clear` 生效；生产环境用 `php artisan config:cache` 重建缓存。

### 全局变量

```dotenv
CMF_MEDIA_DRIVER=tos               # tos / oss / cos / local，默认 tos
CMF_MEDIA_MAX_SIZE=524288000       # 单文件上限（字节），默认 500MB
CMF_MEDIA_VERIFY_ETAG=true         # callback 建档比对 ETag，防冒领，默认 true
CMF_MEDIA_CROP_MAX_WIDTH=1600      # 裁剪产物最大像素宽
CMF_MEDIA_CROP_QUALITY=0.92        # 裁剪 JPEG 重编码质量
CMF_MEDIA_AUTO_DELETE=true         # 引用归零自动软删并排期清理云端对象
CMF_MEDIA_DELETE_DELAY=60          # 归零删除缓冲（分钟），需队列 worker
CMF_MEDIA_ORPHAN_CLEANUP_HOURS=24  # 孤儿文件清理宽限（小时）
CMF_MEDIA_ROUTE_PREFIX=cmf-media   # 上传/访问路由前缀
CMF_MEDIA_AUDIT=false              # 切换可审计媒体模型（需已装审计模块）
```

### 云驱动凭证

只有 `bucket` 有值的驱动才会注册 disk，因此可一次配好多家、用 `CMF_MEDIA_DRIVER` 切换；
凭证留空或 adapter 未安装时，相关 disk 解析会得到明确异常。

```dotenv
# 火山引擎 TOS
TOS_ACCESS_KEY=...
TOS_SECRET_KEY=...
TOS_REGION=cn-beijing
TOS_BUCKET=...
TOS_ENDPOINT=tos-s3-cn-beijing.volces.com   # 必须是 S3 兼容域名；
                                            # 原生域名 tos-{region}.volces.com 只认 TOS4 签名，会 403
TOS_URL=https://media.example.com           # 自定义访问域名，见下节
TOS_THUMB_SUFFIX="?x-tos-process=image/resize,w_200"

# 阿里云 OSS
OSS_ACCESS_KEY_ID=...
OSS_ACCESS_KEY_SECRET=...
OSS_BUCKET=...
OSS_ENDPOINT=oss-cn-hangzhou.aliyuncs.com
OSS_URL=https://media.example.com
OSS_THUMB_SUFFIX="?x-oss-process=image/resize,w_200"

# 腾讯云 COS
COS_SECRET_ID=...
COS_SECRET_KEY=...
COS_REGION=ap-guangzhou
COS_BUCKET=example-1250000000               # 含 appid
COS_URL=https://media.example.com
COS_THUMB_SUFFIX="?imageMogr2/thumbnail/200x200"

# 本地磁盘（CMF_MEDIA_DRIVER=local；两项都不设时默认 public/cmf-media、URL /cmf-media，
# 不要留空赋值，留空会覆盖默认值）
# CMF_MEDIA_LOCAL_ROOT=/data/cmf-media
# CMF_MEDIA_LOCAL_URL=/cmf-media
```

### 自定义访问域名（TOS_URL / OSS_URL / COS_URL）

`*_URL` 即 `disks.{driver}.url`，是访问 URL 的 host，未配置时回退到默认 bucket 域名
（`https://{bucket}.{endpoint}/{key}`）。

配置用法（以 TOS 为例）：

1. 在 TOS 控制台为 bucket 绑定自定义域名（如 `media.example.com`）并完成 CNAME 解析；
2. `.env` 填 `TOS_URL=https://media.example.com`（含协议头，末尾斜杠可有可无）；
3. `php artisan config:clear` 后，`$media->url()` / `thumbUrl()` 即走该域名。

注意：

- **`disposition=inline` 预览必须依赖自定义域名**：TOS/OSS/COS 默认 bucket 域名在投递层
  对所有 GET 强制返回 `attachment`（对象元数据覆盖无效），绑定自定义域名后 inline 才生效；
  `attachment` 下载行为在默认域名下即可用。
- 变量只在配置了对应 bucket 的驱动上生效，切换驱动时按驱动分别填写；
- `thumb_suffix` 是追加在 URL 后的图片处理参数，绑定自定义域名后同样可用。

### 无环境变量的配置项

`allowed_mimes`、`rules`（按入口上传规则）、`picker_library`、`sign_expires`、`middleware` /
`file_middleware`、`model` / `resource` / `policy` 等无对应环境变量，需直接改
`config/cmf-media.php`（`cmf:install` 发布后的文件）。

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

选图前裁剪（可选）：字段声明比例后，选中的图片先在本机弹出的裁剪层里裁好再进入上传
链路。裁剪发生在计算内容指纹之前，因此秒传去重与回调校验都基于裁剪后的文件；比例只是
前端体验约束，服务端不校验。

```php
MediaPicker::make('cover')
    ->cropAspectRatio('4:3')   // '4:3' / '4/3' / '1.5' / 1.5，或闭包按 $record 动态取值
    ->cropMaxWidth(1600);      // 可选，裁剪产物像素宽上限，超出等比缩小
```

- PNG 保持 PNG（留住透明通道），其余格式转 JPEG 并以白色铺底；
- GIF / SVG 不裁剪直接上传；未配置比例时行为与之前完全一致；
- 用户在裁剪层点「取消」则该文件不上传，其余待上传文件继续；
- 裁剪层异常时退回原文件，仅在控制台 warn，不阻断上传；
- 输出质量与宽度上限见 `cmf-media.crop` 配置（`CMF_MEDIA_CROP_MAX_WIDTH` / `CMF_MEDIA_CROP_QUALITY`）。

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
  （如 `TOS_URL`）后 `inline` 才生效；`attachment` 在默认域名下即可生效。配置方法见
  「环境配置（.env）→ 自定义访问域名」。

### 入口目录（path）

规则可声明可选的 `path` 静态目录段，对象 key 落在约定目录下（共享桶按项目/业务
归拢的场景）：

```php
'rules' => [
    // 多级段即递归目录：A/B/C/{hash前2位}/{hash}.{ext}
    'archive' => ['mimes' => ['image/*'], 'path' => 'A/B/C'],
    // 环境差异化：预发/生产目录由环境变量驱动
    't4t'     => ['mimes' => ['image/*'], 'path' => env('CMF_MEDIA_T4T_PATH', 't4tstudio')],
],
```

语义与约束：

- **语法**：静态段以 `/` 分隔，每段仅允许字母数字下划线连字符（拒 `..`、空段、
  前后斜杠），总长 ≤200，非法在规则解析期报错（fail fast）。
- **递归目录**：多级段即对象 key 的多级路径——对象存储按多级 key 直接写入，
  local 驱动由存储层递归建目录，全程无“建目录”动作。
- **防伪不变**：目录段由服务端从规则取得，客户端只能选规则名；callback 仍按
  「重算 expectedKey（含 path）全等比对」防伪，伪造目录段直接 422。
- **判重不变**：path 不参与判重（键仍为 hash + disposition）——同内容重复上传
  命中既有记录复用，记录的 path 保持首传值（指向旧文件路径）；目录是“上传时的
  落点”而非归属边界，同内容先经他入口传过时本次入口目录下不会有新对象。
- **环境差异化**：path 写在 config 里可用 `env()` 驱动；path 变更只影响新上传，
  存量记录 URL 不变（记录存完整 key）。切换 env 的部署窗口内“已签未调”的上传
  会 422，重传自愈。
- **全站统一目录**：与 RichEditor 附件接管的 `rich_content.rule` 指向同一含 path
  规则，可让所有上传入口（MediaPicker 字段 + 富文本附件）统一落约定目录。
- **边界**：绕过 check 直接 sign+直传的异常客户端会在云端留下无记录指向的孤儿
  对象（纯存储浪费，无一致性问题）；正常 check→sign 流程不会触发。

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

### 富文本附件的入口规则（rich_content.rule）

可选：声明 `cmf-media.rules` 中的规则名，富文本拖入/粘贴/附件工具上传的文件按
该规则落桶（含 `path` 目录段时同样生效）；未配置 = 沿用全局限制（盘根原格式，
存量行为）：

```php
// config/cmf-media.php
'rich_content' => [
    'rule' => env('CMF_MEDIA_RICH_CONTENT_RULE'),
],
```

与「入口目录（path）」配合可让全站上传（MediaPicker 字段 + 富文本附件）统一落
约定目录：声明一个含 path 的规则，MediaPicker 挂 `->uploadRule()`，
`rich_content.rule` 指向同一规则即可。

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

## CDN 加速与边缘鉴权（私有桶推荐）

私有桶裸 URL 匿名 403；COS 原生 q-sign 签名打 CDN 域名也会被边缘拒绝（两套验证体系互斥）。
绑定开启鉴权的 CDN 域名后，`url()` 全部出口（前台 API / 后台预览 / 富文本 / 缩略图）
按配置动态签发，调用方零改动：

```dotenv
COS_CDN_URL=https://cdn.example.com
COS_CDN_AUTH_TYPE=type_d          # 须与控制台一致
COS_CDN_SIGN_KEY=xxxxxxxx        # 控制台主密钥（6-40 位）
COS_CDN_QUANTIZE_SECONDS=300      # 可选，时间戳量化桶，页面 URL 稳定
```

四类型算法对照（拼接顺序与进制互不相同，不可混用；密钥为控制台
主密钥，备密钥轮换是控制台侧双认概念，签发方不参与）。实测状态：type_a 已有现网控制台印证（TypeA +
md5 + sign，五段式签名 URL GET 200，query 追加图片处理参数不破坏
签名）；type_b / type_c / type_d 仅官方文档示例值复算守护，未实测：

| 类型 | URL 形态 | md5（无分隔符直拼） | 时间戳 |
|------|---------|--------------------|--------|
| type_a | `?sign=ts-rand-uid-md5` | `md5(uri-ts-rand-uid-pkey)` 密钥最后 | 十进制 |
| type_b | `/ts/md5/path` | `md5(pkey-ts-uri)` | 北京时间 `YmdHi` |
| type_c | `/md5/ts/path` | `md5(pkey-uri-ts)` | 十六进制 |
| type_d | `?sign=md5&t=ts` 参数名可配 | `md5(pkey-uri-ts)` | 十进制（推荐） |

要点：

- **有效期由控制台「鉴权有效时间」全局决定**，代码无法逐 URL 指定；建议
  ≥ 30 分钟（后台长开页面不刷新不死图），且大于页面缓存 TTL
- `quantize_seconds` 默认 0（关闭）：仅当需要页面两次渲染输出同一 URL 时
  开启，且 ≤ 有效时间的 1/4（桶尾签发剩余 = 有效时间 − quantize）；
  它不影响 CDN 命中率——四种类型官方均自动排除鉴权参数出缓存 key，
  且优先级高于「缓存键规则」（TypeA 文档 41623 明文）；
  type_a 的 `rand` 建议配固定非空值（现网验证使用随机 22 位；
  空串未实测，保守起见配非空）
- 缩略图等处理参数追加在 query，四种类型签名均不覆盖 query，不受影响；
  但「过滤参数」仍须选「保留」（否则 imageMogr2 等回源失效）
- COS 控制台需开启「默认 CDN 回源授权」（允许 CDN 服务账号回源读私有桶）
- 未配置本段 = 存量行为不变（url 裸拼、`temporaryUrl()` q-sign 兜底）

其它云厂商的 CDN 鉴权按同接口扩展：在 `src/Cdn/<厂商>/` 子命名空间实现
签名器（腾讯实现位于 `src/Cdn/Tencent/`，TypeA-D 为腾讯口径——其他厂商
的 A/B/C 类命名与腾讯算法并不相同，不可混用），工厂 match 一行接入 +
对应 extend 接线 + `disks.<驱动>.cdn` 配置段。

### CDN 子目录关联（path_prefix）

仅 COS 驱动支持（TOS/OSS 的自定义域名 `TOS_URL` / `OSS_URL` 暂无此开关，把域名配成关联子目录的 CDN 地址仍会双重前缀 404）。

CDN 域名在控制台若关联的是桶内子目录（「回源源路径」= `t4tstudio/`），边缘回源拼 Key 的方式是「源路径 + URL Path」。此时对象 Key 仍是全路径（`t4tstudio/image/ab/hash.jpg`，DB、查重、删除、q-sign 兜底均用全 Key，上传逻辑零改动），但 `url()` 出口必须剥掉前缀——否则边缘拼出 `t4tstudio/t4tstudio/...` → 404。

```env
COS_CDN_PATH_PREFIX=t4tstudio
```

- 值必须与控制台「回源源路径」**逐字一致**（首尾空白与斜杠会被归一）；改控制台必须同步改 env
- 签名 uri 与 URL 拼接同用剥后 path（同源），边缘按 URL Path 复算 md5 才能通过；未开鉴权（仅加速）同样剥；仅加速时 `temporaryUrl` 仍走原生 COS 域名 + 全 Key q-sign（不剥）
- 上传期守卫：`sign`、`store`、回调建档三处校验对象 key 是否落在前缀下，不满足直接 422（消息带 key、前缀与处置提示），不让脏对象进库。校验条件与 adapter 换用同源——`cdn.url` 与 `auth_type` 都为空时 CDN 出口未启用，前缀不参与拼接，不拦上传
- 判重复用保留首传 path（`config/cmf-media.php` 的 path 注释）：历史按无 path 规则落过桶根的对象，开启前缀后**重传不会自愈**——秒传命中那条根路径记录并删掉新 key，`url()` 依旧 null。存量只能搬迁，不能靠重传
- 对象不在该目录之下 → `url()` 抛 `InvalidArgumentException`（该对象边缘必 404，不应静默）。模型层 `Media::url()` 不 500，退化为 null 并记一条 warning（含 `disk` / `path` / 异常对象），按 disk + 异常类型去重；半配置（`auth_type` 缺 `url` / `primary_key`、驱动 adapter 未装）在 `Storage::disk()` 解析期失败同样留痕，只有磁盘未注册（该驱动未启用）才静默

- 空（默认）= CDN 关联桶根，URL Path 即全 Key，存量行为不变

排障公式：**签名 URL 去掉域名的 path 段 + 回源源路径 == COS 控制台里的对象 Key**。等式不成立即 404（路径偏移）或 403（鉴权 uri 错位）。

#### 既有项目升级（必读）

`cmf:install` 不覆盖已发布的文件，存量宿主的 `config/cmf-media.php` 里可能连 `cdn` 段都没有（更早发布的版本）或没有 `path_prefix` 键，此时**只设 `COS_CDN_PATH_PREFIX` 不生效**（env 无人读取，前缀恒为空，双重前缀 404 原样保留）。二选一：

1. 手动把本包 `config/cmf-media.php` 中 `disks.cos.cdn` 段（含 `path_prefix`）补到宿主配置；
2. `php artisan cmf:install --force` 后 `git diff config/cmf-media.php` 逐项核对，保留本项目的其它自定义。

#### 启用前提与回滚

`path_prefix` 是部署约束，不是向后兼容的开关：

- 所有上传入口规则（`cmf-media.rules.*.path`）必须落在该目录下：规则没写 `path` 的入口，新上传会被守卫直接 422
- 存量对象也必须已位于 `{prefix}/`，否则这些记录 `url()` 全部失败 → 模型层 null，后台媒体库与前台接口成片空白（包内暂无对象搬迁命令，需自行用 `coscmd` / `rclone` 等搬迁后再启用）
- 上线前在真环境用生产实际的 `auth_type` 各验一条：签名 URL 的 path + 回源源路径 == 控制台对象 Key，200 通过后再全量放开
- 回滚：把 `COS_CDN_PATH_PREFIX` 置空并 `php artisan config:clear` 即恢复「CDN 关联桶根」的存量行为（对象、DB、签名口径均未改动，可随时回退）

> `Media::url()` 的退化路径：磁盘解析失败（已注册但半配置 / adapter 未装）→ null + warning；或 `url()` 抛 → 回退 `temporaryUrl()`（开鉴权时同样抛）→ null + warning。每个 disk 每种异常（类型 + 消息指纹）只记一条——Web 每请求复位，队列/CLI 下随进程存活；排查看日志里的 `exception` / `temporary_url_exception` 堆栈，或直接 `Storage::disk('cmf-media-cos')->url($path)` 看真实异常。

## COS 排障：404 被吞成「对象不存在」

COS 适配器（overtrue/flysystem-cos）把一切 404 统一当作「对象不存在」返回，
无法区分「桶不存在 / 桶不可达」与「对象真不存在」。桶名接线错误时不会报错，
只会表现为静默假阴性：

| 现象 | 根因方向 |
|------|----------|
| 秒传查重恒 miss，同一文件重复上传 | 服务端 `exists()` 打向不存在的桶 |
| callback 建档校验异常 | `StorageObjectInspector::inspect()` 假阴性 |
| 删除后云端对象仍残留 | `DeleteMediaJob` 删不到对象 |
| 前台图片 404（未配 `COS_URL`） | `getObjectUrl()` 返回坏域名 |

排查：浏览器直传正常而服务端全错，先核对桶名接线——本模块的
`cmf-media-cos` 驱动已内建拆名（bucket 配置仍传全名，适配器收到的
是裸名 + 显式 domain 兜底），若宿主自行覆盖 driver，务必保持
「服务端与直传同一 host」（测试套件 `CosDriverHostTest` 守护此约束）。

```bash
php artisan cmf-media:smoke --disk=cos   # put→exists→get→delete 全绿即接线正确
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
5. 配了 `cropAspectRatio()` 的字段选图后弹出裁剪层，裁剪框比例锁定、输出图尺寸符合预期；
   多选连续选图时裁剪层逐个弹出、不互相覆盖，裁剪后再传同一张图走秒传。

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
- 选图前裁剪（比例解析、输出格式、异常回退）：`src/Filament/Forms/Components/MediaPicker.php`、`resources/js/crop.js`
- 各云厂商签名实现：`src/Signers/`
