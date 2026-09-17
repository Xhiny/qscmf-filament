# quansitech/cmf-module-import

QS CMF 导入扩展：基于 Filament v5 官方 `ImportAction` 的 xlsx 增强导入方案。安装：`composer require quansitech/cmf-module-import`。总文档与接入指南见 monorepo：[quansitech/qscmf-filament](https://github.com/quansitech/qscmf-filament)。

> 本仓库由 monorepo CI 自动生成的镜像（split 产物），请勿直接提交或提 PR；开发与 issue 请到上方 monorepo。

导入仅接受 `.xlsx`，模板内置单元格校验（文本锁定 / 长度 / 下拉），失败清单输出 xlsx。
终结 CSV 链路三类常见事故——身份证号被 Excel 转科学计数法、GBK 编码乱码、改后缀伪文件晦涩报错。

## 特性

- **仅接受 `.xlsx`**：CSV / 改后缀伪文件整单拒绝，杜绝编码乱码与晦涩报错
- **模板内置校验**：文本锁定（防长数字被转科学计数法丢精度）、长度、下拉选项，Excel / WPS 原生生效
- **失败清单 xlsx 导出**：原数据 + 行级失败原因拼接，且**默认保留与模板一致的单元格约束**（下拉 / 长度 / 文本锁定，下拉选项为清单生成时点快照），用户可直接修正后重传
- **兼容官方 Importer API**：行级校验、钩子、sync / async 完全沿用 Filament 官方能力
- **零迁移、零配置**：不依赖 cmf-core，任何 Filament v5 应用可用

## 安装

```bash
composer require quansitech/cmf-module-import
php artisan vendor:publish --tag=filament-actions-migrations   # imports / failed_import_rows（连带 exports）
php artisan migrate
```

异步导入需常驻队列 worker；默认 `sync` 模式完成通知走页面弹窗，无额外基础设施要求。

## 使用

导入是批量入口：行处理桥接既有领域 Action，与单条新增同一套校验。

三个能力出口：

- `XlsxImportAction`：导入
- `TemplateDownload` + `XlsxImportColumn`：模板生成，由 Importer `getColumns()` 驱动
- `XlsxFailedRowsDownloader`：失败清单导出，经 `XlsxImporter` 默认启用

### 1. 定义 Importer

继承 `XlsxImporter`，行级校验 / 钩子 / sync-async 完全沿用官方 API：

```php
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Illuminate\Database\Eloquent\Model;
use Quansitech\Cmf\Import\Template\ConstSource;
use Quansitech\Cmf\Import\Template\XlsxImportColumn;
use Quansitech\Cmf\Import\Xlsx\XlsxImporter;

class MemberImporter extends XlsxImporter
{
    public const MAX_ROWS = 300;

    public static function getColumns(): array
    {
        return [
            XlsxImportColumn::make('name')->label('姓名')
                ->requiredMapping()
                ->rules(['required', 'string', 'max:20']),
            // 长数字列必配 textLock + length：防 Excel 科学计数法丢精度
            XlsxImportColumn::make('id_card')->label('身份证号')
                ->requiredMapping()
                ->rules(['required', 'regex:/^\d{18}$/'])
                ->textLock()
                ->length(18),
            XlsxImportColumn::make('level')->label('等级')
                ->dropdown(ConstSource::make(['一级', '二级'])),
        ];
    }

    public function resolveRecord(): ?Model
    {
        $this->validateData();

        try {
            app(CreateMember::class)->create($this->data);
        } catch (\InvalidArgumentException $e) {
            throw new RowImportFailedException($e->getMessage()); // 行级失败
        }

        return null; // 已由领域 Action 落库
    }

    public function getJobConnection(): ?string
    {
        return 'sync';
    }
}
```

### 2. 挂载 Action

单一入口：模板下载内嵌导入弹窗。

```php
use Filament\Actions\Action;
use Quansitech\Cmf\Import\Template\TemplateDownload;
use Quansitech\Cmf\Import\Xlsx\XlsxImportAction;

XlsxImportAction::make()
    ->importer(MemberImporter::class)
    ->maxRows(MemberImporter::MAX_ROWS)
    ->label('批量导入')
    ->registerModalActions([
        Action::make('downloadTemplate')
            ->label('下载导入模板')
            ->link()
            ->action(fn () => TemplateDownload::downloadResponse(MemberImporter::class)),
    ]),
```

`maxFileSize()`（默认 20MB）、`authGuard`、`chunkSize` 等官方能力不变。

### 3. 列配置 API

在官方 `rules()` / `requiredMapping()` / `label()` 之上新增：

- `textLock()`：模板单元格锁定文本格式
- `length($exact)` / `length($min, $max)`：模板 textLength 校验
- `dropdown(ConstSource::make([...]) | QuerySource::make(fn (): array => ...))`：
  `QuerySource` 为生成时快照，上限 2000 项；字典高频变动或选项有依赖（如镇街→村）
  时不放下拉、行级校验兜底
- 普通 `ImportColumn` 可混用（无模板校验，行为不变）
- 可选：静态 `getTemplateGuideLines(): array` 接管模板「填写说明」文案

## 行为契约

- **整单拒绝**（解析期，零写入）：非 xlsx / 伪文件、无数据行、重复表头、超 `maxRows`
- **行级失败**（失败清单 = 原数据 + 末列该行全部原因拼接）：列级 `rules()` 校验、
  业务异常（`RowImportFailedException`）、科学计数法 / 截断可疑值（基类对所有列
  自动附加）
- **失败清单约束一致性**（默认行为）：清单表头保持用户原文件表头序，声明了模板
  校验的列按「表头 ≈ 导入列 label」匹配挂载同等约束（匹配口径与官方表头自动映射
  同源）；表头匹配不上（如导入时手动改过列映射）的列降级为无约束、原数据完整
  保留，服务端行级校验兜底；约束装配异常（如 `QuerySource` 查询失败）时整单降级
  为无约束纯数据清单，不影响数据导出
- 已知边界：声明 `isSensitive()` 的列按官方 `filterSensitiveData` 语义不进入失败
  清单（整列缺失），若该列 `required` 则修正后重传会再次失败——接入方声明前须
  知晓并自行权衡

## 解析规则与边界

- 数字字符串化：≤15 位精确；16 位+ 已被 Excel 截断，科学计数法字样原样透传、行级拒绝
- 日期 → `Y-m-d H:i:s`；公式 → 缓存值（无缓存按 0，openspout 上游语义）；全空行跳过；
  合并单元格取左上值；固定读第一个工作表；中间格式为内存 UTF-8 CSV 流，不落盘
- openspout 4.x 无 DataValidation → 模板生成与失败清单约束装配用 PhpSpreadsheet
  （导入读取仍 openspout 流式；失败行数受 `maxRows` 上限约束，全内存无压力）
- 失败清单数据单元格一律以字符串类型落盘（防数值绑定把长数字变科学计数法，
  否则重传会触发可疑值检测形成失败循环）
- 失败清单不做错误列高亮（后续优化候选）

## 测试

```bash
composer install
vendor/bin/phpunit
```

升级 `filament/actions`（含 minor）后请重跑包测试：实现以 `getUploadedFileStream()`
作为官方流水线唯一文件入口，vendor 内部消费结构变化可能使 xlsx 转换静默失效。

应用侧接入测试建议覆盖：规则矩阵（含科学计数法输入断言行级失败）、全流水线
（成功计数 + 失败清单原因拼接）、超 `maxRows` 整单拒绝零写入，范式见包内 `tests/`。
