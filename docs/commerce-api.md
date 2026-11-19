# 商店、订单与钱包接口

2026-11-07；P4本地开发完成，真实渠道、NTP和生产验收归P7。本文件描述已落地的本地版本：单页商店、钱包、支付宝和微信Native、查单/关单、退款/复核、账单、通知及独立钱包充值均已实现；任何合成平台替身测试都不等于真实渠道验收。

## 页面和身份

- /shop：唯一购买页面，价格卡片、报价确认、下单、钱包支付及订单状态在同页完成。
- /wallet：按币种显示当前余额及可持续翻页的本人资金记录，不创建第二个购买页面。
- /admin/commerce：商品价格与购买开关。页面按实际权限分别展示表单。
- /admin/orders：分页订单、财务查单及二次确认关单；客服只读，系统配置与安全角色不自动取得财务权限。
- /admin/bills：财务日/月交易账单下载，独立bills.read权限。
- /profile增加商店与钱包入口。完整五模块导航与中英页面在P6汇总。

所有用户接口使用D2S_USER_SESSION；管理员使用独立D2S_ADMIN_SESSION。POST要求同源JSON与对应CSRF。user_id、金额、商品、币种均由会话和服务端报价决定，客户端额外传入这些字段不会覆盖订单。

## 用户接口

| 方法与路径 | 输入/返回 |
|---|---|
| GET /api/v2/shop/catalog | 正式授权商品、已启用币种价格/版本、购买开关、可用付款方式、本人的formal_owned |
| POST /api/v2/shop/quote | currency为CNY或USD；返回id、整数amount、price_revision、900秒有效期 |
| POST /api/v2/orders | quote_id、payment_method=wallet、alipay_pc或wechat_native（微信默认禁用）、idempotency_key（16～100字母数字下划线短横线） |
| GET /api/v2/orders?cursor=... | 本人订单每页50笔、next_cursor、per_page及独立pending_order |
| GET /api/v2/orders/{id} | 本人订单，否则404 |
| POST /api/v2/orders/{id}/pay | 钱包全额支付；不接受外部渠道订单、混付或客户端金额 |
| POST /api/v2/orders/{id}/cancel | 取消本人未支付钱包订单；关闭购买时仍可操作 |
| POST /api/v2/orders/{id}/checkout | 仅本人订单；支付宝返回签名页面参数和二维码模式；微信返回pending/code_url/expires_at或已确认状态，要求购买/渠道开关可用 |
| GET /api/v2/wallet?cursor=... | CNY/USD当前余额、每页50条资金记录、next_cursor、per_page |

首次请求不传cursor；将响应next_cursor原样传入下次请求，null表示末页。按订单(created_at,id)倒序及钱包账本id倒序做严格小于游标的键集查询，一次取51条判断是否还有下一页，最多返回50条；不使用offset或任意页数上限。订单同秒使用ID区分，不会在同秒边界反复返回一页。钱包编号作为不透明字符串输出，SQL按UNSIGNED精确比较，浏览器不转成Number，避免超过2^53时丢精度。

游标为带版本、资源类型、账户和位置的Base64URL JSON，不是权限凭据或签名证明。每次查询强制使用会话user_id；其他账户或其他资源类型的游标、非规范/超长编码、非法ID和时间返回400 history_cursor_invalid。自填user_id不能读取他人数据。服务内部原orders(userId,before)保留兼容包装，不作为公开分页参数。

用户页提供上一页、下一页、刷新最新记录和本页数量。请求成功才切换页码及游标，失败保留旧页；上一页位置由页面游标栈保存，刷新最新记录重回第一页。翻页只读取记录，不创建报价、订单或付款，不改变正在确认的订单、币种和渠道；pending_order独立查询，即使待付款订单不在当前50条内仍可恢复确认流程。历史错误独立展示且成功后清除；若历史请求忙碌时付款轮询定时器到期，会重新调度核对，不丢失后续付款检查。

