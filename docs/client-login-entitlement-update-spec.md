# 客户端登录、授权、下载更新与信息同步编程规格

适用于使用 Desktop2Stereo Portal 账号的 Windows、macOS、Linux 和 Android 软件，包括 Android 头显。授权合同以[现有客户端授权 API](client-api.md)为准，更新和统计设计以[后台管理实施方案](software-update-admin-implementation.md)为准。下表接口和字段已在当前服务端代码实现；目标服务器部署并联调后，客户端再启用对应能力。网站自身的系统升级有独立协议。

## 1. 协议和兼容范围

| 操作 | 接口 | 阶段 |
| --- | --- | --- |
| 原生登录 | `POST /api/v2/client/login` | 已实现。提交 `identifier`、`password`、`device_hash`、`device_public_key`、`product`；可带 `platform`、`device_type`（旧客户端缺省分别为 `unknown`）。成功响应包含权益、会话凭证、心跳策略和 `client_state`。 |
| 心跳与退出 | `POST /api/v2/client/heartbeat`、`POST /api/v2/client/logout` | 已实现。Bearer Token 加 `device_hash`。商品、用户及会话由服务端从 Token 解析。 |
| 商品初始化 | `POST /api/v1/products/{product_code}/bootstrap` | 服务端已实现。Bearer Token；JSON 必填 `device_hash`、`platform`、`architecture`，`device_type` 可选。返回 `license`、`client_state`、最新发布摘要及 `minimum_supported_version`。 |
| 读取版本下载资料 | `POST /api/v1/products/{product_code}/update-downloads` | 服务端已实现。Bearer Token；JSON 必填 `device_hash`、`platform`、`architecture`、整数 `version_code`，`region_code` 可选。仅返回该目标版本匹配文件的元数据、SHA-256 和可用渠道。 |
| 补报版本或设备 | `POST /api/v2/client/environment` | 服务端已实现。Bearer Token；JSON 必填 `device_hash`、`platform`、`device_type`，并至少带 `version` 或 `environment` 其中一组。成功响应返回冲突项和最新 `client_state`。 |

原生登录使用 HTTPS 和 JSON。设备摘要及 X25519 公钥的编码遵循现有授权合同。认证请求不跟随重定向。密码只在请求期间使用，访问 Token 只保留在内存。日志排除密码、Token、私钥、包装后的授权材料、网盘提取码及带凭据的 URL。商品标识由客户端构建配置固定。

以上更新与统计接口及登录 `client_state` 已在当前代码库实现；部署到目标服务端并完成联调前，客户端应按能力协商结果启用，不能把接口 404 解释为最新版本或无授权。登录返回的 `client_state` 可直接用于比较；若之后调用 `bootstrap`，以较新响应的状态为准，不重复上传相同数据。三个新增路径均返回 `Cache-Control: no-store`。

## 2. 登录与功能授权

1. 启动时读出运行中的应用版本、平台和架构。资料留在本地，登录只发送现有认证字段和定位统计所需的平台、架构、设备类别。若某个受限业务必须实时判断版本，按该业务接口要求发送本机版本。
2. 登录成功后解析会话和权益。使用签名凭证的产品必须按现有合同核对 ES256、受信任的 `kid`、商品、设备、资源、凭证用途、会话版本和有效期。资源密钥仅在验证通过后按既有包装协议解封。网络返回的公钥不能自行成为初始信任根。
3. 在展示或执行受保护功能前核对权益。服务端资源每次请求仍由服务端裁决。客户端收到撤权、顶号、过期或明确拒绝时，立即关闭对应受保护功能并清理当前商品的会话、缓存凭证及已解封密钥。另一个商品的会话独立。
4. 已有 v2 产品继续使用服务端返回的授权和试用规则。当前 v2 合同中，首次成功客户端登录会启动默认试用。启用新公测策略的商品改由服务端返回阶段和权益，首次领取试用走账号中心的显式入口，使用用户会话和 CSRF 保护的 `POST /api/v2/me/license/trial/claim`。网页登录、更新检查、下载和资料补报均不创建新试用。

| 新策略返回的权益 | 客户端行为 |
| --- | --- |
| 永久授权 `licensed` | 开放已购功能，公测、免费和收费模式切换不产生购买或试用提示。 |
| 公测或免费使用 | 按服务端允许范围开放功能，隐藏付费入口。 |
| 试用有效 | 开放试用范围，显示服务端给出的到期时间。 |
| 未取得有效权益、试用到期 | 提供允许的基础功能及购买入口。服务端要求有效的商品会话和权益；权益状态不允许访问时，更新接口拒绝请求。 |
| 明确拒绝或未知权益 | 按错误类型提示，受保护功能保持关闭。 |

