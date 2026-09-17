# QS CMF（quansitech/cmf-*）

基于 Laravel + Filament 的内容管理框架：把各项目共用的后台能力（用户、角色权限、操作审计等）沉淀为可复用模块包。

## 包结构

| 包 | 说明 |
| --- | --- |
| `core`（quansitech/cmf-core） | 面板基座 `CmfPanelProvider`、模块插件注册表、`cmf:install` / `cmf:extend` / `make:cmf-module` 命令 |
| `users`（quansitech/cmf-module-users） | 用户模型（FilamentUser + HasRoles + Auditable）、用户管理 Resource、UserPolicy |
| `roles`（quansitech/cmf-module-roles） | 角色管理 Resource（Filament Shield 集成）、可审计角色模型、RolePolicy |
| `auditing`（quansitech/cmf-module-auditing） | 中文版审计后台、回滚 Action、AuditPolicy、AuditLogger（pivot 审计补记） |
| `media`（quansitech/cmf-module-media） | 媒体库：TOS/OSS/COS 浏览器直传与 local 本地上传、内容哈希去重（秒传）、引用计数与归零自动清理、MediaPicker 字段、RichEditor 富文本附件接管（HasMediaRichContent）、媒体管理 Resource |
| `area`（quansitech/cmf-module-area） | 行政区划：内置四级区划数据、业务引用登记（merge_strategy）、AreaPicker、上游变更升级流水线 |
| `import`（quansitech/cmf-module-import） | 导入扩展：仅 xlsx 导入（单元格归一与可疑值行级拒绝）、xlsx 模板生成（文本锁定/长度/下拉）、失败清单 xlsx 导出 |

## 安装（新项目）

**环境要求**：PHP >= 8.3、Composer 2、Node.js + npm、MySQL / PostgreSQL / SQLite 任一（新项目默认 SQLite，可直接使用）。

### 1. 创建 Laravel 项目

```bash
laravel new my-admin --no-interaction   # 未装 laravel/installer：composer create-project laravel/laravel my-admin
cd my-admin
```

`--no-interaction` 跳过 starter kit 等询问，默认 SQLite、不装前端脚手架——CMF 自带 Filament 面板与登录，不需要 starter kit。

### 2. 安装 CMF 模块包

```bash
composer require quansitech/cmf-core quansitech/cmf-module-users quansitech/cmf-module-roles quansitech/cmf-module-auditing
```

### 3. 一键初始化

```bash
php artisan cmf:install
```

依次完成（幂等，可重复执行；已发布的文件与已有数据不会重复或覆盖）：

1. 发布模块配置、语言包、视图与品牌静态资源（logo 等，`--force` 可强制覆盖）；
2. 发布 Filament 前端资源（`filament:assets`，缺失时登录页按钮、表单等 JS 组件会异常）；
3. 绑定用户模型：宿主模型未集成 `HasRoles` 时，自动把 `AUTH_MODEL` 指向 `Quansitech\Cmf\Users\Models\User`；
4. 发布 permission / audits 迁移并执行 `migrate`；
5. 脚手架 `AdminPanelProvider` + Filament 主题，并注册到 `bootstrap/providers.php`；
6. `shield:generate` 生成权限点，创建 `super_admin` / `panel_user` 角色；
7. 创建初始超管，并在终端打印登录邮箱与随机密码。

常用参数：

| 参数 | 说明 |
| --- | --- |
| `--admin-name` / `--admin-email` / `--admin-password` | 指定初始超管信息（邮箱默认 `admin@应用域名`，密码默认随机生成并打印） |
| `--skip-admin` | 跳过创建初始超管 |
| `--force` | 强制覆盖已发布的配置文件 |

### 4. 编译前端主题

`cmf:install` 已自动把 `resources/css/filament/admin/theme.css` 加入 `vite.config.js` 的 `input`，直接编译即可：

```bash
npm install && npm run build
```

### 5. 启动并登录

```bash
php artisan serve
```

访问 <http://localhost:8000/admin>，用第 3 步终端打印的邮箱和密码登录。随机密码仅在安装时输出一次，登录后请尽快修改。

