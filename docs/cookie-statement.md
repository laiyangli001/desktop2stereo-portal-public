# Desktop2Stereo Cookie 说明

本说明是《隐私政策》的组成部分，适用于 `d2s.site` 与 `d2s.top`。

## 当前使用的Cookie

| Cookie | 类型 | 用途 |
|---|---|---|
| `D2S_USER_SESSION` | 必要 | 保存网站用户会话令牌，设置为 HttpOnly、SameSite=Lax，HTTPS 下为 Secure |
| `D2S_USER_SESSION_CSRF` | 必要 | 供同源页面读取并向写请求提交 CSRF Token |
| `D2S_LANG` | 必要 | 记住中文或英文界面偏好 |
| `D2S_ADMIN_SESSION` | 管理必要 | 独立管理员会话，与普通用户Cookie隔离 |
| `D2S_ADMIN_SESSION_CSRF` | 管理必要 | 管理员写请求 CSRF 防护 |

服务端只保存会话Token和CSRF Token的哈希；会话令牌本身不写入业务审计日志。

## 非必要Cookie

当前网站不使用广告Cookie、跨站跟踪Cookie或第三方统计分析Cookie。如未来引入非必要Cookie或同类本地存储，将更新本说明和隐私政策，并在适用法律要求时提供同意和撤回机制。

## 外部网站

点击 GitHub、夸克网盘、百度网盘、支付渠道或企业微信后，将离开本站。外部网站可能使用其自己的Cookie和跟踪技术，适用其自身规则。

## 联系方式

有关Cookie或个人信息的请求，可通过私密工单或 `zuohuanhao@tulishisoft.cn` 联系运营主体烟台图立视软件开发有限公司。
