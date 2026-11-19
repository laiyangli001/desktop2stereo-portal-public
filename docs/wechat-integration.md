# 微信支付 APIv3 接入与阶段验证

2026-11-06。P4开发进行中；已完成协议、固定域名JSON传输及后台加密配置，已新增不可变商户快照、支付回调与主动查询共用的数据库幂等结算；尚未开放微信渠道、未调用真实微信支付、未改生产。参考商城的XML/MD5接口不作为新系统接入或验收依据。

商户事实：用户于2026-11-06确认已开通“普通商户 Native 支付”，控制台提供“微信支付公钥”；这是用户确认信息，真实商户参数、接口调用及收退款仍待验收。

## 已实现

| 组件 | 当前代码能力 | 不能据此宣称的能力 |
|---|---|---|
| includes/services/WechatProtocol.php | 精确请求原文RSA-SHA256/2048签名；原文响应/通知验签；AES-256-GCM认证解密 | 订单到账、自动履约、退款成功 |
| includes/services/WechatHttp.php | 固定api.mch.weixin.qq.com HTTPS；限定Native下单、交易查询/关单、退款申请/查询及交易账单申请路径 | 已有微信订单业务、收银台或账单下载 |
| includes/services/WechatSettings.php及配置API/页面 | payment.configure权限、参数校验、加密/不回显、清除、双槽轮换、行锁/审计及启用门禁 | 已完成商户关联、已能收款 |
| includes/services/WechatClient.php | Native/查询/关单/退款/账单申请的内部适配，身份/订单/整数金额及状态核对 | 微信订单落库/幂等履约、退款撤权、二维码页面或账单文件下载 |
| includes/services/WechatProfiles.php / WechatPayments.php | 加密商户快照、同商户密钥轮换、回调/查询幂等结算、正式授权与通知/审计同事务 | 可公开下单的Native收银台、退款业务或真实到账 |
| tests/wechat-protocol.php / .cjs | 111项本地协议/传输替身检查及Node密码学交叉验证 | 实际商户产品开通、真实HTTPS调用、真实收退款 |

### 密钥边界

传入配置结构为mchid、merchant_serial、private_key、api_v3_key、verification_keys。这是内部协议参数，由WechatSettings::protocolConfiguration从已保存或首次受限环境配置转换；不要将密钥写入源码，也不要在聊天中发送密钥。

- merchant_serial为商户API证书序列号，请求使用对应的PKCS#8 PEM商户私钥；RSA2048，与微信支付公钥或平台证书不是同一密钥。
- api_v3_key为32字节对称密钥，仅用于回调认证解密，不代替验签。
- verification_keys是可信序列号到PEM材料的映射，最多8项，允许轮换重叠期保留多个可信项。
- PUB_KEY_ID_开头的ID配微信支付公钥；普通十六进制序列号配平台X.509证书，必须与证书序列号一致，验签时检查证书有效期。
- 纯数字序列号也合法；兼容PHP对数字数组键的类型转换。
- 不信任来报中的新密钥，不自动下载或安装平台证书；配置只能由获授权管理员提供经核对的可信材料。没有配置对应ID时拒绝验签，不回落到其他密钥试验。

### 原文、时效及回调

请求签名串依次为方法、含原始查询串的目标路径、秒时间戳、随机串、原始JSON，每项以换行结束；GET的请求体为空。每次默认生成独立随机串。

响应和通知均先验证Wechatpay-Timestamp / Nonce / Serial / Signature与原文，再解析JSON。时间戳允许本机当前时间前后300秒，未知序列号、测试错误签名、重复签名头、非法Base64及篡改均拒绝。服务器需正常校时。

JSON借用现有RawJson解析器拒绝任何层级重复键，包括转义后重复的名称；解析错误转换为微信协议错误，不泄漏原文。响应体最多1MiB，头最多16KiB。

通知只识别TRANSACTION.SUCCESS及REFUND.SUCCESS / ABNORMAL / CLOSED，校验resource类型、算法、nonce、认证附加数据和GCM标签。返回事件ID/类型与解密后的数据。ABNORMAL/CLOSED只是退款事件，不表示退款已成功。未知产品通知拒绝处理。

