# 私密工单、日志审计与应急限制规格

2026-11-07；适用项目：`desktop2stereo-portal`；阶段：P5本地开发完成。

2026-11-17规则调整：日志导出无需审批，页面点击后直接下载；导出权限、同源与CSRF检查、筛选限制、CSV公式防护及操作审计仍保留。下方历史验证记录对应原运行版本，本次调整需以新的回归结果为准。

本文描述已落地实现。真实生产附件存储、EdgeOne可信代理地址、保留期调度和导出权限配置仍属P7发布验收，不能把本文当作生产验收记录。

## 1. 权限边界

后台管理员独立于前台用户。所有页面与API要求对应会话；写请求要求同源JSON和CSRF。角色权限：

| 能力 | 权限 |
|---|---|
| 查看/回复私密工单 | `tickets.read` / `tickets.reply` |
| 查询行为、注册和管理审计 | `audit.read` |
| 直接导出日志 | `audit.export.request` |
| 旧版导出权限兼容 | `audit.export.approve`（不再提供审批操作） |
| 紧急限制、强制下线、触发式身份核验 | `accounts.restrict` |
| 用户检索 | `users.read` 或 `accounts.restrict` |

导出无需审批。超级管理员或被授予 `audit.export.request` 的后台账号可直接导出；旧版 `audit.export.approve` 权限兼容为导出权限。客服、财务、系统配置和安全审计角色不会因页面统一而自动获得彼此权限。

## 2. 私密工单

### 数据

- `portal_support_tickets`：账户、可选业务订单、分类、标题、状态、已验证联系快照、来源IP/UA和时间。运行账号只可SELECT/INSERT/UPDATE，不可删除。
- `portal_support_messages`：用户/管理员消息，`PUBLIC` 或 `STAFF` 可见范围，`body_format` 为 `PLAIN` 或经过服务端白名单清理的 `HTML`，正文摘要哈希；只追加。
- `portal_support_attachments`：随机非公开存储名、原文件名、白名单MIME、字节数、SHA-256和所属消息；只追加。
- `portal_support_ticket_events`：创建、回复、状态变化等不可变事件。

附件保存在 `D2S_TICKET_STORAGE`（默认 `storage/tickets`），不得位于 `public/`。单文件最大5 MiB、每工单最多5个且累计不超过20 MiB；允许 PDF、JPEG、PNG、ZIP、TXT。下载时重新检查账户本人或管理员权限、数据库SHA-256和文件内容哈希。

“我的工单”回复区使用本地 Quill 编辑器，支持粗体、斜体、下划线、删除线、列表、引用、代码和 HTTP(S)/mailto 链接。服务端通过 HTMLPurifier 白名单重新清理正文，删除脚本、事件属性、危险 URL、图片标签和表单元素；JPG/PNG 通过鉴权附件上传并显示为缩略图，不使用外链图片或粘贴式 base64 图片。历史 `PLAIN` 消息继续按纯文本渲染。创建工单及用户/管理员回复接口接受可选 `body_format=PLAIN|HTML`；未提供时默认 `PLAIN`。

Nginx参考值`client_max_body_size 7m`；PHP-FPM参考`upload_max_filesize=6M`、`post_max_size=7M`、`max_file_uploads=1`。应用仍需执行独立大小和MIME校验。

### 状态与可见性

- 状态：`OPEN`、`IN_PROGRESS`、`RESOLVED`、`CLOSED`。
- 用户提交或回复后由服务端创建工单；未验证或受限账户不能提交。
- 用户只能读取本人 `PUBLIC` 消息；`STAFF` 内部说明仅获授权客服可见。
- 用户可关闭本人工单；关闭后不能继续回复。客服可以回复、内部备注和改变状态。
- 工单可选16位业务订单号，服务端必须核对该订单属于当前账户。

管理审计不会保留可重新关联已删除工单的工单号、消息ID或附件ID；只记录操作者、时间、来源IP、结果、可见范围及状态变化等必要元数据。

### 六个月生命周期

关闭工单满6个日历月后，由独立保留期账号执行：

```bash
php bin/prune-support.php --apply
```

