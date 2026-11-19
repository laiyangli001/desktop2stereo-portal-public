# 在线升级源与包格式

状态：GitHub Releases 检测、签名暂存、候选准备及 CLI 安装已实现。生产只读凭据、签名密钥和正式升级发布仍需由发布环境配置。

网页升级入口采用“签名元数据 + 外部升级包”模型。网页可下载并验证升级包、在 `public/` 外准备隔离候选目录；它不会覆盖当前代码、不执行迁移，也不关闭维护模式。安装、迁移、目录切换和回滚只能由具备文件系统权限的显式 CLI 操作执行。

## 配置

受限 PHP-FPM/CLI 环境注入：

- `D2S_UPDATE_PUBLIC_KEY`：Ed25519 公钥 Base64，固定 32 字节；
- `D2S_UPDATE_GITHUB_REPOSITORY`：用于网站升级的 `owner/repository`，默认 `laiyangli001/desktop2stereo-portal`；
- `D2S_GITHUB_TOKEN`：私有仓库使用的只读令牌，仓库权限只需 Contents read；
- `D2S_UPDATE_ALLOWED_HOSTS`：旧版按 URL 手工暂存接口使用的主机白名单；
- `D2S_UPDATE_STAGING_DIR`：`public/` 外的私有暂存目录；
- `D2S_UPDATE_CANDIDATE_DIR`：`public/` 外的候选目录根；默认 `storage/updates/candidates`；
- `D2S_CA_BUNDLE`：可选受信 CA 文件；TLS 证书和主机名验证始终开启。

私钥只保存在离线发布环境，不部署到网站服务器，也不进入仓库、网页或日志。

## GitHub 发布

在网站代码仓库创建正式 Release，标签指向部署包对应的提交。先生成发布清单、打包并离线签名，再把下列三个文件作为 Release 附件上传。GitHub 自动生成的 Source code 压缩包不包含项目签名和发布清单。

- `artifact.tar.gz`
- `metadata.json`
- `metadata.sig`

签名元数据的 `artifact.url` 必须是该标签下的附件路径，例如 `https://github.com/laiyangli001/desktop2stereo-portal/releases/download/v1.2.3/artifact.tar.gz`。下载由服务端通过 GitHub 的 Release Asset API 完成。网站检查时验证签名、标签对应的提交、包大小和版本先后关系，只有发布提交是已部署提交的后续版本时才允许暂存。

## 元数据

`metadata.json` 是紧凑 JSON，签名前不得重新格式化。格式：

```json
{
  "format": "d2s-update-metadata-v1",
  "release": "40位完整提交SHA",
  "created_at": 1799990000,
  "release_manifest_root_hash": "64位SHA-256",
  "artifact": {
    "url": "https://artifact.example.invalid/releases/app.tar.gz",
    "sha256": "64位SHA-256",
    "size": 12345678,
    "format": "tar.gz"
  },
  "migrations": [
    {
      "version": "0056_example.php",
      "sha256": "64位迁移文件SHA-256"
    }
  ]
}
```

`metadata.sig` 保存 Base64 编码的 Ed25519 detached signature，签名对象为 `metadata.json` 的原始字节。

## 升级包

升级包必须是 GZip 数据并遵守 `tar.gz` 格式，最多 128 MiB。包内必须包含由 `ReleaseManifest::build()` 生成的 `release-manifest.json`。候选目录准备阶段会：

1. 在解压前逐项检查 TAR 头和校验和，拒绝符号链接、硬链接、PAX 扩展头、绝对路径、反斜杠、`.` / `..` 段和重复路径；
2. 限制最多 5,000 个文件和 512 MiB 解压总量，并拒绝未声明于运行时目录集合的路径；
3. 手工写入新的临时目录，避免使用归档库的递归解压快捷方式；
4. 运行 `ReleaseManifest::verify()`，核对完整 SHA、文件集合和 `release_manifest_root_hash`；
5. 要求 `database/migrations/*.php` 与签名迁移清单逐项一致，并核对每个 SHA-256；
6. 使用 `PhpToken::tokenize(..., TOKEN_PARSE)` 检查所有 PHP 语法且不执行包内代码；
7. 成功后原子重命名候选目录，并只在私有暂存记录 `prepared.json`。

后续安装仍需在维护窗口执行数据库与附件备份、依赖与部署预检、迁移编排、原子切换、健康检查和可执行回滚。准备候选目录不等于安装。

## 下载限制

- 元数据最多 1 MiB，签名最多 4 KiB，升级包最多 128 MiB；
- 只接受 HTTPS，端口仅 443；
- URL 不允许用户信息或 fragment；
- 主机必须精确命中白名单，并解析为公网地址；
- 按 URL 手工暂存接口不跟随重定向。GitHub 附件只跟随到指定 GitHub 下载域名，令牌只发给 `api.github.com`。两种方式均把 DNS 解析固定为已验证的公网地址；
- 同一完整 SHA 已暂存不同包时拒绝覆盖。

## 命令行复核

```text
php bin/verify-staged-update.php --stage=/absolute/private/update-stage/<release-sha>
php bin/prepare-staged-update.php --release=<40-character-release-sha>
php bin/apply-staged-update.php --release=<40-character-release-sha> --backup=<absolute-private-backup-set> --deployment-root=<absolute-release-root> --health-url=<https-api-status-url>
php bin/apply-staged-update.php --rollback --deployment-root=<absolute-release-root>
```

前两条命令只重新验证签名、GZip 标志、包大小和 SHA-256，并安全解压、核对发布清单与迁移清单、扫描 PHP 语法和幂等准备私有候选目录。`apply-staged-update.php` 才执行安装。

## CLI 安装布局

部署根必须包含固定名称的 `current/` 目录；网站 Web root 应固定指向 `deployment-root/current/public`。安装器维护 `staging/`、`rollback/`、`failed/`、`rolled-back/`、`state/current.json` 和 `install.lock`。

安装顺序：

1. 重新验证准备记录、候选路径、发布清单和签名包依赖；要求包内包含 `composer.lock` 与 `vendor/autoload.php`，不运行 Composer 或包内脚本；
2. 拒绝候选符号链接并复制到独立 staging 目录；
3. 以当前 release SHA 验证私有备份集；
4. 迁移账号执行候选 `database/migrations`；
5. 将旧 current 移到 rollback，再把 staging 切为 current；
6. 请求 HTTPS 健康地址，要求候选 SHA、数据库迁移、发布清单和 HTTP 状态全部一致；
7. 健康失败时自动恢复旧 current 并隔离 failed；迁移已执行时设置 `database_restore_required=true`；
8. 显式回滚同样只恢复代码，数据库必须按备份与删除清单流程人工恢复和核对。

## 当前边界

网页支持：

- `POST /api/v2/admin/operations/upgrade/stage`，下载并验证后写入私有暂存，审计操作 `STAGE_ONLINE_UPDATE`；
- `POST /api/v2/admin/operations/upgrade/prepare`，只接受完整 release SHA 并由服务端推导暂存和候选路径，审计操作 `PREPARE_ONLINE_UPDATE`。

两个接口都要求 `settings.manage`、同源和 CSRF。安装不在网页开放，CLI 使用迁移账号、已验证备份集、候选 release SHA、显式部署根和 HTTPS 健康地址。生产公钥、白名单、离线发布、真实托管、Nginx/PHP-FPM 目录切换、共享存储、迁移失败后的数据库恢复和实际回滚演练仍未验收。详见安装与回滚验证。
