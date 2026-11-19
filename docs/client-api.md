# PHP 客户端授权 API v2

状态：服务端本地实现。尚未完成实际 Desktop2Stereo 客户端改造、Go/Python测试向量对验和生产联调，不代表可切换生产。

## 端点

多软件标识、会话隔离和资源密钥配置见[多软件授权接入](multi-software-licensing.md)。缺省软件保持 `desktop2stereo`；其他软件客户端及多商品交易仍需各自验收。

| 方法 | 路径 | 认证和输入 |
|---|---|---|
| POST | /api/v2/client/login | JSON：identifier、password、device_hash、device_public_key |
| POST | /api/v2/client/heartbeat | Bearer access_token；JSON：device_hash |
| POST | /api/v2/client/logout | 同心跳认证；撤销当前客户端会话 |
| GET | /api/v2/license/keys | 公开ES256 JWK，包含活动及仍需验签的历史密钥 |
| GET | /api/v2/me/license | 用户网站Cookie；返回本人权益，首次软件登录前为null |
| GET | /api/v2/me/license/devices | 用户网站Cookie；本人权益、设备历史和当前客户端会话元数据 |
| POST | /api/v2/me/license/session/logout | 用户网站Cookie、CSRF、同源；device_hash、整数session_version；只退出本人的指定客户端会话 |

登录支持同一账户的用户名、已验证邮箱或手机号。只接受小写64位十六进制设备摘要及43字符规范base64url X25519公钥。
POST需application/json，最大32KiB。浏览器跨域来源受白名单约束；原生客户端无Origin时允许。Token仅经HTTPS发送，数据库只保存SHA-256。

成功：success=true、data。
登录data包含access_token、token_type=Bearer、session_version、user_id、license，以及offline_token、core_grant、offline_expires_at、heartbeat_seconds=900、heartbeat_jitter_percent=10。
心跳返回新的签名凭证及权益，不返回新access_token。Token最长无验证24小时，到期需密码登录。

错误：success=false、error、clear_credentials。
401/403拒绝时clear_credentials=true，客户端必须清理本地会话、离线凭证及已解包密钥，不能转离线。
网络异常或503不会授权自行续期；只允许使用之前已验证且仍有效的设备绑定缓存。
429等待后重试；不要将其当作支付/授权成功。

| 错误 | 含义 |
|---|---|
| invalid_credentials | 密码或账户错误 |
| login_temporarily_locked | 失败锁定/来源限流 |
| account_restricted_or_unverified | 账户禁用、登录限制或无已验证联系方式 |
| client_session_replaced | 被新登录替换、设备不符或账户版本已变 |
| client_session_invalid / client_session_expired | Token格式、有效期或时钟异常 |
| license_expired / license_disabled | 权益到期、退款撤权或其他禁用 |
| client_upgrade_required | 旧/api/v1/客户端要求升级，HTTP426 |

P6 网站授权页不要求签名私钥，不启动试用；设备接口不返回客户端Token或Token哈希。
网站退出用最近读取的设备摘要和会话版本确认，已替换会话返回409，审计失败回滚；网页会话、权益和设备历史保留。
完全断网客户端仍可能使用原签名凭证至到期，最长24小时。字段和错误码见[内容与授权管理接口](content-api.md)。

## 权益和并发

每个账户对每个软件各有一条权益，唯一约束为 `(user_id,product)`。网页注册和网页登录不开始试用。
首次成功客户端登录在事务中开始30×86400秒试用；签名、核心密钥包装或审计失败时全部回滚。
用户行先锁定，唯一约束兜底；两个进程同时首次登录也只产生一条权益与一个有效客户端Token。
同一软件后登录替换先登录的客户端会话；不同软件的当前会话可共存，网站Cookie独立。更换密码/联系方式提升账户版本，旧客户端随后被拒绝。
正式权益永久；离线缓存仍最多24小时。退款等流程按订单绑定的权益撤权，只退出对应软件的客户端会话。账户封禁、密码修改等账户级措施仍使所有软件会话失效。
离线客户端在凭证到期前可能与新会话重叠，这是既定24小时离线策略的边界。

