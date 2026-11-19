# 交易通知与可靠投递

2026-11-06；本地实现与隔离验收，真实渠道/生产验收未完成。

## 事件和交易一致性

- ORDER_PAID：实际正式授权发放时，在原付款/履约事务追加一条通知及待投递任务。钱包、支付宝通知/主动查询、财务重试发放共用OrderFulfillment入口。
- PAYMENT_REVIEW：已验签收款但未履约或额外收款进入核查，按真实收款ID追加，不声称授权已生效。
- REFUND_CONFIRMED：钱包退款同事务入账，或支付宝逐退款号已确认成功后追加。仅申请退款、业务错误或unknown不发成功消息；对应主收款全退撤权时记录revoked，额外收款退款不误报原授权撤销。

唯一source_key对每份履约/收款/退款去重，重复通知、查询与同键退款不会重复建立站内通知。事件与任务均在原交易提交；队列写入失败时资金、订单、授权和新通知一起回滚，不能发生“交易提交但尚未保存通知任务”。事务中不连接邮件/短信服务。交易提交后的外部发送失败不回滚已经完成的交易。

所有通知是发生时的历史记录，金额为本次交易/退款的最小货币单位，license_effect为当时变更。迟到邮件不代表当前授权状态；当前状态仍由服务端授权和订单接口决定。

## 用户与财务入口

用户从个人资料的“交易通知”进入/notifications；页面/API只接受前台用户会话。每页50条，以(created_at,id)游标继续；未读数量独立计算。标为已读是不可变首次read_at记录，不修改通知原文。用户不能查看、标记或用游标遍历其他账户通知。

财务入口/admin/notifications，仅notifications.manage，财务角色模板包含此项，既有账号不静默扩权。其他支付配置、客服、系统和安全角色不自动获得。可按pending/processing/retry/sent/blocked/failed筛选、分页查询，并显示尝试次数、固定错误原因及下次时间。只有blocked/failed可二次确认重新排队；原因、操作者、时间、来源IP被审计。不允许指定收件人、文案、任意发送地址，也不直接在HTTP请求发邮件。

| 方法与路径 | 输入/返回 |
|---|---|
| GET /api/v2/notifications?cursor=... | 本人notifications、unread、per_page、next_cursor；不返回联系方式或队列内部数据 |
| POST /api/v2/notifications/{id}/read | 同源JSON及用户CSRF；只追加本人首次已读时间 |
| GET /api/v2/admin/notifications?page=1&status=blocked | notifications.manage；每页50条，投递元数据/总数；VIEW_NOTIFICATION_DELIVERY审计 |
| POST /api/v2/admin/notifications/{id}/retry | 同源JSON、管理员CSRF；reason、idempotency_key、confirm_retry=true；仅重新排队并记录RETRY_NOTIFICATION |

同幂等键/同原因返回原重试凭据，不重新应用旧决定；换原因拒绝。重试凭据与队列状态/审计同事务，失败回滚。不会因点击重试而重新付款、退款或发放授权。用户已读与通知原文的只追加权限均由数据库限制。

## 发送任务

命令：`php bin/notify-transactions.php`。迁移及凭据/渠道验收后，可由受限CLI账号每分钟运行一次；每次最多10项，本阶段未配置生产定时任务。

注入D2S_DB_DSN/USER/PASSWORD及D2S_MASTER_KEY；发送配置从既有DeliverySettings加载，保存的配置权威，不回落到旧环境秘密。任务不使用Web管理员Cookie。验证码的portal_delivery_outbox与bin/deliver.php完全独立，交易通知不会挤占验证码的5次尝试或10分钟期限。

- 用户→任务锁，120秒随机租约；发送不持有事务。同一有效租约只能被一个进程领取，过期任务可恢复；旧进程结果不得覆盖新租约结果。
- 每轮最多8次；失败按120秒起的指数退避，代码上限6小时，7天发送窗口。超过次数/窗口标failed，未配置渠道或没有已验证联系人标blocked。
- 发送时优先选择该账户当前已验证邮箱，只有没有已验证邮箱时使用已验证手机号；不使用订单旧邮箱，不给未验证联系方式发送。选择发生在领取时，已经开始的网络动作无法撤回。
- 外部接受后才记sent，表示渠道接受，不证明收件人阅读。错误只保存固定代码，不保存提供商异常正文、密码、密钥、验证码、邮件正文或收件地址。审计前置失败不发送；发送后的成功审计失败保留租约，后续可恢复核查/重试。
- 阻塞/失败不会删除站内事件。财务修复渠道或联系方式后可重新排队，重置本轮次数但保留total_attempts及原重试审计。
- CLI输出processed/sent/retry/blocked/failed/busy/lease_lost；blocked、failed或lease_lost非零返回退出码1。调度器应监控这些状态及长期retry，不能只看进程是否启动。

外部投递是至少一次：提供商已接受但进程在本地确认前退出时，重试可能重复邮件/短信。站内事件有唯一来源，不重复创建；SMTP使用稳定Message-ID辅助关联，不能据此承诺提供商精确去重。发件失败或重新排队不改变资金/授权。

## 通道配置与模板

后台已拆分为“SMTP 邮箱”“腾讯云 SES”“短信设置”三个页面；SES 与短信页分别提供D2S_SES_TEMPLATE_TRANSACTION与D2S_SMS_TEMPLATE_TRANSACTION可选模板ID。留空不影响原验证码模板，交易事件仍保留站内，外部任务进入blocked等待配置。

- SMTP：沿用现有465 TLS或587 STARTTLS及加密凭据；固定纯文本中英双语交易正文、事件时间和订单尾号，不插入追踪图片/链接。
- SES：沿用既有地域、凭据、发件人与可选回复邮箱，必须提供独立交易模板。模板变量为event_title、order_ref（订单后8位）、amount（十进制字符串）、currency、license_effect、event_id、event_time（UTC）。模板应说明历史事件以当前登录状态为准，不添加追踪。沿用已选SES，失败不静默改用SMTP。
- 腾讯云短信：沿用短信开关、签名和应用，必须提供审核通过的交易模板。4个按序参数为通知类型（付款确认/到账待核对/退款确认）、订单后8位、十进制金额、币种；SessionContext为事件ID，仅用于关联，不是渠道幂等保证。

参数依据：[腾讯云短信SendSms](https://cloud.tencent.com/document/api/382/55981)、[SES SendEmail](https://intl.cloud.tencent.com/document/product/1084/39408)。实际模板、提供商控制台设置、TLS和真实收件仍需渠道验收；本地替身不等于短信/邮件实际到达。没有自动重开邮件追踪。

## 数据库与部署

0012_transaction_notifications新增事件、首次已读、人工重试凭据三个只追加表，运行账号只INSERT/SELECT；任务状态表portal_notification_outbox才有受控DML。通知表不进入通用日志清理清单，没有删除/清空/修改原文接口。事件不复制收件地址、支付账户标识、设备指纹或完整支付响应。

旧迁移校验和不变；迁移账号运行bin/migrate.php后，使用新表显式授权的新受限运行账号。迁移不倒灌旧付款通知，不突然给历史用户发邮件；旧库/生产未改。保存新增模板字段后，回滚版本需兼容这些配置字段，或按受控流程恢复配置备份。

验证：P4通知记录。整体PHP重构仍进行中。
