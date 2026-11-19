# 支付宝电脑网站支付迁移状态

2026-11-06；P4进行中。商家自研、RSA2公钥模式；当前已实现后台参数、alipay_pc建单、iframe收银台、支付通知、主动查单、超时关单、财务订单后台、幂等授权结算及支付宝部分/全额退款；财务交易账单查询及下载也已实现；真实渠道验收仍待完成。

## 已实现的配置

当前入口（2026-11-08）：管理控制台 → 支付接口 → 支付宝（`/admin/settings/payments#alipay_pc`）。旧 `/admin/settings/alipay` 地址跳转到统一页面。新页面使用 `/api/v2/admin/operations/payment-interfaces`，同页维护 PayPal、Stripe、微信和支付宝；首次保存会在服务端导入旧密钥，关闭接口不回退到旧配置。以下原接口说明保留供兼容与历史验收参考。
API：GET/POST /api/v2/admin/settings/alipay，独立管理员身份与payment.configure权限；POST另要求CSRF及同源JSON。

POST格式为values对象和可选clear_fields数组。values字段均为字符串，enabled使用true/false字符串。支持：
enabled、environment、app_id、seller_id、private_key、alipay_public_key、notify_url、app_gateway_url、return_url、qr_pay_mode、qrcode_width。

- 数据库保存到portal_settings/alipay.v1，应用私钥及支付宝公钥分别用主密钥加密和字段上下文绑定。
- 页面/API不回显密钥，只返回secrets_configured布尔状态。空密钥保留原值；clear_fields显式清除。已保存数据库配置优先，清除后不从环境复活。
- 开启前要求APPID、商家PID和两把RSA密钥格式有效。当前实现允许2048～4096位RSA PEM或裸Base64 DER；不读取任意密钥文件，不启用证书模式或服务商授权令牌。
- 查看/保存均记录操作者、时间和来源IP。保存与审计同事务，审计失败回滚。
- 配置完整且enabled=true时，返回checkout_integrated=true；CNY收银台首位默认支付宝，另有钱包可切换。新订单与打开收银台还受购买总开关约束，默认关闭；既有通知不受购买/渠道开关影响。

环境变量回退及全部参数见.env.example。D2S_ALIPAY_ENVIRONMENT只接受production或sandbox，网关分别固定为https://openapi.alipay.com/gateway.do和https://openapi-sandbox.dl.alipaydev.com/gateway.do，不接受自定义服务器地址。

## 回调与二维码

| 配置 | 当前默认值/约束 |
|---|---|
| notify_url | https://d2s.site/api/v1/alipay/d2s/notify |
| app_gateway_url | https://d2s.site/api/v1/alipay/app-gateway |
| return_url | https://d2s.site/alipay/return（推荐用于iframe） |
| qr_pay_mode | 0/1/3/4，由管理员选择，默认4 |
| qrcode_width | 默认240，本站允许75～600，仅模式4发给支付宝 |

URL必须为D2S_ALLOWED_ORIGINS允许的本站HTTPS域名及对应固定路径，不允许查询参数、片段、用户信息或自定义端口。d2s.top可使用同路径。

GET /api/v1/alipay/app-gateway返回纯文本success且不跳转；POST应用网关与POST /api/v1/alipay/d2s/notify共用处理。其余旧客户端/api/v1仍返回426。仅本地HTTP已验证，尚未进行生产公网HTTPS验收。