客户端使用服务端时间和返回的期限展示状态，不自行推算试用起点或公测截止。每个商品独立。已购商品的版本查询独立于受保护功能的运行授权。后端确认当前会话和该商品权益有效后，即可返回该商品的更新资料。

### 2.1 客户端购买与退款入口

客户端仅提供购买入口。购买完成后，客户端内不允许用户自行申请或发起退款。订单详情、授权页和支付结果页均不提供退款按钮、退款窗口或退款申请链接，客户端不调用退款接口。服务端在后续登录或心跳中返回撤权时，客户端按现有授权流程停止对应商品的受保护功能。

## 3. 在线会话、离线与错误状态

- 心跳按服务端下发的策略发起。心跳只接受与当前会话一致的响应，退出或切换账号后忽略旧请求的迟到响应。
- 心跳更新签名凭证和有效范围，保持当前访问 Token。超出会话允许的无验证期限后重新登录。用户主动退出时先关闭本机受保护功能和删除凭证，再尽力通知服务器。远端退出失败也保持本地已退出。
- 网络异常或 503 时，可为已接入现有离线签名机制的商品提供显式离线选项。只用设备与商品绑定且重新验签通过的缓存，同时遵守签名期限、权益期限和既有最多 24 小时限制。检测到时钟倒退或缓存已过期时停止离线授权。明确的 401、403 或要求升级的 426 不触发离线回退。
- 429 按服务器给出的等待时间或退避策略处理。请求超时、失败或被拒绝均不能自行延长本地权限。更新查询和资料同步各自显示可重试状态，不能改变已确认的权益结果。

### 3.1 服务端心跳策略

登录和每次成功心跳响应都包含 `heartbeat_seconds` 与 `heartbeat_jitter_percent`。服务端从当前平台设置读取最新策略；缺省值为 900 秒和 10%。客户端不请求单独配置接口，也不读取数据库。登录取初始值，每次成功心跳都用响应中的新值安排下一次请求，策略修改在下一次成功心跳后生效。

- `heartbeat_seconds` 为整数秒，建议首版允许 300–3600 秒。 `heartbeat_jitter_percent` 为 0–20 的整数，默认 10。客户端在间隔附近随机错开请求，且实际周期不超过策略允许的上下限。字段缺失或无效时保留本会话最后一次有效策略，首次没有有效值时采用 900 秒和 10%。
- 每个会话只排一个心跳计时器，使用单调时钟，不叠加请求。客户端唤醒后重新检查凭证期限，至多补发一次已到期的心跳。收到 429 或带 `Retry-After` 的 503 时遵守重试时间，其他临时错误采用带抖动的指数退避。认证或授权明确拒绝时立即停止心跳及受保护功能。
- 心跳间隔和重试时间不能延长 Token、试用或签名凭证期限。正常情况下在凭证到期前安排下一次校验。如果重试限制跨过凭证期限，到期后关闭受保护功能，等允许重试时再验证。请求序列和过期处理继续遵守当前会话规则。

## 4. 更新检查、下载与安装

商品初始化每次成功登录检查一次，用户可手动重试。使用实际运行的本机整数版本号判断，Android 使用 `versionCode`，电脑端使用构建清单的整数版本。展示字符串用于 UI，不做排序。按商品、平台和架构过滤安装包，接受明确标记兼容的通用文件。

更新分两步。首先，服务端确认当前账号对 `product_code` 的有效购买权益，商品初始化只查版本信息，跳过下载渠道查询。客户端比较最新整数版本号与本机版本。本机版本相同或更高时结束。发现新版后显示更新提示，用户确认下载或打开下载渠道时，才请求 `update-downloads`。服务端再次确认购买权益和目标版本仍是当前发布，按平台与架构返回文件信息、SHA-256 和有效链接。若目标版本已撤回或替换，返回 `release_changed`，客户端重新查版本并重新比较。下载链接失效时可重新请求同一版本的渠道。