这一层不写数据库、不发放授权、不加余额、不撤权。后续业务层仍必须核对应用/商户/订单/交易号、整数金额、币种及状态，保存不可变事件并去重；验签成功不等于业务成立。签名时效检查也不能取代数据库事件幂等。

### HTTP行为

JSON传输仅固定官方HTTPS主机，不采用环境代理、不跟随跳转；开启TLS证书/主机名校验，连接5秒、总时限15秒。限定接口路径不能传入任意URL或下载地址。原始响应必须验签，包括400/401/429/500错误；204空响应也要求签名。返回http_status、data及body_empty标记供后续业务精确判断，网络不确定结果不转换为交易失败或本地取消。

下载交易账单将另设受控下载流程，不通过现有JSON请求器拉取文件。当前路径白名单不证明请求字段符合各产品业务规范。

## 后台配置与受限环境

当前配置页（2026-11-08）：`/admin/settings/payments#wechat_native`，统一“支付接口”中的微信支付选项卡；旧 `/admin/settings/wechat` 地址跳转到此页。新页面使用 `/api/v2/admin/operations/payment-interfaces`；首次保存会在服务端保留旧密钥，关闭后不会回退启用旧配置。以下旧 API 与受限环境说明保留作历史兼容参考。独立管理员且具有payment.configure权限才能访问。财务退款、客服和安全审计角色不会自动取得支付配置权限。

`GET /api/v2/admin/settings/wechat`返回values、secrets_configured、configuration_ready及checkout_integrated；`POST`接受values对象与clear_fields字符串列表，需要管理员会话、CSRF及同源JSON请求。没有DELETE接口。技术参数完整仅表示格式及密钥材料检查通过，不证明AppID/商户/密钥实际归属，也不证明真实渠道已验收。

| 后台字段 | 首次受限环境参数 |
|---|---|
| enabled（当前只允许关闭） | D2S_WECHAT_ENABLED=false |
| app_id | D2S_WECHAT_APP_ID |
| mchid | D2S_WECHAT_MCHID |
| merchant_serial | D2S_WECHAT_MERCHANT_SERIAL |
| private_key | D2S_WECHAT_PRIVATE_KEY |
| api_v3_key | D2S_WECHAT_API_V3_KEY |
| verification_id | D2S_WECHAT_VERIFICATION_ID |
| verification_key | D2S_WECHAT_VERIFICATION_KEY |
| previous_verification_id（可选） | D2S_WECHAT_PREVIOUS_VERIFICATION_ID |
| previous_verification_key（可选） | D2S_WECHAT_PREVIOUS_VERIFICATION_KEY |
| notify_url | D2S_WECHAT_NOTIFY_URL |
| refund_notify_url | D2S_WECHAT_REFUND_NOTIFY_URL |

- 首次无wechat.v1记录时可读取上述环境值；后台保存后，数据库配置整体权威，清除或缺失字段不回落环境，避免旧密钥复活。`.env.example`只作说明，不自动加载。
- private_key、api_v3_key及两份PEM验签材料分别通过现有SecretBox加密，绑定不同字段上下文；读取接口、HTML及审计不回显材料，只返回是否已配置。
- 空白输入保留已有密钥，明确勾选clear_fields才清除；非秘密字段使用普通空字符串可以清空。PEM使用多行输入框，APIv3密钥输入框为密码类型。
- 两个验签槽位支持当前和轮换期旧材料。已有材料的ID或商户证书序列号变更时，须同时输入对应新材料，或明确清除该材料；不静默给旧密钥更换ID。ID不得重复。
- 后台支持公钥/证书材料匹配；本站已确认采用公钥模式，填写PUB_KEY_ID_开头的ID及微信支付公钥。证书能力只作为兼容路径，不自动切换或下载。
- 保存使用行锁合并参数，审计与配置同事务，审计失败回滚。查看/修改分别记录VIEW_WECHAT_SETTINGS、UPDATE_WECHAT_SETTINGS，仅记录变更/清除字段名。
- 当前CHECKOUT_INTEGRATED=false，完整参数也不能启用，服务端返回wechat_checkout_not_integrated。购买总开关仍独立存在，尚未增加可下单的微信渠道。
- 通知地址仅允许D2S_ALLOWED_ORIGINS中的HTTPS源站域名，以及固定`/api/v2/wechat/notify`、`/api/v2/wechat/refund-notify`路径，不允许用户名密码、端口、查询串或片段。付款与退款基址均返回404。实际已实现路由为`POST /api/v2/wechat/notify/{64位profile_id}`及`POST /api/v2/wechat/refund-notify/{64位profile_id}`，由订单绑定的加密快照定位商户；Native建单与退款请求均附加实际快照后缀。不能把基址当作可接收通知的地址，不能提前当作控制台验收通过。
- 配置复用portal_settings。结算新增0013迁移，四张微信表仅授予INSERT/SELECT；不改旧迁移校验和，不授予UPDATE/DELETE。新装权限87项检查通过。没有连接生产库或导入生产Secret。