向头部插入新记录不造成原有后续记录偏移、重复或遗漏，刷新后可见新头部；这不是跨HTTP请求的数据库快照，订单状态和当前余额仍实时读取，回翻或刷新可能看到新状态。保留页内每笔资金发生后的余额，不把旧页余额当成当前余额。本功能是本人历史浏览，不等于监管导出。

0011_history_cursor_index仅给portal_wallet_entries增加(user_id,id)索引；订单既有InnoDB (user_id,created_at)二级索引已包含主键ID。不修改原账本/订单、旧迁移校验和或新增运行权限。

金额均为整数最小货币单位，范围1～1000000000000；展示时除以100仅用于格式化。无默认售价。

新订单增加order_no字符串：固定16位YYMMDDHHMMSS＋4位秒内流水，Asia/Shanghai，每秒0001～9999；id继续用于内部关系和现有路径。0017新增独立提交序列/不可变映射、订单表order_no唯一索引/绑定外键及禁止改号触发器。建单失败仍保留已发号码，不回退/复用。旧单order_no=NULL，不伪造新生成时间。0018新增只追加支付编号绑定，支付宝及微信新单下单、支付通知、查关单、退款和财务核查已切换16位号；旧单保持原支付单号并拒绝混用别名。用户商店历史、后台订单、退款、财务核查和交易通知统一显示order_no，历史订单无新号时回退原ID；接口路径仍使用内部ID，展示号不作为权限凭据。真实渠道未验收。见[规则](订单号规则V1.0.md)、生成验证、支付宝编号验证和展示验证。

## 钱包充值

充值使用独立`product=wallet_recharge`，不复用正式授权价格、履约或许可证。后台“支付设置”中的钱包充值开关默认关闭，需要`payment.configure`权限；`D2S_WALLET_RECHARGE_ENABLED=true`只作为未保存数据库设置时的显式后备值。

| 方法与路径 | 输入/返回 |
|---|---|
| GET /api/v2/wallet/recharge | 开关、CNY、¥1–¥10,000范围、可用支付宝/微信渠道及本人待付款充值 |
| POST /api/v2/wallet/recharge/quote | 整数amount（分），900秒报价 |
| POST /api/v2/wallet/recharges | quote_id、alipay_pc/wechat_native、idempotency_key |

充值订单进入正常Alipay/WeChat建单、二维码、通知、主动查询、关单及账单链路。通知或财务核查确认成功后调用`portal_wallet_recharge_fulfillments`唯一映射并增加CNY余额，写`RECHARGE`账本；不会调用OrderFulfillment或产生/修改许可证。重复通知、同号查询和并发只入账一次。充值独立于商品购买开关和正式授权状态，但账户必须active、已验证联系方式且未被购买限制。

外部充值退款在提交平台前从余额预留本次退款额，写负向`RECHARGE_REFUND_HOLD`。余额不足则拒绝创建退款，避免用户花掉充值款后形成负余额；平台确认成功后保留预留并写退款凭据/状态，平台明确取消或管理员取消未发送请求时写正向`RECHARGE_REFUND_RELEASE`恢复余额。退款不操作许可证。到账待核对可恢复充值入账，仍无授权副作用。

0020新增只追加充值履约映射和可变退款预留状态；运行账号对履约映射仅SELECT/INSERT，对预留状态可用受控UPDATE。44项核心、两种渠道、退款、核查和HTTP及真实本地浏览器通过，详见钱包充值验证。真实渠道、生产调度及P7未验收。

报价不是付款凭证。新幂等键必须匹配有效报价及当前价格版本；相同用户、相同键且报价ID/方式一致返回原订单，不因报价过期或开关关闭重新创建。换报价或方式返回409 idempotency_conflict。快照表为运行账号仅INSERT/SELECT。

每账户仅允许一个pending_user_id占位。用户行锁加唯一约束处理同键并发、不同键并发及付款并发。已拥有formal权益不能再买；被撤权的formal也不能通过新下单绕过限制。临时购买限制或未验证账户服务端拒绝。

