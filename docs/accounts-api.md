# 账户与管理员接口（P2）

所有写接口只接收application/json，32KiB上限；公开登录不启用跨域。浏览器会话使用HttpOnly、SameSite=Lax Cookie；HTTPS配置下为Secure。Cookie认证写操作必须带X-CSRF-Token。
用户Cookie为D2S_USER_SESSION，管理员为D2S_ADMIN_SESSION；对应CSRF Cookie后缀为_CSRF。前端不持久化密码。

| 方法 | 路径 | 输入与行为 |
|---|---|---|
| POST | /api/v2/auth/verification | kind=email/phone、contact、purpose=register/password_reset/bind_contact；绑定需用户Cookie和CSRF。返回challenge_id、expires_in、retry_after，不返回验证码 |
| POST | /api/v2/auth/register | username、password、kind、contact、challenge_id、code、privacy_accepted=true、terms_accepted=true；无需提交地区，手机验证仅接受中国大陆手机号；服务端读取当前政策版本，在账户事务中写PRIVACY/TERMS同意快照；成功201且账户激活，不自动建立会话 |
| POST | /api/v2/auth/login | identifier、password；用户名/验证邮箱/验证手机共用账户；Cookie会话及CSRF返回。当前政策版本未同意时返回 consent_required=true 及 consent_versions，前端进入 /consent |
| GET | /api/v2/auth/session | 当前用户资料及已验证联系方式；待重新同意的会话返回409 legal_reconsent_required |
| POST | /api/v2/auth/logout | Cookie+CSRF；撤销当前网站会话；待重新同意时仍允许退出 |
| POST | /api/v2/auth/password-reset | kind、contact、challenge_id、code、password；验证新密码后撤销网站与客户端会话 |
| POST | /api/v2/me/profile | Cookie+CSRF；display_name（可为空，最多80字符）、region=CN/INTL；更新后写PROFILE_UPDATE审计，审计失败回滚 |
| POST | /api/v2/me/password | Cookie+CSRF；current_password、new_password；验证当前密码，禁止新旧密码相同；成功后提升账户版本、撤销网站与客户端会话并清除登录Cookie |
| POST | /api/v2/me/consents | Cookie+CSRF；legal_accepted=true；待同意会话的受限入口；读取服务端当前政策，只补充缺失PRIVACY/TERMS版本快照，完成后解除当前会话门禁，重复提交幂等 |
| POST | /api/v2/me/contact | Cookie+CSRF；kind、contact、challenge_id、code、current_password；绑定/替换验证联系方式后须重新登录 |
| POST | /api/v2/admin/login | 管理员username作为identifier及password，仅独立管理员表 |
| GET | /api/v2/admin/session | 当前管理员及显式权限列表 |
| POST | /api/v2/admin/logout | 管理Cookie+管理CSRF；独立退出 |

成功结构为success=true及data；失败success=false及error。400参数/验证码错误、401未认证、403权限或CSRF、409唯一冲突、429锁定限流、503配置或服务不可用。
不返回密码哈希、会话Token、密钥和内部数据库错误。

用户名3～32位ASCII字母/数字/下划线/短横线，不能全数字，大小写不敏感。用户密码至少10个Unicode字符且UTF8不超过64字节；管理员至少14字符并包含大小写、数字和符号。
手机号仅中国大陆，规范化E.164；地区由用户选，不用IP自动认定身份。邮箱/短信核验不代表法定真实身份核验。

网页：/login、/register、/password-reset、/profile、/admin/login、/admin，以及 /terms、/privacy-policy、/legal-statement、/contact、/about、/business-license 法律与公示页面。个人资料页支持显示名称/地区、密码和验证联系方式管理；账户删除、数据查阅或更正请求通过私密工单进入人工处理，不提供即时自助擦除。未登录访问个人/管理页跳转相应登录；API直接拒绝。

注册页必须主动勾选当前用户协议和隐私政策。`portal_policy_consents` 为只追加安全记录，保存政策类型、服务端读取的版本/生效日期、同意时间、来源、截断UA和SHA-256；运行账号无UPDATE/DELETE权限。
客户端授权接口属于P3，网站登录不启动试用，也不占用客户端单在线会话。

## 后台发送配置

管理页面：`/admin/settings/smtp`、`/admin/settings/ses`、`/admin/settings/sms`。管理首页仅向具有 `settings.manage` 权限的管理员展示入口，页面和API分别校验权限。旧 `/admin/settings/delivery` 与 `/admin/settings/email` 兼容跳转到 SMTP 页面。SMTP、SES、短信页面共用同一保存与测试 API，但只展示本页字段。

| 方法 | 路径 | 行为 |
|---|---|---|
| GET | /api/v2/admin/settings/delivery | 返回非敏感配置及 secrets_configured 标记，不返回密钥 |
| POST | /api/v2/admin/settings/delivery | 管理Cookie+CSRF；values为配置对象，clear_fields为待清除密钥名列表 |
| POST | /api/v2/admin/settings/delivery/test | 管理Cookie+CSRF；只向当前管理员已存邮箱发送测试邮件，60秒间隔、每小时5次 |

密钥空字符串保留原值，显式清除通过clear_fields；相应通道开启时不能清除必需参数。
配置保存在portal_settings的delivery.v1，以D2S_MASTER_KEY加密密钥。已存配置优先于环境变量，空值不会重新启用旧环境中的密钥。
查看、保存及测试发送均追加管理审计；测试发送先记录PENDING，再记录服务商接受或失败。测试验证码没有对应账户验证记录，不能用来注册。
发送任务每次进程启动读取当前保存配置；保存不代表真实渠道验收通过。

## 发送与配置

D2S_MASTER_KEY：稳定的base64编码32字节随机密钥，通过受限环境注入；更换前须规划旧密文与验证码失效。
D2S_MAIL_DRIVER=smtp或ses；配置缺失时对应验证不可用。
SMTP：D2S_SMTP_HOST/PORT/USER/PASSWORD/FROM；仅465 TLS或587 STARTTLS，校验证书。
SES：D2S_SES_SECRET_ID/SECRET_KEY/REGION/FROM/REPLY_TO；地域默认ap-shanghai；模板变量code、minutes、purpose。
短信：D2S_SMS_ENABLED=true及D2S_SMS_SECRET_ID/SECRET_KEY/REGION/APP_ID/SIGN_NAME；地域默认ap-guangzhou；短信模板两项变量依次为验证码、有效分钟数。
SES/SMS都需配置TEMPLATE_REGISTER、TEMPLATE_PASSWORD_RESET、TEMPLATE_BIND_CONTACT（对应前缀D2S_SES_或D2S_SMS_）。
配置名只作为环境变量说明，不在文档填写真实值。

每分钟执行php bin/deliver.php，最多处理50项；数据库租约防重复领取，失败退避并在验证码到期后清除加密载荷。SMTP等外部发送不保证绝对一次，因此重试使用原验证码，消费仍只允许一次。
未配置真实邮件短信时，不应将本地替身验证描述为已完成渠道验收。

创建管理员：受限注入D2S_ADMIN_DB_DSN/USER/PASSWORD和临时D2S_BOOTSTRAP_PASSWORD，运行php bin/create-admin.php --username=运营自定用户名 --email=实际管理邮箱 --roles=system,security。可用角色support/business/finance/system/auditor/security；不要在命令行参数填写密码。