后台本轮证据：P4微信配置验证。

## 本地验证记录

运行：`php tests/wechat-protocol.php`，需要PHP OpenSSL/mbstring及Node；不需要MySQL、商户参数或真实网络。Windows OpenSSL配置由受限运行环境提供，不写入项目。

2026-11-06实际输出111项通过，包含：

- OpenSSL独立验证请求签名，UTF-8原文、GET查询串、空请求体及随机串；金额和空格篡改拒绝。
- 响应时间边界、过期/未来报文、缺头/重复头、未知密钥、SIGNTEST和非规范签名拒绝。
- 已签名JSON重复键、嵌套/转义重复键、非对象、非法UTF-8、超长报文拒绝。
- 付款及三种退款通知；错误签名先于解密拒绝；密钥/nonce/附加数据/密文或认证标签异常拒绝；解密后JSON仍严格校验。
- 公钥与证书重叠、证书序列号不符/尚未生效/到期、弱RSA密钥拒绝。
- Node独立验证PHP商户请求，并独立生成RSA签名与AES-GCM密文，PHP成功验签解密。
- 固定官方URL/签名头/原文、所需路径白名单、路径穿越/任意URL拒绝且不进入传输替身；已签名成功/错误/204、无签名错误、跳转及无效传输结果。
- 原支付宝协议69项回归通过，新增3个PHP文件及Node脚本语法通过，git diff --check通过。

传输使用本地Closure替身，密码学使用随机合成商户/平台密钥和自签测试证书。真实CURL/TLS、微信平台回执与交易数据均未验收。

内部业务接口适配119项检查及明确边界：P4微信接口适配验证。微信使用原始32位订单ID，支付宝现有D2S前缀规则未改；该记录反映接口适配阶段；后续数据库结算已完成本地验证，二维码页面仍待实现。

内部二维码请求持久化与恢复：43项收银台服务验证。已实现查询优先、同号重试、订单上下文加密缓存、连接级串行和短事务；尚未连接公开下单API/商店页面，渠道禁用状态不变。0014新增只追加缓存表，当前新装权限检查为90项。

单页商店与Native建单已接入，22项接口检查及真实本地Edge手机/键盘/缓存恢复/签名付款通知通过；原支付宝四模式浏览器回归通过。证据：微信单页商店验证。后台CHECKOUT_INTEGRATED门禁暂仍关闭，等待后续财务与查关单流程；历史阶段的“尚未接通页面”描述仅对应当时版本。真实平台Native请求、微信客户端扫码和真实到账尚未验收。

## 16位新单编号

2026-11-07：新微信订单在建单事务内通过既有0018只追加绑定固定16位业务单号；原已存在订单保留原32位支付引用，不根据已有order_no强行更换平台单号。新旧别名、跨渠道及无绑定的16位号拒绝处理。

Native请求/恢复前查询、支付通知定位/验证、主动查单及先查后关再查均使用固定支付编号。退款按原微信交易号提交，核对退款查询/通知中的out_trade_no时使用同一16位绑定，退款号仍为独立R＋ID。幂等账本、原授权和审计关联继续使用内部ID，不把可见订单号误当作授权标识。

36项新号签名/MySQL/真实HTTP检查及Native商店浏览器通过，原适配119/结算90/缓存43/查关单168/退款74/通知58/复核48/商店22回归通过，见微信编号验证。到账待核对随后已接入：98项签名查单、额外收款、退款、并发/审计/HTTP及独立浏览器通过，见微信财务核查验证。交易账单已接入GZIP/SHA1、公网DNS/HTTPS/32MiB及审计，132项专项和账单浏览器通过，见微信账单验证。钱包充值已接入微信Native，独立入账/退款预留及核查，见钱包充值验证。门禁仍关闭；真实渠道验收继续。