| 条件 | 客户端行为 |
| --- | --- |
| 未匹配到已购商品 | 隐藏已购更新下载入口。商品允许购买时可以显示购买入口。 |
| 无发布信息或无匹配文件 | 显示无可用包或无匹配版本。若已低于最低支持版本，保留重试、退出和客服入口，并按服务端规则限制相关功能。 |
| 本机版本大于等于最新发布 | 静默，不提示降级。 |
| 本机低于最新但仍受支持 | 显示可选更新、版本说明及「稍后」。 |
| 本机低于最低支持版本且有可用包 | 展示必须更新页，允许切换渠道、打开下载、联系支持或退出。 |
| 必须更新但所有渠道不可用 | 展示恢复页并允许重试。服务端明确禁用的功能保持受限。 |

- 使用服务端提供的匹配文件列表，展示文件名、架构、大小和每个文件的 SHA-256，提供复制。不同架构或格式各自使用对应哈希。同一文件的各渠道共享同一个 SHA-256。按服务器返回的顺序显示所有可用渠道，允许用户手动切换。
- 仅打开 HTTPS 渠道地址。网盘链接交给系统浏览器，提取码由用户按需复制。下载请求不携带门户 Token 或 Cookie。首版允许第三方下载后手动安装。
- 客户端能读取下载文件时，流式计算 SHA-256，并核对文件大小。任一不匹配时停止由客户端发起的安装，提示重新下载或换源。浏览器下载的文件尚未提供给客户端时，明确显示「未校验」，提供系统文件选择器或平台校验说明。
- Android 安装前按平台流程验证包名、签名证书和可覆盖升级性。电脑端沿用目标系统的安装签名流程。若应用自行发起安装，仍由系统执行授权与用户确认。打开链接不等于下载完成，打开安装器也不等于安装成功。下次重新启动时以当前运行的版本为准。

## 5. 软件版本及系统资料按变化同步

1. 登录成功后读取服务端保存值。软件版本按用户、商品、平台定位；设备资料再加设备类别，手机与头显各有记录。返回两组各自的保存值和 `revision`。服务端每次成功登录单独刷新 `last_client_login_at`。
2. 将正在运行的 `version_code` 与 `version_name` 作为同一组比较。系统资料按平台采集并标准化。电脑包括 `os_name`、`os_version`、可选 `os_build` 和实际使用的 `gpu_model`。Android 头显包括 Android 版本、可选 `android_api_level` 和头显 `device_model`。平台、架构与设备类别用于定位或适配。读取不到的可选字段省略。
3. 两组都相同则不调用补报接口。缺少保存值或存在变化时，把发生变化的组放进一次请求，附上各组的 `revision`。版本组同时发送两个版本字段，设备组仅发送有变化的字段。服务端保存后返回新版本标记，冲突时读取返回的新值并重新比较。运行版本降级也属于变化。
4. 上报失败可单独重试，重新登录或切换账号时重建待同步状态。设备资料不作为鉴权依据，省略或同步失败不阻塞登录。统计组未变化时保持原上报时间，管理员仍可从最后成功登录时间分析活跃度。

环境补报实际 JSON 使用 `version` 与 `environment` 两个对象，revision 是正整数；对象可在一次请求中同时发送，也可以只发送变化的一组。服务端从 Token 解析账号、商品和设备摘要，不接受客户端提交 `user_id` 或 `product_code`。

```json
{
  "device_hash": "小写64位设备摘要",
  "platform": "windows",
  "device_type": "desktop",
  "version": {
    "revision": 17,
    "version_code": 2100,
    "version_name": "2.1.0",
    "architecture": "x64"
  },
  "environment": {
    "revision": 9,
    "os_name": "Windows 11",
    "os_version": "24H2",
    "os_build": "26100.1742",
    "gpu_model": "NVIDIA GeForce RTX 4060"
  }
}
```

只补报系统信息时可省略 `version`；只补报软件版本时可省略 `environment`。版本对象中的 `architecture` 必须与本次运行构建一致。

## 6. 服务端 SQL 访问指导

客户端程序不连接 MySQL/MariaDB，也不执行 SQL。所有 SQL 只由 Portal 服务端在参数化查询和事务中执行。客户端通过前文列出的 HTTPS JSON API 读写数据；不得把数据库账号、用户 ID、明文 Token 或 SQL 暴露给客户端。以下命令用于服务端接口实现和字段核对，参数均为绑定参数。

### 6.1 登录、会话和心跳对应的数据操作

服务端用 SHA-256 十六进制摘要定位会话，不在数据库保存明文 Access Token：

