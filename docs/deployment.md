# 部署与发布验收边界

当前仅授权本地/隔离环境开发。生产部署、生产数据库变更和破坏性操作需用户明确授权。
以下为部署准备资料，不是生产操作记录；P7 验收证据见 P7 验收清单。

## 目录、域名与运行账户

使用独立新 MySQL 数据库，不导入旧用户、订单、余额或授权。保留旧站、旧库及原配置。
发布目录只读，Web 根目录必须为 current/public；参考 deploy/nginx.conf.example、deploy/php.ini.example。
d2s.site 与 d2s.top 绑定相同代码、路由和功能。两域名分别验证 HTTPS、Cookie、同源 POST 和支付回调。
D2S_ALLOWED_ORIGINS 包含两个 HTTPS 域名；D2S_APP_URL 设为实际规范站点 URL。
`deploy/nginx.conf.example` 将HTTP请求以308重定向到规范化HTTPS域名，向PHP-FPM传递`HTTPS=on`和`REQUEST_SCHEME=https`，并在证书及两个域名验证后启用HSTS。正式启用HSTS前必须确认所有生产子域都可长期使用HTTPS，避免不可逆的浏览器策略影响。
Nginx 只转发 index.php 到 PHP-FPM，并传递 HTTP_AUTHORIZATION；其他 PHP 和隐藏路径不可访问。

PHP8.4 及 PDO/pdo_mysql、OpenSSL、Sodium、mbstring、curl 等实际依赖须检查。
迁移账号、应用账号、工单留存账号分别注入 D2S_MIGRATION_DB_*、D2S_DB_*、D2S_RETENTION_DB_*。
迁移命令是 `php bin/migrate.php`，应用禁止用 root；迁移账号须与 D2S_DB_* 应用账号分离并具有 GRANT OPTION。命令迁移后会核对相同的 127.0.0.1 数据库，并按 `DatabasePermissions` 白名单刷新应用账号的运营表级权限；不会授予数据库级通配权限。权限配置参考 bin/provision-account.php。
备份可由只读备份账号执行：设置 D2S_BACKUP_DB_DSN/USER/PASSWORD 后运行 `php bin/backup.php --output=/绝对私有路径`；未设置时回退使用 D2S_DB_*。输出目录必须为空，生成 database.sql、工单附件、加密删除清单及 SHA-256 backup.json。主密钥、SMTP/支付密钥和环境变量不会写入备份集，必须由独立受限秘密备份流程保存。
.env.example 不自动加载，只作字段说明；秘密经受限 PHP-FPM/CLI 环境注入，不进入仓库、日志或命令行参数。

## 可写目录和缓存

D2S_TICKET_STORAGE 必须是 public 以外的私有目录，仅受限任务/应用账户可读写。
附件通过鉴权接口下载，不能添加静态公开映射；恢复时同时核对数据库与附件 SHA-256。
D2S_RETENTION_MANIFEST_DIR 保存加密不可变删除清单，默认 storage/retention-manifests，必须位于 public 外，仅保留期任务可写。该目录不得随机回退：数据库或附件恢复到旧快照后，必须先恢复并保留清单，再运行 php bin/prune-support.php --apply 重放已批准删除。清单应按独立保留策略备份和到期，不能与被恢复数据库快照一并回滚。
D2S_RELEASE_CACHE 指向持久私有缓存路径，避免每次代码发布丢失缓存。
刷新任务账户需能写缓存目录，PHP-FPM 需能读缓存文件；代码将缓存文件权限设为0600。
需要共享读取时使用同一受限 Unix 账户，不要将缓存目录开放给无关用户。
本地测试密钥配置不等于生产签名密钥；生产签名私钥、主密钥、支付凭据须另行注入。

## 任务

按真实环境设置 CLI 路径、工作目录、环境注入、并发控制、失败告警和日志轮转。
以下仅登记已实现任务，不代表已安装调度；部署前逐个阅读对应脚本及其已有专项文档。