钱包支付在同一事务中扣款、追加账本、升级/创建唯一正式权益、追加履约记录、标记订单和写行为审计。任何一步失败全部回滚。升级保留原权益ID和试用起始时间；首次使用前购买则trial_started_at为NULL，不伪造试用。重复付款返回已支付订单，不重复扣款或发放权益。余额严格按币种隔离。

余额不足不会扣款。钱包订单15分钟未付款后不可再付，可取消释放占位。支付宝订单通过bin/alipay-reconcile.php先查询，平台确认关闭后才释放占位；交易不存在、超时或结果不明不会在本地取消。详见支付宝实施状态。

payment_account_identifier保留NULL，钱包没有外部支付账户标识。email、source_ip随建单快照保存，手机用户可以没有邮箱。没有真实支付通道回执，不宣称外部支付验收通过。

## 微信Native商店接入

同一/shop页面支持微信渠道，顺序为可用支付宝、微信、钱包。支付宝可用时仍默认推荐；外部支付仅CNY且金额不超10000000000分。建单同事务捕获微信商户快照，不混付；默认配置关闭，后台CHECKOUT_INTEGRATED门禁仍关闭，待查关单/退款/账单等后续流程完成后再调整，当前验证只在隔离夹具中写入合成启用配置。

微信checkout复用已实现的WechatCheckout，缓存code_url加密且只追加，超时先查原订单并同参数重试。前端通过本地MIT库生成SVG，不请求第三方制码、不打开外部页面；URI、期限和结构错误拒绝展示。付款结果只读取本站已提交订单状态，二维码、浏览器事件和返回页都不是到账证明。

刷新恢复pending_order及原订单渠道，仍在同一页面展示；默认每5秒查询本地订单，后台页15秒，忙碌时重排。截止后隐藏二维码，即使订单核对暂时失败也不会继续展示旧码；不据前端时钟取消订单或重新下单。微信主动查询恢复及bin/wechat-reconcile.php周期任务已实现，过期先查、关单后再查；实际生产调度尚未安装验收，不能承诺生产已自动恢复通知丢失。Native使用另一台设备微信扫一扫，不宣称支持同机长按/相册识别。

GET订单只读取本站状态，不直接触发平台网络请求。支付通知见[微信接入说明](wechat-integration.md)，本地接口/浏览器证据见P4微信商店验证。真实商户收退款及扫码、通知丢失恢复归P7，尚未验收。

## 管理员接口

| 路径（POST） | 权限 | 输入 |
|---|---|---|
| /api/v2/admin/commerce/price | products.manage（业务角色） | currency、amount（整数分/美分）、enabled（布尔） |
| POST /api/v2/admin/operations/products | products.manage | 在商品设置中保存 `purchases_enabled`、价格与产品资料 |
| POST /api/v2/admin/operations/wallet-recharge | payment.configure | 在支付设置中保存 `enabled`（布尔） |

普通安全审计角色不自动拥有支付配置或价格权限。配置更改与管理审计同事务，审计失败则回滚。页面也检查权限，隐藏入口不能替代API鉴权。

购买开关按商品保存，新商品默认关闭。报价、建单和付款检查所选商品的开关。钱包充值由支付设置单独控制。已有付款回执和退款仍按原订单处理，订单查询和取消保持可用。

## 统一财务查单与关单

/admin/orders已统一显示支付宝、微信、钱包。POST /api/v2/admin/payments/orders/{id}/query要求orders.reconcile；/close要求orders.close及confirm_close=true，继续要求独立管理员、同源JSON与CSRF。客服只读，支付配置/安全角色不自动获得权限。原订单payment_method决定服务分派，请求方不能更换渠道。

管理员意图在平台I/O前提交，结果沿用operation_id和渠道；未决微信关单close_status=pending/unknown与NOT_FOUND/UNKNOWN都记录PENDING并在页面提示保持原状态。只有平台明确关闭后才取消订单。真实服务与HTTP/浏览器证据见微信财务接入验证。退款与待核对继续使用渠道专用服务，不复用支付宝或钱包资金接口。

### 微信到账待核对