```sql
SELECT user_id, product
FROM portal_client_sessions
WHERE token_hash = SHA2(:access_token, 256);
```

定位后服务端还会按事务顺序锁定账户、商品权益及当前会话，并校验设备摘要、用户版本、权益会话版本、Token 有效期与账户状态。仅查询到 Token 摘要不代表授权成功。每个用户和商品仅保留一个当前会话；成功的新登录会替换同商品的旧会话。

```sql
SELECT id, kind, status, trial_started_at, expires_at, session_version
FROM portal_licenses
WHERE user_id = :user_id AND product = :product_code
FOR UPDATE;

SELECT public_key, device_hash, user_version, session_version, verified_at, expires_at
FROM portal_client_sessions
WHERE user_id = :user_id AND product = :product_code
FOR UPDATE;
```

成功登录时服务端更新该商品的客户端活跃时间；此操作不会因为网页登录、失败登录或下载而触发：

```sql
INSERT INTO portal_product_user_activity
    (user_id, product_code, platform, last_client_login_at, revision, updated_at)
VALUES
    (:user_id, :product_code, :platform, :now, 1, :now)
ON DUPLICATE KEY UPDATE
    last_client_login_at = VALUES(last_client_login_at),
    updated_at = VALUES(updated_at);
```

心跳先重新验证会话和权益，再刷新服务端验证时间、设备最近在线时间并签发新的授权材料。客户端保留现有 Access Token，只按 JSON 响应中的心跳策略安排下次请求。

### 6.2 读取登录快照、平台版本与最低支持版本

client_state.version 对应 portal_product_user_activity，唯一键是 (user_id, product_code, platform)：

```sql
SELECT last_client_login_at, last_version_reported_at,
       version_code, version_name, architecture, revision
FROM portal_product_user_activity
WHERE user_id = :user_id
  AND product_code = :product_code
  AND platform = :platform;
```

client_state.environment 对应 portal_product_user_environment，唯一键再加 device_type。Android 手机与头显分别保存：

```sql
SELECT os_name, os_version, os_build, gpu_model,
       android_api_level, device_model, reported_at, revision
FROM portal_product_user_environment
WHERE user_id = :user_id
  AND product_code = :product_code
  AND platform = :platform
  AND device_type = :device_type;
```

无记录时服务端返回空统计值和 revision=1。最新版本摘要按商品、平台和架构读取；这里只查发布信息，不读取下载渠道：

```sql
SELECT r.version_code, r.version_name, r.release_notes, r.published_at
FROM portal_product_releases AS r
WHERE r.product_code = :product_code
  AND r.platform = :platform
  AND r.status = 'published'
  AND EXISTS (
      SELECT 1
      FROM portal_product_release_files AS f
      WHERE f.release_id = r.id
        AND f.architecture = :architecture
  )
ORDER BY r.version_code DESC
LIMIT 1;

SELECT COALESCE(
    (SELECT minimum_supported_version
     FROM portal_product_update_policies
     WHERE product_code = :product_code AND platform = :platform),
    0
) AS minimum_supported_version;
```

响应字段是整数 version_code、字符串 version_name、更新说明 release_notes、Unix 秒 published_at 及整数 minimum_supported_version。找不到发布时 latest 为 null。

### 6.3 用户确认下载后读取文件与渠道

只有客户端确认需要下载并调用 update-downloads 后，服务端才读取目标版本的文件和渠道。先按 product_code + platform + version_code + published 确认发布仍存在，再按架构取文件：

```sql
SELECT id, version_code, version_name
FROM portal_product_releases
WHERE product_code = :product_code
  AND platform = :platform
  AND version_code = :version_code
  AND status = 'published'
FOR UPDATE;

SELECT id, architecture, file_format, file_name, byte_size, sha256,
       android_package_name, android_signing_sha256
FROM portal_product_release_files
WHERE release_id = :release_id
  AND architecture = :architecture
ORDER BY file_format;
```

传入地区时，按地区优先返回匹配渠道，再追加全球 `*` 渠道；未传地区时返回所有启用地区的渠道。两种情况都会忽略已停用渠道及未启用域名：

```sql
SELECT c.id, c.channel_name, c.provider, c.region_code,
       c.download_url, c.passcode_ciphertext, c.sort_order
FROM portal_product_download_channels AS c
JOIN portal_product_download_hosts AS h
  ON h.hostname = c.hostname AND h.enabled = 1
WHERE c.file_id = :file_id
  AND c.enabled = 1
  AND (:region_null IS NULL OR c.region_code IN (:region_match, '*'))
ORDER BY CASE WHEN c.region_code = :region_order THEN 0 ELSE 1 END,
         c.sort_order, c.id;
```