## 签名和资源包装

ES256，P-256；JWT签名是64字节R||S，base64url无填充。必须固定算法并核对kid与受信任的公钥。
现有关键字段保留：version、key_id、license_id、product、device_hash、issued_at、not_before、expires_at。
offline_token另含entitlement_id、mode=online、trial、offline_period_days=1、features、session_version。
core_grant另含grant_id；两个凭证均包含核心资源绑定及包装字段。
当前资源为parallax-core版本1。X25519临时密钥交换，HKDF-SHA256，info为d2s-parallax-core-v1-key-wrap，salt为空。
AES-256-GCM随机12字节nonce，16字节tag附在ciphertext后。
AAD为grant_id（离线凭证使用entitlement_id）|license_id|device_hash|core_id|core_version|resource_sha256。
客户端必须检查签名、软件标识（`product`）、设备、资源摘要、版本、时间和凭证用途，再解包；单独收到JWK不构成信任初始化。
到期为min(验证时间+86400,权益到期)，不能因本地时间回退延长。可信时间和安全存储仍须在客户端落地。

## 受控密钥轮换

以下接口均要求独立管理员Cookie及keys.manage权限；POST同时要求管理CSRF：

| 方法 | 路径 | 输入及结果 |
|---|---|---|
| GET | /api/v2/admin/license-keys | 已登记公钥状态，以及本实例配置的key_id和公钥指纹 |
| POST | /api/v2/admin/license-keys/activate | confirm_key_id、confirm_fingerprint必须与GET返回的配置密钥一致 |
| POST | /api/v2/admin/license-keys/retire | key_id；当前活动密钥或尚有未到期凭证的密钥拒绝退役 |

1. 首次成功签发时登记活动公钥，记录系统初始化审计。私钥不入库。
2. 轮换时先通过受限Secret向受控实例注入新的P-256私钥及全新key_id；读取其管理API元数据，核对公钥指纹后激活。
3. 激活事务将旧公钥设为verify_only；数据库全局活动ID决定签发权。配置旧私钥的实例将返回503，不能自行轮回旧密钥。须安排受控切换并更新全部签发实例，不能当作零停机热换。
4. 老公钥持续公开，直至签发记录中所有对应凭证到期，才能退役。无提前强制退役入口。
5. 公钥记录、活动ID和管理审计同事务；审计失败回滚。key_id不可复用为另一把公钥，退役的ID不能重新激活。
6. 当前提供管理API；完整安全后台操作页后续随P5/P6整合。生产轮换、分发新版客户端信任清单以及PHP-FPM链路尚未验收。

## 配置和验收

受限注入D2S_LICENSE_KEY_ID、D2S_LICENSE_PRIVATE_KEY_B64（P-256 PEM的base64，或真实多行D2S_LICENSE_PRIVATE_KEY_PEM），以及D2S_PARALLAX_CORE_SHA256、D2S_PARALLAX_CORE_KEY_HEX。
不得启动时自动生成生产签名密钥。历史公钥注册、受控激活与退役API已实现，流程见下文。私钥仍仅经受限Secret注入，不在管理接口传递。
Nginx必须向PHP传HTTP_AUTHORIZATION，示例已包含；真实PHP-FPM链路仍待部署验证。
本地测试密钥完全随机，不读取旧生产秘密。
Windows PHP测试生成密钥时需指定可用OPENSSL_CONF；本机用Git附带配置，仅测试进程生效。

自动化：php tests/license-crypto.php、php tests/licensing.php、php tests/software-license-upgrade.php、php tests/multi-software-licensing.php（数据库测试需要隔离MySQL管理配置）。
独立Node验证器检查PHP签名、篡改拒绝、X25519/HKDF/AES-GCM解包及错误AAD拒绝。
Go及Python交叉验证已通过，Python还调用了实际客户端核心验签/解包函数。旧客户端离线策略拒绝mode=online，必须随新版接口升级，不能据核心验签通过声明整机兼容。实际客户端v2流程、可信时间、终端清缓存及生产HTTPS验收仍是P3/P7待完成门槛。