| 脚本 | 用途 |
|---|---|
| bin/run-task.php | 白名单任务运行器、每任务非阻塞锁及 JSONL 结果日志；调度模板 deploy/tasks.cron.example |
| bin/check-task-health.php | 只读检查必选任务是否缺失、失败、过期或仍在运行，失败退出码供监控告警 |
| bin/check-clock.php | 查询配置的SNTP服务器并检查偏移quorum，不修改系统时钟 |
| bin/refresh-release.php | 每15分钟刷新 GitHub Release 缓存；示例 deploy/release-refresh.cron.example |
| bin/deliver.php | 邮箱/短信发送队列 |
| bin/notify-transactions.php | 交易通知投递 |
| bin/alipay-reconcile.php / bin/wechat-reconcile.php | 查询及关单恢复 |
| bin/alipay-refunds.php / bin/wechat-refunds.php | 退款状态恢复 |
| bin/prune-support.php | 独立留存账户受控清理已关闭满6个日历月工单 |
| bin/prune-login-history.php | 独立留存账户分批清理超过最低366天的详细登录历史；最近成功登录快照保留 |
| bin/backup.php | 生成数据库、私有附件和加密删除清单的哈希校验备份集；不包含部署秘密 |
| bin/verify-backup.php | 只读核验备份集候选SHA、路径、半成品标记、文件大小和SHA-256 |
| bin/preflight.php | 只读核对PHP扩展、候选SHA、运行时清单、主密钥、迁移、数据库账号、管理员、私有目录、留存账号、NTP配置策略和Release缓存 |
| bin/build-release-manifest.php | 按完整候选SHA生成运行时文件与依赖的SHA-256清单 |
| bin/verify-release-manifest.php | 只读校验候选目录、清单、文件集合和完整SHA一致 |
| bin/verify-staged-update.php | 只读复核私有暂存升级包的 Ed25519 签名、GZip 标志、包大小和 SHA-256 |
| bin/prepare-staged-update.php | 安全解压已暂存包，核对发布/迁移清单与 PHP 语法并幂等准备 `public/` 外候选目录；不安装 |

建议统一通过 `bin/run-task.php` 调度任务；同一任务重叠时返回退出码75，失败为1，未知参数/任务为2。`D2S_TASK_LOCK_DIR` 与 `D2S_TASK_LOG_DIR` 必须位于 public 外的受限目录；日志默认5 MiB轮转并保留5代，可通过 `D2S_TASK_LOG_MAX_BYTES`、`D2S_TASK_LOG_KEEP` 调整。示例见 `deploy/tasks.cron.example`，其中每15分钟执行 `check-task-health.php`；退出码非0应由监控告警。`prune-login-history` 使用 `D2S_RETENTION_DB_*`，`D2S_LOGIN_HISTORY_RETENTION_DAYS` 默认及下限均为366天，不得通过运行账号或网页按钮执行清理。仍须按生产路径、环境注入、告警和日志轮转修改后验收。

Release 刷新失败应告警且保留既有缓存；页面不能因 GitHub 不可达阻塞。GitHub TLS 始终启用证书和主机名校验；系统 CA 不完整时通过受限 `D2S_CA_BUNDLE` 指向绝对路径的可读 CA 文件，禁止关闭校验。

后台系统升级通过 `D2S_UPDATE_GITHUB_REPOSITORY` 指定的 GitHub 仓库检查正式发布。私有仓库额外配置仅可读取该仓库内容的 `D2S_GITHUB_TOKEN`。网页使用 `D2S_UPDATE_PUBLIC_KEY` 校验签名，并验证更新包的 GZip、大小与 SHA-256 后暂存于 public 外的 `D2S_UPDATE_STAGING_DIR`；候选版本在 `D2S_UPDATE_CANDIDATE_DIR` 核对发布清单、迁移清单和 PHP 语法。私钥只保留在离线发布环境。实际安装只由 `bin/apply-staged-update.php` 在维护窗口执行：验证当前 release 备份集、签名包内依赖、迁移并切换部署根 `current/`，再请求 HTTPS 健康 SHA；失败自动恢复旧代码，数据库迁移已执行时仍须从备份恢复。详见[升级包格式](online-update-package-format.md)和安装与回滚验证。
工单清理不等于日志清空：用户行为、注册快照和管理审计最低保留366天。
本地已通过故障注入、清单幂等重放及原生备份恢复后的删除重放；生产备份、独立清单持久化、定时任务、告警和清单到期仍须按 P7 验收，不能从本地单测推定完成。

