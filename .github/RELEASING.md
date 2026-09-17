# 发布指南

本仓库是 monorepo（真源），通过 CI split 出 7 个只读镜像仓库，Packagist 从镜像仓库取包。

```
quansitech/qscmf-filament（本仓库，所有开发只在这里）
   ├── core/      ──split──► quansitech/cmf-core            ──┐
   ├── users/     ──split──► quansitech/cmf-module-users     ──┤
   ├── roles/     ──split──► quansitech/cmf-module-roles     ──┤
   ├── auditing/  ──split──► quansitech/cmf-module-auditing  ──┼──► Packagist
   ├── media/     ──split──► quansitech/cmf-module-media     ──┤
   ├── area/      ──split──► quansitech/cmf-module-area      ──┤
   └── import/    ──split──► quansitech/cmf-module-import    ──┘
```

> 以下假设 monorepo 仓库为 `quansitech/qscmf-filament`、镜像仓库为 `quansitech/cmf-*`。命名不同时全局替换即可。

## 版本策略（独立版本）

各包版本**独立演进**：tag 以包前缀区分，只 split 对应包。

| 包目录 | tag 示例 | 镜像仓库收到的 tag |
| --- | --- | --- |
| `core` | `core-v1.2.0` | `v1.2.0` |
| `users` | `users-v0.5.0` | `v0.5.0` |
| `roles` | `roles-v0.5.0` | `v0.5.0` |
| `auditing` | `auditing-v0.3.0` | `v0.3.0` |
| `media` | `media-v1.1.0` | `v1.1.0` |
| `area` | `area-v1.0.0` | `v1.0.0` |
| `import` | `import-v1.0.0` | `v1.0.0` |

- monorepo 的 tag 带 `{package}-` 前缀（仅用于触发对应包的 split）；
- 镜像仓库与 Packagist 上的版本是不带前缀的 `v{version}`，符合 Composer semver；
- 裸 `v*` tag 不再触发任何 split（历史统一版本策略已废弃）。

## 一次性配置

1. 在 GitHub 创建 7 个 **public** 仓库，保持默认分支 `main`：
   `cmf-core`、`cmf-module-users`、`cmf-module-roles`、`cmf-module-auditing`、`cmf-module-media`、`cmf-module-area`、`cmf-module-import`。
   **每个仓库至少要有一个提交**（勾选 Add a README 或任意初始提交均可）——split action
   （v2.4.5 实测）在完全空的仓库上会推送未出生分支而失败。
2. 创建 fine-grained PAT，对这 7 个仓库授予 `Contents: Read and write`（或用 GitHub App 生成安装 token）。
   **后续新增镜像仓库时必须同步把它加入 PAT 的授权仓库列表**，否则该包的 split 会因推送权限被拒而失败。
   在本仓库 Settings → Secrets and variables → Actions 添加 secret：`SPLIT_TOKEN`（secret 里只放 token 原文，
   不带任何前缀；workflow 会自动加 `oauth2:` 前缀，因为 split action 不支持裸 fine-grained token，见
   [action issue #47](https://github.com/danharrin/monorepo-split-github-action/issues/47)）。
3. 推送首个版本 tag，等 workflow 跑完（7 个包各自打一个，互不影响）：

   ```bash
   git tag core-v1.0.0 users-v1.0.0 roles-v1.0.0 auditing-v1.0.0 media-v1.0.0 area-v1.0.0 import-v1.0.0
   git push origin --tags
   ```

4. 用 GitHub 账号登录 [packagist.org](https://packagist.org)，Submit 7 个镜像仓库地址，例如
   `https://github.com/quansitech/cmf-core`。
5. 按 Packagist 包页面的提示，在 7 个镜像仓库配置 webhook（或安装 Packagist GitHub App），
   之后新 tag 会自动同步。

## 发版流程

```bash
git checkout main && git pull

# 只发 media 的 v1.1.0（核心包无改动就不打 core/... 的 tag）
git tag media-v1.1.0
git push origin media-v1.1.0
```

CI（`.github/workflows/split.yml`）按 tag 前缀定位包，对每个包执行：

1. 前缀匹配到的包才真正执行 split（其余 matrix 任务快速跳过）；
2. 把子目录内容推到镜像仓库 `main`（内容无变化则跳过 commit）；
3. 在镜像仓库创建并推送去掉前缀的 `v{version}` tag。

Packagist 收到 webhook 后刷新版本。

> 历史：`v1.0.0` ～ `v1.1.0` 为旧的「统一版本」tag（一次给 5 个包打同一版本号），已废弃；
> 此后一律使用 `{package}-v{version}` 独立发版。

## 跨包依赖规则

- 模块对核心包的依赖写 `^1.0`（已配置）。核心包出现 BC 变更发 `2.0` 时，
  必须在同一个提交里把依赖它的模块约束改成 `^2.0`，再统一发版。
- 开发态依赖通过根 `composer.json` 的 path repository 解析；各包的
  `extra.branch-alias` 把 `dev-main` 映射成 `1.x-dev`，所以 `^1.0` 在开发态也能解析。

## 注意

- **不要**向镜像仓库直接提交或提 PR，内容会被下一次 split 覆盖；issue/PR 请指向 monorepo。
- 镜像仓库默认分支保持 `main`，与 workflow 中 `branch: main` 一致。
- monorepo tag 必须是 `{package}-v{version}` 格式（package 为 `core/users/roles/auditing/media/area/import` 之一），
  版本号部分必须是合法 semver（`v1.2.0` 或 `1.2.0`），否则 Composer 不识别。格式不符时 workflow 会报错。
- `SPLIT_TOKEN` 未配置时 workflow 只打印 notice 并跳过 split（不报红），配置后自动生效。
- 发布 = 打 tag，不要手动改镜像；所有变更先合入 monorepo `main`。
- media 包为后加入的独立仓库：其首个版本 `v1.0.0` 是在镜像仓库一次性引导发布的
  （早于它加入 CI split）；此后与其他包一样按 `media-v{version}` 独立发版。
