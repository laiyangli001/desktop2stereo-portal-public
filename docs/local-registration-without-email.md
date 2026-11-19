# 未配置邮件时测试本地注册

适用于本地/隔离演示库，不得用于生产。

## 最快方式：直接使用已创建用户

本地演示库已有普通用户：

- 用户名：`i18n_demo_user`
- 密码：`D2S-I18n-Demo-User-2026!`

该账户已有已验证邮箱和试用权益，可直接测试登录、资料、商店、钱包、授权、通知和工单，无需重新注册。

## 测试真实注册表单

本地演示环境配置一个不会实际投递的 SMTP 占位地址，并启用仅限回环地址的验证码查看工具：

```powershell
$env:D2S_APP_URL = 'http://127.0.0.1:4186'
$env:D2S_LOCAL_VERIFICATION_CAPTURE = 'true'
$env:D2S_MAIL_DRIVER = 'smtp'
$env:D2S_SMTP_HOST = 'smtp.example.invalid'
$env:D2S_SMTP_PORT = '587'
$env:D2S_SMTP_USER = 'local-demo'
$env:D2S_SMTP_PASSWORD = 'local-demo-not-sent'
$env:D2S_SMTP_FROM = 'noreply@example.invalid'
```

重启本地 PHP 服务后：

1. 打开 http://127.0.0.1:4186/register?lang=zh
2. 填写用户名、邮箱和密码，勾选用户协议与隐私政策。
3. 点击“发送验证码”。
4. 从另一个终端加载同一份本地环境后运行：

```powershell
php bin/show-local-verification-code.php --target=你的邮箱 --purpose=register
```

输出示例：

```json
{"success":true,"data":{"kind":"email","target":"user@example.invalid","purpose":"register","code":"123456","expires_at":1790000000}}
```

5. 将 `code` 填入注册页，完成注册。验证码10分钟内有效。

## 安全边界

- `show-local-verification-code.php` 只有在 `D2S_LOCAL_VERIFICATION_CAPTURE=true` 且 `D2S_APP_URL` 主机为 `127.0.0.1`、`localhost` 或 `::1` 时才执行。
- 工具只从加密 `portal_delivery_outbox` 读取最新未过期、未消费验证码，不解密其他邮件内容。
- 占位SMTP只让注册页认为邮件通道已配置；注册验证码由本地工具读取，不要运行 `bin/deliver.php`。
- 不得把占位SMTP、可见验证码开关用于生产或公网环境。
- 测试结束后应关闭本地服务，并移除上述占位配置后再重新启动。