## 可信代理、时间和健康版本

D2S_TRUSTED_PROXY_CIDRS 只填写已核对的实际 EdgeOne 回源网段；不信任任意来源请求头。生产设置 `D2S_TRUSTED_PROXY_REQUIRED=true`，`bin/preflight.php` 会拒绝空集合和非法 IPv4/IPv6 CIDR。
必须验证非可信来源伪造 EO-Connecting-IP/X-Forwarded-For 无效、可信代理才还原用户 IP。可信代理场景源端口保持NULL。
无法可靠取得用户源端口时为 NULL，不把代理 TCP 源端口当用户端口。
具体网段从部署时的官方资料核实，不在仓库硬编码推测范围。

订单号依赖 Asia/Shanghai 时间和数据库秒内序列。生产 NTP 同步状态及禁止手动回拨需留证。`bin/check-clock.php` 可按 `D2S_NTP_SERVERS`、`D2S_NTP_MAX_OFFSET_MS`、`D2S_NTP_QUORUM`、`D2S_NTP_TIMEOUT_MS` 查询实际偏移，但它不修改时钟，也不替代 chrony/systemd-timesyncd 守护状态验收。
生产设置 `D2S_NTP_REQUIRED=true`，且`D2S_NTP_SERVERS`至少包含一个合法IPv4、主机名或IPv6源；预检只验证语法和策略，不发起DNS/NTP请求。任何非空列表中的非法主机、端口、quorum、偏移或超时都会使预检失败。实际网络可达性、偏移结果和守护进程状态必须另行留证。
D2S_RELEASE 注入实际发布的完整 Git SHA；健康端点 /api/status 验证数据库、迁移及该 SHA，data.version 应与发布 SHA 一致，data.database_ready 应为 true。
健康路由、失败状态和返回字段须按当前 Health 服务及真实 PHP-FPM 环境验收，不能只测 CLI。

候选目录必须生成并保留运行时清单：

```text
php bin/build-release-manifest.php --release=<完整SHA> --output=<发布目录>/release-manifest.json
php bin/verify-release-manifest.php --root=<发布目录> --manifest=<发布目录>/release-manifest.json --release=<完整SHA>
```

生产设置 `D2S_RELEASE_MANIFEST` 指向清单；`bin/preflight.php` 会在清单缺失、SHA 不一致、文件被修改或存在未登记运行时文件时失败；配置清单后`/api/status`也会验证并返回`release_manifest_ready/root_hash`，异常时返回503。存在 `.git` 时生成/校验还要求SHA等于当前HEAD，禁止把未提交代码标成旧提交。清单不包含 `storage/`、测试、文档或秘密。

## 验收运行器

```text
php bin/run-release-acceptance.php --profile=local --output=/absolute/private/acceptance
```

运行器只执行 `ReleaseAcceptance` 内置白名单测试，逐项限制超时并写私有 JSON 摘要和 stdout/stderr SHA-256。不得将输出目录放在 `public/`。运行器结果仍需按本地、渠道和生产分别登记；另见验收运行器验证。

## 发布与回滚

1. 记录候选 SHA；生成并校验运行时清单；在隔离环境完成迁移、权限、HTTP、浏览器、客户端及备份恢复验证。
2. 取得生产授权后备份旧站/旧库/受限配置及附件，记录摘要与恢复演练证据。
3. 默认关闭购买及未验收渠道，部署独立新库和对应代码；不运行旧系统安装器。
4. 按清单完成每渠道不超过 CNY10 的用户手动支付、到账、退款和账单核对，以及客户端实机验证。
5. 确认健康 SHA、HTTPS、代理、任务、告警及两个域名一致，再按授权受控开放购买。
6. 上线异常先关购买，继续接收已有合法通知与退款，保留财务核对通道。

产生新交易后不得切回空旧库。只能回滚到兼容当前新库的已验证代码，或进入维护并处理未决交易。
旧项目退役、生产库清理及任何破坏性回退另行授权。