region_null、region_match 和 region_order 都绑定同一个地区值；无地区时三者都绑定 NULL，此时查询返回全部启用地区渠道。服务端解密后只在 HTTPS JSON 响应中返回 passcode，不返回密文。客户端结果包含 product_code、platform、version_code、version_name、files[] 与权益摘要；文件含架构、格式、文件名、字节数、SHA-256、Android 包名/签名指纹及渠道。目标版本已撤回时返回 release_changed；没有可用匹配文件或渠道时返回相应错误，不可使用旧缓存链接绕过。

### 6.4 按 revision 条件保存版本或设备变化

服务端在事务中锁定对应统计行，并把请求 revision 与数据库当前 revision（无记录按 1）比较。不同则不写入，返回冲突类别和最新 client_state。相同时先比较字段：值相同则不执行 UPDATE，保留原上报时间；确有变化时才写入并令 revision 加一。

软件版本按 (user_id, product_code, platform) 更新：

```sql
INSERT INTO portal_product_user_activity
    (user_id, product_code, platform, last_version_reported_at,
     version_code, version_name, architecture, revision, updated_at)
VALUES
    (:user_id, :product_code, :platform, :now,
     :version_code, :version_name, :architecture, :next_revision, :now)
ON DUPLICATE KEY UPDATE
    last_version_reported_at = VALUES(last_version_reported_at),
    version_code = VALUES(version_code),
    version_name = VALUES(version_name),
    architecture = VALUES(architecture),
    revision = VALUES(revision),
    updated_at = VALUES(updated_at);
```

设备资料按 (user_id, product_code, platform, device_type) 更新：

```sql
INSERT INTO portal_product_user_environment
    (user_id, product_code, platform, device_type, os_name, os_version,
     os_build, gpu_model, android_api_level, device_model, reported_at, revision)
VALUES
    (:user_id, :product_code, :platform, :device_type, :os_name, :os_version,
     :os_build, :gpu_model, :android_api_level, :device_model, :now, :next_revision)
ON DUPLICATE KEY UPDATE
    os_name = VALUES(os_name),
    os_version = VALUES(os_version),
    os_build = VALUES(os_build),
    gpu_model = VALUES(gpu_model),
    android_api_level = VALUES(android_api_level),
    device_model = VALUES(device_model),
    reported_at = VALUES(reported_at),
    revision = VALUES(revision);
```

设备字段未出现在请求中时由服务端保留当前值；不要用客户端本地空字段覆盖服务器已有资料。登录时间只由成功登录更新，补报设备或版本不会刷新 last_client_login_at。下载检查、网页登录和失败登录也不更新此字段。

客户端实现顺序：登录读取 client_state → 与本机比较 → 缺失/变化才调用 /api/v2/client/environment → 需要下载时先比较版本，再调用 /api/v1/products/{product_code}/update-downloads。SQL 只属于服务端实现和排障参考。

## 7. 开发交付与验收

| 场景 | 通过条件 |
| --- | --- |
| 登录、顶号和退出 | 同商品后登录使旧会话失效，不同商品互不影响。认证失败不更新活跃度，旧客户端仍能按原协议连接。 |
| 权益及离线 | 覆盖正式、试用到期、公测转换、服务端撤权、签名或商品不匹配、断网与 24 小时到期。新公测商品查更新和下载不发试用。 |
| 客户端购买 | 购买前后均无退款操作入口，点击订单或授权信息不会打开退款申请，也不会发送退款请求。 |
| 更新与恢复 | 同版或本机版本更高时，确认客户端未调用下载资料接口、服务端未查询渠道。选择下载后才读取指定版本，并覆盖未购买、购买权益撤销、版本撤回、可选更新和强更。用实际电脑和 Android 设备完成下载、校验和安装。 |
| 资料同步 | 首次补报、无变化零补报、仅软件升级、仅更换显卡、两组同时变化、旧客户端缺省、并发冲突。检查后台最后登录时间与上报时间分开更新。 |

按「现有授权联调 → 服务端扩展上线 → 下载和资料同步联调 → 强更演练」交付。版本发现和下载可先上线，强更须在目标设备和备用渠道验收通过后启用。