### 6. 可选：AI 辅助开发（Laravel Boost，推荐）

[Laravel Boost](https://laravel.com/docs/boost) 为 Claude Code / Cursor / Copilot / Codex 等 AI 编码工具提供 Laravel 项目上下文、最新文档检索和 MCP 工具：

```bash
composer require laravel/boost --dev
php artisan boost:install
```

- 自动识别项目里的 Filament / Livewire 等依赖，生成 AI guidelines、skills 与 MCP 配置（`.mcp.json`、`CLAUDE.md`、`AGENTS.md`、`boost.json` 等，可加入 `.gitignore`）
- AI 可直接读取数据库结构、路由、日志并执行 artisan，写 Resource / Policy / 迁移时更贴合 Laravel 与 Filament 约定
- 依赖升级后执行 `php artisan boost:update` 刷新上下文

## 双模使用：默认开箱即用，按需深度定制

- **默认模式**：模块所有类（模型 / Resource / Policy）在包内，`composer update` 即升级。
- **定制模式**：需要深度定制某个模块时：

```bash
php artisan cmf:extend users
```

会在宿主 `app/` 下生成继承类（Resource + 全部 Pages），并把 `config/cmf-users.php` 的 `resource` 指向继承类。之后在 app/ 下覆写父类方法（`getFormFields()`、`getTableColumns()` 等）即可自由定制，未覆写的部分仍随包升级。

模型层面的定制：在 app/ 下继承 `Quansitech\Cmf\Users\Models\User`，替换 `config/cmf-users.php` 的 `model` 与 `config/auth.php` 的 `providers.users.model`（新增字段用宿主自己的追加迁移）。工厂会自动产出配置的新模型。

## 添加新的公共模块

```bash
php artisan make:cmf-module Blog --path=/var/www/qscmf-filament
```

生成标准骨架（composer.json / config / ServiceProvider / Plugin）。开发要点：

1. Resource 放 `src/Filament/Resources/`，Plugin 的 `register()` 里通过 `config('cmf-xxx.resource')` 注册；
2. 模型挂 `Auditable` trait 接入审计；Resource 有 View 页时 `getRelations()` 加 `AuditsRelationManager::class`；
3. 有 Shield 权限点时，在 ServiceProvider `packageBooted()` 里向 `filament-shield.resources.manage` 登记（参照 users 模块）；
4. 在 `.github/workflows/split.yml` 的 matrix 增加一条（`local_path` / `repository_name`），创建对应 GitHub public 空仓库，打 tag 后到 Packagist submit，之后随本仓库 tag 自动发版。

## 参与包开发（本地联调）

宿主 `composer.json` 改用 path 仓库指向本目录，symlink 即时生效：

```json
"repositories": [
    { "type": "path", "url": "../qscmf-filament/*", "options": { "symlink": true } }
]
```

## 关键约定

- 模块 ServiceProvider 在 `packageRegistered()` 里 `Cmf::registerPlugin()`，面板自动挂载；`config/cmf-core.php` 的 `disabled_plugins` 可临时停用模块。
- 宿主 `config/filament-shield.php` 的 `resources.manage` 只登记项目自研 Resource；模块权限点由模块自动登记，宿主配置同名条目时以宿主为准。
- 审计 `audit` / `restoreAudit` Gate 已由 auditing 模块接管为 Shield 权限点（`ViewAny:Audit` / `Restore:Audit`）。
- 新增可审计模型/字段时，记得在 `lang/vendor/filament-auditing/zh_CN` 补 `model.Xxx` / `field.xxx` 中文键。

## 仓库与发布

本仓库为 monorepo（唯一真源），CI 按目录 split 出只读镜像仓库并同步到 Packagist：
`quansitech/cmf-core` / `quansitech/cmf-module-users` / `quansitech/cmf-module-roles` / `quansitech/cmf-module-auditing` / `quansitech/cmf-module-media` / `quansitech/cmf-module-area` / `quansitech/cmf-module-import`。
发版按包独立打 tag（如 `media-v1.1.0`，只 split 并发布 media），完整流程见 [RELEASING.md](.github/RELEASING.md)。