## 后续实施顺序及门禁

1. 用户已确认普通商户Native及微信支付公钥模式；实际参数与商户归属核对仍待真实渠道验收。
2. 中文后台配置、加密/不回显/清除、权限/审计、可信材料轮换及默认关闭已实现，80项配置检查及真实本地浏览器通过；不能据此提前开放支付。
3. Native/查询/关单/退款/账单内部适配及不可变商户快照、付款回调/查询共用结算已实现。90项真实隔离MySQL/本地HTTP及独立进程并发检查通过；证据见P4微信结算验证。Native建单和唯一商店二维码现已接入；避免钱包混付，继续查关单任务和财务流程。
4. 先查再关再查、通知丢失恢复、单主批量任务、60秒冷却及关闭购买后处理既有订单已实现；168项检查含独立进程付款/关单竞态通过，详见微信查关单验证。生产调度仍待P7。财务后台查询/关单已按独立权限及意图/结果审计接入，62项服务/HTTP及Edge角色/二次确认/手机键盘检查通过，详见微信财务验证。
5. 微信退款核心已实现：固定退款号查询确认、只追加申请/成功凭据、部分保持授权/累计全退撤权、独立权限与事务审计、租约及异常只查询；74项核心/独立进程并发与96项迁移权限通过，见退款核心验证。订单额与现金额分开保存；退款回调与单主查询任务已接入，58项真实MySQL/HTTP/独立进程/CLI检查通过，见退款通知与任务验证。通知省略币种时只信任原收款CNY快照，附加认证数据可省略为空，但仍必须通过原文签名和GCM认证。成功通知/查询共用事务，审计失败回滚并允许重试，迟到异常不降级已确认成功。任务只推进已有审批，异常/关闭保持只查询。财务UI/API现已接入：36项真实HTTP及Edge二次确认/真实审批/只查询/角色/手机检查通过，见微信退款财务验证。独立微信路由，不走支付宝/钱包退款。人工复核现已接入：只查询/原号重试、严格未领取撤销及只追加凭据，48项服务/并发/HTTP、102项数据库权限及三角色浏览器通过，见微信退款复核验证。不能把申请受理或人工决定当成退款到账。
6. 按日期申请并校验下载账单/哈希已实现：微信固定GZIP、SHA1、官方主机路径、公网DNS、32MiB、无代理/跳转及审计；真实商户账单文件仍待P7核对。
7. 钱包充值独立订单目的/入账/退款模型，防止充值触发授权或重复加余额。
8. 真实商户配置、受控金额支付/退款、账单核对和生产门禁归P7；当前不开放真实渠道，不把本地111项当成这些验收。

## 官方依据

- [APIv3请求签名规则](https://pay.wechatpay.cn/doc/v3/merchant/4012365336)
- [公钥模式接入易错点](https://pay.wechatpay.cn/doc/v3/merchant/4054257713)
- [平台证书验签](https://pay.wechatpay.cn/doc/v3/merchant/4013053420)
- [通知报文认证解密](https://pay.wechatpay.cn/doc/v3/merchant/4012071382)
- [Native支付成功通知](https://pay.wechatpay.cn/doc/v3/merchant/4012791882)
- [Native下单](https://pay.wechatpay.cn/doc/v3/merchant/4012791877)
- [Native退款申请](https://pay.wechatpay.cn/doc/v3/merchant/4012791883)
- [Native订单查询](https://pay.wechatpay.cn/doc/v3/merchant/4012791880)
- [Native二维码调起](https://pay.wechatpay.cn/doc/v3/merchant/4012791878)
- [Native退款查询](https://pay.wechatpay.cn/doc/v3/merchant/4012791884)
- [Native退款结果通知](https://pay.wechatpay.cn/doc/v3/merchant/4012791886)
- [交易账单申请](https://pay.wechatpay.cn/doc/v3/merchant/4012791866)

文档核对时点为2026-11-06；用户已确认产品及公钥模式，最新字段、商户材料归属与真实配置仍需在相应业务接入及P7时核实。