二维码参数生成遵循[官方页面支付接口](https://ideservice.alipay.com/cms/site/0izblh)。0/1/3的iframe最低尺寸分别为600×300、300×600、75×75；4使用自定义宽度。iframe使用签名POST，不跳走主页面；0/1/3实际框架分别600×300、300×600、240×240，4在二维码宽高基础上预留边距。移动端0模式在可键盘聚焦区域内横向滚动。仅收银台打开后每5秒读取本站订单，浏览器标签页处于后台时每15秒，至订单到期停止自动轮询；可手动检查。return_url只提示父页重新查询，消息和查询参数均不能直接确认支付。保留旧/shop返回配置兼容，但iframe建议使用新/alipay/return静态核对页。

## 协议实现与已验证边界

AlipayClient::pagePay生成签名参数而不访问网络。订单只接受CNY、pending/alipay_pc、整数金额、D2S前缀订单号；金额不超过10000000000分，剩余期限60秒至15天。金额格式化使用整数商/余数，时间使用Asia/Shanghai。

请求签名包含sign_type=RSA2；通知验签排除sign和sign_type，使用支付宝公钥，随后核对APPID、卖家ID及存在时的auth_app_id。解析表单拒绝重复字段、数组字段、畸形编码、无效UTF-8及超64KiB内容。AlipayPayments继续核对notify_type、订单归属渠道、CNY、精确金额、trade_status、notify_id及trade_no后才结算；AlipayClient本身仅返回验签字段，不直接发放授权。

API调用支持查询/关单/退款/退款查询/账单地址查询的签名和HTTPS传输。传输校验证书、禁止重定向、固定目标网关、超时15秒、限制响应1MiB。未知传输结果报告alipay_transport_unknown，不当作已付款或已退款。

API验签针对响应对象原始JSON，拒绝重名字段及错误响应节点，匹配[官方SDK验签路径](https://github.com/alipay/alipay-sdk-php-all/blob/master/v2/aop/AopClient.php)。仅增加官方SDK同样采用的转义斜杠兼容；不重编码整个JSON，不把“响应节点名=JSON”作为原文。通过响应验签仍不等于业务成功，后续必须再核对交易身份、订单和金额。

## 支付快照与结算

0006_payments新增支付尝试、收款凭据与事件表，运行账号仅INSERT/SELECT。下单将完整商户参数加密绑定订单；后续改APPID、关渠道或清除现行密钥，不使旧订单改用新商户。

同一用户先锁用户行再处理订单。事件号及平台交易号去重；收款凭据、订单、正式权益、履约与行为审计同事务。审计失败全部回滚，平台可以重试。钱包与支付宝共用OrderFulfillment，不混合资金。平台账户仅保存buyer_id的域隔离HMAC，缺失则NULL；不保存原始通知正文或支付邮箱。

有效到账晚于本地过期时间仍保留并处理，不能因本地超时丢单。账户限制、已有正式权益、已关闭订单后的付款或同订单不同平台收款均进入payment_review，凭据保留但不重复授权；可按独立收款发起退款，但待核对案件的完整结案流程仍待完成。已退款状态收到迟到通知不会恢复权益。WAIT_BUYER_PAY保持待付款，可信TRADE_CLOSED通知才释放未支付占位。未知产品通知验签后只记录类型摘要、来源IP、时间等元数据并拒绝。

行为PURCHASE事件记录回调网络上下文；用户下单来源IP独立保留在订单中，不将平台回调IP冒充用户原始IP。

## 主动查询、关单与对账任务

AlipayPayments::query使用订单加密快照发起alipay.trade.query，校验原始JSON响应签名、out_trade_no、精确total_amount及已知交易状态。通知与查询调用同一个私有settle事务，不能从HTTP提交所谓“已验证证明”。重复通知、重复查询及独立进程并发只形成一次收款和履约。

[官方查询响应](https://ideservice.alipay.com/cms/site/0izam7)不保证返回APPID、卖家ID或币种；这些字段出现时严格核对，缺失时依据固定网关、已签名的原商户请求、已验签响应及原CNY订单建立关联，不伪称逐字段取得了平台证明。买家标识存在时仅作HMAC，不存原始查询正文。

closeExpired先查；未到期只查询。到期且平台仍WAIT_BUYER_PAY时调用alipay.trade.close；[官方关单响应](https://aipay.alipay.com/docs/vibe-pay/ai-web-app-payment-qianyi/api-list/alipay-trade-close.html)没有trade_status，必须验签、code=10000、本站订单号及有效交易号匹配后才在同一结算事务记录关闭。平台省略可选关联字段时保留未知，宁可重试也不误关。关单失败再查，可恢复付款赢得竞争或平台已经关闭的情况。网络/签名/金额/审计失败不取消订单。

主节点定时命令：`php bin/alipay-reconcile.php`。通过受限进程环境注入D2S_DB_DSN/USER/PASSWORD及D2S_MASTER_KEY；不把秘密放命令参数。建议任务每分钟调用一次，不在本阶段配置生产任务。

- 同一数据库GET_LOCK互斥，其他连接占用时返回busy=true；finally释放，连接终止由MySQL释放。
- 默认每批10笔，服务支持1～50；仅扫描pending/alipay_pc；创建或上次查询不足60秒不重查，优先最久未检查的订单。
- 网络调用不持有本地事务或用户行锁。查询事件追加到原仅INSERT/SELECT表，不新增可修改日志。
- 输出busy/processed/failed；存在失败返回非零，需监控告警。交易不存在或平台业务状态未知保持pending并重试，可能长期占位；不提供后台强制本地取消旁路。
- 当前开关关闭或现行密钥清除不停止既有订单恢复，仍使用原快照。已支付订单的退款核对由独立bin/alipay-refunds.php任务处理。

## 财务后台

入口/admin/orders，所有页面/API要求独立管理员。财务角色新增orders.reconcile和orders.close；客服只有orders.read，安全或系统配置角色不自动具有财务权限。新建财务账号自动取得新权限，既有账号不静默扩权；后续权限管理仍属P5。

| 方法与路径 | 权限与约束 |
|---|---|
| GET /api/v2/admin/payments/orders?page=1 | orders.read，每页50条，完整分页总数；记录VIEW_ORDERS |
| POST /api/v2/admin/payments/orders/{id}/query | orders.reconcile，同源JSON及CSRF；核对原商户 |
| POST /api/v2/admin/payments/orders/{id}/close | orders.close，另需confirm_close=true；本地pending且alipay_pc，先查后关，可在本地到期前主动关闭 |

关单有浏览器二次确认；服务端不依赖按钮隐藏。外部请求前记录PENDING意图，审计写入失败不访问平台；请求后用相同operation_id记录结果、操作者、时间与来源IP。平台结果未知记PENDING，异常记FAILURE。外部动作与本地审计不能组成跨平台事务；如果平台动作成功但后续本地提交失败，已有意图保留，定时查询恢复订单，不能把HTTP503视为平台未执行。待付款列表和支付操作不回显密钥、加密快照或支付账户原文。

## 退款数据与业务边界

0007_refunds新增：

- portal_payment_fulfillments：不可修改的收款与授权履约关联；新支付在结算事务追加。既有P4只有一笔收款时补关联，多笔不猜，缺失关联的历史履约拒绝退款并要求核查。
- portal_refund_requests：管理员确认的退款意图，保存订单、收款、整数金额、原因、操作者、幂等键和生成时间；运行账号仅INSERT/SELECT。
- portal_refund_receipts：平台逐退款号确认的金额凭据，运行账号仅INSERT/SELECT；不保存原始响应或买家账户。
- portal_refund_states：可变任务状态、次数、下一次时间、租约及发送时间；不作为金额账本。

迁移使用独立迁移账号，旧迁移文件不改校验和。部署时新建包含新表显式权限的受限运行账号；原provision-account不接受向已有高权限账号追加授权。本阶段没有执行生产迁移。

同一用户/订单行锁保护退款预留。每笔请求必须指定已核实payment_id，HTTP路径订单必须与之匹配；累计申请金额不得超过该收款整数金额；未核对退款继续占用额度，不能通过换幂等键绕过。同键同金额/原因返回原请求，改变快照拒绝；一笔收款同一时刻只处理一笔退款，后续退款另守3秒发送间隔。支付宝与钱包不混付；本实现不提供钱包退款旁路。

部分退款确认后保留正式授权。授权对应收款累计确认全退时，同事务写凭据、累计订单金额、标记refunded、撤销原权益、提升权益会话版本并删除当前客户端会话；不重置试用。已因其他收款冲突进入payment_review的订单保留核查标记，金额照常更新、应撤权照常执行。额外重复收款可独立退款，但不会撤销原收款取得的授权。离线旧机仍遵循既定签名凭证上限，不承诺断网即时停机。

## 退款执行与恢复

[官方退款接口](https://aipay.alipay.com/docs/vibe-pay/ai-web-app-payment-qianyi/api-list/alipay-trade-refund.html)的refund_fee是累计退款额，不与本次部分退款金额强行相等；fund_change=Y/N或code=10000均不直接在本站记成功。本实现统一在后续至少60秒后调用[退款查询](https://aipay.alipay.com/docs/vibe-pay/ai-web-app-payment-qianyi/api-list/alipay-trade-fastpay-refund-query.html)，要求REFUND_SUCCESS、固定D2SR退款号、原交易号、订单号、逐笔退款金额及原支付总额一致后确认。可选身份/币种出现时同样严格核对。特殊可选关联字段缺失时保留未知，不猜测成功。

先追加管理员意图，再由`php bin/alipay-refunds.php`消费已批准请求；POST提交本身不调用平台，也不发出已退款假消息。任务默认每批10笔、独立数据库单主锁；每请求120秒租约、60秒冷却。发送前持久化unknown，进程终止后保留额度；租约过期恢复先查询。网络请求不持有本地事务。

未知退款先查同一退款号；明确查到成功即记账；查询code成功但缺少退款状态时，再查原交易，只有原订单/交易/金额一致且仍TRADE_SUCCESS才用完全相同的退款号/金额/原因重试。业务错误、未知状态、签名错误或信息矛盾不能盲目新建退款号。已发送请求即使返回业务错误也保留预留；人工复核可改为只查询或恢复同号重试，只有可核实从未发送的请求可撤销，详见下文。

审计意图失败不发起平台调用。确认凭据、累计金额、撤权与管理审计同一事务；确认审计失败全部回滚，保留原请求，恢复后再查。自动任务审计operator_id为NULL，明确automatic和批准管理员；不把任务伪装成人工操作。实际请求的审批人及来源IP保留在REQUEST_REFUND审计。

## 财务退款界面与接口

/admin/orders的“退款与记录”只对refunds.manage开放，客服和系统/安全角色不自动得到。页面选择具体收款，显示保留额度后的可退金额，原因只作为文本展示；输入人民币元，在浏览器按十进制拆分成整数分。先核对金额/原因，再确认提交；失败重试保留幂等键。页面有退款记录分页、手动刷新和处理核对按钮，pending/unknown/succeeded分别展示待执行/待平台核对/已确认退款。

| 方法与路径 | 权限和输入 |
|---|---|
| GET /api/v2/admin/payments/orders/{id}/refunds?page=1 | refunds.manage；收款/预留/确认金额与每页50条退款记录 |
| POST /api/v2/admin/payments/orders/{id}/refunds | refunds.manage、同源JSON/CSRF；payment_id、amount整数分、reason、idempotency_key、confirm_refund=true；只保存待执行意图 |
| POST /api/v2/admin/payments/refunds/{id}/process | refunds.manage、同源JSON/CSRF；处理已保存请求，尊重租约/冷却，不能提交任意平台证明 |

任务输出busy/processed/confirmed/pending/cancelled/failed；failed>0返回非零，仍待核对的业务结果保留pending。运维应告警长期unknown，不能把pending计数视为渠道验收成功。当前账号角色开通、任务配置与真实退款仍须在P7验收后安排。

## 财务交易账单

入口/admin/bills，独立管理员及bills.read；客服、安全与系统配置角色不自动获得权限。POST /api/v2/admin/payments/bills/download要求同源JSON和CSRF，输入bill_date，以及可选的历史支付宝order_id。不接受客户端提交下载URL、账单类型或商户密钥。

调用[官方查询账单地址接口](https://aipay.alipay.com/docs/vibe-pay/ai-web-app-payment-qianyi/api-list/alipay-data-dataservice-bill-downloadurl-query.html)，固定bill_type=trade。支持已结束的日账单YYYY-MM-DD与月账单YYYY-MM，按Asia/Shanghai校验近六年及真实日历；当日/当月、越界和非法日期拒绝。账单生成可能延迟，BILL_NOT_EXIST/NO_BILL_DATA展示暂不可用，不伪造空账单。APPID/商户/日期/类型可选响应字段出现时核对，响应必须验签。默认使用当前商户配置；填写历史订单时用其原加密商户快照，不受新购买开关影响。

官方文档示例仍返回HTTP地址；本站仅接受dwbillcenter.alipay.com的/downloadBillFile.resource，将该主机的HTTP地址升级为HTTPS并保留原查询串，不允许TLS失败后降级。拒绝用户信息、片段、其他端口、其他主机/路径、控制字符或反斜线。取到临时地址后立刻在本次请求下载，不把签名URL暴露给浏览器或写入审计。

DNS结果必须全部是公网单播地址；拒绝回环、私网、链路本地、CGNAT、保留地址、映射IPv4及组播。CURL固定选定地址、校验原主机TLS、禁用代理环境和重定向，只允许HTTPS。连接5秒/下载25秒超时，最多32MiB，仅在头部符合ZIP特征时转交文件，不解压执行，不在本站长期保存。超限、非ZIP、网络或TLS失败拒绝输出；大账单需另外安排受控能力，不能取消限额或改用任意地址。

下载前留PENDING意图，取得并校验文件后、输出前写SUCCESS，保存日期/类型、商户应用、字节数、SHA256、管理员及来源IP；异常写FAILURE。审计失败不向浏览器交付文件。SUCCESS表示服务端已准备好被审计的字节，不证明用户实际保存到磁盘。响应为application/zip、固定安全文件名alipay_trade_日期.zip，继承no-store；原文和临时地址不落审计。

本地176项签名/日期/URL/DNS/大小/审计/HTTP与财务浏览器检查通过。测试使用合成平台签名和文件替身，默认CURL的真实支付宝TLS/地址/文件格式仍需P7渠道验证；不把本地下载替身当成真实账单。无新增迁移、配置密钥字段或数据库权限。

## 到账待核对处理

/admin/orders增加“到账待核对”筛选及“处理财务核查”窗口。独立orders.resolve权限加入财务角色模板，其他管理角色不自动拥有；已有管理员不会静默追加权限。查看证据写VIEW_PAYMENT_REVIEW审计，提交需同源JSON、CSRF、处理原因、幂等键及confirm_resolution=true。

| 方法与路径 | 内容 |
|---|---|
| GET /api/v2/admin/payments/orders?status=payment_review&page=1 | orders.read；先筛选后分页，每页50条 |
| GET /api/v2/admin/payments/orders/{id}/review?page=1 | orders.resolve；不可变收款、已确认退款、未决退款数量、履约关联、每页50条处理记录及总数 |
| POST /api/v2/admin/payments/orders/{id}/review | action=retry_fulfillment或close_case、reason、idempotency_key、confirm_resolution=true；重试需payment_id，结案须空 |

重试发放：只能处理支付宝payment_review订单，指定不可变收款须尚未退款；先使用原加密商户快照向平台查询，验签并核对成功状态、交易号、订单、金额及出现的身份/币种字段。外部I/O不持有本地事务；进入事务锁住用户及订单后重新核对全部收款和退款。额外收款必须已确认全退，任何未决退款均阻断；账户须激活、已验证联系方式且未受登录/购买限制。调用原统一履约逻辑，已有正式/被撤销授权不得借此重购或重置试用。购买总开关关闭不阻断已收款的合规履约。

结案：已有明确履约关联时，所有额外收款须全退，主收款凭据决定paid/partially_refunded/refunded；有未退部分仍要实时签名查单。全部已确认退款时不额外调用平台。无本订单履约时须每笔收款均全退才可结案，绝不撤销属于其他订单/来源的授权。历史履约关联缺失则拒绝猜测，不能手工指定支付成功、退款成功、金额或授权状态。

0009_payment_resolutions仅新增不可变处理表：操作者、原因、动作、幂等键、结果、证据快照及SHA256。运行账号仅SELECT/INSERT，不属于通用留存清理表。成功的状态更新、履约/必要撤权、处理凭据及成功审计在同一事务；审计失败全回滚。调用前先写PENDING意图，失败追加FAILURE。相同键/相同动作、收款、原因返回原结果；并发不重复发放。原收款及退款凭据不修改。

平台未知退款仍占预留额度，此订单结案窗口不能释放、取消或伪造确认。退款人工复核使用下节独立入口，仅可证实未发送的请求允许撤销；已发送不作废。关联不明确或平台后台另行发起的站外退款仍保留待核查，不能上传人工证明直接改成成功。真实渠道门槛不因本地功能而完成。

验证证据：财务待核对记录。

## 退款人工复核与预留释放

/admin/orders可筛选refund_pending（退款处理中）；到账冲突订单仍用payment_review筛选。在“退款与记录”中选择“复核退款”。独立refunds.resolve权限加入财务角色模板，refunds.manage、系统配置或安全角色不自动获得；既有账号不静默扩权。

| 方法与路径 | 内容 |
|---|---|
| GET /api/v2/admin/payments/refunds/{id}/review?page=1 | refunds.resolve；当前模式、领取/发送证据、原订单/收款及每页50条复核历史；审计VIEW_REFUND_REVIEW |
| POST /api/v2/admin/payments/refunds/{id}/review | action、reason、idempotency_key、confirm_review=true；同源JSON及CSRF、浏览器二次确认 |

- cancel_unsent：锁用户→订单→退款状态，确认没有成功退款凭据、没有有效租约，状态为pending/unknown且last_sent_at=NULL。退款接口调用前会持久化last_sent_at，因此“只查询过”或“领取后发送前崩溃”也能在租约结束后复核撤销。追加不可变取消标记并清除旧租约Token；旧任务发送前更新匹配不到Token时必须停止。原申请和任务记录保留，不生成退款成功凭据、不返余额、不撤销或恢复授权。
- 取消标记从申请金额预留和待处理统计中排除。主授权收款原为refund_pending且已无未决请求时，依据既有确认退款额恢复paid/partially_refunded/refunded；payment_review标记保留。原申请幂等键仍返回cancelled，只有新的明确审批才创建新申请。
- pause_retry：改为query_only。自动任务和人工“仅核对退款”都只按原退款号查询；没有成功证据继续保留预留，不能再调用退款或原交易查询来触发重发；若查询确认REFUND_SUCCESS，仍按原事务记账并执行全退撤权。
- resume_retry：恢复automatic；不立即发起退款，也不绕过冷却。已领取过的请求之后先查询原退款，再核对原交易，符合既有条件时使用同一退款号、金额和原因重试；未领取过的请求恢复正常首次发送。

有效租约内拒绝更改；成功/已取消请求拒绝新决定。相同幂等键、动作和原因返回原决定，不重新应用旧模式；改变快照拒绝。复核历史显示决定后的模式，当前状态单独读取，避免把旧记录当成当前设置。

0010_refund_review只新增portal_refund_review_actions（决定、原因、操作者、证据SHA256及快照）和portal_refund_cancellations两个只追加表，以及可变portal_refund_controls。运行账号对前两表只SELECT/INSERT。取消标记/模式、订单修正、决定及REVIEW_REFUND成功审计同事务；审计失败全部回滚，失败另追加审计。不改旧迁移校验和、不删除原申请。部署仍需独立迁移账号及新受限运行账号，本阶段不部署生产。

[官方退款查询说明](https://aipay.alipay.com/docs/vibe-pay/ai-web-app-payment-qianyi/api-list/alipay-trade-fastpay-refund-query.html)区分查询成功与退款成功，重试须保持退款号及金额。本站额外以发送前持久化标记为取消依据；已发送或可能到达平台的退款不允许人为标失败或释放。长期未知可选择只查询并保留核查记录，待渠道明确确认后继续处理，不能换退款号重新占额。外部请求已开始后不能靠切换模式撤回；未知结果照旧留存核对。

验证：退款人工复核记录。

## 16位订单号切换

2026-11-07新单使用YYMMDDHHMMSS＋4位秒内流水的16位业务订单号，不加D2S前缀。内部关联ID和退款请求号保持各自用途，不能从支付编号截取内部ID。

0018新增只追加portal_merchant_order_numbers，新支付宝订单与商户快照在建单同一事务绑定业务编号，绑定外键核对原订单号。已有订单不回填绑定，即使此前已新增16位展示号，仍按原D2S＋内部ID处理，避免改掉已提交平台的单号。

下单参数、通知定位、查询/关单、退款提交/查询结果核对和财务补发均读取固定绑定。16位通知只查明确绑定；新单拒绝旧格式别名，旧单拒绝直接拿展示号冒用支付号；仍必须原文验签与商户/金额/币种核对。运行账号只有INSERT/SELECT，不能更改/清空绑定。

43项新编号MySQL/签名/真实HTTP检查通过，旧结算58/查关单160/退款178/财务核查155回归通过；实际浏览器四模式建单、原文签名通知、订单业务号和收银台编号一致性已通过，证据见编号验证。微信与通用页面展示继续实施，真实支付宝和生产仍未验收。

## 继续实施

1. 真实渠道及异常场景验收；钱包原币种退款见[交易接口](commerce-api.md#钱包退款)。
2. 充值与微信继续实施；本人历史分页见[交易接口](commerce-api.md)，付款/确认退款的可靠通知见[通知接口](transaction-notifications.md)。
3. 真实支付宝页面、扫码到账、退款、账单和生产HTTPS网关验收。浏览器对支付宝网页使用替身，只证明本站集成；不代表真实渠道。

完整重构基线仍为网站PHP重构计划书，不能用本阶段范围替代完整P4要求。
