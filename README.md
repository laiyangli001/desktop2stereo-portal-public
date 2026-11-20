# Desktop2Stereo 网站系统

Desktop2Stereo Portal 是面向桌面软件的多语言销售与服务网站。访客可以查看商品、下载客户端和阅读帮助，用户可以购买软件、管理授权设备和提交工单，管理员可以在独立后台管理网站内容、商品、订单和日常运营。

## 系统功能

| 使用者 | 可以做什么 |
| --- | --- |
| 访客 | 浏览公开商店、查看商品价格和下载链接，阅读帮助文章、公告及政策，通过「投诉与反馈」查找客服入口。 |
| 用户 | 注册与登录、购买软件、查看付款记录、按商品管理授权和设备、修改联系方式与密码、处理私密工单。钱包充值和邀请返利按后台配置开放。 |
| 管理员 | 管理软件商品、用户和员工角色，配置支付与投递渠道，处理订单、退款、账务和工单，发布多语言内容，查看任务和审计记录。 |

同一用户可购买多个软件商品，每个软件独立授权。商品可分别设置人民币和美元价格、配图、下载链接和购买开关。支付宝、微信支付、PayPal、Stripe 等渠道按站点配置和币种提供。

## 安装与启动

**环境要求**：PHP 8.4、MySQL 8.4 和 Composer。PHP 需启用 PDO、pdo_mysql、OpenSSL、Sodium、mbstring、curl、GD、DOM、Intl、Fileinfo 和 JSON。数据库使用 `utf8mb4`，Web 根目录指向 `public/`，`storage/` 由应用账户写入并放在公开目录外。

### 首次安装

1. 准备一个新的空 MySQL 数据库。安装向导只支持本机数据库，安装账户需要能够创建表、创建受限的运行账号并授予权限。
2. 进入项目根目录，安装依赖并生成安装令牌。

   ```bash
   composer install --no-dev --prefer-dist --optimize-autoloader
   php bin/install-token.php
   ```

3. 本地预览可使用 PHP 内置服务器。

   ```bash
   php -S 127.0.0.1:4186 -t public public/router.php
   ```

4. 打开 `http://127.0.0.1:4186/install`。检查运行环境，填写安装令牌、数据库连接、站点地址和首个管理员的信息，再测试连接并安装。正式站点填写 HTTPS 地址。
5. 安装成功后进入 `/admin/login`。系统生成受限的数据库运行账号，将本机配置保存在 `storage/config.local.json`，并锁定安装入口。安装令牌和本机配置应仅供部署人员访问。

### 正式环境

使用 PHP-FPM 和 Web 服务器，参考 [Nginx 配置示例](deploy/nginx.conf.example)及[部署说明](docs/deployment.md)。如通过进程环境管理配置，可按 [.env.example](.env.example) 注入参数。示例文件只用于查阅，PHP 不会自动加载它。应用使用独立的数据库运行账号，数据库迁移使用单独的迁移账号。按需接入邮件、短信、翻译、支付回调和[计划任务](deploy/tasks.cron.example)。

## 用户如何使用

1. 打开导航栏的「商店」`/products`。无需登录即可查看商品和下载链接。点击购买后按提示登录或注册，然后选择可用渠道付款。
2. 付款后到「授权设备」`/license`。可按软件查看授权、设备和会话，付款时间、金额和方式则在「付款记录」`/notifications` 中查看。
3. 在「个人资料」`/profile` 修改联系方式或密码。账户需要帮助时，可从悬浮「投诉与反馈」进入，已提交的私密工单在 `/tickets` 查看。
4. 后台开启相应功能后，用户可在钱包充值，或在邀请返利页面获取专属链接和申请佣金提现。邀请佣金以被邀请人的实际软件付款为基础，按站点规则结算。

## 管理员如何使用

从 `/admin/login` 登录。首次安装时创建首个管理员。已有数据库可通过受限命令行补建管理员，详见[创建系统管理员](docs/administrator-setup.md)。导航和操作权限按管理员角色显示。

| 想做的事 | 后台入口与操作 |
| --- | --- |
| 设置网站资料 | 「网站信息」`/admin/content/site`。填写站点名称、运营主体、客服链接、政策版本和生效日期等。 |
| 发布商品 | 「商品管理」`/admin/products`。新建软件，填写介绍、价格、图片和下载地址，按商品设置购买开关。可以先保存草稿，再公开发布。 |
| 配置付款与通知 | 「支付设置」`/admin/settings/payments` 配置支付渠道及钱包充值。「SMTP / 腾讯云邮件 / 短信」负责账户验证和通知投递，「通知投递」可检查失败记录。 |
| 发布公告和帮助内容 | 「系统公告」`/admin/content/announcements` 从列表进入独立的新建或编辑页，可设置页头、首页或弹窗及展示时间。帮助文章在 `/admin/articles` 管理，政策正文在 `/admin/content/policies` 管理。 |
| 查看交易与账户 | `/admin/order-search` 查订单和导出，`/admin/orders` 查单、对账及处理适用的退款，`/admin/finance` 查资金流水。用户详情可查看一个账号下的多个商品授权。 |
| 处理工单与授权 | `/admin/tickets` 查阅、公开回复或添加内部备注。通过「员工管理」分配后台角色，通过「账户限制」处理受限账户。日志导出需要相应权限。 |
| 维护语言和系统 | `/admin/locales` 管理界面字典，支持导出 JSON、离线翻译、导入及发布。`/admin/tasks` 配置计划任务与内容翻译，`/admin/upgrade` 检查和下载符合要求的 GitHub 发布包。 |

### 发布多语言内容

公告、政策、帮助文章和商品介绍可以先用一种语言录入，再勾选目标语言。英语始终包含在发布范围内。**保存草稿不会启动翻译**。点击发布后，其它目标语言由后台任务生成，管理员可以查看进度及已发布语言。未选的语言在英文版本可用时回退到英语。首页、导航、页脚和后台按钮等固定文字由界面语言字典管理。

## 更新已有站点

更新前备份数据库和私有文件。代码更新包含新迁移时，用独立的迁移账号在同一个站点数据库执行：

```bash
php bin/migrate.php
```

例如 `0066` 为商品和公告增加独立草稿字段。代码与数据库需要配套更新。后台「系统升级」用于检测、下载和准备符合要求的发布包，正式安装及回滚请按[升级包与安装说明](docs/online-update-package-format.md)操作。

## 许可证

本项目原创源码采用 [MIT 许可证](LICENSE)。第三方组件和素材沿用各自的授权，来源见 [第三方来源与声明](third-party/SOURCES.md)。

## 相关文档

- [部署、备份和计划任务](docs/deployment.md)
- [本地注册配置](docs/local-registration-without-email.md)
- [多语言运行与词典管理](docs/multilingual-runtime.md)
- [软件更新分发与后台管理实施方案](docs/software-update-admin-implementation.md) · [V2 原始方案](docs/software-update-distribution-v2.md)
- [客户端登录、授权、更新与信息同步编程规格](docs/client-login-entitlement-update-spec.md)
- [账户接口](docs/accounts-api.md) · [交易接口](docs/commerce-api.md) · [内容接口](docs/content-api.md) · [客户端授权接口](docs/client-api.md)
