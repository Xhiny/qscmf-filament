# qscmf-filament 行政区划模块（area）开发方案

## 1. 目标与范围

在 [qscmf-filament](https://github.com/quansitech/qscmf-filament) monorepo 中新增 `area` 模块（包名 `quansitech/cmf-module-area`），实现：

1. **数据底座**：内置 [AreaCity-JsSpider-StatsGov](https://github.com/xiangyuecn/AreaCity-JsSpider-StatsGov) 最新四级行政区划数据（省市区乡镇），随模块迁移入库；
2. **引用登记**：记录业务系统中"哪些表的哪些字段"引用了地区 ID，作为后续数据变更的影响面依据；登记机制带强制校验，防止漏登；
3. **变更升级流水线**：整套流程做成一个 **skill 交给 AI 驱动**——确定性步骤全部脚本化（下载/diff/校验/生成迁移），语义判断（取证、判型）写进 skill 提示词；AI 产出经人工 PR 审查后发布模块新版本 → 使用方 `composer update` + 迁移后自动完成数据变更。

非目标：坐标/边界数据（ok_geo）不在本期范围；第五级村级数据（村/社区/居委会）不在范围。

---

## 2. 总体架构

```
┌────────────────────────── 开发侧（模块仓库）──────────────────────────┐
│                                                                      │
│  ① 发现上游更新（人工关注/社区反馈，area:check-upstream 确认版本）    │
│                          │                                           │
│                          ▼                                           │
│  ②~⑤ 由升级 skill 驱动 AI agent 完成（确定性步骤=脚本，语义=提示词）  │
│    ② area:download + area:diff      脚本：下载新版 csv → diff.json    │
│    ③ AI 取证判读（webfetch）        语义：查维基年度变更列表、        │
│        跟进政府公告 → changes.json     政府公告，判定类型与归属        │
│    ④ area:check-changes             脚本：schema + 逻辑校验，         │
│                                       不过则回到 ③ 修正              │
│    ⑤ area:generate-migration        脚本：changes.json → 迁移文件     │
│                                       【只含区划变更+映射数据，       │
│                                         不写死业务表名】              │
│                          │                                           │
│  ⑥ 人工闸门：PR 审查（changes.json + 迁移文件 + 新基线 csv 同 PR）    │
│                          ▼                                           │
│  ⑦ 合并、打 tag 发版（area-vX.Y.Z，走 split.yml）                     │
└────────────────────────────────────────────────┬─────────────────────┘
                                                  │ composer update
┌────────────────────────── 使用侧（业务项目）─────▼─────────────────────┐
│  引用登记（平时）：模型 areaReferences() 方法声明（纯数据、无副作用）  │
│                    → 部署时 area:sync-references 扫描收集落库          │
│                                                                      │
│  区划升级（发版后）：php artisan migrate                               │
│    → 更新 cmf_areas + 读取本项目 cmf_area_references                  │
│    → 按 merge_strategy 对命中表逐行执行映射改写（延迟绑定）            │
│    → 无法自动判定的条目进入"待人工处理"清单                            │
└──────────────────────────────────────────────────────────────────────┘
```

核心原则：

- **AI 只做"判读"，落地必须是可审核、可回滚的确定性迁移**。AI 产出的每一条变更判定都要附信息源 URL；维护者在 PR 上审查 changes.json 与生成的迁移文件，合并放行后才发版生效。
- **迁移行为跨环境确定性**：迁移逻辑不分析业务数据的分布来决定行为（不做"抽样推断列语义"之类的事），同一版本号在任何环境执行结果一致。
- **破坏性操作显式声明**：业务数据改不改、怎么改，由业务方在注册引用时用 `merge_strategy` 亲口声明，默认最安全的 `keep`。

---

## 3. 数据表设计

### 3.1 `cmf_areas` — 行政区划主表

字段与上游 `ok_data_level4.csv` 保持一致（降低 diff 复杂度），另加状态与承继字段：

| 字段 | 类型 | 说明 |
|---|---|---|
| id | bigint unsigned PK | 上游短编号（如东莞 4419） |
| pid | bigint unsigned, index | 上级 ID |
| deep | tinyint | 0省 1市 2区 3镇（**地区自身的层级属性**，与引用登记的 deep 无关） |
| name | varchar | 精简名（如"武汉"） |
| pinyin_prefix | varchar | 拼音前缀 |
| pinyin | varchar | 拼音 |
| ext_id | bigint | 数据源原始编号 |
| ext_name | varchar | 完整名（如"武汉市"） |
| status | tinyint default 1 | 1正常 0已撤销/停用（**撤销不删行**，保证历史业务数据可回显名称、迁移可回滚） |
| successor_id | bigint null | 撤销/合并后的**主要**承继地区 ID（粗粒度映射用；细粒度归属明细在 cmf_area_changes.detail） |
| created_at / updated_at | timestamp | |

> 撤销保留行的原因：历史业务数据（订单、合同、发票）仍以旧 id 引用它，删行会导致历史回显断链、迁移无法回滚。选择器与录入校验一律过滤 `status=1`，撤销行对新数据不可见。

### 3.2 数据版本履历的载体（不建表）

**不引入专门的版本表。** 数据更新通过发布模块新版本实现，每个数据迁移文件名携带上游版本号：

- 初始导入：`xxxx_seed_cmf_areas_2025.251231.260403.php`
- 后续升级：`xxxx_area_update_{version}.php`

Laravel 的 `migrations` 表天然记录了每个已执行迁移的文件名，即构成完整的"本库已应用数据版本"履历：排查定责、多环境核对直接查它即可；"composer 包升了但 migrate 未执行"的漂移检测由 `php artisan migrate:status` 的 pending 列表覆盖；`cmf_area_changes.version` 以字符串自含版本号，无需外键。

模块侧的 diff 基线与 AI 检索年份区间，由仓库内置基线数据（`database/data/ok_data_level4.csv`）与 `config/cmf-area.php` 的 `data_version` 管理，与业务库无关。

### 3.3 `cmf_area_references` — 业务引用登记表

**本方案的关键表**。业务模型在约定的 `areaReferences()` 静态方法中声明，`area:sync-references` 扫描收集落库（见 §6）：

| 字段 | 说明 |
|---|---|
| id PK | |
| table_name | 业务表名，如 `orders` |
| column_name | 字段名，如 `receiver_area_id` |
| merge_strategy | varchar default `keep`：`keep` 历史事实型，区划变更绝不改写业务数据 / `remap` 当前状态型，安全场景下自动映射到新 id |
| snapshot_column | varchar null | 名称快照列（如 `receiver_area_name`），见下方"名称快照双写" |
| description | 用途说明 |
| created_at / updated_at | |

声明方式（业务模型上定义静态方法，表名由模型自动推导）：

```php
class Order extends Model
{
    public static function areaReferences(): array
    {
        return [
            'receiver_area_id' => ['onMerge' => 'keep', 'snapshotColumn' => 'receiver_area_name'],
        ];
    }
}
```

**merge_strategy 的选择指引**：

| 业务字段语义 | 例子 | 策略 |
|---|---|---|
| 历史事实型 | 订单收货地址、合同签订地、发票地址、籍贯 | `keep`（绝不动数据，靠软删行/名称快照回显） |
| 当前状态型 | 门店所属区域、用户现居地、管辖片区、配送范围 | `remap`（自动映射到新 id） |

> 拿不准就选 `keep`（默认），数据归业务方自己管：少量直接改库，量大写脚本批处理。真正需要人工介入的"待人工清单"由**变更本身的不可判定性**产生（见 §10.1），对所有注册列生效，与列策略无关——因此不设第三种 `manual` 策略。

> 约束：只支持按 **ID 引用** 的字段；字段可存**任意层级**的地区 id（迁移逐行解析值的真实层级，无需声明层级）。按名称存储的历史字段不在自动迁移范围。

### 3.4 `cmf_area_changes` — 变更记录表

每次版本升级的逐条变更档案，支撑迁移执行、回滚与事后审计。**开发侧不建库**——流水线全程操作文件（diff.json → changes.json → 迁移文件），人工审查在 PR 上进行；本表由迁移在业务项目执行时写入，作为该库的已应用变更履历：

| 字段 | 说明 |
|---|---|
| id PK | |
| version | 所属上游版本 |
| change_type | `add`新设 / `split_from`析出 / `merge_into`合并并入 / `rename`更名 / `abolish`撤销（无承继）/ `parent_change`隶属变更 / `code_change`代码变更 / `code_reuse`代码重用 |
| old_id / new_id | 变更前后地区 ID（rename 时相同） |
| old_name / new_name | 变更前后名称 |
| detail | json：归属明细（child_id_map 下级新旧 id 对照，覆盖任意层级、renames、是否 100% 单一承继 full_transfer）、归档映射、各业务表受影响行数 |
| evidence_url / evidence_title | AI 判定的信息源（维基页面、政府公告链接） |
| ai_summary | text：AI 的判定理由摘要 |
| applied_at | 迁移执行时间（null=未应用） |

### 3.5 PostgreSQL 适配要点

本项目数据库为 PostgreSQL。使用 Laravel Schema builder 时大部分类型会自动映射（`tinyInteger()`→smallint、`unsignedBigInteger()` 忽略 unsigned），不会报错；但以下几点必须遵守，否则触发 PG 类型错误：

1. **业务引用列必须是整型（int2/int4/int8）**：PG 对运算符两侧类型严格校验。迁移生成的 `UPDATE … SET col=653228 WHERE col=653223`，若业务列是 varchar 存 adcode，PG 报 `operator does not exist: character varying = integer`（MySQL 会静默强转）。**`area:sync-references` 落库时必须校验被注册列的数据类型，非整型直接报错拒绝登记**；生成的迁移统一按整型字面量输出。
2. **ext_id 必须 int8（bigint）**：上游 12 位长码（如 653223102000）超过 int4 上限 2,147,483,647，建成 `integer` 导入即报 `integer out of range`。id 短码目前最大 9 位可放 int4，但统一 int8 免后患。
3. **类型标注的 PG 映射**（手写原生 SQL 时注意）：`tinyint`→`smallint`；PG 无 unsigned，`bigint unsigned` 在 PG 是语法错误，统一写 `bigint`。
4. **json 一律用 jsonb**：`detail` 等列用 `jsonb`；注意 `detail->>'full_transfer'` 返回 text，布尔判断需 `(detail->>'full_transfer')::boolean` 或 `detail @> '{"full_transfer":true}'`。
5. **merge_strategy 不用 PG 原生 ENUM**（改枚举值需 ALTER TYPE，运维麻烦），用 varchar + check 约束（Laravel `enum()` 在 PG 上即此行为）。

---

## 4. 名称快照双写（keep 类字段的推荐存法）

行业最佳实践（电商/物流订单地址是典型）：历史事实型字段**代码 + 名称快照双写**：

```sql
orders
├── receiver_area_id    bigint   -- 500112，用于统计、关联
└── receiver_area_name  varchar  -- "重庆市渝北区"，下单时刻的名称快照
```

- `area_id` 回答"**现在归哪管**"——走代码，跟着区划表演进；
- `area_name` 快照回答"**当时是哪**"——写死不动，历史/法律事实级展示用它，且与代码表彻底解耦（即使发生代码重用也不受影响），查历史还免 JOIN。

`AreaPicker` 提供 `.withNameSnapshot('receiver_area_name')`：选中后自动把当前 `ext_name` 写入快照列，业务方零成本养成双写习惯。引用注册时用 `snapshotColumn` 声明快照列。

---

## 5. 模块目录结构

遵循 monorepo 现有模块（参照 `media/`）的规范：

```
area/
├── composer.json                      # quansitech/cmf-module-area
├── README.md
├── phpunit.xml
├── config/
│   └── cmf-area.php                   # 上游 Release 地址、当前数据版本、scan_paths 兜底扫描路径
├── database/
│   ├── migrations/
│   │   ├── 2026_09_15_000001_create_cmf_areas_table.php
│   │   ├── 2026_09_15_000002_create_cmf_area_references_table.php
│   │   ├── 2026_09_15_000003_create_cmf_area_changes_table.php
│   │   ├── 2026_09_15_000004_seed_cmf_areas_2025.251231.260403.php  # 初始数据导入，文件名携带上游版本号
│   │   └── updates/                  # 后续每次升级生成的变更迁移
│   │       └── 2027_xx_xx_area_update_{version}.php
│   └── data/
│       ├── ok_data_level4.csv         # 内置的上游数据（当前基线）
│       └── SOURCE.md                  # 数据来源与上游 LICENSE 声明
├── src/
│   ├── AreaServiceProvider.php        # packageRegistered() 注册插件
│   ├── CmfAreaPlugin.php
│   ├── Facades/Area.php
│   ├── Models/{Area,AreaReference,AreaChange}.php
│   ├── Services/
│   │   ├── ImportService.php          # csv → 分块批量插入
│   │   ├── DiffService.php            # 新旧 csv 对比 → diff.json（含代码重用检测）
│   │   ├── ReferenceCollector.php     # 扫描模型 areaReferences() 收集声明 + 落库
│   │   ├── MigrationGenerator.php     # changes.json → 迁移文件
│   │   └── MigrationExecutor.php      # 迁移执行器：payload 应用/回滚（业务项目 migrate 时运行）
│   ├── Casts/
│   │   └── AreaIdCast.php             # 模型写入路径的注册强制校验
│   ├── Exceptions/
│   │   └── AreaReferenceNotRegisteredException.php
│   ├── Console/Commands/
│   │   ├── CheckUpstreamCommand.php   # area:check-upstream 查上游新版本
│   │   ├── DownloadCommand.php        # area:download {version} 下载上游 Release 数据
│   │   ├── DiffCommand.php            # area:diff {new_csv}
│   │   ├── CheckChangesCommand.php    # area:check-changes {changes.json} schema + 逻辑校验
│   │   ├── GenerateMigrationCommand.php # area:generate-migration {changes.json}
│   │   └── SyncReferencesCommand.php  # area:sync-references
│   └── Filament/
│       ├── Resources/
│       │   └── AreaResource.php       # 区划数据浏览
│       └── Forms/Components/
│           └── AreaPicker.php         # 级联选择组件（含注册强制校验、名称快照）
├── skill/                             # 升级流水线 skill：AI 驱动全流程（维护者侧）
│   ├── SKILL.md                       # 流程 SOP（脚本调用顺序）+ 取证判读规则
│   └── changes.schema.json            # AI 产出物的 JSON Schema 契约
└── tests/                             # Pest 测试
```

skill 启用：在 monorepo 的 opencode 配置中将 `area/skill` 登记为 skill 搜索路径（或软链为 `.opencode/skills/area-upgrade`）；该目录随 composer 包分发，但仅供维护者在模块仓库侧使用，业务项目不触发。

发版：按仓库 `RELEASING.md` 约定打 `area-vX.Y.Z` tag，并在 `.github/workflows/split.yml` 的 matrix 中增加 area 条目，split 出只读镜像仓库后提交 Packagist。

---

## 6. 引用声明机制与强制校验

### 6.1 声明的位置：模型上的约定方法

业务模型显式定义静态方法，以纯数据形式声明本模型的地区引用（无副作用、不碰数据库）：

```php
class Order extends Model
{
    public static function areaReferences(): array
    {
        return [
            'receiver_area_id' => ['onMerge' => 'keep', 'snapshotColumn' => 'receiver_area_name'],
        ];
    }
}
```

- 方法统一 `public static`（消费方是三个外部调用者：Cast、Picker、sync 收集器，protected 只会徒增反射成本）；
- 表名由模型自动推导（`(new static)->getTable()`），声明里不写表名；
- 声明与模型同处一地，加字段时顺手声明，漏登概率最低。

消费方按需自取，**不设全局内存注册表、不要求 ServiceProvider 预注册**：

| 消费方 | 时机 | 获取方式 |
|---|---|---|
| AreaIdCast（写入校验） | 赋值/保存时 | 直接调所属模型的 `areaReferences()` |
| AreaPicker（表单校验） | 组件构建/保存时 | 解析目标模型类，调其 `areaReferences()` |
| `area:sync-references` | 部署时 | 扫描收集全部模型的声明，upsert 落库 |
| 区划升级迁移 | migrate 时 | 读 `cmf_area_references` 表 |

模型是被动加载，但这恰恰不是问题：Cast/Picker 校验时模型本就在手边；sync 是主动扫描而非被动等声明。相比"boot() 每次请求预注册进内存"的中转层，少了全生命周期的运行时状态，也彻底消解了"boot() 在 migrate 期间执行、表不存在"的时序顾虑。

### 6.2 收集：sync 时主动扫描，扫描范围自动发现

`area:sync-references` 的扫描范围无需人工配置，以 **Laravel 已注册的 ServiceProvider 为锚点**反推：

1. 遍历 `app()->getProviders()`——应用自身、monorepo 本地模块、composer 安装的扩展都在其中（扩展不经 package discovery 注册 provider，它的迁移/配置/路由就进不来，等于没装，故该清单与安装状态天然同步）；
2. 反射每个 provider 类拿到文件路径 → 向上定位包根目录 → 按 composer PSR-4 映射推导包内类名，筛出 `Model` 子类中定义了 `areaReferences()` 的类，逐一调用收集声明；
3. 汇总后幂等 upsert 落库，输出各包的收集计数，便于核对。

> 为什么不用固定路径清单：vendor 扩展的 Models 目录位置各异、随装随卸变化，人工维护 `scan_paths` 必然漏配；而"有 provider"是 Laravel 包的准入条件，以它为锚点，**装了就会被扫到，卸载即消失**。

兜底：`config/cmf-area.php` 保留 `scan_paths`（覆盖无 provider 的纯模型库等边缘场景）；无 Model 的表（DB facade 操作的历史表等）保留 `Area::registerReference('table', 'col', onMerge: ...)` 兜底注册口。两个兜底与自动发现的结果合并落库。

### 6.3 强制校验（两层硬异常）

防止开发者忘记声明，让"未声明"在常用路径上直接报错：

**第 1 层：AreaPicker 使用时强制**

表单组件构建/保存时，解析目标模型类并调用 `areaReferences()`，字段不在声明中立即抛 `AreaReferenceNotRegisteredException`，异常信息含可直接粘贴的代码模板：

```
[orders.region_id] 未声明地区引用，无法使用 AreaPicker。
请在 Order 模型中添加：

    public static function areaReferences(): array
    {
        return [
            'region_id' => ['onMerge' => 'keep'],
        ];
    }

onMerge 可选值：keep（历史事实，默认）/ remap（当前状态）
```

**第 2 层：模型 Cast 写入强制（覆盖非表单路径）**

API、任务、导入等不经过 Picker 的写入路径，由模型 Cast 拦截：

```php
class Order extends Model
{
    protected $casts = ['region_id' => \Quansitech\Cmf\Area\Casts\AreaIdCast::class];
}
```

赋值/保存时同样调模型的 `areaReferences()` 校验，未声明抛异常。

> 评审决定：**不实施**"生产环境降级为日志告警"与"area:lint 数据库扫描"两层。校验读代码声明（模型方法）而非数据库表，sync 滞后不影响校验结果。
>
> 为何不静默自动注册：`merge_strategy` 是业务语义决策（这列数据将来该不该被自动改写），必须由开发者显式声明，默认值替人做决定就是将来数据改错时找不到责任人。

---

## 7. 阶段一：基础模块与初始数据入库

**任务**

1. `php artisan make:cmf-module Area --path=<monorepo路径>` 生成骨架；
2. 建 3 张表（见 §3）；
3. 下载上游最新 Release 的 `ok_data_level3-4.csv.7z`，解压取 `ok_data_level4.csv` 放入 `database/data/`；
4. `ImportService`：读取 csv（UTF-8 带 BOM、双引号限定符），分块（每 1000 条）`insert`，约 4 万+ 乡镇级记录；seed 迁移文件名携带上游版本号，版本履历由 `migrations` 表承载（见 §3.2）；
5. `AreaResource` 提供树形浏览；`AreaPicker` 级联表单组件（任意层级可选中、注册强制校验、`.withNameSnapshot()` 名称快照）。

**验收**：全新项目 `composer require` + `cmf:install` 后，库内含完整四级数据；未声明的字段使用 AreaPicker 立即报错；`area:sync-references` 可扫描收集声明并落库。

---

## 8. 阶段二：上游更新检测与 diff

**触发方式（按需）**

不实现定期轮询。维护者/社区发现重要变更（如新区设立的新闻）时，先 `php artisan area:check-upstream` 确认上游是否有新版，再让 AI agent 跑升级 skill。

> 本节的 `area:download`、`area:diff` 是 skill 按序调用的确定性脚本，本身不做语义判断（§9.1）。

**diff 规则**（DiffService，纯算法、确定性）：

| 现象 | 判定 |
|---|---|
| 新版有、旧版无的 id | `added` |
| 旧版有、新版无的 id | `removed` |
| id 相同、ext_name 不同 | `renamed` |
| id 相同、pid 不同 | `parent_changed` |
| `added` 的 id 命中历史 `status=0` 的废止行 | **`code_reuse_suspected` 疑似代码重用 → 阻断，转人工** |

产出 `diff.json`：按省分组的清单。这一步**不做变更类型推断**（撤销还是合并算法分不清，交给 AI）；但代码重用检测是硬规则，宁可误报不可放过。

---

## 9. 阶段三：AI 变更判定（升级 skill 驱动，核心环节）

### 9.1 skill 结构与 agent SOP

升级流水线整体做成一个 skill（`area/skill/`），交给 AI agent 驱动：

- **确定性流程 = 脚本**：`area:download` → `area:diff` → `area:check-changes` → `area:generate-migration`；
- **语义判断 = 提示词**：取证策略、change_type 判定规则、边界处理写进 SKILL.md。

agent 的 SOP：

1. `area:download {version}` + `area:diff` → `diff.json`（纯事实：哪些 id 新增/消失/改名/换父级）；
2. **语义判读（本 skill 的核心环节，AI 对每条 diff 循环执行）**：
   - 按 §9.3 策略联网取证：查维基年度变更列表 → 跟进县/区独立词条 → 跟进政府公告原文；
   - 理解语义后判定 change_type（新设/析置/合并/更名/撤销/换码/代码重用…）与归属关系；
   - 用新旧两版 csv 做下级配对，生成 `child_id_map`；对不上的下级回查资料，仍无法确认的标注疑点；
   - 每条产出追加进 `changes.json`（契约见 §9.2），必须附 evidence；查不到可靠来源标 `confidence: "low"`；
3. `area:check-changes changes.json` → 机器校验（校验清单见 §9.4）；**不通过则把错误清单反馈给 agent，回到第 2 步修正后重跑，直至全绿**；
4. `area:generate-migration changes.json` → 迁移文件（生成物结构见 §10.2）；
5. 更新 `database/data/ok_data_level4.csv` 为新基线，连同 changes.json、迁移文件一并提交 PR，**停**——后续由人工接管（§9.5）。

### 9.2 输入输出契约

- **输入**：`diff.json` + 变更发生的年份区间（由模块当前数据基线版本与上游目标版本推出，均在模块仓库侧确定）
- **输出**：`changes.json`，必须符合 `skill/changes.schema.json`：

```json
{
  "version": "2026.xxx",
  "changes": [
    {
      "change_type": "split_from",
      "new_id": 653228, "new_name": "和康县", "ext_name": "和康县",
      "old_id": 653223, "old_name": "皮山",
      "detail": {
        "summary": "析皮山县南部山区设立和康县，县政府驻原赛图拉镇（后更名昆岭镇）",
        "child_id_map": {"653223102": "653228101"},
        "renames": [{"old_id": 653223102, "new_id": 653228101, "from": "赛图拉镇", "to": "昆岭镇"}]
      },
      "evidence": [
        {"title": "2024年中华人民共和国县级以上行政区划变更列表", "url": "https://zh.wikipedia.org/wiki/..."},
        {"title": "新疆维吾尔自治区人民政府关于党中央、国务院批准设立和康县的公告", "url": "https://www.xinjiang.gov.cn/..."}
      ],
      "confidence": "high"
    }
  ]
}
```

merge_into 类型的 detail 必须给出 `full_transfer`（旧区是否 100% 疆域并入单一承继者）与旁落明细（如渝北区 5 镇划归北碚区）。

### 9.3 AI 信息源检索策略（写进 SKILL.md）

1. 先查维基百科年度列表：`{年份}年中华人民共和国县级以上行政区划变更列表`（1949 至今每年一页），用 MediaWiki API 取 wikitext 解析表格：
   `https://zh.wikipedia.org/w/api.php?action=parse&page={页面名}&prop=wikitext&format=json`
2. 列表中"原行政单位"为空或信息不足时，**跟进到该县/区的独立词条**（如"和康县"词条写明"原为皮山县的一部分"）；
3. 再跟进词条引用的**政府公告原文**（省级政府/民政厅网站）核实归属明细（含乡镇级新码）；
4. 乡镇级变更维基覆盖不全（民政部 2019 后不再集中公布），兜底来源：国家地名信息库 dmfw.mca.gov.cn 的地名沿革、地级政府公告；
5. 疑似代码重用的条目：必须确认"新 id 单位"与"历史废止单位"是两个不同的行政单位（而非更名复活），并附证据；
6. 每条判定**必须附 evidence**；查不到可靠来源的标记 `confidence: "low"` 并在 summary 说明疑点，由 PR 审查重点核对，**禁止编造**。

### 9.4 `area:check-changes` 校验清单（机器兜底）

纯确定性校验，不联网、不做语义判断。分两层：

**schema 层**（对 `skill/changes.schema.json`）：字段齐全、类型正确；`change_type` 为枚举内取值；每条至少一条 evidence（title + url）；`merge_into` 必须含 `full_transfer`。

**逻辑层**（与 diff.json、新旧两版 csv 交叉核对）：

| 校验项 | 规则 |
|---|---|
| 覆盖率 | diff.json 中每个 added/removed/renamed/parent_changed 的 id 必须恰好被一条 change 认领；不允许有 diff 事实无人认领，也不允许 change 凭空捏造 diff 之外的 id |
| id 存在性 | `old_id` 必须存在于旧基线 csv；`new_id` 必须存在于新版 csv |
| 类型与事实一致 | `rename` 的 old_id==new_id 且仅名称变化；`split_from` 的 new_id ∈ added；`merge_into`/`abolish` 的 old_id ∈ removed；`parent_change` 的 id 两版均在且 pid 不同 |
| child_id_map 配对完整性 | 新版 new_id 的全部下级 id 出现在 map 值侧、旧版 old_id 的全部下级 id 出现在 key 侧；存在未匹配项时 detail 必须说明原因（如某镇同期被撤并），否则不通过 |
| 代码重用 | 命中历史 `status=0` 行的新增 id，必须走 `code_reuse` 类型且附证据 |
| 版本一致 | changes.json 的 `version` 与目标上游版本一致 |

输出校验报告：通过 / 逐条错误清单。错误清单直接反馈给 agent，作为第 2 步的修正输入。

### 9.5 人工闸门：PR 审查

**不设专用审核界面。** changes.json 与生成的迁移文件本身就是可 diff 的文本产物，git PR 审查即人工闸门，再建一套 Filament 审核 UI 没有增量价值。维护者在 PR 上重点核对：

- evidence 链接真实可查、与判定结论一致；
- `confidence: "low"` 条目逐条人工复核；
- `full_transfer` 判定与疆域归属明细（child_id_map 配对完整性由 `area:check-changes` 机器背书，见 §9.4）；
- 迁移文件与 changes.json 内容一致。

合并 PR 即放行发版；判错的在 PR 上修正 changes.json 后重新生成迁移。

---

## 10. 阶段四：迁移生成与发版

### 10.1 变更类型 → 迁移操作映射

`MigrationGenerator` 按 `change_type` 生成迁移；业务表操作取决于**每列声明的 merge_strategy** 与**变更的 full_transfer**：

| change_type | cmf_areas 操作 | 业务表操作 |
|---|---|---|
| `rename` 更名 | update name/ext_name | **无需操作**（id 不变） |
| `add` 新设 | insert 新行 | 无需操作（纯新增） |
| `split_from` 析出新设 | insert 新行 | **逐行处理**：值命中 detail.child_id_map 的行 → UPDATE 为对应新码；值等于被析出的旧单位本身（如 653223 皮山县）→ **进人工清单**（数据层面无法判定归属） |
| `merge_into` 且 full_transfer=true | 旧行 status=0、successor_id=新 id | `remap` 列：`UPDATE {table} SET {col}={new} WHERE {col}={old}`；`keep` 列：不动 |
| `merge_into` 且 full_transfer=false（部分疆域旁落第三方，如渝北区 5 镇归北碚区） | 同上 | **一律进待人工清单**（remap 也不自动执行——只存县级 id 的行分不清是否在被划走的镇里） |
| `abolish` 撤销（无承继） | status=0 | 进人工清单，不自动改 |
| `parent_change` 隶属变更 | update pid | 一般无需操作（业务多存叶子级 id） |
| `code_change` 代码变更 | insert 新行、旧行 status=0、successor_id | `remap` 列批量 UPDATE；`keep` 列不动 |
| `code_reuse` 代码重用 | 见 §10.3 专项处理 | **阻断自动迁移，归档迁移专项** |

> 逐行解析说明：业务列可存任意层级 id，迁移对每行查 `cmf_areas`（旧行软删保留，永远可查）得到值的真实层级与当前状态，再套用上述规则。迁移规则本身不因数据分布而改变，保证跨环境行为一致。
>
> "待人工清单"完全由**变更的不可判定性**驱动（split 浅层值、merge 部分疆域旁落、code_reuse、无承继 abolish），对所有注册列生效。另外，迁移对 `keep` 列中仍引用 `status=0` 旧 id 的行输出**信息性报告**（不动作），业务方按需自行改库或写脚本批处理。

### 10.2 生成物结构与延迟绑定机制

`area:generate-migration` 是确定性翻译器：把 changes.json 按 §10.1 展开、冻结成数据，生成一个**薄壳迁移文件**——文件里只有数据，执行逻辑全部在模块内置的 `MigrationExecutor`（业务项目 composer update 后自然带着对应版本的执行器）。

**生成时（模块仓库侧，本步做的）**：读 changes.json → 展开为结构操作与映射指令 → 冻结为 payload → 写迁移文件（文件名携带版本号）。不碰任何数据库、不读引用登记表。

**迁移文件结构**（以儋州升格为例）：

```php
return new class extends Migration
{
    // 内嵌变更数据：来自经 PR 审查的 changes.json，不含任何业务表名
    private array $payload = [
        'version' => '2026.xxx',
        // cmf_areas 结构操作：执行时无条件应用，所有项目结果一致
        'areas' => [
            ['op' => 'insert', 'id' => 4604,  'pid' => 46,   'deep' => 1, 'name' => '儋州', 'ext_name' => '儋州市'],
            ['op' => 'insert', 'id' => 460400,'pid' => 4604, 'deep' => 2, 'name' => '儋州', 'ext_name' => '儋州市'],
            // …新版 17 个镇码 insert
            ['op' => 'retire', 'id' => 469003, 'successor_id' => 460400],
            // …旧版 17 个镇码 retire
        ],
        // 业务映射指令：执行时读本项目引用登记表，按每列 merge_strategy 动态应用
        'mappings' => [
            ['type' => 'code_change', 'old' => 469003, 'new' => 460400,
             'full_transfer' => true,
             'child_id_map' => ['469003100' => '460400100', /* …共 17 条 */]],
        ],
        // 待人工项（split 浅层值、full_transfer=false、code_reuse、无承继 abolish）：
        // 执行时不改数据，输出到迁移日志/报告文件
        'manual' => [],
        // 留档数据：执行时写入本项目 cmf_area_changes（change_type/old/new/detail/evidence/ai_summary）
        'records' => [/* … */],
    ];

    public function up(): void
    {
        app(\Quansitech\Cmf\Area\Services\MigrationExecutor::class)->apply($this->payload);
    }

    public function down(): void
    {
        app(\Quansitech\Cmf\Area\Services\MigrationExecutor::class)->revert($this->payload);
    }
};
```

**执行时（业务项目 migrate，`MigrationExecutor` 做的）**：

1. 执行 `areas` 结构操作（insert / retire / rename / pid 更新）；
2. 读本项目 `cmf_area_references`，把 `mappings` 按每列 merge_strategy 应用：remap 列生成 `UPDATE {table} SET col=new WHERE col=old`（逐行查 `cmf_areas` 解析值的真实层级）；keep 列不动；
3. `manual` 项输出待人工清单；keep 列命中旧 id 的行输出信息性报告；
4. `records` 写入 `cmf_area_changes` 并标 `applied_at`。

**职责分工原则**：

| 放迁移文件里（冻结的数据） | 放执行器里（唯一一份逻辑） | 哪里都不放 |
|---|---|---|
| 变更事实与映射、版本号（文件名） | 执行/幂等/回滚规则，全项目共用 | 业务表名——延迟绑定，执行期才从引用登记表读 |

延迟绑定的必要性：

| 如果迁移里写死业务表名 | 延迟绑定 |
|---|---|
| 模块发版方必须知道所有业务项目的表结构——不可能 | 模块只管区划与映射规则，**无需知道谁在用** |
| 新业务项目接入旧版本升级时迁移对它无效 | 任何项目在任何时刻 migrate，都按**它自己**的引用登记执行 |
| 业务表改名/加字段要模块重新发版 | 业务侧自己 sync 即可 |

### 10.3 代码重用防御

**背景**：县级以上区划代码按唯一性原则不得重用，但乡镇级赋码、上游自定义/补齐码段存在重用可能。一旦发生而流水线静默覆盖，历史数据会"张冠李戴"（旧 500105=江北区、新 500105=某新区，历史订单地址全部显示错）——比删行更恶劣。

**处理三层**：

1. **检测（DiffService 硬规则）**：新版"新增 id"命中历史 `status=0` 行 → 疑似代码重用 → **阻断自动迁移，转人工**；
2. **AI 确认**：必须佐证新/旧两个单位是不同的行政单位，附证据，PR 审查确认；
3. **确认后走归档迁移（专项）**：旧行主键迁至归档 id 段（如 `90{原id}`），ext_name 保留不变，所有 `keep` 策略的业务引用一并指向归档 id（**显示结果不变，语义不断链**）；新单位正常使用该官方代码。事件记入 `cmf_area_changes`（change_type=`code_reuse`）。

> 政务级/超长生命周期系统可选更严格的方案：`cmf_areas` 用自增代理主键 + code + valid_from/valid_to 有效期，业务表引用代理键，代码重用零影响。默认不采用（增加理解成本）。

### 10.4 发版与应用

1. skill 流程产出（§9.1）：`database/migrations/updates/xxxx_area_update_{version}.php` + changes.json + 新基线 `database/data/ok_data_level4.csv`，同一 PR 提交；
2. 维护者 PR 审查（§9.5）通过后合入；
3. 打 tag `area-vX.Y.Z` 发版（CI split 到只读仓库 → Packagist）；
4. 业务项目 `composer update` + `php artisan migrate`：**迁移自动完成区划表与业务表的数据变更**；
5. 迁移执行后把每条 change 写入 `cmf_area_changes` 并标记 `applied_at`；"待人工清单"与 keep 列信息性报告输出到迁移日志/报告文件，业务方按需逐条处理或写脚本批处理。

### 10.5 幂等与回滚

- 迁移可重复执行：每个 UPDATE 前先校验当前值是否为 old_id；
- `down()` 基于内嵌映射数据反向执行（merge_into 反向 = new→old）；大表迁移前提示备份，并在 `cmf_area_changes.detail` 记录各表受影响行数供事后核对；
- 建议业务侧开启 qscmf 的 auditing 模块，变更过程留审计痕。

---

## 11. 测试策略（Pest）

| 测试 | 内容 |
|---|---|
| DiffService | 构造 csv fixture，验证四类 diff 判定 + 疑似代码重用检测 |
| ImportService | fixture csv 导入行数、字段正确性 |
| 声明与强制校验 | 未声明字段用 AreaPicker/Cast 抛异常；已声明正常；sync 经 provider 反推自动发现扫描范围（含模拟 vendor 扩展的 fixture provider）、兜底注册口合并、幂等落库；引用列非整型（int2/int4/int8 以外）时 sync 拒绝登记 |
| MigrationGenerator + Executor | 各 change_type × 各 merge_strategy 组合的 payload 生成与执行正确；full_transfer=false 时 remap 也不自动执行，一律进待人工清单；幂等性（跑两遍结果一致） |
| 延迟绑定 | 迁移文件不含业务表名；在两个不同引用登记的"项目"上执行，各自按自己的登记表生效 |
| **真实案例回归** | 用已验证的真实变更做 fixture：<br>① 2024 和康县(653228)/和安县(653229)析自皮山县/和田县（split 类）；<br>② 2025 重庆撤江北区、渝北区设两江新区(500157)（merge 且 full_transfer=false 类：渝北 5 镇归北碚区）；<br>③ 2026 岑岭县析自叶城县（split 类） |
| changes.schema.json + area:check-changes | AI 产出物的契约校验 + 逻辑校验（child_id_map 配对完整性等） |

---

## 12. 里程碑拆解

| 里程碑 | 内容 | 产出 |
|---|---|---|
| M1 | 模块骨架 + 3 张表 + 初始数据导入 + 注册机制与两层强制校验 + AreaPicker | `area-v0.1.0` |
| M2 | 升级脚本链：`area:download` + DiffService（含代码重用检测）+ `area:check-upstream` | diff 流水线可用 |
| M3 | 升级 skill（SKILL.md 判读规则 + changes.schema.json + `area:check-changes` 校验） | 首份 changes.json 走通 |
| M4 | MigrationGenerator（延迟绑定、策略映射）+ 发版 + 真实案例回归测试 | `area-v1.0.0`（含一次真实升级） |

---

## 13. 风险与已知限制

1. **上游滞后**：AreaCity 的数据源（高德/地名信息库）本身有滞后，可能出现"政府已批复、上游未收录"（如 2026-04 版未含和康县/和安县）。因此升级由人工按需触发，且 AI 判定时以政府公告为准、上游数据为准绳，两者冲突时在变更记录中标注。
2. **AI 幻觉**：强制 evidence 链接 + `area:check-changes` 契约/逻辑校验 + PR 人工审查，三层兜底；查不到可靠来源的条目标 `confidence: "low"`，由 PR 审查重点核对。
3. **乡镇级盲区**：2019 年后乡镇级变更无集中官方公告，AI 查不到时会大量标 `confidence: "low"`、加重 PR 审查负担——这是客观限制，方案接受它而非强行自动化。
4. **split 类无法完全自动**：业务行只存县级 id 时，"该行该归入新县还是留在旧县"在数据层面不可判定，只能转人工；行内存了乡镇级 id 的行则可自动 remap。**建议业务方在允许的情况下尽量引导用户选到更深的可用层级**。
5. **代码重用**：县级以上制度禁止但乡镇级/上游自定义码段存在可能，方案以"检测阻断 + 归档迁移"兜底，不做无检测的静默覆盖。
6. **合规**：AreaCity 为 MIT 协议，`database/data/SOURCE.md` 需注明来源与许可；上游数据含高德/腾讯来源，商业使用自行评估其服务条款。

---

## 14. 与现有 CMF 的集成点

- 模型挂 `Auditable` trait 接入审计模块（AI 判定依据、迁移改了什么可查）；
- Shield 权限点：区划查看权限点在 ServiceProvider `packageBooted()` 登记；
- `cmf:install` 幂等发布配置与迁移，与现有模块一致；
- 中文语言包：`lang/vendor/filament-auditing/zh_CN` 补模型/字段键名。