`/admin/orders`对微信`payment_review`订单提供“处理财务核查”，复用订单证据弹窗但服务端按原`payment_method`分派到WechatFinancialReviews。仅orders.resolve可读/处理；客服只读、系统/安全角色不自动取得财务核查权限。

GET/POST `/api/v2/admin/payments/orders/{id}/review`使用管理员会话、同源JSON、CSRF和confirm_resolution=true。GET返回内部订单ID、16位order_no、状态、已核实微信收款/累计退款/未决退款、授权映射及每页50条处理凭据。POST支持`retry_fulfillment`（指定收款）和`close_case`（不指定收款）。

恢复发放前在数据库事务外使用原加密商户快照和固定16位支付号执行主动查单，验签后核对状态、交易号、金额、币种及payer_total；REFUND状态只有在已有本地退款凭据时才可作部分退款证据，不能据此发放。事务内重新锁用户、订单和收款事实；未决退款、额外未退回收款、已有授权、受限/未验证账户、历史映射冲突均拒绝。恢复发放与微信履约映射、订单状态和PURCHASE审计同事务。

结案不把人工按钮当作到账成功：全部收款已核实退回时可无网络结案为refunded；主授权收款只部分退回时按本地确认额更新partially_refunded并保留授权；累计全退撤权。无授权映射的订单不会撤销其他订单权益。处理凭据、证据SHA-256和专用RESOLVE_WECHAT_PAYMENT审计不可修改，幂等键重放只返回原结果。

0019只新增portal_wechat_payment_resolutions，运行账号仅SELECT/INSERT。98项服务/退款/独立进程并发/HTTP、111项MySQL新装权限、升级8/5/11、原支付宝155/37/90及浏览器验证通过，详见微信财务核查验证。真实微信查单、TLS、商户归属及生产验收仍待P7。

### 交易账单下载

`POST /api/v2/admin/payments/bills/download`支持`bill_channel=alipay|wechat`，默认alipay保持兼容。仅bills.read，独立管理员、同源JSON和CSRF。支付宝支持日/月ZIP；微信只支持已结束日账单，申请时固定`bill_type=ALL&compress_type=GZIP`。

微信账单使用当前配置或历史orders快照；下载URL只允许HTTPS `api.mch.weixin.qq.com/v3/bill/downloadurl?token=...`，DNS解析只接受公网地址，CURL固定解析、禁用代理/跳转并限制32MiB。文件必须是GZIP，SHA1必须与已验签的申请响应一致；成功返回`wechat_trade_YYYY-MM-DD.csv.gz`。临时URL、文件正文和账单金额明细不长期保存，也不写审计。

开始与结果分别写DOWNLOAD_BILL审计，结果只记录日期、渠道、历史订单内部ID、字节数、SHA1/SHA256及商户应用标识。132项微信专项、原支付宝176项及渠道UI浏览器通过，详见微信账单验证。真实微信账单接口、文件内容和生产验收仍待P7。

微信退款核心及0015迁移、通知、任务和财务页面/API已接入；不要将微信收款交给原支付宝退款接口。当前核心支持已批准申请、同号查询、租约、部分/全额确认及撤权，见微信退款核心验证。

### 微信财务退款

/admin/orders共用退款弹窗，按原订单显示“微信支付退款与记录”，并使用独立路由。要求refunds.manage、独立管理员身份；POST必须同源JSON及CSRF。客服、系统配置、安全角色不自动拥有财务退款权限。

| 方法与路径 | 输入/返回 |
|---|---|
| GET /api/v2/admin/payments/orders/{id}/wechat-refunds?page=1 | 原微信收款、累计确认/预留金额、has_pending、授权关联及每页50条退款记录 |
| POST /api/v2/admin/payments/orders/{id}/wechat-refunds | payment_id、整数amount、1～80字原因、idempotency_key及confirm_refund=true；仅创建审批，不发起平台网络请求 |
| POST /api/v2/admin/payments/wechat-refunds/{id}/process | 空JSON；推进原审批，遵循租约/冷却，只以验签后的平台确认记为成功 |

