# DCAI 授权系统 开发说明书

| 项目名称 | DCAI 授权系统 |
|---|---|
| 文档版本 | V1.3.1 |
| 编制日期 | 2026-09-05 |
| 技术栈 | PHP 8.2 / MySQL 5.6 / Nginx(Apache) + PHP-FPM |
| 文档状态 | 正式版（含 V1.1/V1.2/V1.3/V1.3.1 全部特性） |

> **版本说明**：本文档随系统演进持续更新。V1.2 新增"Skill 技能管理模块"（见第 13 章）；V1.3 新增机器码绑定、试用模式、离线激活、Python SDK 与沙箱降级模式（见第 14~18 章）；V1.3.1 新增全站 UI 体系化优化（Apple 流体设计 + 统一背景图 + 二级折叠导航）与 GitHub 在线更新（见第 19、20 章）。

---

## 目录

1. [项目概述](#1-项目概述)
2. [需求分析](#2-需求分析)
3. [系统总体架构](#3-系统总体架构)
4. [数据库设计](#4-数据库设计)
5. [接口设计（API 规格）](#5-接口设计api-规格)
6. [功能模块详细设计](#6-功能模块详细设计)
7. [客户端 SDK 设计](#7-客户端-sdk-设计)
8. [后台管理端设计](#8-后台管理端设计)
9. [安全设计](#9-安全设计)
10. [部署方案](#10-部署方案)
11. [开发计划](#11-开发计划)
12. [附录](#12-附录)
13. [Skill 技能管理模块](#13-skill-技能管理模块)
14. [机器码/硬件指纹绑定](#14-机器码硬件指纹绑定)
15. [试用模式（Trial）](#15-试用模式trial)
16. [离线激活](#16-离线激活)
17. [Python SDK](#17-python-sdk)
18. [沙箱降级模式](#18-沙箱降级模式)
19. [全站 UI 体系优化（Apple 流体设计）](#19-全站-ui-体系优化apple-流体设计)
20. [GitHub 在线更新](#20-github-在线更新)

---

## 1. 项目概述

### 1.1 项目背景

软件开发者向客户交付自研程序（下称"**被授权程序**"）时，需要一个集中式的授权管理平台，用于：

- 统一管控客户程序的授权状态；
- 远程掌握客户程序的搭建情况；
- 远程下发管理指令、弹窗与更新；
- 将部分功能模块托管在平台侧供客户程序远程调用。

本系统即为该集中管理平台，部署在授权方（软件厂商）自己的服务器上，通过互联网与各客户服务器上的被授权程序进行通信。

### 1.2 项目目标

1. 提供 **域名 + 授权码 + IP 三合一** 的严格授权验证机制，三者同时满足才判定为已授权；
2. 提供"授权未开启即默认放行"的灵活策略，保证授权系统未介入时客户程序不受影响；
3. 实时掌握每个被授权程序的**搭建现状**（域名、IP、版本、服务器与数据库环境、在线状态）；
4. 支持对已搭建的被授权程序进行**远程管理**（启用/禁用、配置推送、维护模式等）；
5. 支持对被授权程序的**弹窗**进行集中管理；
6. 支持对已搭建的被授权程序进行**远程更新**；
7. 支持**在线通过安装包**添加新的被授权程序产品；
8. 支持将部分功能模块**托管在授权系统**供被授权程序**远程调用**；
9. 提供后台管理界面，供授权用户管理上述所有能力（含远程模块管理）。

### 1.3 技术选型

| 层 | 选型 | 说明 |
|---|---|---|
| 服务端语言 | PHP 8.2 | 使用 PDO、openssl、filter、fileinfo 等内置扩展，面向过程 + 轻量 MVC 分层，不强制引入重型框架 |
| 数据库 | MySQL 5.6 | InnoDB，utf8mb4（详见 4.1 兼容性约束） |
| Web 服务器 | Nginx（推荐）/ Apache | 支持 HTTPS |
| 客户端 SDK | PHP 8.0+ | 提供给被授权程序集成的 PHP 库，底层走 REST + JSON |
| 通信协议 | HTTPS + JSON | 语言无关，其他语言开发的被授权程序可直接调用 HTTP 接口 |

### 1.4 术语定义

| 术语 | 定义 |
|---|---|
| 授权系统（授权程序） | 本系统，部署在授权方服务器，包含后台管理端与对外 API |
| 被授权程序（客户端程序） | 部署在客户服务器上的业务软件，通过集成 SDK 接入授权系统 |
| 授权码（License Key） | 授权方签发并绑定了域名、IP 的唯一授权凭证 |
| 实例（Instance） | 被授权程序的一次实际部署（一个域名 / 一台服务器对应一个实例） |
| 三合一验证 | 授权码有效性 + 域名授权 + IP 授权三项同时校验 |
| 实例令牌 | 实例注册成功后获得的身份令牌，用于后续 API 签名 |
| 远程模块 | 托管在授权系统侧、供被授权程序远程调用的功能单元 |

---

## 2. 需求分析

### 2.1 角色定义

| 角色 | 说明 |
|---|---|
| 授权方管理员（Admin） | 使用授权系统后台，管理产品、授权码、实例、弹窗、更新、远程模块等 |
| 被授权程序（客户端） | 部署在客户服务器，通过 SDK 调用授权系统 API |

### 2.2 功能需求总览

| 编号 | 需求 | 对应章节 |
|---|---|---|
| F1 | 域名授权、授权码授权、IP 授权三种方式并存，三者同时满足才完成授权 | 6.1 |
| F2 | 授权系统未开启被授权程序时，默认程序已授权（放行） | 6.2 |
| F3 | 授权程序能看到被授权程序的搭建现状 | 6.3 |
| F4 | 授权程序可直接管理已搭建的被授权程序 | 6.4 |
| F5 | 授权程序可直接管理被授权程序的弹窗 | 6.5 |
| F6 | 授权程序可实现被授权程序的远程更新 | 6.6 |
| F7 | 授权程序可在线通过安装包添加新的被授权程序 | 6.7 |
| F8 | 被授权程序的功能模块可放置在授权程序中远程调用 | 6.8 |
| F9 | 授权用户可在授权程序后台管理远程模块 | 6.8 |

### 2.3 核心业务场景

#### 场景一：首次部署与三合一授权验证

1. 客户从授权方处获得安装包（内含 SDK 与授权码）；
2. 客户将程序部署到自己的服务器（域名、IP 固定下来）；
3. 被授权程序启动时调用 SDK 的 `verify()`；
4. SDK 向授权系统提交 `产品编码 + 授权码 + 域名 + IP`；
5. 授权系统依次校验：授权码是否存在/有效/未过期 → 域名是否在授权码允许列表 → IP 是否在允许列表 → 是否超过最大实例数；
6. 三项全部通过才返回"已授权"，并签发带有效期的授权令牌供本地缓存；
7. 任一不通过则返回失败原因，被授权程序按策略拒绝运行或降级运行。

#### 场景二：授权未开启（默认放行）

1. 授权方后台未开启某产品的"授权管控"开关，或客户程序的 SDK 配置中未启用授权；
2. 该产品的全部被授权程序默认视为已授权，不发起验证请求，正常运行；
3. 授权方后续开启"授权管控"后，新启动的被授权程序进入三合一验证流程。

#### 场景三：搭建现状监控

1. 被授权程序首次验证通过后自动调用注册接口，上报域名、IP、版本、服务器与数据库环境信息；
2. 注册后按固定间隔发送心跳（默认 60 秒），心跳携带最新版本与环境信息；
3. 授权系统后台"实例管理"页面实时展示每个实例的搭建现状：域名、IP、版本、环境、最后心跳时间、在线/离线状态。

#### 场景四：远程管理

1. 授权方在后台选中某实例，下发管理命令（如禁用程序、推送配置、进入维护模式）；
2. 命令写入命令队列；
3. 被授权程序每次心跳后轮询命令接口，取到命令并执行；
4. 执行完成后上报执行结果，后台可见命令状态。

#### 场景五：弹窗管理

1. 授权方在后台创建弹窗（标题、内容、类型、目标范围、有效期、展示次数上限）；
2. 被授权程序定期轮询弹窗接口获取命中自身的启用中弹窗；
3. 被授权程序在页面展示弹窗并上报展示记录；
4. 后台可查看每个弹窗在各实例上的展示次数。

#### 场景六：远程更新

1. 授权方在后台为某产品上传新版本更新包（zip + 更新日志）；
2. 被授权程序心跳响应中携带"存在新版本"提示，或后台主动下发"立即更新"命令；
3. 被授权程序调用更新检测接口获取更新信息，下载更新包，校验 MD5，解压替换，执行迁移脚本；
4. 更新结果上报后台；强制更新场景下未更新则拒绝启动。

#### 场景七：在线添加新产品

1. 授权方将新产品代码按规范打包成安装包（内含 manifest.json 描述文件与 SDK）；
2. 后台"安装包管理"页面上传安装包，系统解析 manifest 自动创建产品记录与初始版本；
3. 该产品即可进入后续授权码签发、实例管理、更新等全流程。

#### 场景八：远程模块调用

1. 授权方在后台"远程模块"中创建模块（PHP 代码型 / HTTP 转发型 / 数据查询型），并指定归属产品；
2. 被授权程序调用 `callModule()`，携带模块编码与参数；
3. 授权系统执行模块逻辑（或转发、或查询），返回结果，并记录调用日志；
4. 后台可管理模块启停、编辑代码、调整参数校验规则、查看调用日志。

---

## 3. 系统总体架构

### 3.1 逻辑架构

```
┌─────────────────────────────────────────────────────────────┐
│                        授权方服务器                          │
│                                                             │
│   ┌───────────────────────┐     ┌───────────────────────┐  │
│   │   后台管理端 (Admin)    │     │  对外 API 层          │  │
│   │  · 登录/权限           │     │  /api/v1/*           │  │
│   │  · 产品/安装包          │     │  verify|register|     │  │
│   │  · 授权码/实例          │     │  heartbeat|command|   │  │
│   │  · 弹窗/更新/模块/日志   │     │  popup|update|module  │  │
│   └──────────┬────────────┘     └──────────┬────────────┘  │
│              │                             │               │
│   ┌──────────▼──────────────┬──────────────▼────────────┐   │
│   │           业务服务层 (Business Service)              │   │
│   │ 验证服务│实例服务│命令服务│弹窗服务│更新服务│模块服务│   │   │
│   └──────────┬───────────────────────────────┬─────────┘   │
│              │                               │             │
│   ┌──────────▼──────────┐     ┌──────────────▼─────────┐   │
│   │    MySQL 5.6 数据层  │     │  文件存储(packages/     │   │
│   │   (授权码/实例/命令/  │     │   updates/modules)      │   │
│   │    弹窗/模块/日志)    │     │   + 本地缓存(Redis可选)  │   │
│   └──────────────────────┘     └────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
        ▲                                      ▲
        │ HTTPS + JSON + 签名                    │
        │                                      │
┌───────┴───────────────────┐   ┌──────────────┴─────────────┐
│  客户服务器 A              │   │  客户服务器 B               │
│  被授权程序 + DCAI SDK     │   │  被授权程序 + DCAI SDK      │
│  (域名1 / IP1)             │   │  (域名2 / IP2)             │
└───────────────────────────┘   └────────────────────────────┘
```

### 3.2 核心组件

| 组件 | 说明 |
|---|---|
| `admin/` | 后台管理端（Web UI），仅管理员可访问 |
| `api/` | 对外 API 入口，供被授权程序 SDK 调用 |
| `core/` | 核心库：数据库、签名验签、加解密、日志、限流、统一响应 |
| `service/` | 业务服务层：验证、实例、命令、弹窗、更新、模块等 |
| `sdk/` | 被授权程序客户端 SDK（随安装包分发） |
| `packages/`、`updates/` | 安装包、更新包文件存储（置于 Web 根之外或受保护） |

### 3.3 通信机制

- 协议：HTTPS + JSON；
- 鉴权分两阶段：
  - **未注册阶段**：使用 SDK 内置的 `app_id + app_secret` 进行 HMAC-SHA256 签名；
  - **已注册阶段**：使用服务端下发的 `instance_id + instance_token` 进行 HMAC-SHA256 签名；
- 防重放：请求携带 `timestamp + nonce`，服务端校验时间差 ≤ 300 秒且 nonce 未使用过；
- 授权结果防伪造：验证成功时服务端用 RSA 私钥签发 `verification_token`，SDK 内置 RSA 公钥可本地验签，保证离线缓存结果不可篡改。

### 3.4 项目目录结构

```
DCAI授权系统/
├── public/                       # Web 根目录（对外唯一可访问目录）
│   ├── index.php                 # 入口（路由分发）
│   ├── admin/                    # 后台管理端静态资源与页面
│   │   ├── login.php
│   │   ├── dashboard.php
│   │   ├── products.php
│   │   ├── packages.php
│   │   ├── licenses.php
│   │   ├── instances.php
│   │   ├── commands.php
│   │   ├── popups.php
│   │   ├── updates.php
│   │   ├── modules.php
│   │   ├── logs.php
│   │   └── settings.php
│   └── assets/                   # css/js/img
├── api/                          # 对外 API
│   ├── v1/
│   │   ├── auth.php
│   │   ├── instance.php
│   │   ├── command.php
│   │   ├── popup.php
│   │   ├── update.php
│   │   └── module.php
│   └── index.php                 # API 入口（统一鉴权/限流/日志）
├── core/                         # 核心库
│   ├── Database.php              # PDO 单例封装
│   ├── Response.php              # 统一 JSON 响应
│   ├── Signature.php             # HMAC 验签 / RSA 签发与验证
│   ├── Crypto.php                # AES-256 加解密
│   ├── RateLimit.php             # 接口限流
│   ├── Logger.php                # 日志写入
│   ├── Csrf.php                  # 后台 CSRF 防护
│   └── Version.php               # 版本号比较
├── service/                      # 业务服务层
│   ├── VerifyService.php
│   ├── InstanceService.php
│   ├── CommandService.php
│   ├── PopupService.php
│   ├── UpdateService.php
│   ├── PackageService.php
│   ├── ModuleService.php
│   └── LicenseService.php
├── modules/                      # 远程模块代码文件（如模块采用文件方式存储）
├── storage/                      # 上传文件存储（Web 根之外）
│   ├── packages/                 # 安装包
│   ├── updates/                  # 更新包
│   ├── logs/                     # 运行日志
│   └── cache/
├── sdk/                          # 被授权程序 SDK（随安装包分发）
│   ├── dcai_client.php           # SDK 主类
│   └── dcai_config.sample.php    # SDK 配置样例
├── install/                      # 安装向导（首次使用后建议删除）
├── config/
│   └── config.php                # 全局配置（数据库、密钥、域名、限流等）
└── docs/                         # 文档（本说明书、集成文档等）
```

---

## 4. 数据库设计

### 4.1 MySQL 5.6 兼容性约束

| 约束 | 处理方式 |
|---|---|
| 无 CHECK 约束 | 业务状态用 TINYINT，应用层校验 |
| 无 JSON 类型 | JSON 结构统一用 `TEXT` 存储，应用层 `json_encode/decode` |
| 无窗口函数 / CTE | SQL 全部使用基础 SELECT/JOIN/子查询 |
| utf8mb4 索引长度限制（767 字节） | 需建索引的 VARCHAR 列长度 ≤ 191；排序规则用 `utf8mb4_general_ci` |
| 无 `utf8mb4_0900_ai_ci` 排序规则 | 使用 `utf8mb4_general_ci` |
| 引擎 | 全部使用 InnoDB |
| 字符集 | `DEFAULT CHARSET=utf8mb4` |

### 4.2 表清单

| 表名 | 说明 |
|---|---|
| `admin_users` | 后台管理员 |
| `products` | 被授权程序产品 |
| `install_packages` | 安装包 |
| `licenses` | 授权码 |
| `instances` | 被授权程序实例（搭建现状） |
| `instance_commands` | 远程管理命令 |
| `popups` | 弹窗 |
| `popup_show_logs` | 弹窗展示记录 |
| `updates` | 更新包 |
| `update_apply_logs` | 实例更新记录 |
| `remote_modules` | 远程模块 |
| `module_invoke_logs` | 远程模块调用日志 |
| `verify_logs` | 授权验证日志 |
| `settings` | 系统设置 |
| `operation_logs` | 后台操作日志 |
| `machine_bindings` | 机器码绑定（V1.3 新增，防授权码多机共用） |
| `offline_activations` | 离线激活记录（V1.3 新增，内网断网场景） |
| `skills` | 技能包（V1.2 新增，见 13.1） |
| `skill_functions` | 技能函数（V1.2 新增） |
| `skill_grants` | 技能按码授权（V1.2 新增） |
| `skill_invoke_logs` | 技能调用日志（V1.2 新增） |

### 4.3 表结构详细设计

```sql
-- 后台管理员
CREATE TABLE `admin_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL COMMENT '登录名',
  `password_hash` VARCHAR(255) NOT NULL COMMENT '密码哈希(bcrypt)',
  `nickname` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '昵称',
  `email` VARCHAR(100) NOT NULL DEFAULT '',
  `role` TINYINT NOT NULL DEFAULT 1 COMMENT '1=超级管理员 2=普通管理员',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1=启用 0=禁用',
  `twofa_secret` VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'Google身份验证器密钥(可选)',
  `last_login_at` DATETIME DEFAULT NULL,
  `last_login_ip` VARCHAR(45) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='后台管理员';
```

```sql
-- 被授权程序产品
CREATE TABLE `products` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_code` VARCHAR(50) NOT NULL COMMENT '产品唯一编码(如 shop_v2)',
  `name` VARCHAR(100) NOT NULL COMMENT '产品名称',
  `description` TEXT COMMENT '产品描述',
  `current_version` VARCHAR(30) NOT NULL DEFAULT '1.0.0' COMMENT '当前最新版本',
  `enforce_auth` TINYINT NOT NULL DEFAULT 0 COMMENT '0=未开启授权管控(默认放行) 1=开启三合一验证',
  `fail_open` TINYINT NOT NULL DEFAULT 1 COMMENT '1=授权系统不可达时默认放行 0=拒绝运行',
  `verify_ttl` INT NOT NULL DEFAULT 3600 COMMENT '授权令牌本地缓存秒数',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1=上架 0=下架',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_product_code` (`product_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='被授权程序产品';
```

```sql
-- 安装包
CREATE TABLE `install_packages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `version` VARCHAR(30) NOT NULL,
  `package_path` VARCHAR(255) NOT NULL COMMENT '安装包相对 storage 的路径',
  `package_md5` VARCHAR(64) NOT NULL,
  `package_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `manifest` TEXT COMMENT 'manifest.json 原始内容',
  `download_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1=可用 0=停用',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='安装包';
```

```sql
-- 授权码
CREATE TABLE `licenses` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `license_key` VARCHAR(64) NOT NULL COMMENT '授权码',
  `product_id` INT UNSIGNED NOT NULL,
  `customer_name` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '授权客户名称',
  `customer_email` VARCHAR(100) NOT NULL DEFAULT '',
  `allowed_domains` TEXT COMMENT '允许域名列表，每行一个，支持通配符 *.example.com',
  `allowed_ips` TEXT COMMENT '允许IP列表，每行一个，支持网段 1.2.3.0/24',
  `max_instances` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '最大部署实例数',
  `expire_at` DATETIME NOT NULL COMMENT '授权到期时间',
  `remark` VARCHAR(255) NOT NULL DEFAULT '',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1=有效 0=禁用',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_license_key` (`license_key`),
  KEY `idx_product` (`product_id`),
  KEY `idx_status_expire` (`status`,`expire_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='授权码';
```

```sql
-- 被授权程序实例（搭建现状）
CREATE TABLE `instances` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `instance_id` VARCHAR(64) NOT NULL COMMENT '实例唯一ID(UUID)',
  `instance_token_enc` VARCHAR(255) NOT NULL COMMENT '实例令牌(AES加密存储)',
  `product_id` INT UNSIGNED NOT NULL,
  `license_id` INT UNSIGNED DEFAULT NULL,
  `domain` VARCHAR(191) NOT NULL COMMENT '部署域名',
  `ip` VARCHAR(45) NOT NULL DEFAULT '' COMMENT '部署服务器IP',
  `version` VARCHAR(30) NOT NULL DEFAULT '' COMMENT '程序当前版本',
  `server_info` TEXT COMMENT '服务器环境信息(JSON: PHP版本/系统/内存/磁盘)',
  `db_info` TEXT COMMENT '数据库信息(JSON: 类型/版本)',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1=在线 0=离线 2=已禁用(远程禁用)',
  `last_heartbeat_at` DATETIME DEFAULT NULL COMMENT '最后心跳时间',
  `first_seen_at` DATETIME NOT NULL COMMENT '首次注册时间',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_instance_id` (`instance_id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_license` (`license_id`),
  KEY `idx_domain` (`domain`),
  KEY `idx_last_heartbeat` (`last_heartbeat_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='被授权程序实例';
```

```sql
-- 远程管理命令
CREATE TABLE `instance_commands` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `instance_id` INT UNSIGNED NOT NULL,
  `command_type` VARCHAR(50) NOT NULL COMMENT 'disable|enable|config_push|maintenance_on|maintenance_off|reboot|update',
  `payload` TEXT COMMENT '命令参数(JSON)',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0=待执行 1=已下发 2=执行成功 3=执行失败 4=超时',
  `result` TEXT COMMENT '执行结果(JSON)',
  `issued_by` INT UNSIGNED DEFAULT NULL COMMENT '下发管理员ID',
  `issued_at` DATETIME NOT NULL,
  `picked_at` DATETIME DEFAULT NULL,
  `executed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_instance_status` (`instance_id`,`status`),
  KEY `idx_issued` (`issued_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='远程管理命令';
```

```sql
-- 弹窗
CREATE TABLE `popups` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(191) NOT NULL,
  `content` TEXT NOT NULL COMMENT '弹窗内容，支持HTML',
  `popup_type` TINYINT NOT NULL DEFAULT 1 COMMENT '1=公告 2=通知 3=警示',
  `target_type` TINYINT NOT NULL DEFAULT 0 COMMENT '0=全部实例 1=指定实例',
  `target_instance_ids` TEXT COMMENT '指定实例ID列表(JSON数组)',
  `start_at` DATETIME DEFAULT NULL COMMENT '开始展示时间',
  `end_at` DATETIME DEFAULT NULL COMMENT '结束展示时间',
  `max_show_per_instance` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '每实例最大展示次数 0=不限',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1=启用 0=停用',
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_product_status` (`product_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='弹窗';
```

```sql
-- 弹窗展示记录
CREATE TABLE `popup_show_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `popup_id` INT UNSIGNED NOT NULL,
  `instance_id` INT UNSIGNED NOT NULL,
  `shown_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_shown_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_popup_instance` (`popup_id`,`instance_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='弹窗展示记录';
```

```sql
-- 更新包
CREATE TABLE `updates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `version` VARCHAR(30) NOT NULL,
  `package_path` VARCHAR(255) NOT NULL,
  `package_md5` VARCHAR(64) NOT NULL,
  `package_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `min_version` VARCHAR(30) NOT NULL DEFAULT '' COMMENT '允许升级的最低版本',
  `changelog` TEXT COMMENT '更新日志',
  `is_force` TINYINT NOT NULL DEFAULT 0 COMMENT '1=强制更新 0=可选',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1=已发布 0=已撤回',
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_product_version` (`product_id`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='更新包';
```

```sql
-- 实例更新记录
CREATE TABLE `update_apply_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `update_id` INT UNSIGNED NOT NULL,
  `instance_id` INT UNSIGNED NOT NULL,
  `from_version` VARCHAR(30) NOT NULL DEFAULT '',
  `to_version` VARCHAR(30) NOT NULL DEFAULT '',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0=下载中 1=已下载 2=应用成功 3=应用失败',
  `error` VARCHAR(500) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  `finished_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_instance` (`instance_id`),
  KEY `idx_update` (`update_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='实例更新记录';
```

```sql
-- 远程模块
CREATE TABLE `remote_modules` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `module_code` VARCHAR(64) NOT NULL COMMENT '模块唯一编码',
  `product_id` INT UNSIGNED NOT NULL COMMENT '归属产品',
  `name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(500) NOT NULL DEFAULT '',
  `module_type` TINYINT NOT NULL DEFAULT 1 COMMENT '1=PHP代码型 2=HTTP转发型 3=数据查询型',
  `code` LONGTEXT COMMENT '模块PHP代码(module_type=1时有效)',
  `upstream_url` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '转发目标URL(module_type=2时有效)',
  `version` VARCHAR(30) NOT NULL DEFAULT '1.0.0',
  `params_schema` TEXT COMMENT '参数校验规则(JSON Schema 子集)',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1=启用 0=停用',
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_module_code` (`module_code`),
  KEY `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='远程模块';
```

```sql
-- 远程模块调用日志
CREATE TABLE `module_invoke_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `module_id` INT UNSIGNED NOT NULL,
  `instance_id` INT UNSIGNED DEFAULT NULL,
  `params` TEXT COMMENT '调用参数(JSON)',
  `result` LONGTEXT COMMENT '返回结果(JSON)',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1=成功 0=失败',
  `error` VARCHAR(500) NOT NULL DEFAULT '',
  `cost_ms` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_module` (`module_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='远程模块调用日志';
```

```sql
-- 授权验证日志
CREATE TABLE `verify_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED DEFAULT NULL,
  `license_id` INT UNSIGNED DEFAULT NULL,
  `instance_id` INT UNSIGNED DEFAULT NULL,
  `domain` VARCHAR(191) NOT NULL DEFAULT '',
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `license_key_masked` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '授权码掩码(前6后4)',
  `result` TINYINT NOT NULL COMMENT '1=通过 0=失败',
  `reason` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '失败原因',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_product_result` (`product_id`,`result`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='授权验证日志';
```

```sql
-- 系统设置
CREATE TABLE `settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `skey` VARCHAR(64) NOT NULL,
  `svalue` TEXT,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_skey` (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统设置';
```

```sql
-- 后台操作日志
CREATE TABLE `operation_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` INT UNSIGNED NOT NULL,
  `action` VARCHAR(100) NOT NULL,
  `detail` TEXT,
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='后台操作日志';
```

#### V1.3 新增表结构（migrate_v1.3.php 提供）

```sql
-- 机器码绑定（防授权码多机共用）
CREATE TABLE `machine_bindings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `license_id` INT UNSIGNED NOT NULL COMMENT '授权码ID',
  `machine_code` VARCHAR(64) NOT NULL COMMENT '机器指纹(HMAC-SHA256)',
  `machine_name` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '机器名称(如 hostname)',
  `first_instance_id` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '首个绑定实例ID',
  `first_seen_at` DATETIME NOT NULL,
  `last_seen_at` DATETIME DEFAULT NULL,
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1=绑定 0=已解绑',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_license_machine` (`license_id`,`machine_code`),
  KEY `idx_license` (`license_id`),
  KEY `idx_machine` (`machine_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='机器码绑定';
```

```sql
-- 离线激活记录
CREATE TABLE `offline_activations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `license_id` INT UNSIGNED NOT NULL COMMENT '授权码ID',
  `product_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `machine_code` VARCHAR(64) NOT NULL COMMENT '机器指纹',
  `machine_name` VARCHAR(255) NOT NULL DEFAULT '',
  `request_id` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '激活请求ID',
  `expire_at` DATETIME NULL COMMENT '离线授权到期时间(NULL=随授权码)',
  `activate_file` TEXT COMMENT '签发的激活文件内容(JSON, 含RSA签名)',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1=已签发 0=已作废',
  `created_by` INT UNSIGNED DEFAULT NULL COMMENT '签发管理员ID',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_req_machine` (`request_id`,`machine_code`),
  KEY `idx_license` (`license_id`),
  KEY `idx_machine` (`machine_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='离线激活记录';
```

**V1.3 新增/变更列**（由 `install/migrate_v1.3.php` 幂等执行）：

| 表 | 列 | 类型/默认 | 说明 |
|---|---|---|---|
| `licenses` | `machine_limit` | INT UNSIGNED DEFAULT 1 | 最大绑定机器数（0=不限制，兼容旧行为） |
| `licenses` | `trial_days` | INT UNSIGNED DEFAULT 0 | 试用天数（0=正式授权；N=首次注册免费试用 N 天） |
| `licenses` | `source` | INT DEFAULT 0 | 授权来源（3=自助试用） |
| `products` | `trial_enabled` | TINYINT DEFAULT 0 | 1=开放自助申请试用 |
| `products` | `trial_days` | INT UNSIGNED DEFAULT 0 | 产品默认试用天数 |
| `instances` | `machine_code` | VARCHAR(64) DEFAULT '' | 实例所属机器指纹（HMAC-SHA256 摘要） |

### 4.4 关键索引与数据规模说明

- 高频表（`verify_logs`、`module_invoke_logs`、`operation_logs`、`popup_show_logs`）采用 BIGINT 主键并定期归档/清理，避免表过大；
- `instances.last_heartbeat_at` 建索引用于后台"离线超时"批量扫描；
- `licenses` 与 `instances` 通过 `product_id`、`license_id` 关联，均为高频查询条件，已建索引；
- 文本型字段（`allowed_domains`、`allowed_ips`、`server_info` 等）按行/JSON 存储，由应用层解析，不参与 SQL 过滤。
- `machine_bindings` 以 `(license_id, machine_code)` 唯一索引防重复绑定；`offline_activations` 以 `(request_id, machine_code)` 唯一索引保证请求文件不被重复签发。

---

## 5. 接口设计（API 规格）

### 5.1 通用约定

- 基础地址：`https://{授权系统域名}/api/v1/`
- 请求头：`Content-Type: application/json; charset=utf-8`
- 响应格式：

```json
{
  "code": 0,
  "msg": "ok",
  "data": { }
}
```

- 鉴权与签名（所有接口，`auth/verify` 除外）：

  - 请求头携带：`X-Instance-Id`、`X-Timestamp`、`X-Nonce`、`X-Sign`
  - 未注册阶段的验证请求使用 SDK 内置密钥签名：`sign = HMAC-SHA256(app_secret, timestamp + "\n" + nonce + "\n" + sha256(body))`
  - 已注册阶段使用实例令牌签名：`sign = HMAC-SHA256(instance_token, timestamp + "\n" + nonce + "\n" + sha256(body))`
  - 服务端校验：时间戳偏差 ≤ 300 秒；nonce 在缓存中不存在（防重放）；签名一致

- 版本比较采用三段式（`x.y.z`），由 `Version.php` 统一处理。

### 5.2 错误码表

| code | 含义 |
|---|---|
| 0 | 成功 |
| 1001 | 参数缺失或格式错误 |
| 1002 | 签名校验失败 |
| 1003 | 时间戳过期或 nonce 重复 |
| 1004 | 接口限流 |
| 2001 | 授权码不存在 |
| 2002 | 授权码已禁用 |
| 2003 | 授权码已过期 |
| 2004 | 授权码与产品不匹配 |
| 2005 | 域名未授权 |
| 2006 | IP 未授权 |
| 2007 | 实例数超出上限 |
| 2008 | 实例已被远程禁用 |
| 2009 | 产品未上架或不存在 |
| 2011 | 机器未绑定（V1.3） |
| 2012 | 绑定机器数已达上限（V1.3） |
| 2100 | 实例令牌无效 |
| 3001 | 模块不存在或未启用 |
| 3002 | 模块参数校验失败 |
| 3003 | 模块执行异常 |
| 5000 | 服务器内部错误 |
| 5001 | 离线激活文件无效（V1.3） |

### 5.3 接口清单

#### 5.3.1 三合一授权验证 `POST /auth/verify`

**用途**：被授权程序启动时进行"授权码 + 域名 + IP"三合一验证，成功后签发授权令牌。

**请求（未注册阶段，使用 app_secret 签名）**：

```json
{
  "product_code": "shop_v2",
  "license_key": "DCAI-XXXX-XXXX-XXXX-XXXX",
  "domain": "client.example.com",
  "ip": "1.2.3.4",
  "client_version": "1.0.0",
  "instance_id": "" 
}
```

**处理逻辑**：

```
1. 校验产品存在且上架；
2. 校验授权码：存在、status=1、未过期、product_id 匹配；
3. 校验域名：请求 domain 匹配 allowed_domains 列表（支持 *.通配符，严格域名比较）；
4. 校验 IP：请求 ip 匹配 allowed_ips 列表（支持精确 IP 与 CIDR 网段）；
5. 校验实例数：该授权码下 status!=2 的实例数 < max_instances；
6. 全部通过 → 记录 verify_logs(result=1)；
   使用 RSA 私钥签发 verification_token（载荷：product_code、license_key 哈希、domain、ip、expire_at、签发时间）；
7. 任一失败 → 记录 verify_logs(result=0, reason)，返回对应错误码。
```

**成功响应**：

```json
{
  "code": 0,
  "data": {
    "verified": true,
    "token": "RSA签名的verification_token",
    "expire_at": "2026-08-28 10:00:00",
    "server_time": "2026-08-27 10:00:00"
  }
}
```

**失败响应**：`code` 为 2001~2009 对应错误码，`data.reason` 说明具体原因。

#### 5.3.2 实例注册 `POST /instance/register`

**用途**：三合一验证通过后，被授权程序向授权系统注册实例并获取 `instance_id / instance_token`。

**请求（使用 app_secret 签名）**：

```json
{
  "product_code": "shop_v2",
  "license_key": "DCAI-XXXX-XXXX-XXXX-XXXX",
  "domain": "client.example.com",
  "ip": "1.2.3.4",
  "version": "1.0.0",
  "server_info": {"php_version":"8.2.0","os":"Linux","memory_mb":4096,"disk_free_mb":10240},
  "db_info": {"type":"mysql","version":"5.7.40"}
}
```

**处理逻辑**：

```
1. 执行与 verify 相同的三合一校验（失败返回 2001~2009）；
2. 以 (product_id, domain) 查重：已存在且状态正常 → 复用旧实例并返回其令牌（幂等）；
   否则创建新实例，生成 instance_id（UUID v4）与 instance_token（64位随机 hex，AES 加密入库）；
3. 返回实例凭证。
```

**成功响应**：

```json
{
  "code": 0,
  "data": {
    "instance_id": "550e8400-e29b-41d4-a716-446655440000",
    "instance_token": "a3f1...64hex...",
    "verify_interval": 3600,
    "heartbeat_interval": 60
  }
}
```

#### 5.3.3 心跳上报 `POST /instance/heartbeat`

**用途**：被授权程序定期上报搭建现状（版本、环境信息），并获取授权状态、新版本与命令提醒。

**请求（实例令牌签名）**：

```json
{
  "version": "1.0.0",
  "server_info": {"php_version":"8.2.0","os":"Linux","memory_mb":4096,"disk_free_mb":10240},
  "db_info": {"type":"mysql","version":"5.7.40"}
}
```

**处理逻辑**：

```
1. 校验实例令牌；实例 status=2（被禁用）→ 返回 revoked=true；
2. 更新实例的 version / server_info / db_info / last_heartbeat_at，status=1；
3. 统计该实例待执行命令数、命中弹窗数（提示用）；
4. 查询该产品是否有比当前版本更新的已发布更新包。
```

**成功响应**：

```json
{
  "code": 0,
  "data": {
    "next_interval": 60,
    "revoked": false,
    "pending_commands": 1,
    "new_version": {"version":"1.1.0","is_force":false,"changelog":"修复若干Bug"},
    "server_time": "2026-08-27 10:00:00"
  }
}
```

#### 5.3.4 命令轮询 `POST /command/poll`

**用途**：被授权程序心跳后调用，拉取待执行的远程管理命令。

**请求（实例令牌签名）**：`{}`

**处理逻辑**：

```
1. 取该实例 status=0 的最早 10 条命令；
2. 将其置为 status=1（已下发），记录 picked_at。
```

**成功响应**：

```json
{
  "code": 0,
  "data": {
    "commands": [
      {"id": 1024, "command_type": "config_push", "payload": {"config": {"site_name": "新站名"}}}
    ]
  }
}
```

#### 5.3.5 命令结果上报 `POST /command/report`

**请求（实例令牌签名）**：

```json
{"command_id": 1024, "status": 2, "result": {"ok": true, "detail": "配置已更新"}}
```

**处理逻辑**：更新命令 `status/result/executed_at`。

#### 5.3.6 弹窗拉取 `POST /popup/list`

**请求（实例令牌签名）**：`{}`

**处理逻辑**：

```
1. 查出该实例归属产品的启用中弹窗；
2. 过滤：start_at/end_at 时间窗内；target_type=1 时包含该实例；
3. 过滤：max_show_per_instance>0 且已展示次数达到上限的弹窗；
4. 返回命中列表。
```

**成功响应**：

```json
{
  "code": 0,
  "data": {
    "popups": [
      {"id": 5, "title": "系统维护通知", "content": "<p>...</p>", "popup_type": 2}
    ]
  }
}
```

#### 5.3.7 弹窗展示上报 `POST /popup/report`

**请求（实例令牌签名）**：`{"popup_id": 5}`

**处理逻辑**：`popup_show_logs` 中 `shown_count+1`、更新 `last_shown_at`（不存在则插入）。

#### 5.3.8 更新检测 `POST /update/check`

**请求（实例令牌签名）**：`{"current_version": "1.0.0"}`

**处理逻辑**：

```
1. 取该产品最新已发布(status=1)更新包；
2. 版本号比较：更新包版本 > 当前版本 且 当前版本 ≥ min_version；
3. 命中则返回更新信息与带签名的下载地址。
```

**成功响应**：

```json
{
  "code": 0,
  "data": {
    "has_update": true,
    "update": {
      "version": "1.1.0",
      "changelog": "修复若干Bug",
      "is_force": false,
      "md5": "d41d8cd98f00b204e9800998ecf8427e",
      "size": 2048576,
      "download_url": "https://auth.example.com/api/v1/update/download?u=签名串"
    }
  }
}
```

#### 5.3.9 更新包下载 `GET /update/download`

- 通过带签名的 `download_url` 下载，响应为 zip 文件流；
- 服务端校验签名与时效，记录 `update_apply_logs`。

#### 5.3.10 更新结果上报 `POST /update/report`

**请求（实例令牌签名）**：

```json
{"target_version": "1.1.0", "status": 2, "error": ""}
```

**处理逻辑**：更新 `update_apply_logs` 状态，同步实例 `version`。

#### 5.3.11 远程模块列表 `POST /module/list`

**请求（实例令牌签名）**：`{}`

**成功响应**：

```json
{
  "code": 0,
  "data": {
    "modules": [
      {"module_code": "order_stat", "name": "订单统计", "version": "1.0.0"}
    ]
  }
}
```

#### 5.3.12 远程模块调用 `POST /module/invoke`

**请求（实例令牌签名）**：

```json
{"module_code": "order_stat", "params": {"start_date": "2026-08-01", "end_date": "2026-08-27"}}
```

**处理逻辑**：

```
1. 校验模块存在、启用、归属该实例产品；
2. 按 params_schema 校验参数（类型、必填、范围）；
3. 按 module_type 执行：
   - PHP代码型：在受限沙箱环境中执行（禁用危险函数、内存/执行时间上限、开启输出缓冲）；
   - HTTP转发型：透传请求到 upstream_url（超时与白名单校验）；
   - 数据查询型：执行白名单内预定义 SQL 模板（仅 SELECT）；
4. 记录 module_invoke_logs（含耗时、结果、错误）。
```

**成功响应**：

```json
{
  "code": 0,
  "data": {"result": {"total_orders": 128, "amount": 35680.5}}
}
```

#### 5.3.13 离线激活在线签发 `POST /offline/request`（V1.3）

**用途**：客户端生成激活请求（含产品码、授权码、机器码），服务端校验后直接签发 RSA 签名激活文件。**公开操作**：使用 `app_secret` 签名，无需实例令牌（与 `/auth/verify` 同级）。

**请求**：

```json
{
  "product_code": "shop_v2",
  "license_key": "DCAI-XXXX-XXXX-XXXX-XXXX",
  "machine_code": "a3f5...e9c1",
  "machine_name": "web-01"
}
```

**处理逻辑**：

```
1. 校验产品存在且上架、授权码存在且未过期；
2. machine_limit>0 时校验机器数是否达上限（未达上限自动绑定）；
3. DCAI_OfflineActivationService::issue() 签发激活文件（RSA-SHA256 签名，expire_at 取授权码到期）；
4. 写 verify_logs（result=1，reason=离线激活签发(API)）。
```

**成功响应**：

```json
{
  "code": 0,
  "data": {
    "activation": {"license_id": 1, "product_code": "shop_v2", "expire_at": "2027-01-01 00:00:00"},
    "file_json": "{\"version\":1,\"type\":\"offline_activation\",...}"
  }
}
```

#### 5.3.14 离线激活文件校验 `POST /offline/verify`（V1.3）

**用途**：联网场景下客户端导入激活文件前先校验有效性（签名、授权码、到期时间）。**公开操作**（`app_secret` 签名）。

**请求**：`{"file_json": "{\"version\":1,...}"}`

**校验逻辑**：RSA 验签 → 授权码存在未禁用 → 到期时间未过期。

**成功响应**：`{"code": 0, "data": {"valid": true, "msg": "激活文件有效，授权至 2027-01-01 00:00:00"}}`

**失败**：`5001 离线激活文件无效`（携带具体原因）。

#### 5.3.15 远程模块调用（沙箱降级）补充说明（V1.3）

`POST /module/invoke` 的 PHP 代码型模块执行器在 `proc_open` 被禁用的环境（如宝塔面板默认 `disable_functions`）下自动降级为**进程内沙箱**（详见第 18 章「沙箱降级模式」）。调用方无需任何改动，响应结构与错误码保持一致（`3003 模块执行异常`、超时返回 `5000 模块执行超时`）。

---

## 6. 功能模块详细设计

### 6.1 模块一：三合一授权验证（F1）

**设计要点**：授权码、域名、IP 三项校验互为"与"关系，缺一不可。

```
                 ┌─────────────────────────────┐
  验证请求 ─────► │         VerifyService        │
 (code+domain+ip) │                             │
                 │ ① 授权码校验(存在/有效/未过期)  │
                 │ ② 域名校验(白名单/通配符)      │
                 │ ③ IP 校验(白名单/CIDR)        │
                 │ ④ 实例数上限校验              │
                 └──────────────┬──────────────┘
                       全部通过 │ │ 任一失败
                       ┌───────▼─┴────────┐
                       │ 签发验证令牌(RSA) │  记录验证日志(含失败原因)
                       └──────────────────┘
```

| 校验项 | 数据来源 | 判定规则 |
|---|---|---|
| 授权码 | `licenses` | `license_key` 存在、`status=1`、`expire_at > NOW()`、`product_id` 匹配 |
| 域名 | `licenses.allowed_domains` | 每行一条规则，支持 `example.com` 与 `*.example.com`；`example.com` 匹配 `example.com` 及其任意子域名 |
| IP | `licenses.allowed_ips` | 每行一条规则，支持精确 IP 与 `a.b.c.d/mask` CIDR 网段 |
| 实例数 | `instances` | 该授权码下 `status<>2` 的实例数 `< max_instances` |

**授权码格式**：`DCAI-` + 5 组 4 位大写 base32 随机串（如 `DCAI-8K2Q-7XBM-P3WD-H9YC-Q4NZ`），服务端生成，全局唯一。授权码与产品的绑定关系由签发时确定。

**验证令牌（verification_token）**：

- 载荷（base64url JSON）：`product_code`、`license_key` 的 SHA-256 哈希、`domain`、`ip`、`iat`、`expire_at`；
- 使用授权系统 RSA 私钥进行 SHA-256 签名；
- SDK 内嵌 RSA 公钥，本地验签后可离线信任该令牌，令牌过期后重新调用验证接口。

### 6.2 模块二：授权未开启默认放行（F2）

**设计要点**：系统提供"双重开关 + 双级放行"，保证授权系统未介入时客户程序完全不受影响。

**层级一：产品级管控开关（服务端）**

| 开关 | 字段 | 说明 |
|---|---|---|
| `enforce_auth` | `products.enforce_auth` | 0=未开启授权管控：该产品的所有实例默认已授权，验证接口对非强制场景不拦截；1=开启：进入三合一验证 |

**层级二：SDK 本地开关（客户端）**

| 开关 | 配置项 | 说明 |
|---|---|---|
| 授权开关 | `dcai_config.php` 中 `DCAI_ENABLED` | `false` 时 SDK 不发起任何请求，`verify()` 直接返回"已授权" |

**放行策略（fail_open / fail_close）**：

- 产品配置 `fail_open=1`（默认）：验证接口网络不可达 / 超时 / 返回异常时，SDK 按"已授权"处理并记录告警日志；
- 产品配置 `fail_open=0`：网络不可达时拒绝运行（用于严格合规场景）；
- 产品已开启 `enforce_auth=1` 且网络正常时，三合一校验结果为准，与 fail_open 无关。

> 决策说明：F2 的"默认放行"与 fail_open 是独立的两件事——前者是"未开启授权管控"，后者是"已开启但网络异常"。二者均默认放行，符合"授权系统未开启时默认已授权"的产品要求。

### 6.3 模块三：搭建现状监控（F3）

**数据流**：

```
被授权程序启动
   │ ① verify() 通过
   ▼
register 上报域名/IP/版本/服务器环境/数据库环境
   ▼
定期 heartbeat（默认60s）刷新版本与环境、心跳时间
   ▼
后台 instances.php 实时展示
   · 在线/离线/已禁用 状态徽标（离线阈值可配，默认180s）
   · 域名、IP、版本、最后心跳
   · 服务器环境（PHP版本/OS/内存/磁盘）
   · 数据库类型与版本
   · 关联授权码、所属产品
```

**离线判定**：定时任务或惰性查询将 `last_heartbeat_at < NOW() - 阈值` 的实例置为 `status=0`。

**辅助能力**：实例详情页展示历史心跳趋势（可选）、验证日志关联、命令历史关联。

### 6.4 模块四：远程管理（F4）

**命令模型**：服务端命令队列 + 客户端轮询执行。

```
后台下发命令 ──► instance_commands(status=0)
被授权程序心跳 ──► /command/poll 拉取(status=0→1)
执行命令 ──► /command/report 上报结果(status→2/3)
```

**内置命令类型**：

| command_type | 说明 | payload 示例 |
|---|---|---|
| `disable` | 禁用程序（kill switch），SDK 拒绝继续运行并跳转引导页 | `{}` |
| `enable` | 解除禁用 | `{}` |
| `config_push` | 推送配置覆盖文件 | `{"config": {...}}` |
| `maintenance_on` | 进入维护模式（页面显示维护公告） | `{"notice": "系统升级中"}` |
| `maintenance_off` | 退出维护模式 | `{}` |
| `reboot` | 重启程序（清缓存、重载配置） | `{}` |
| `update` | 触发远程更新流程 | `{"target_version": "1.1.0"}` |

**扩展机制**：SDK 内置命令处理器之外，允许被授权程序注册自定义命令回调（`DCAI_Client::onCommand('my_action', $callback)`）。

**后台交互**：实例管理页可对单个实例或批量实例下发命令，命令中心可查看/重发/中止待执行命令。

### 6.5 模块五：弹窗管理（F5）

**弹窗模型**：

```
后台创建弹窗(标题/内容/类型/目标/时间窗/次数上限)
   ▼
被授权程序定期 /popup/list 拉取命中弹窗
   ▼
被授权程序在页面渲染弹窗（SDK 提供 JS/HTML 注入与 CSS 样式）
   ▼
展示后 /popup/report 上报，后台可统计展示次数
```

**弹窗字段**：

| 字段 | 说明 |
|---|---|
| 类型 | 公告 / 通知 / 警示（不同配色与图标） |
| 目标范围 | 全部实例 / 指定实例列表 |
| 时间窗 | 开始时间、结束时间，空则长期有效 |
| 展示上限 | 每实例最大展示次数，0 表示不限 |
| 内容 | 支持 HTML，前端做 XSS 过滤 |

### 6.6 模块六：远程更新（F6）

**更新包规范**：zip 压缩包，顶层须包含 `update.json`：

```json
{
  "version": "1.1.0",
  "min_version": "1.0.0",
  "changelog": "修复若干Bug",
  "files": ["app/", "config/sample.php"],
  "delete_files": ["app/old_module.php"],
  "migrate": "upgrade_1.1.0.php"
}
```

- `files`：需要替换/新增的路径清单（白名单校验，禁止覆盖除程序目录外的路径）；
- `delete_files`：需要删除的旧文件；
- `migrate`：可选迁移脚本（版本升级时执行，成功后脚本自删除）。

**更新流程**：

```
后台上传更新包(解析update.json，记录md5/size)
   ▼ 发布(updates.status=1)
被授权程序 /update/check 检测到新版本
   ▼
可选更新：后台下发 update 命令 / 心跳提示
强制更新：SDK 在 verify 或心跳响应中获知 is_force=1，未更新则拒绝启动
   ▼
下载更新包(/update/download) → 校验 md5 → 备份原文件 → 解压替换 → 执行 migrate → 清缓存
   ▼
/update/report 上报结果 → update_apply_logs + 实例 version 更新
失败：自动回滚备份，上报失败原因
```

### 6.7 模块七：在线添加新产品（F7）

**安装包规范**：zip 压缩包，顶层须包含 `manifest.json`：

```json
{
  "product_code": "shop_v2",
  "product_name": "商城系统 V2",
  "version": "1.0.0",
  "entry": "index.php",
  "description": "多商户商城系统",
  "requires": {"php": "8.0", "extensions": ["pdo_mysql", "curl", "openssl"]},
  "install_script": "install/install.php"
}
```

**流程**：

```
后台 packages.php 上传安装包
   ▼
解析 manifest.json 校验合法性
   ▼
校验 zip 安全性（防目录穿越、可执行文件白名单）
   ▼
自动创建 products 记录（product_code 冲突则报错提示更新版本）
   ▼
生成安装包记录与下载链接，产品进入可签发授权码流程
```

**分发**：客户下载安装包 → 部署 → 输入授权码 → SDK 注册实例进入授权管控体系。

### 6.8 模块八：远程模块托管与调用（F8）+ 模块九：远程模块后台管理（F9）

**模块类型**：

| module_type | 说明 | 适用场景 |
|---|---|---|
| 1 PHP 代码型 | 模块逻辑为 PHP 代码，托管在授权系统，沙箱执行 | 需要服务端密钥/算法/集中逻辑的功能 |
| 2 HTTP 转发型 | 请求转发至授权系统内部或授权方指定服务 | 对接第三方服务、集中代理 |
| 3 数据查询型 | 执行预定义 SELECT 模板并返回结果 | 授权系统侧数据统计、白名单查询 |

**调用链路**：

```
被授权程序 SDK::callModule('order_stat', params)
   ▼ POST /module/invoke (实例令牌签名)
授权系统：鉴权 → 模块启用与归属校验 → 参数校验 → 执行(沙箱/转发/查询) → 记日志
   ▼
返回 {result}，SDK 解析后继续业务
```

**后台管理（F9）**：

| 能力 | 说明 |
|---|---|
| 模块 CRUD | 创建、编辑（代码/转发地址/查询模板）、删除（有关联日志时软禁用） |
| 启停控制 | `status` 开关，停用后调用返回 3001 |
| 参数校验 | `params_schema`（JSON Schema 子集）在线编辑 |
| 版本管理 | 模块版本号，修改后版本 +1，调用方可在 `/module/list` 感知 |
| 调用日志 | 按模块/实例/时间段检索，查看参数、结果、耗时、错误 |
| 归属限制 | 模块绑定产品，仅该产品实例可调用 |
| 安全策略 | PHP 代码型模块启用"危险函数禁用清单 + 执行时间/内存上限 + 输出缓冲 + 结果大小限制" |

**PHP 代码型模块执行沙箱约束**：

- 禁用函数（`exec`、`shell_exec`、`system`、`passthru`、`proc_open`、`popen`、`eval` 嵌套、`file_put_contents` 到系统目录、`unlink` 等）；
- `max_execution_time` ≤ 5s，`memory_limit` ≤ 128M；
- 模块内仅可访问注入的白名单对象（`$db`、`$params`、`$logger`）；
- 返回值仅允许标量 / 数组 / 可 JSON 序列化对象，序列化后返回。

---

## 7. 客户端 SDK 设计

### 7.1 SDK 文件构成

```
sdk/
├── dcai_client.php            # SDK 主类
├── dcai_config.sample.php     # 配置样例（复制为 dcai_config.php）
├── dcai_cache.php             # 本地缓存（授权令牌/实例凭证）
├── dcai_updater.php           # 更新执行器（下载/解压/回滚）
├── dcai_guard.php             # 集成辅助：全局守卫与钩子
└── dcai_client.py             # Python SDK（V1.3 新增，见第 17 章）
```

### 7.2 核心类与主要方法

```php
final class DCAI_Client
{
    // 初始化：读取 dcai_config.php
    public function __construct();

    // ① 三合一授权验证（含本地缓存与 fail_open 策略）
    public function verify(): bool;
    // 获取当前授权信息（产品/授权码/到期时间/验证时间）
    public function getVerifiedInfo(): ?array;

    // ② 实例注册（首次调用自动完成，凭证缓存到本地）
    public function registerInstance(): array;
    // ③ 心跳上报（返回 revoked / new_version / pending_commands）
    public function heartbeat(): array;

    // ④ 命令轮询与执行（内置处理器 + 自定义回调）
    public function pollCommands(): array;
    public function onCommand(string $type, callable $handler): void;

    // ⑤ 弹窗
    public function getPopups(): array;
    public function reportPopupShown(int $popupId): bool;

    // ⑥ 更新
    public function checkUpdate(): ?array;
    public function applyUpdate(array $update): bool;

    // ⑧ 远程模块
    public function callModule(string $moduleCode, array $params): mixed;

    // ⑨ V1.3：机器码（硬件指纹）采集与上报
    public function currentMachineCode(): string;   // 机器指纹 HMAC 摘要（与 MachineService 算法一致）
    public function machineFingerprint(): string;   // 原始指纹（主板序列号+CPU+磁盘 组合摘要）
    public function machineName(): string;          // hostname

    // ⑩ V1.3：离线激活（断网场景）
    public function createOfflineRequest(): array;  // 生成激活请求（含机器码）
    public function applyOfflineActivation(string $fileJson): bool; // 导入激活文件并校验
    public function hasOfflineActivation(): bool;   // 本地是否存在有效离线授权
    public function getOfflineActivation(): ?array;
    public function lastOfflineError(): string;

    // ⑪ V1.3：试用状态
    public function getTrialStatus(): ?array;       // is_trial/trial_days/trial_expire_at/trial_remaining_days

    // 统一周期任务入口（建议配合定时器或框架钩子调用）
    public function runHooks(): void;
}
```

**V1.3 验证决策顺序（叠加离线授权）**：

```
0. 若本地存在有效离线激活文件（未过期且 RSA 验签通过）→ 直接判定已授权（全程无网络）；
1. 本地存在未过期验证令牌，且 RSA 公钥验签通过 → 已授权（不发请求）；
2. 令牌过期或缺失 → 调用 /auth/verify；
   - 成功：更新本地令牌；
   - 网络异常/超时：
     · 产品 fail_open=1 → 放行并写告警日志；
     · 产品 fail_open=0 → 拒绝；
3. 接口返回"禁用/过期/吊销" → 拒绝运行。
```

### 7.3 SDK 配置样例

```php
// dcai_config.php
return [
    'server_url'    => 'https://auth.example.com/api/v1/',  // 授权系统地址
    'product_code'  => 'shop_v2',                            // 产品编码
    'app_secret'    => 'sdk内置签名密钥',                    // 首次验证签名用
    'license_key'   => 'DCAI-XXXX-XXXX-XXXX-XXXX',           // 客户授权码
    'enabled'       => true,                                 // 授权开关(false=默认放行)
    'cache_dir'     => __DIR__ . '/cache/',                  // 本地缓存目录
    'rsa_public_key'=> '-----BEGIN PUBLIC KEY-----...',      // 验证令牌验签公钥
    'http_timeout'  => 5,                                    // 请求超时(秒)
];
```

### 7.4 集成方式

在被授权程序入口（或引导文件）加入：

```php
require __DIR__ . '/sdk/dcai_client.php';
$dcai = new DCAI_Client();

// 守卫：未授权则引导到授权页（开启授权管控后生效）
$dcai->guard(function () {
    http_response_code(403);
    exit('程序未授权，请联系授权方获取有效授权码');
});

// 可选：挂接框架钩子，周期执行心跳/命令/弹窗/更新
register_shutdown_function(function () use ($dcai) {
    $dcai->runHooks();
});
```

### 7.5 本地缓存与离线策略

| 缓存项 | 内容 | 有效期 |
|---|---|---|
| 授权令牌 | verification_token + expire_at | 服务端签发（`products.verify_ttl`，默认 3600s） |
| 实例凭证 | instance_id + instance_token | 长期（本地文件） |

**验证决策顺序**：见 7.2 的「V1.3 验证决策顺序（叠加离线授权）」，初始化阶段增加离线激活文件优先判定。

---

## 8. 后台管理端设计

### 8.1 页面与功能清单

| 页面 | 功能 |
|---|---|
| 登录页 `login.php` | 账号密码登录（bcrypt 校验）、登录失败锁定、可选 Google 两步验证 |
| 仪表盘 `dashboard.php` | 产品数、授权码数、实例在线/总数、今日验证成功/失败数、待执行命令数、最近告警 |
| 产品管理 `products.php` | 产品 CRUD、启停"授权管控"开关、fail_open 配置、查看安装包/更新包入口 |
| 安装包管理 `packages.php` | 上传安装包、解析 manifest、下载链接、删除/停用 |
| 授权码管理 `licenses.php` | 生成授权码（绑定产品/客户/域名/IP/实例数/有效期）、编辑、启停、删除、导出 CSV |
| 实例管理 `instances.php` | **搭建现状列表**（域名/IP/版本/环境/最后心跳/状态徽标）、详情、下发命令、启用/禁用、强制离线 |
| 命令中心 `commands.php` | 命令队列、历史记录、按实例/类型/状态检索、重发、中止 |
| 弹窗管理 `popups.php` | 弹窗 CRUD、实时预览、目标与时间窗设置、展示统计 |
| 更新管理 `updates.php` | 上传更新包、解析 update.json、发布/撤回、历史记录 |
| 远程模块 `modules.php` | 模块 CRUD、代码/转发/查询模板编辑、启停、参数校验规则、调用日志、安全策略配置 |
| 日志管理 `logs.php` | 验证日志、操作日志、模块调用日志、SDK 告警日志（分页/检索/导出） |
| 系统设置 `settings.php` | 站点名称、验证令牌 TTL、心跳阈值、限流阈值、RSA 密钥轮换、密钥管理（app_secret 查看/重置） |
| 管理员管理 | 后台账号 CRUD、角色、状态 |
| 机器码管理 `machines.php`（V1.3） | 按授权码查看/解绑已绑定机器（机器码脱敏、首末次绑定时间、名称） |
| 离线激活 `offline_activate.php`（V1.3） | 导入激活请求文件 → 校验 → 签发激活文件（可下载/复制）；激活记录列表、作废、按期检索 |

### 8.2 权限模型

- 超级管理员：全部权限；
- 普通管理员：可配置细分权限（如仅可查看、仅可管理弹窗等），权限点：产品、安装包、授权码、实例、命令、弹窗、更新、模块、日志、设置、管理员；
- 所有写操作写入 `operation_logs`。

### 8.3 关键交互细节

- 实例列表默认按"最后心跳"倒序，支持按产品/授权码/域名/IP/状态筛选；
- 下发命令前二次确认；`disable` 命令弹强警示框；
- 弹窗编辑提供移动端/桌面端预览；
- 授权码生成支持批量（一次生成 N 个）；
- 所有列表统一分页 + 导出（CSV）。

---

## 9. 安全设计

| 类别 | 措施 |
|---|---|
| 传输安全 | 全站强制 HTTPS；API 拒绝非 TLS 请求 |
| 接口鉴权 | 实例令牌 + HMAC-SHA256 签名；时间戳（±300s）+ nonce 防重放 |
| 授权防伪 | 验证令牌由 RSA 私钥签名，SDK 内嵌公钥验签；关键数据（instance_token）AES-256 加密存储 |
| 注入防护 | 全部 SQL 使用 PDO 预处理参数绑定 |
| 认证安全 | 后台密码 bcrypt；登录失败 5 次锁定 15 分钟；会话 Cookie 加 HttpOnly + Secure + SameSite；CSRF Token 校验 |
| 后台 XSS | 输出编码；弹窗/模块内容渲染前过滤（HTMLPurifier 或白名单过滤器） |
| 上传安全 | 仅允许 zip；校验文件类型/大小；解压时防目录穿越（`../`、绝对路径）；安装包内禁止上传可执行二进制外文件（白名单：php/js/css/html/md/json）；`storage/` 置于 Web 根外 |
| 沙箱执行 | 远程模块 PHP 代码沙箱：危险函数禁用、执行时间/内存/输出大小上限、降权运行 |
| 限流 | `/auth/verify` 按 IP+授权码 维度限流（默认 30 次/分钟）；全部 API 按实例维度限流 |
| 日志审计 | 验证日志、操作日志、模块调用日志、SDK 告警日志全覆盖 |
| 密钥管理 | RSA 私钥、app_secret、数据库口令仅存于 `config/` 且不入版本库；后台提供密钥轮换能力 |
| 防暴力破解 | 授权码枚举防护（限流 + 失败日志告警） |
| 备份 | 数据库每日备份，更新包/安装包文件定期归档 |

---

## 10. 部署方案

### 10.1 授权系统部署

| 项 | 要求 |
|---|---|
| 操作系统 | Linux（CentOS 7+ / Ubuntu 20.04+） |
| PHP | 8.2+，扩展：pdo_mysql、openssl、curl、mbstring、fileinfo、json、zip、bcmath |
| Web 服务器 | Nginx（推荐）或 Apache，强制 HTTPS |
| 数据库 | MySQL 5.6（InnoDB，utf8mb4），独立账号最小权限 |
| 目录权限 | `storage/` 可写；`config/config.php` 权限 600；Web 根指向 `public/` |

Nginx 关键配置示意：

```nginx
server {
    listen 443 ssl;
    server_name auth.example.com;
    root /var/www/DCAI授权系统/public;
    index index.php;

    # API 与后台统一入口
    location /api/ { try_files $uri /index.php?$query_string; }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

### 10.2 安装向导

`install/` 目录提供 Web 安装向导：环境检测（PHP 版本/扩展）→ 数据库配置 → 导入 `schema.sql` → 创建超级管理员 → 生成 RSA 密钥对与 app_secret → 写入 `config.php` → 提示删除 `install/`。

### 10.3 被授权程序集成

1. 安装包内含 SDK（`sdk/`）与 `dcai_config.sample.php`；
2. 客户部署时填写授权码，开启授权开关后即纳入授权管控；
3. 升级 SDK 随产品更新包分发。

---

## 11. 开发计划

| 里程碑 | 内容 | 交付物 |
|---|---|---|
| M1 项目骨架 | 目录结构、`config.php`、PDO 封装、统一响应、安装向导、后台登录与管理员管理 | 可登录的后台框架 |
| M2 产品与授权码 | 产品管理、安装包上传解析（F7）、授权码签发/绑定/启停 | 后台可用 |
| M3 三合一验证 | verify 接口、RSA 令牌签发、SDK `verify()`、双重开关与默认放行（F1、F2） | 验证闭环 |
| M4 实例与心跳 | 实例注册、心跳、搭建现状列表、离线判定（F3） | 现状可视 |
| M5 远程管理与弹窗 | 命令队列、SDK 命令执行器、弹窗管理与展示上报（F4、F5） | 远程可控 |
| M6 远程更新 | 更新包上传发布、检测/下载/应用/回滚（F6） | 更新闭环 |
| M7 远程模块 | 模块 CRUD 后台（F9）、三类模块执行器与调用日志（F8） | 模块闭环 |
| M8 安全与日志 | 限流、沙箱加固、日志中心、密钥轮换、安全审计 | 安全加固 |
| M9 联调上线 | 全链路联调、性能压测（verify 接口）、部署文档、验收 | 上线交付 |

---

## 12. 附录

### 12.1 授权码生成规则

```
格式：DCAI-XXXXX-XXXXX-XXXXX-XXXXX
字符集：大写字母 A-Z（去 I、O）+ 数字 2-9，共 32 字符集（base32 子集）
随机源：random_bytes()，密码学安全
```

### 12.2 config.php 关键配置项

```php
return [
    'db' => [
        'host' => '127.0.0.1', 'port' => 3306,
        'name' => 'dcai_auth', 'user' => 'dcai',
        'pass' => '***', 'charset' => 'utf8mb4',
    ],
    'app' => [
        'base_url' => 'https://auth.example.com',
        'timezone' => 'Asia/Shanghai',
        'debug' => false,
        'version' => '1.3.1',           // 系统版本号（升级服务版本门禁依据，须高于线上当前版本才可应用）
    ],
    'security' => [
        'rsa_private_key' => '-----BEGIN PRIVATE KEY-----...',
        'rsa_public_key'  => '-----BEGIN PUBLIC KEY-----...',
        'aes_key'         => '32字节随机密钥',   // instance_token 加密
        'verify_ttl'      => 3600,              // 授权令牌缓存秒数
        'heartbeat_threshold' => 180,           // 离线判定阈值(秒)
        'rate_limit'      => ['verify' => 30, 'api' => 120],
    ],
    'storage' => [
        'path' => __DIR__ . '/../storage',
        'max_package_size' => 1024 * 1024 * 200, // 200MB
    ],
    'update_source' => [
        // 远程升级源（GitHub 在线更新，V1.3.1）
        'enabled'    => 0,    // 1=开启远程更新检查
        'manifest'   => '',   // 远程 manifest.json URL（如 https://gcore.jsdelivr.net/gh/OWNER/REPO@main/release/manifest.json）
        'auth_token' => '',   // 私有仓库 Bearer Token（公开仓库留空）
        'timeout'    => 15,   // 请求超时(秒)
    ],
];
```

### 12.3 manifest.json 与 update.json 示例

见 6.6 与 6.7 节规范。

### 12.4 远程模块 PHP 代码型示例

```php
// module_code: order_stat，params: {start_date, end_date}
$rows = $db->queryAll(
    'SELECT DATE(created_at) d, COUNT(*) c FROM orders
     WHERE created_at BETWEEN ? AND ? GROUP BY d',
    [$params['start_date'] . ' 00:00:00', $params['end_date'] . ' 23:59:59']
);
return ['total' => array_sum(array_column($rows, 'c')), 'days' => $rows];
```

### 12.5 设计决策记录

| 编号 | 决策 | 理由 |
|---|---|---|
| D1 | 三合一验证以授权码为中心，域名与 IP 挂载在授权码下 | 满足"三者同时满足"要求，且授权码可携带多域名/多IP |
| D2 | F2 实现为"产品级开关 + SDK 本地开关 + fail_open"三层 | 区分"未开启管控"与"网络异常"，行为可控可审计 |
| D3 | 客户端通信以"轮询"为主（心跳/命令/弹窗） | 兼容客户服务器防火墙、内网环境，无需开放入站端口 |
| D4 | 验证令牌采用 RSA 签名、SDK 本地验签缓存 | 减少对授权系统可用性依赖，支持离线期正常使用 |
| D5 | 远程模块支持三类执行方式并强制沙箱 | 平衡"远程调用"能力与安全风险 |
| D6 | 被授权程序 SDK 以 PHP 为主，API 保持语言无关 REST | 服务 PHP 客户为主，同时可扩展其他语言客户端 |
| D7 | 技能（Skill）采用"技能包=一组函数"体系，核心逻辑托管授权系统 | 被授权站点仅拿"壳"，远程调用函数，拿不到核心实现 |
| D8 | 技能授权模型分"产品级"与"按授权码授予"两档 | access_model=0 全实例可用；access_model=1 走 skill_grants 逐码授权，粒度可控 |
| D9 | SDK IP 采集默认只信 REMOTE_ADDR，仅显式配置 proxy_headers 时信任转发头 | 阻断被授权站点恶意注入 X-Forwarded-For 绕过 IP 白名单 |
| D10 | 机器码 = SHA256(硬件指纹) 后再做 HMAC-SHA256(app_secret) 双层摘要 | 客户机上报的 64 位摘要即使泄露也无法反推指纹、不能用于其他授权码（V1.3） |
| D11 | 离线激活文件采用 RSA-SHA256 签名 + 有效期，激活记录落库可作废 | 断网场景可离线验真，服务端可随时吊销，防伪造/防重放（V1.3） |
| D12 | 产品/授权码双轨试用参数（products.trial_enabled/trial_days + licenses.trial_days），试用授权 source=3 | 自助申请入口在产品侧开放，授权码记录独立试用周期，来源可追踪（V1.3） |
| D13 | Sandbox 双模式：优先子进程隔离（proc_open），不可用时降级进程内命名空间沙箱 + tick 超时 | 兼容宝塔面板 disable_functions 禁用 proc_open 的常见部署，保证远程模块功能可用（V1.3） |
| D14 | 全站视觉统一为单一主题色 + Apple 流体动效（弹簧曲线变量、按钮 :active 缩放、弹窗入场动画），`prefers-reduced-motion` 无条件降级 | 后台/商城一体品牌感，动效克制不干扰操作，同时满足无障碍（V1.3.1） |
| D15 | 升级包发布后自动向低版本在线实例下发 update 命令（`DCAI_CommandService::issue`） | 云端实例无需主动轮询升级，发布即推送提示，缩短升级周期（V1.3.1） |
| D16 | 远程更新 manifest 托管 GitHub，url 直链优先 gcore.jsdelivr.net | 国内网络实测 raw.githubusercontent.com / cdn.jsdelivr.net 不可达，gcore 镜像稳定可达（V1.3.1） |

## 13. Skill 技能管理模块

**目标**：实现"授权系统为核心、被授权系统为框架（壳）"。核心逻辑/数据查询/转发统一托管在授权系统，被授权站点通过 SDK 远程调用，改造费用与核心代码零泄露。

### 13.1 数据表（migrate_v1.2.php 提供）

| 表 | 用途 | 关键字段 |
|---|---|---|
| `skills` | 技能包 | skill_code(唯一)、product_id、access_model(0/1)、status、sort_order |
| `skill_functions` | 技能函数 | skill_id、function_code(技能内唯一)、func_type(1代码/2HTTP/3SQL)、code/upstream_url/sql_template、params_schema |
| `skill_grants` | 按授权码授予关系 | skill_id、license_id、status |
| `skill_invoke_logs` | 技能调用日志 | skill_id、function_id、license_id、instance_id、params/result、status、cost_ms |

### 13.2 调用链路

```
被授权站点(壳) --SDK callSkill/getSkills--> /api/v1/skill/* (签名鉴权+实例校验)
    --> SkillService::invoke (技能授权判定 + 函数查询 + 参数校验)
    --> 三类执行器(沙箱/HTTP转发/SELECT查询，复用 ModuleService) --> 落 skill_invoke_logs
```

### 13.3 后台

`public/admin/skills.php`（权限点 `skill`）：技能 CRUD、函数管理（分类面板展开）、按码技能授权（skill_grants）、调用日志（logs.php?type=skill）。

### 13.4 SDK 兜底

- `getSkills()`：未启用/未注册/网络异常时返回空数组，不抛异常（壳可静默降级）。
- `callSkill()`：抛异常交由使用方 `try/catch` 降级到本地兜底逻辑。

### 13.5 测试

- `php install/migrate_v1.2.php` → `php tests/seed.php` → `php tests/skill_test.php`（11 项断言：可见性脱敏、执行、参数校验、按码授权授予/撤销、日志落库）。

---

## 14. 机器码/硬件指纹绑定

**目标**：防止一个授权码在多台服务器上共用（授权码泄露后任意部署）。通过采集客户机硬件指纹并在服务端绑定，限制单个授权码可绑定的机器数量。

### 14.1 机器码算法

```
机器码 = HMAC-SHA256( app_secret, SHA256(硬件指纹) )
```

- 硬件指纹 = `主板序列号 + CPU 标识 + 磁盘序列号` 拼接后的 SHA256 摘要（`DCAI_Client::machineFingerprint()`）；
- 机器码为 64 位小写十六进制，客户端与服务端算法完全一致（`sdk/dcai_client.php` 与 `service/MachineService.php` 各自实现，逻辑等价）；
- 双层摘要设计：上报给服务端的 64 位机器码即使被中间人截获，也无法反推硬件指纹，更无法用于其他授权码（app_secret 不同则机器码不同）。

### 14.2 绑定规则

| 场景 | 行为 |
|---|---|
| `licenses.machine_limit = 0` | 不限制机器数（默认兼容旧行为） |
| `machine_limit > 0` 且未达上限 | `/auth/verify` 或 `/instance/register` 上报机器码时**自动绑定**，写 `machine_bindings` |
| `machine_limit > 0` 且已达上限 | 返回 `2012 绑定机器数已达上限`，拒绝授权 |
| 未上报机器码（如纯 Web 场景） | 不强制绑定，放行 |

### 14.3 数据流

```
被授权程序 --SDK verify()/registerInstance() 上报 machine_code + machine_name-->
    VerifyService::validateMachine()/MachineService::checkForRegister()
        --> 已绑定? 放行 / 未达上限? 自动绑定 / 达上限? 2012
后台 machines.php --> 查看授权码绑定机器列表、解绑（释放配额）
```

### 14.4 后台管理

`public/admin/machines.php`（权限点 `machine`）：

- 按授权码检索其绑定机器列表（机器码脱敏显示，如 `a3f5****e9c1`）；
- 展示机器名称、首次绑定时间、最后上报时间；
- 支持解绑（软删除 `status=0`，解绑后释放机器配额，该机器需重新走绑定流程）。

---

## 15. 试用模式（Trial）

**目标**：让潜在客户先体验后购买。产品开放自助试用后，买家登录商店可一键申请，自动生成带试用期的授权码。

### 15.1 参数模型（双轨）

| 层级 | 字段 | 含义 |
|---|---|---|
| 产品级 | `products.trial_enabled` | 1=开放自助申请试用 |
| 产品级 | `products.trial_days` | 产品默认试用天数 |
| 授权码级 | `licenses.trial_days` | 该授权码试用天数（0=正式授权） |
| 授权码级 | `licenses.source = 3` | 标记该授权码为"自助试用"来源 |

### 15.2 申请流程（`public/shop/trial.php`）

```
买家登录 → 选择开放试用的产品 → 点击「申请试用」-->
StoreService::applyTrial() 校验：
    1. 产品 trial_enabled=1 且 trial_days>0；
    2. 同一买家同一产品仅可申请一次（防刷）；
    3. 生成试用授权码：trial_days=N，到期 = 当前时间 + N 天，source=3
    --> 跳转「我的授权」查看新授权码
```

### 15.3 验证行为

- 试用授权码在试用期内**视同正式授权**参与三合一验证（`LicenseService::validate` 放行）；
- `VerifyService::trialStatus()` 返回试用状态：`is_trial / trial_days / trial_expire_at / trial_remaining_days`；
- 试用期结束后按正式授权码过期处理（`2003 授权码已过期`）；
- SDK 提供 `getTrialStatus()` 供被授权程序感知试用剩余天数（可用于展示"距试用到期 N 天"提示）。

---

## 16. 离线激活

**目标**：支持内网/断网环境（客户机无法访问授权系统）的授权验证。管理员签发 RSA 签名的"激活文件"，客户机导入后本地验签，全程无需联网。

### 16.1 激活文件格式（JSON + RSA-SHA256 签名）

```json
{
  "version": 1,
  "type": "offline_activation",
  "request_id": "uuid",
  "product_code": "shop_v2",
  "license_key_masked": "DCAI-XXXX-****-****-XXXX",
  "license_id": 1,
  "machine_code": "a3f5...e9c1",
  "machine_name": "web-01",
  "expire_at": "2027-01-01 00:00:00",
  "issued_at": "2026-09-01 10:00:00",
  "signature": "base64(RSA-SHA256)"
}
```

### 16.2 两种签发途径（产物完全一致）

| 途径 | 适用场景 | 流程 |
|---|---|---|
| **后台离线签发**（推荐） | 完全断网 | 客户机 SDK 生成请求文件 → 管理员在 `offline_activate.php` 导入 → 校验授权码/机器码 → 签发激活文件 → 交回客户机导入 |
| **API 在线签发** `POST /offline/request` | 客户机可联网但需长期离线运行 | 客户端提交 `product_code/license_key/machine_code` → 服务端校验后直接返回激活文件（等同后台签发产物） |

### 16.3 验证流程（`DCAI_OfflineActivationService::verifyActivationFile`）

```
1. RSA-SHA256 验签（服务端公钥，签名不匹配 → 5001）；
2. 授权码存在且未禁用；
3. expire_at 未过期（空=永久有效，随授权码）；
通过 → SDK 写入本地缓存 offline_activation，verify() 判定已授权（全程无网络）。
```

### 16.4 SDK 行为（PHP + Python 一致）

- `createOfflineRequest()`：生成请求 JSON（含机器码、request_id）；
- `applyOfflineActivation(file_json)`：本地校验签名+到期 → 写入缓存；
- `verify()`：**优先检查本地离线激活**（有效则直接授权，且跳过在线验证），网络不可用时兜底离线授权；
- `lastOfflineError()`：导入失败时获取原因。

### 16.5 后台管理（`public/admin/offline_activate.php`）

- 导入激活请求文件（粘贴 JSON 或上传）；
- 签发激活文件（可下载 `.json` / 一键复制），自动落库 `offline_activations`；
- 激活记录列表（按授权码/机器码/状态检索）、作废（`status=0`，作废后客户端验签仍有效但下次校验服务端可拒绝）。

---

## 17. Python SDK

**目标**：为 Python 编写的被授权程序提供与 PHP SDK 完全对等的能力。纯标准库实现（`urllib`/`hashlib`/`hmac`/`json`），零第三方依赖，可选 `cryptography` 用于 RSA 验签。

### 17.1 文件构成

```
sdk/dcai_client.py              # Python SDK 主类（约 700 行）
sdk/dcai_config.sample.py       # 配置样例
```

### 17.2 能力清单

| 方法 | 对应 PHP SDK | 说明 |
|---|---|---|
| `verify()` | `verify()` | 三合一验证 + 本地令牌缓存 + 离线激活优先 |
| `verify_token(token)` | `verify()` 内部 | RSA 验签验证令牌 |
| `register_instance()` | `registerInstance()` | 实例注册（含机器码上报） |
| `heartbeat()` | `heartbeat()` | 心跳上报 |
| `poll_commands()` / `on_command()` | `pollCommands()` / `onCommand()` | 命令轮询与回调 |
| `get_popups()` / `report_popup_shown()` | `getPopups()` / `reportPopupShown()` | 弹窗 |
| `check_update()` / `apply_update()` | `checkUpdate()` / `applyUpdate()` | 更新检测/应用 |
| `call_module()` | `callModule()` | 远程模块调用 |
| `get_skills()` / `call_skill()` | `getSkills()` / `callSkill()` | 技能（V1.2） |
| `create_offline_request()` | `createOfflineRequest()` | 离线激活请求 |
| `apply_offline_activation()` | `applyOfflineActivation()` | 导入激活文件 |
| `get_trial_status()` / `get_machine_status()` | `getTrialStatus()` | 试用/机器码状态 |
| `guard()` / `run_hooks()` | `guard()` / `runHooks()` | 守卫与周期任务 |

### 17.3 请求签名（与 PHP SDK 完全一致）

```
sign = HMAC-SHA256( secret, timestamp + "\n" + nonce + "\n" + sha256(body) )
X-Timestamp / X-Nonce / X-Sign 请求头
```

### 17.4 使用示例

```python
from dcai_client import DCAIClient, DCAICache

cache = DCAICache('/path/to/cache_dir')
client = DCAIClient({'server_url': 'https://auth.example.com/api/v1/',
                     'product_code': 'shop_v2',
                     'app_secret': '...',
                     'license_key': 'DCAI-XXXX-XXXX-XXXX-XXXX',
                     'rsa_public_key': '...'}, cache)

if not client.verify():
    raise SystemExit('程序未授权')

# 断网场景：生成离线激活请求
req = client.create_offline_request()      # 交给管理员签发
client.apply_offline_activation(file_json) # 导入激活文件
```

---

## 18. 沙箱降级模式

**背景**：宝塔面板等常见部署环境默认在 `php.ini` 的 `disable_functions` 中禁用了 `proc_open`/`exec`/`popen` 等进程函数，导致远程模块（PHP 代码型）无法在子进程沙箱中执行。

**方案**：`core/Sandbox.php` 双模式自动降级，调用方（`ModuleService`/`SkillService`）与 API 响应结构完全无感。

### 18.1 双模式执行

| 模式 | 触发条件 | 隔离手段 |
|---|---|---|
| **子进程模式** `runInSubprocess()` | `proc_open` 可用（默认） | 独立 PHP 子进程 + 命名空间 + 危险函数 stub + 输出缓冲 |
| **进程内模式** `runInProcess()` | `proc_open` 不可用（宝塔默认） | 同进程命名空间隔离 + 危险函数 stub + `declare(ticks=1)` 超时中断 |

### 18.2 进程内模式安全设计

- **命名空间隔离**：模块代码包裹在随机命名空间 `DCAI_Sandbox_Local_xxx` 中，无法触碰全局作用域类/函数；
- **危险函数 stub**：`exec`/`system`/`shell_exec`/`passthru`/`proc_open`/`popen`/`eval`/`file_put_contents`(系统路径)/`unlink` 等被同名 stub 替换，调用即抛异常或返回空；
- **白名单注入**：仅注入 `$db`（只允许 SELECT 查询）、`$params`、`$logger`；
- **tick 超时中断**：`declare(ticks=1)` + `register_tick_function` 检查运行时长，超过阈值抛 `DCAI_SANDBOX_TIMEOUT`，框架捕获后返回 `5000 模块执行超时`；
- **空循环兜底**：无语句空循环（`while(true){}`）不触发 tick，依赖 `set_time_limit` + 框架全局致命错误处理器兜底（返回 JSON 5000，不产生裸 fatal）；
- **返回值约束**：仅允许标量/数组/可 JSON 序列化对象，序列化后返回。

### 18.3 降级切换点

```php
// core/Sandbox.php::run()
if (function_exists('proc_open')) {
    $sub = self::runInSubprocess($code, $params);
    if ($sub[0] || strpos($sub[2], '无法启动沙箱子进程') === false) {
        return $sub;
    }
}
return self::runInProcess($code, $params); // proc_open 禁用时自动降级
```

### 18.4 验证

- `tests/sandbox_local_test.php`：9 项断言覆盖正常执行、危险函数拦截、非 SELECT 查询拦截、资源返回值拒绝、异常捕获、空循环超时；
- 宝塔环境实机验证：`module/invoke` 在 `proc_open` 被禁用时返回结果与子进程模式一致。

---

## 19. 全站 UI 体系优化（Apple 流体设计）

**目标**：后台/商城视觉统一、多屏自适应、交互反馈一致、无障碍友好。纯前端改动（CSS/JS/HTML 结构），无数据库变更。

### 19.1 设计语言

- **主题色统一**：后台与商城统一为 `--primary:#4f6ef7`（商城原 `#4f46e5` 已对齐）；
- **Apple 流体动效变量**（admin.css / shop.css 头部统一声明）：

```css
--spring-fast:  cubic-bezier(.32,.72,0,1);   /* 快速弹簧 */
--spring-smooth:cubic-bezier(.22,.61,.36,1); /* 平滑弹簧 */
--dur-fast: 180ms; --dur-mid: 260ms;
```

- 按钮 `:active{transform:scale(.97)}` 按压反馈；modal / 登录框入场 keyframe；`prefers-reduced-motion` 媒体查询将动画整体降级为淡入，满足无障碍。

### 19.2 统一背景图（4 张 SVG 软渐变）

| 文件 | 用途 |
|---|---|
| `public/assets/images/bg-admin.svg` | 后台桌面端 |
| `public/assets/images/bg-admin-mobile.svg` | 后台移动端（简化版，不干扰文字） |
| `public/assets/images/bg-shop.svg` | 商城桌面端 |
| `public/assets/images/bg-shop-mobile.svg` | 商城移动端（简化版） |

- `background-attachment:fixed` 固定背景；移动端 `@media` 自动切换简化背景。

### 19.3 导航重构（二级折叠目录）

- **后台** `public/admin/includes/header.php`：原 17 项平铺 → `$menuGroups` 6 主题分组（概览 / 产品与授权 / 实例运维 / 分发升级 / 商城运营 / 系统）；`$activeGroup` 自动展开当前项所在组；权限过滤 `$visibleGroups`；移动端 off-canvas 侧栏 + 遮罩；
- **商城** `public/shop/_init.php`：购物组（产品/订单/授权）+ 账户组（登录/注册），登录后切换为用户名组（授权/订单/试用/退出）；移动端 ☰ 抽屉。

### 19.4 回归发现的 3 个真实缺陷（已修复）

1. **服务端/客户端 hidden 状态不同步**：`header.php` 输出 HTML 属性 `hidden`，CSS/JS 操作的是 class `hidden` → 折叠永远失效。修复：服务端统一输出 `class="nav-group-body hidden"`；
2. **折叠链接可被 Tab 聚焦（a11y）**：`.hidden` 仅 `opacity:0` 仍可键盘聚焦不可见链接。修复：`visibility:hidden` + `transition:visibility 0s linear`（展开立即可见、收起动画结束后隐藏）；
3. **商城脚本 head 同步执行致事件未绑定**：`shop.js` 为同步 IIFE，在 `<head>` 执行时 `#shopMenuBtn`/`.nav-group` 尚不存在。修复：`<script defer>`。

### 19.5 验证

- 静态回归 `tests/ui_regression.py` 29/29（后台登录、19 后台页、6 商城页、商城注册、授权码搜索、CSV 导出、API 健康）；
- 真实浏览器（agent-browser）：后台 6 组导航初态仅概览展开 → 点击展开/收起状态机正确 → 5 个二级导航跳转 200 → 搜索过滤生效 → 生成授权码 modal 提交成功 → 商城注册/自动登录/错误密码提示 → 移动端 390x844 侧栏抽屉 → 背景图 computedStyle 确认加载；
- **经验**：① HTML `hidden` 属性 ≠ CSS `.hidden` 类，服务端渲染与客户端 JS 必须统一约定；② `<head>` 内同步脚本在 DOM 就绪前执行，交互失效先查 script 位置与 defer；③ `opacity:0` 隐藏仍可聚焦，折叠菜单需 `visibility` + 延迟 transition。

---

## 20. GitHub 在线更新

**目标**：升级包托管 GitHub，后台一键「检查远程更新 → 下载并发布 → 一键升级」，无需手动上传 zip。V1.3.1 起系统已内置完整机制，零代码改动即可启用。

### 20.1 原理链路（`service/SystemUpdateService.php`）

| 环节 | 方法 | 说明 |
|---|---|---|
| 检查更新 | `checkRemote()` | GET manifest.json；校验 `version > 当前版本` 且 `当前版本 >= min_version`；返回更新信息 |
| 下载发布 | `fetchRemote()` | 按 manifest `url` 下载 zip（可选 Bearer auth）→ 校验 MD5 → 存入 `storage/system_updates/` → 登记为「已发布」 |
| 应用升级 | `apply()` | 备份 → 白名单替换 → 迁移脚本 → 版本号写入 config → 失败自动回滚（保留最近 3 份备份） |

- 配置项 `update_source`（`config/config.php` 默认值，后台「系统升级」页保存后写 `settings` 表 `update_source_*`，启动时由 `core/Bootstrap.php` 合并覆盖）；
- manifest 字段（与 `checkRemote()` 期望完全一致）：`version / min_version / changelog / url / md5 / size / release_at`；
- 校验规则：`version` 合法且高于当前版本；`min_version` 非空时当前版本须 >= 它；`md5` 下载后强校验。

### 20.2 manifest 生成（`tests/build_manifest.php`）

- 自动读取 `config.php app.version` + 升级包内 `system.json` + 计算 MD5/字节大小，生成两个文件：

| 输出 | 指向 | 适用 |
|---|---|---|
| `release/manifest.json` | `dcai_sysupdate_v{version}_full.zip`（min_version=1.0.0） | 任意旧版本可升级（推荐后台填此） |
| `release/manifest_incr.json` | `dcai_sysupdate_v{version}.zip`（min_version=上一版） | 仅上一版可升级（可选） |

- 脚本顶部可配 `$owner / $repo / $branch / $baseUrl`（默认 `https://gcore.jsdelivr.net/gh/{owner}/{repo}@{branch}`）。

### 20.3 国内网络直链选型（实测结论）

| 直链 | 实测结果 |
|---|---|
| `raw.githubusercontent.com` | ❌ 超时不可达 |
| `cdn.jsdelivr.net` | ⚠️ 跟随重定向后超时 |
| **`gcore.jsdelivr.net`**（推荐） | ✅ HTTP 200（约 1.4s） |
| `gh-proxy.com` | ✅ 可用（第三方代理，稳定性依赖其服务） |
| `github.com` / `api.github.com` | ✅ 可用（但 zip 下载会 302 到 objects.githubusercontent.com，实测超时） |

> jsDelivr 有缓存：首次推送文件后 1~5 分钟生效，先 `curl -sI` 验证 200 再配置后台。

### 20.4 后台配置步骤

1. 后台 → 系统 → 系统升级 → 「远程升级源配置」卡片；
2. 启用远程更新=开启；manifest 地址填 `https://gcore.jsdelivr.net/gh/{用户}/{仓库}@main/release/manifest.json`；超时默认 15；私有仓库填 Token（`repo` 权限），公开仓库留空；
3. 保存配置 → 点「🔄 检查远程更新」：有新版显示橙色卡片 → 「⬇ 下载并发布此版本」（校验 MD5 后登记已发布）→ 列表中点「一键升级」（自动备份/替换/迁移/回滚）。

### 20.5 发布新版本完整流程

```bash
# 1. 改代码 + 提升版本号（config/config.php app.version），有 DB 变更则更新 migrate 脚本
# 2. 打包（自动读版本号）
php tests/build_sysupdate.php     # 增量包
php tests/build_fullupdate.php    # 全量包
# 3. 生成 manifest（先改 tests/build_manifest.php 顶部 $owner/$repo）
php tests/build_manifest.php
# 4. 推送 GitHub
git add release/ && git commit -m "release vX.Y.Z" && git push
# 5. 验证直链（jsDelivr 需等缓存预热）
curl -sI https://gcore.jsdelivr.net/gh/{user}/{repo}@main/release/dcai_sysupdate_vX.Y.Z_full.zip | head -1
# 6. 线上后台 → 检查远程更新 → 下载并发布 → 一键升级
```

### 20.6 升级包规范（打包脚本已固化，勿破坏）

- 升级包顶层必须含 `system.json`（`version/min_version/changelog/files/delete_files/migrate`）；
- **版本门禁**：`version` 必须高于线上当前版本（`upload()`/`checkRemote()` 均拒绝），改动前先 `curl .../api/healthz` 探测线上版本；
- **纯 UI/无 DB 变更的增量包 `migrate` 必须置空**，否则 `apply()` 因找不到迁移脚本而回滚；
- **升级包内不携带 `sdk/*.py`**（旧版系统上传白名单无 py 会拦截），Python SDK 由 `install/migrate_v1.3.php` 内嵌 base64 在升级落盘时写入；
- 打包排除：`tests/ release/ storage/* docs/ .workbuddy/ .ai-memory/ .git/ config.php` 等（三个 build 脚本已配置）。

### 20.7 云端实例自动升级提示

后台「系统升级」发布更新包后，`service/UpdateService.php` 通过 `DCAI_CommandService::issue` 自动向低版本且在线实例下发 `update` 命令，实例 SDK 轮询命令后弹出升级提示（无需实例手动检测）。

### 20.8 故障排查

| 现象 | 处理 |
|---|---|
| 检查无反应/提示已最新 | 先 curl manifest 验证 200；或 version 不高于当前、min_version 不满足 |
| MD5 校验失败 | manifest md5 写错，或 jsDelivr 缓存了旧包（换 gcore 域名/等缓存刷新） |
| HTTP 404 | URL 与仓库实际结构不一致（区分 `@main` 分支写法、检查 release/ 目录） |
| 下载超时 | 换 gcore/cdn/gh-proxy 直链，或改用自建 CDN 托管 |
| 私有仓库 401/403 | Token 无 repo 权限或过期；公开仓库留空 |
| 升级失败自动回滚 | 看 `storage/logs/app.log`：常见 min_version 不满足、migrate 缺失、白名单缺文件 |

---

*本文档为 DCAI 授权系统 V1.3.1 的开发说明书，后续如需求变更请同步更新本文档版本。*