每次最多处理100个工单、单次任务最多10批。清理先锁定候选并核对关闭状态，在数据库事务内将精确工单ID、关闭时间和附件存储名写入 `D2S_RETENTION_MANIFEST_DIR` 的不可变加密清单，随后独立提交 `CLEANUP_SUPPORT_TICKETS/PENDING` 审计。数据库事务提交成功后才删除附件文件并写 `SUCCESS`；数据库失败时文件保持不变并写 `FAILURE`，文件失败时数据库删除已提交、清单保留用于重试。

任务每次启动先重放全部加密清单，再选择新的到期工单。重放使用清单中的精确ID，重复执行无副作用；从旧数据库快照恢复后，即使该工单已不在当前保留期候选范围，也会按清单再次删除恢复出的正文、消息和附件。清单不向普通用户、应用日志或管理审计暴露工单标识，默认 `storage/retention-manifests`，必须位于 `public/` 之外、仅保留期任务可写，并与数据库/附件备份分开持久化和备份。清单到期清理尚未在P7生产环境验收。

`D2S_RETENTION_DB_*` 使用独立保留期账号；该账号对工单四表只有SELECT/DELETE，对审计表按既定保留期职责授权，不得复用PHP-FPM运行账号或数据库超级用户。`bin/prune-support.php` 同时需要 `D2S_MASTER_KEY` 和 `D2S_RETENTION_MANIFEST_DIR`。

## 3. 用户行为日志

`portal_user_events` 为仅追加日志，字段包括用户ID、冗余邮箱、动作、UTC秒、来源/目标IP和端口、截断UA、设备哈希、路径、结果、SHA-256和哈希版本。动作限定：

`REGISTER / LOGIN / LOGOUT / PURCHASE / DOWNLOAD / PASSWORD_RESET / PROFILE_UPDATE`

行为、注册快照、管理审计和登录凭据表由运行账号只SELECT/INSERT；不提供UPDATE/DELETE接口。最低保留期常量为366天，不提供网页清空入口。

### 详细登录历史与清理

`portal_login_history` 分别记录用户与管理员的成功、失败和被阻止登录，内容包括可用账户ID、登录结果、来源IP、截断User-Agent和UTC秒时间。它用于异常登录核查、单在线会话保护、账户恢复和争议处理，不等同于当前登录IP快照。

`bin/prune-login-history.php --apply` 必须使用 `D2S_RETENTION_DB_*` 专用账号。任务默认且最低保留366天，可通过 `D2S_LOGIN_HISTORY_RETENTION_DAYS` 延长，单次最多处理20批；MySQL 命名锁避免并发删除。开始和完成的时间范围、数量写 `portal_retention_runs`，成功/失败审计与数据库删除同事务。清理只删除 `created_at` 早于截止时间的详细历史，`portal_users/portal_admins.last_login_ip` 等最近状态字段保留。

### 查询

`GET /api/v2/admin/audit/events` 支持：

- `start_time`、`end_time`：UTC Unix秒；
- `user_id`、`email`、`source_ip`、`action_type`；
- `page`、`page_size`（最大100）。

授权管理员看到全部字段，不做字段级隐藏。每次查询写 `VIEW_USER_LOG` 管理审计。

`POST /api/v2/admin/audit/events/{id}/verify`（同源、CSRF）重新计算 `user_id/action_type/event_time/source_ip/result_status` 的SHA-256并与存储值比较；校验动作写 `VERIFY_USER_LOG_HASH`。

### 客户端来源IP

默认只信 `REMOTE_ADDR`。只有配置 `D2S_TRUSTED_PROXY_CIDRS` 且该地址命中可信代理网段时，才读取 `EO-Connecting-IP` 或从右向左解析 `X-Forwarded-For`；代理TCP端口不会伪装成用户源端口，记录为NULL。生产必须按EdgeOne实际回源网段配置，禁止使用宽泛公网CIDR。

## 4. 直接导出和CSV

### 创建导出

`POST /api/v2/admin/audit/exports`

```json
{
  "resource_type": "access_log",
  "start_time": "1799990000",
  "end_time": "1800000000",
  "user_id": "",
  "email": "",
  "source_ip": "",
  "action_type": ""
}
```

`resource_type` 支持 `access_log`、`registration`、`admin_audit`。导出必须给出起止时间，最长366天。申请记录保存规范化筛选和SHA-256，状态兼容存储为 `APPROVED`，表示立即可下载，未执行审批。