GET拒绝不存在及非微信订单；POST核对收款确属URL订单。同键同快照返回原申请，异快照冲突。已有未决申请时不允许再预留，页面同时隐藏该收款的申请表单；服务端仍独立校验，不能仅靠页面限制。退款/处理/查看均沿用服务审计，审批记录操作者与来源。

页面二次确认显示渠道、金额和原因；使用文本节点，不将原因解释为HTML。微信金额按订单额填写，优惠可能使实退现金不同；已确认记录单独显示payer_refund，NULL不显示为零。PROCESSING/ABNORMAL/CLOSED均有中文状态，异常/关闭只查询，不表示资金退回。微信人工复核使用下述独立接口，页面按原订单渠道加载复核面板。

本轮36项真实HTTP及Edge实际申请/冷却查询、权限、手机/键盘检查通过，见微信退款财务验证。未调用真实平台，启用门禁仍关闭；到账待核对结案继续实施。

`POST /api/v2/wechat/refund-notify/{64位profile_id}`是独立微信退款通知入口；基址无后缀返回404。该入口使用原文签名和AES-GCM认证，不要求浏览器用户会话/CSRF，不允许以此创建退款审批。只处理本地已有的批准申请，按原商户、原订单、交易号、退款号及订单额/现金额核对。同事件冲突拒绝；通知与查询共用同一确认事务，提交事件、凭据、累计退款、必要的撤权和审计后才返回空204。错误或数据库忙碌不返回成功，平台可重试。通知中的币种省略时仅使用原收款的CNY快照，明确不同币种仍拒绝。

`php bin/wechat-refunds.php`推进已批准退款，使用受限运行环境、数据库单主锁、默认每批10笔/内部上限50笔及持久化冷却/租约；不创建新审批。ABNORMAL/CLOSED进入query_only，后续未查到记录也不会重新发送退款；只有明确SUCCESS才确认金额。不明结果保留预留；人工复核只允许释放未被领取且未发送的申请，已尝试记录不能释放。输出busy、processed、confirmed、pending、failed计数；发生单笔异常或全局配置失败返回非零。尚未安装生产调度，真实微信调用未验收。证据见退款通知与任务验证。

### 微信退款人工复核

GET/POST `/api/v2/admin/payments/wechat-refunds/{id}/review`要求独立refunds.resolve权限。GET返回退款状态、是否撤销/确认、领取次数、租约及每页50条复核凭据；POST接受action、1～256字reason、idempotency_key及confirm_review=true，同源JSON与CSRF。财务页面二次确认后提交，客服、只有refunds.manage的角色无法复核。

action支持pause_retry（保留预留、只查询不重发）、resume_retry（恢复原退款号自动查询/重试）和cancel_unsent。撤销条件为pending、attempts=0、last_sent_at=NULL、没有有效租约、没有确认凭据/既有撤销。曾领取但结果未知的申请，即使没有发送时间，也不释放预留。恢复不会创建新审批或退款号，不将人工决定视为成功到账。

0016只新增portal_wechat_refund_review_actions与portal_wechat_refund_cancellations，两表运行账号仅SELECT/INSERT；原申请及凭据不删除、不改写。复核证据保存关键状态JSON及SHA-256，记录操作者/原因/时间，并同事务写管理审计。相同复核键且内容一致返回原结果，改变内容拒绝；确认成功/已撤销为终态。

撤销与任务领取采用账户→订单→任务的统一锁顺序；撤销先完成则任务不发送，领取先完成则不能撤销。撤销后从预留计算和任务批次排除，必要时仅恢复原订单paid/partially_refunded，不改已确认金额、授权或钱包；原幂等申请仍返回已撤销记录，新申请需新键。异常的已撤销退款通知拒绝自动入账，需核查，不能重新占用已释放预留。

实测48项服务/独立进程锁竞争/HTTP，102项MySQL权限、升级及三角色浏览器通过，见微信退款复核验证。无真实微信调用。

