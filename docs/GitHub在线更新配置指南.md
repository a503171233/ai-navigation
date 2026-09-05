# DCAI 授权系统 — GitHub 在线更新配置指南

> 适用版本：V1.3.1+（后台「系统升级」页已内置远程更新源配置 + 检查远程更新 + 下载并发布）
>
> 配置目标：把升级包放到 GitHub，服务器后台一键「检查远程更新 → 下载并发布 → 一键升级」，无需再手动上传 zip。

---

## 一、原理（系统已内置，无需改代码）

后台「系统升级」页的远程更新链路由 `service/SystemUpdateService.php` 实现：

| 环节 | 方法 | 说明 |
|---|---|---|
| 检查更新 | `checkRemote()` | GET manifest.json，校验 `version > 当前版本` 且 `当前版本 >= min_version`，返回更新信息 |
| 下载发布 | `fetchRemote()` | 按 manifest 的 `url` 下载 zip → 校验 MD5 → 存入 `storage/system_updates/` → 登记为「已发布」 |
| 应用升级 | `apply()` | 备份 → 白名单替换 → 迁移脚本 → 版本号写入 config → 失败自动回滚 |

manifest.json 是远程更新的「目录」，字段必须与 `checkRemote()` 期望一致：

```json
{
  "version": "1.3.1",
  "min_version": "1.0.0",
  "changelog": "更新说明（支持 \\n 换行）",
  "url": "https://gcore.jsdelivr.net/gh/OWNER/REPO@main/release/dcai_sysupdate_v1.3.1_full.zip",
  "md5": "9bcf86bba8e3c6d78a79d17d22a178b2",
  "size": 274591,
  "release_at": "2026-09-05"
}
```

校验规则（`checkRemote()` 源码逻辑）：
- `version` 必须合法且 **高于当前系统版本**；
- `min_version` 非空时 **当前系统版本必须 >= min_version**；
- `md5` 用于下载后校验，不匹配直接失败；
- 可选 `auth_token`：私有仓库时以 `Authorization: Bearer xxx` 请求 manifest 与升级包。

---

## 二、GitHub 侧准备（一次性的，约 10 分钟）

### 1. 建仓库并推代码

```bash
# 假设你已有一个 GitHub 账号，在 GitHub 新建公开/私有仓库（如 dcai-updates）
git init
git add .
git commit -m "DCAI release files"
git branch -M main
git remote add origin https://github.com/<你的用户名>/<仓库名>.git
git push -u origin main
```

> 只推 `release/` 目录也可以，但建议把整个项目推上去（后续可做版本管理）。

### 2. 确认 release 目录内容

升级包 + manifest 必须在仓库的 `release/` 目录下（与后台填写的 URL 对应）：

```
release/
├── dcai_sysupdate_v1.3.1.zip          ← 增量包（min_version=1.3.0）
├── dcai_sysupdate_v1.3.1_full.zip     ← 全量包（min_version=1.0.0，推荐 manifest 指向它）
├── manifest.json                      ← 全量包 manifest（后台填这个）
└── manifest_incr.json                 ← 增量包 manifest（可选）
```

### 3. 选择下载直链（国内网络建议 jsDelivr）

| 直链类型 | URL 示例 | 国内访问 |
|---|---|---|
| **jsDelivr（gcore，推荐）** | `https://gcore.jsdelivr.net/gh/<user>/<repo>@<branch>/release/xxx.zip` | ✅ 快（实测 1.4s） |
| jsDelivr（cdn） | `https://cdn.jsdelivr.net/gh/<user>/<repo>@<branch>/release/xxx.zip` | ⚠️ 时通时断 |
| raw.githubusercontent.com | `https://raw.githubusercontent.com/<user>/<repo>/<branch>/release/xxx.zip` | ❌ 常被重置（实测超时） |
| gh-proxy.com | `https://gh-proxy.com/https://raw.githubusercontent.com/...` | ✅ 可用，但第三方依赖 |

**注意**：jsDelivr 有缓存，首次推文件后约 1~5 分钟生效，先 curl 验证 200 再配置后台。

---

## 三、后台配置（线上服务器，约 3 分钟）

1. 登录后台 → **系统 → 系统升级**；
2. 在「远程升级源配置」卡片：
   - **启用远程更新**：`开启`
   - **manifest.json 地址**：填你自己的仓库直链
     `https://gcore.jsdelivr.net/gh/<用户>/<仓库>@main/release/manifest.json`
   - **超时（秒）**：默认 15 即可
   - **私有仓库 Token**：公开仓库留空；私有仓库填 GitHub Personal Access Token（`repo` 权限）
   - 点 **保存配置**；
3. 点右上角 **🔄 检查远程更新**：
   - 有更新 → 顶部出现橙色卡片「发现新版本 vX.Y.Z」，显示更新说明；
   - 点 **⬇ 下载并发布此版本** → 系统下载 zip、校验 MD5、登记为已发布；