点击页面“导出 CSV”会创建记录并立即下载，不需要其他账号操作。新记录下载有效期24小时；历史 `PENDING` 记录也可由创建人直接下载。旧审批接口返回410 `export_approval_not_required`。创建和下载保留审计记录。

### 下载

`POST /api/v2/admin/audit/exports/{id}/download`（同源、CSRF）

只有原申请人可下载。CSV为UTF-8 BOM，按ID分批流式读取；所有以 `= + - @ '` 开头的单元格前置单引号防止电子表格公式注入。文件名：

- `access_log_YYYYMMDD_HHMMSS.csv`
- `registration_records_YYYYMMDD_HHMMSS.csv`
- `admin_audit_YYYYMMDD_HHMMSS.csv`

行为日志CSV包含用户行为表全部字段；注册CSV包含注册时间、IP、UA、验证状态、联系类型和值；管理审计CSV包含操作者、时间、目标、来源、结果和详情。

## 5. 紧急限制与强制下线

### 单账户

`POST /api/v2/admin/accounts/{user_id}/control`

```json
{
  "login_blocked": true,
  "purchase_blocked": true,
  "force_logout": true,
  "reason": "异常登录核查",
  "confirm": true
}
```

服务端在用户行锁内更新 `portal_user_controls`。禁止登录后，网页会话鉴权和客户端登录/心跳均拒绝；禁止购买同时阻断钱包、支付宝、微信下单及充值。强制下线会：

1. 提升 `portal_users.session_version`；
2. 提升 `portal_licenses.session_version`；
3. 删除网页与客户端会话；
4. 在同一事务写入 `portal_user_restriction_events` 和 `EMERGENCY_LOCK` 管理审计。

解锁使用相同接口，将两个限制设为false且 `force_logout=false`，审计为 `EMERGENCY_UNLOCK`。

### 批量预览与确认

支持按 `ip_cidr`、`email_domain`、`register_from`、`register_until` 组合筛选，至少一个条件，选择数量上限10,000。

1. `POST /api/v2/admin/accounts/batch/preview` 生成5分钟预览，保存账户ID集合和SHA-256，并记录 `BATCH_EMERGENCY_PREVIEW`。
2. `POST /api/v2/admin/accounts/batch/apply` 必须携带 `preview_id`、`confirmation_count` 和 `confirmed=true`。
3. 执行时在事务内重新查询；账户集合或数量变化则整体拒绝，不执行部分更改。
4. 每账户写限制事件，批量参数、影响数量写入 `EMERGENCY_LOCK/UNLOCK` 管理审计。

不提供按日志表清空或工单清空按钮。

## 6. 触发式身份核验

`portal_identity_verifications` 默认状态为 `NOT_REQUIRED`，注册时不采集证件。仅在异常交易、退款争议或明确适用要求时由 `accounts.restrict` 管理员发起。

- 发起：`POST /api/v2/admin/accounts/{id}/identity/request`，写 `REQUEST_IDENTITY_VERIFICATION`。
- 审核：`POST /api/v2/admin/accounts/{id}/identity/decision`。
- 发起人与审核人必须不同。
- 证件号码只用于本次服务端处理，使用 `D2S_MASTER_KEY` 的HMAC-SHA-256保存；不保存明文、不写入日志或审计。
- 可选类型：`PASSPORT / FOREIGN_PERMANENT_RESIDENT_ID / OTHER`；签发地使用两位国家/地区代码。
- 状态：`NOT_REQUIRED / PENDING / VERIFIED / REJECTED`。

该功能不等于自动取得法定实名核验能力，也不得扩展到所有用户或所有购买。

## 7. 验证状态

本地隔离MySQL与浏览器已完成：

- 41项服务检查：私密工单、内部消息、附件鉴权、用户隔离、日志哈希、导出申请/审批/下载、单账户强制下线、IPv4 CIDR批量预览、变更检测、身份核验哈希和六个月清理；146项隔离MySQL检查同时验证运行账号与保留期账号权限。
- 23项真实HTTP检查：个人工单/附件、跨账户404、客服队列/回复、安全角色越权拒绝、登录日志查询、双人导出审批、CSV和强制下线。
- Edge浏览器：工单创建/附件/回复、客服解决、哈希校验、导出申请/审批/下载、强制下线、键盘与390px手机布局。

真实腾讯EdgeOne代理网段、生产私有附件目录、限制期调度任务、导出权限配置和备份恢复仍待P7。