## 钱包退款

/admin/orders与支付宝共用财务退款弹窗，按订单渠道分别使用独立接口。仅refunds.manage，独立管理员、同源JSON和CSRF；必须先核对再确认提交。用户不能自助退款，客服/系统/安全角色不自动取得权限。钱包只退回原付款账户及原币种余额，不调用支付宝，不等于提现。

| 方法与路径 | 输入/返回 |
|---|---|
| GET /api/v2/admin/payments/orders/{id}/wallet-refunds?page=1 | 已核实原扣款、累计确认金额及每页50条退款记录/总数 |
| POST /api/v2/admin/payments/orders/{id}/wallet-refunds | amount整数分/美分、reason、idempotency_key、confirm_refund=true；成功即succeeded |

订单必须为wallet渠道及paid/partially_refunded。按purchase:订单号查不可变原扣款，金额为原订单负值，用户、币种、订单、PURCHASE类型均必须匹配；无扣款不能仅凭paid状态造退款。累计不可变退款之和必须等于订单refunded_amount，再按原金额校验本次上限。前端传user_id、currency、payment_id都不能改变退回账户、渠道或币种。

用户→订单→原币种钱包的统一锁顺序处理并发。同一事务增加余额、追加REFUND资金记录与不可变退款凭据、累计订单金额、状态和管理审计；同键同金额/原因返回原凭据，不重复入账；换快照拒绝。超过余额上限拒绝，不能截断金额。

部分退款保持授权及客户端会话；累计全退同事务撤销原履约权益、提升会话版本并删除当前客户端会话，不重置试用。购买关闭、账户购买/登录被限制时仍允许财务归还原扣款。断网客户端仍遵循既定签名凭证到期上限。

审计失败回滚资金、退款凭据、订单及撤权；既有资金记录不修改/删除。用户钱包显示“退款退回余额”，商店区分部分退款/退款处理中/已退款。退款记录分页与本人订单/钱包历史游标分页均已完成；监管导出仍由后续审计模块实现。

0008_wallet_refunds只新增portal_wallet_refunds，运行账号仅SELECT/INSERT。不回填或伪造历史退款，不改旧迁移校验和；升级保留原钱包余额、扣款、订单及权益，已有累计退款与凭据不一致时拒绝自动操作。部署仍需迁移账号及新的显式受限运行账号，本阶段未部署生产。

## 存储和运维

0005_commerce.php新增价格、报价、订单、钱包、账本与履约表，并允许未开始试用的formal权益保留NULL起始时间；trial仍强制有起始时间。不修改既有迁移文件校验和。

portal_quotes、portal_wallet_entries、portal_order_fulfillments单独授予运行账号INSERT/SELECT，不能UPDATE/DELETE/TRUNCATE。它们不属于通用366天清理目标。新表权限必须通过迁移/重新配置受限账号完成；当前provision-account拒绝给已有高权限账号叠加授权，部署时创建新的受限运行账号后切换。

没有管理员直接“增加余额”端点；充值只能通过独立`wallet_recharge`订单和已核验的外部支付回执入账。本轮自动与浏览器测试的资金来源仅为隔离库夹具，不构成真实入账凭证。支付宝建单、商户快照、通知、主动查单、关单及财务接口见[支付宝实施状态](alipay-integration.md)。支付宝退款请求、收款关联、确认凭据使用0007_refunds迁移，运行账号只追加；可变任务状态不是金额账本。财务账单下载使用POST /api/v2/admin/payments/bills/download，详见支付宝实施状态。钱包退款接口及0008_wallet_refunds见上文。到账待核对的证据查看、重试履约与结案使用独立orders.resolve权限及0009_payment_resolutions只追加表，详见[支付宝实施状态](alipay-integration.md#到账待核对处理)。本人完整历史分页见上文及验证记录。交易通知已同事务入队，站内信、投递任务及财务失败重试见[可靠通知接口](transaction-notifications.md)。微信与充值本地流程已完成，真实商户调用、扫码付款和账单内容仍须P7验收。
