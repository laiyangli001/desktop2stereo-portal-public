# 页面内容与授权管理接口

P6 开发接口；代码提交不代表生产已部署。网站只链接外部安装包，d2s.site 与 d2s.top 使用同一功能集。

## 页面

| 路径 | 会话及用途 |
|---|---|
| / | 公共首页，中英介绍、公告、GitHub Release 缓存版本、外部下载 |
| /business-license | 公共营业执照及主体信息 |
| /privacy-policy | 公共隐私政策 |
| /license | 用户会话；本人权益、客户端会话和设备记录 |
| /admin/content | 独立管理员；settings.manage 或 announcements.manage |

公共顶部菜单固定提供首页、商店、帮助中心和我的工单。网站内容后台不再配置顶部菜单。
`?lang=zh` / `?lang=en` 设置语言 Cookie，默认中文。授权页动态内容随语言切换。

## 后台内容

成功响应为 `{"success":true,"data":...}`。失败返回 HTTP 错误状态及 `success=false,error`。
所有后台接口需要管理员 Cookie；POST 还需同源和 `X-CSRF-Token`。
管理员 CSRF 来自登录结果或 `D2S_ADMIN_SESSION_CSRF` Cookie。

| 方法及路径 | 权限 | 请求 |
|---|---|---|
| GET /api/v2/admin/content | settings.manage 或 announcements.manage | 无；返回 site、navigation、announcements、can_settings、can_announcements |
| POST /api/v2/admin/content/site | settings.manage | `{"values":{...}}`；局部更新系统信息 |
| POST /api/v2/admin/content/announcements | announcements.manage | `{"items":[...]}`；保存全量公告 |
| POST /api/v2/admin/content/release/refresh | settings.manage | 无业务参数；显式刷新外部版本缓存 |

site 包括 site_name、company_name、contact_email、tagline_zh/en、intro_zh/en、github_repo、
support_wechat_url、privacy_version、privacy_effective。
客服链接仅允许 HTTPS，主机限定 work.weixin.qq.com；github_repo 为 owner/repo。
下载地址由商品管理保存（download_url、download_quark、download_baidu），仅接受 HTTPS；空地址不展示。公开商店不要求登录或开启购买即可查看已发布商品的下载地址。隐私版本/日期是公示元数据；政策正文修改须更新模板和 docs/privacy-policy.md。

公告最多20项，每项 title、body、enabled，可带 id、created_at；公开端仅展示启用公告，最多10条。
页面文本转义后输出，公告不执行 HTML。保存与 UPDATE_SITE_CONTENT / UPDATE_SITE_NAVIGATION /
UPDATE_SITE_ANNOUNCEMENTS 管理审计同事务；权限不足返回403。

内容接口显式使用递归数组 JSON 解析；既有账户和支付配置接口保留嵌套对象语义。
不得为新增内容接口改变全局默认 JSON 数据形状。

## 本人授权与设备

| 方法及路径 | 请求及结果 |
|---|---|
| GET /api/v2/me/license | 用户 Cookie；data.license，首次客户端登录前可为 null |
| GET /api/v2/me/license/devices | 用户 Cookie；license、devices、current_session |
| POST /api/v2/me/license/session/logout | 用户 Cookie、用户 CSRF、同源；device_hash 和整数 session_version |

devices 返回设备摘要及 first_seen_at、last_seen_at；current_session 返回 device_hash、session_version、
created_at、verified_at、expires_at，或 null。时间为 UTC 秒，不返回 Token、Token 哈希、私钥或资源密钥。
网页授权查看和退出会话不要求配置签名私钥，不启动或重置试用。

退出请求必须使用最近读取的 device_hash、session_version；用户身份只取登录会话，不能指定其他用户。
会话已替换或已退出返回409 `client_session_changed`，不影响新会话。
缺少用户会话为401，缺少 CSRF 或外站 Origin 为403，参数错误为400。
成功返回 `{"success":true,"data":{"logged_out":true}}`。
锁顺序与客户端登录一致：用户、权益、客户端会话。删除与 LOGOUT 用户审计同事务，审计失败回滚。
退出保留网页会话、授权及设备历史；旧客户端下次请求收到明确拒绝并清缓存。
完全断网设备仍可能使用已签凭证至到期，最长24小时；本功能不承诺即时离线停机。

## GitHub Release 与企业微信

PHP 页面只读取 D2S_RELEASE_CACHE，不在首页请求期间调用 GitHub。
CLI `php bin/refresh-release.php` 每15分钟刷新；后台刷新是明确的管理操作。
版本去除 tag 开头 v/V；超过一小时的缓存标记 stale，缺失或损坏不伪造版本。
下载按钮只显示“下载”或“Download”，其地址来自外部下载配置，版本号独立显示。
私有仓库可用受限只读 D2S_GITHUB_TOKEN；不要将凭据交给浏览器。

企业微信浮钮可拖动，默认右下且距底部保留空隙；位置保存在本机浏览器。
点击跳转企业微信，由公司人员处理；聊天不会自动复制到站内工单。