4. 在下方升级包列表找到刚下载的版本 → 点 **一键升级** → 自动备份/替换/迁移；失败自动回滚。

> 云端实例自动升级提示：**更新包发布后**，系统会自动向低版本且在线的授权实例下发 `update` 命令（`service/UpdateService.php`），实例 SDK 收到命令后弹出升级提示。

---

## 四、发布新版本的完整流程（后续每次发版照此操作）

```bash
# 1. 本地改完代码后：
#    a. 提升版本号   config/config.php  →  app.version = '1.3.2'
#    b. 如有 DB 变更，更新 install/migrate_v1.3.php（或新增 v1.4 脚本）

# 2. 打包（自动读取版本号）
php tests/build_sysupdate.php     # 增量包 dcai_sysupdate_v1.3.2.zip
php tests/build_fullupdate.php    # 全量包 dcai_sysupdate_v1.3.2_full.zip

# 3. 生成 manifest（先改 tests/build_manifest.php 顶部的 $owner/$repo）
php tests/build_manifest.php      # release/manifest.json + release/manifest_incr.json

# 4. 推送到 GitHub
git add release/
git commit -m "release v1.3.2"
git push

# 5. 验证直链可达（可选，jsDelivr 需等待缓存预热 1~5 分钟）
curl -sI https://gcore.jsdelivr.net/gh/<user>/<repo>@main/release/dcai_sysupdate_v1.3.2_full.zip | head -1
# 预期: HTTP/1.1 200 OK

# 6. 线上后台 → 系统升级 → 🔄 检查远程更新 → ⬇ 下载并发布 → 一键升级
```

**前置约定（架构硬性要求）**
- 升级包 `system.json` 的 `version` 必须 **高于线上当前版本**（`checkRemote`/`upload` 都会拒绝）；
- 增量包 min_version = 上一版（V1.2.0）；全量包 min_version = 1.0.0（任意旧版可升）；
- 纯 UI / 无 DB 变更的增量包 `migrate` 必须置空，否则 apply 会因找不到迁移脚本而回滚；
- 升级包内不携带 `sdk/*.py`（旧版服务器上传白名单无 py），Python SDK 由 `install/migrate_v1.3.php` 内嵌 base64 在升级落盘时写入；
- `release/`、`tests/`、`storage/`、`config.php`、`.workbuddy/` 等不进入升级包（打包脚本已排除）。

---

## 五、故障排查

| 现象 | 原因/处理 |
|---|---|
| 「检查远程更新」无反应 / 提示已是最新 | manifest URL 不可达（先 curl 验证 200）；或 manifest 的 version 不大于当前版本 |
| 提示「已是最新版本，无需更新」但明明有新版 | version 字段不高于 `DCAI_SYSTEM_VERSION`；或当前版本不满足 manifest 的 min_version |
| 下载后「下载文件 MD5 校验失败」 | manifest 的 md5 写错，或 jsDelivr 缓存了旧包（等缓存刷新 / 换 gcore 域名） |
| HTTP 404 | URL 路径与仓库实际结构不一致（区分 `@main` 分支写法、检查 release/ 目录） |
| 下载超时 | 服务器到该 CDN 网络差：换 `gcore.jsdelivr.net` / `cdn.jsdelivr.net` / `gh-proxy.com` 直链，或改用自有服务器/CDN 托管 |
| 私有仓库 401/403 | Token 无 `repo` 权限，或 Token 已过期；公开仓库留空即可 |
| 升级失败自动回滚 | 看 `storage/logs/app.log`；常见：min_version 不满足、migrate 脚本缺失、文件白名单缺文件 |

---

## 六、附：本仓库现成产物（`release/` 目录）

| 文件 | 大小 | MD5 | 说明 |
|---|---|---|---|
| `dcai_sysupdate_v1.3.1_full.zip` | 274,591 B | `9bcf86bba8e3c6d78a79d17d22a178b2` | 全量升级包（min_version=1.0.0） |
| `dcai_sysupdate_v1.3.1.zip` | 28,054 B | `c14f06344b744e50d5c59fb319e783e5` | 增量升级包（min_version=1.3.0） |
| `manifest.json` | — | — | 指向全量包（**后台填这个地址**） |
| `manifest_incr.json` | — | — | 指向增量包（可选） |
| `dcai_auth_v1.3.1_bt.zip` | 287,768 B | `a078d7fc41e1705fc8df41e4afa7cf2e` | 全新安装完整包（不走在线更新） |

> ❗ `release/manifest.json` 内的 `url` 目前是占位符 `OWNER/REPO`，**必须**改为你的真实仓库后推送。
>
> 生成 manifest 的脚本：`tests/build_manifest.php`（自动读 config.php 版本号 + 升级包 system.json + 计算 MD5/大小，每次发版重跑即可）。