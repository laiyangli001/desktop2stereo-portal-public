# 多语言运行时与本地开发

本文说明当前代码已接入的运行方式；整体迁移范围和未完成项见 实现状态 与 完整方案。

## UI 词条

PHP 和浏览器读取同一份 UTF-8 JSON，按语言和命名空间组织，例如：

~~~text
resources/locales/zh-Hans/common.json
resources/locales/en/common.json
resources/locales/zh-Hans/locales.json
resources/locales/en/locales.json
resources/locales/zh-Hans/accounts.json
resources/locales/en/accounts.json
~~~

文件内使用平铺键，如 common.json 中的 language.select；完整调用键为 common.language.select。

PHP 使用 d2s_tr('locales.title')，输出到 HTML 文本或属性时仍须用 d2s_escape()。浏览器使用 window.D2SI18n.tr('locales.title')，结果通过 textContent、value 或属性设置器显示。词条与参数均作为文本处理，不能将结果直接写入 innerHTML。

参数、复数和条件选择使用 ICU MessageFormat。示例：

~~~json
{
  "items.count": "{count, plural, =0 {No items} one {# item} other {# items}}"
}
~~~

PHP 调用 d2s_tr('common.items.count', ['count'=>3])；浏览器调用 D2SI18n.tr('common.items.count', {count:3})。Symfony Translation 与 i18next-icu 使用相同消息格式；不混入 i18next 的双花括号插值。

页面通过 $uiNamespaces 声明需要的命名空间；公共头部自动包含 common。UiTranslator::browserPayload() 从已启用语言的相应 JSON 生成带版本 hash 的资源数据，locale-runtime.php 安全序列化后由本地静态脚本读取。JSON 中的 HTML 特殊字符使用 JSON_HEX_* 转义。

语言管理表单、账户表单、用户侧栏、operations.php 承载的后台功能页、运营首页、后台导航、账户限制与批量处置页、帮助中心、公共页脚、商店、钱包、购买设置、客服悬浮入口、授权管理页与邀请返利页已迁为语义词条键。账户限制页使用 account_controls 命名空间，由单语语言包渲染筛选、账户详情、身份核验和批量处置反馈。邀请返利页加载 referrals 命名空间；API 接受显式页面 locale，仅影响显示与邀请注册链接，币种和金额不变。授权页加载 licensing 命名空间，状态与计数使用共享消息，日期按当前 locale 格式化。商店/钱包/购买设置加载 commerce 命名空间；微信 QR 渲染器使用 common 词条。后台页面另加载 operations、navigation、permissions；文章编辑器加载 editor。auth.php、profile.php、consent.php、help.php、operations.php、admin.php、commerce.php、license.php 和 referrals.php 设置 $legacyDomTranslation=false，对应页眉不加载旧 i18n.js。未迁移页面仍保留兼容脚本，它尊重 translate="no"；文章正文、富文本输入区和商品文案明确标记该属性，避免内容被当作 UI 文案改写。

用户和管理员工单页声明 support 命名空间，并使用共享 ICU 文案处理类别、状态、复数、附件反馈与回复格式工具栏。发送配置页面声明 delivery 命名空间，交易通知用户页和投递管理页声明 notifications 命名空间，客户端状态、错误、计数、日期和金额均使用当前语言。用户输入的工单标题、消息正文、附件名和客服填写内容作为业务数据原样显示。两个工单页关闭旧 DOM 扫描翻译器；support-tickets.js、admin-tickets.js 与 support-format.js 在模板中使用 d2s_asset() 生成内容哈希 URL。反馈中心同时声明 support 与 feedback 命名空间，提交介绍、分类、表单、附件提示和提交结果均由共享词条服务端渲染；该页关闭旧 DOM 扫描翻译器，feedback.js 使用内容哈希 URL。用户填写的工单内容仍作为业务数据原样提交和显示。

登录、注册、找回密码、修改联系方式和政策确认后的跳转使用 UiTranslator::browserPayload() 中由 LocaleUrl 生成的 account_urls。公开登录地址带语言目录，私密账户地址带明确的语言参数；服务端继续检查 next 白名单。用户侧栏的链接与退出地址同样由服务端生成，客户端不统一给 API 或回调路径添加语言前缀。

## 导航语言下拉框

前台与后台页眉共用 template/partials/language-switch.php，由注册表生成已开放范围的选项，只占一个控件的宽度。选项使用各语言自己的名称；translate=no 防止旧兼容翻译器改写语言名称。locale-switch.js 校验跳转仍在本站，保留当前片段，并立即更新 D2S_LOCALE Cookie。登录用户和管理员还会通过带 CSRF 令牌的 `/api/v2/me/locale` 或 `/api/v2/admin/locale` 保存账户 `preferred_locale`；请求最多等待 1.5 秒，失败时仍继续切换页面。公开语言 URL 前缀优先于 Cookie 和账户偏好；私密页面按本次明确选择、Cookie、账户偏好、Accept-Language、站点默认语言解析。

控件样式和脚本使用内容哈希 URL。手机后台使用两行工具栏，侧栏与遮罩从工具栏下方开始，语言、主题和退出操作均保留。独立检查命令为 php tests/browser-locale-switch.php；其中五语言注册表是控件测试配置，不代表这些语言的整站翻译已经验收。

## 构建和验证

开发依赖固定在 package.json 与 package-lock.json；生产运行只需要 PHP、MySQL 和构建后的静态文件，不需要 Node 服务。

~~~powershell
npm ci --ignore-scripts
npm run build:i18n
$env:D2S_TEST_PHP = 'C:\Users\LaiYangLi\AppData\Local\Programs\PHP\php-8.4.27-1\php.exe'
npm run test:i18n
npm run test:catalogs
~~~

frontend/check-locales.mjs 使用当前 i18next ICU 解析器检查启用语言的键集合与参数签名，允许各语言有不同的复数类别，但要求参数名、类型、select 分支和显式数字分支兼容。缺译、额外键、空消息、无效 ICU 和富文本标签会使构建失败。也可单独运行 npm run check:locales。

运行 `npm run report:locales -- --summary` 可盘点清单中所有语言（包括关闭状态）的缺译和 ICU 参数差异；`--locale=fr` 可筛选目标语言，默认详细输出包含逐条消息键，`--json` 输出完整机器可读报告。默认命令只对不完整的已启用语言返回失败；筛选不完整语言会返回失败，`--strict` 则要求所有登记语言完整。

frontend/check-ui-keys.mjs 使用 Acorn 校验 operations.js、article-editor.js、accounts.js、shop.js、wechat-cashier.js、licensing.js、referrals.js 和 account-controls.js 中的静态语义键引用，动态键不推测展开，由浏览器验证角色、模块、权限和待办数据。frontend/ui-inventory.mjs 与 frontend/php-ui-inventory.php 可盘点遗留双语调用；它们仅报告，不自动生成翻译。

frontend/build-i18n.mjs 先执行目录及静态消息键引用检查，再使用 esbuild 生成 public/assets/vendor/i18n-runtime.js，并从实际进入 bundle 的包收集许可证，写入同目录 i18n-runtime.LICENSE.txt。两个文件随代码发布。resources/ 中的语言包也纳入 ReleaseManifest 文件哈希校验；它们属于发布代码资源，不能在生产发布目录随意覆盖。

当前锁定 i18next 27.0.1、i18next-icu 2.7.0、intl-messageformat 12.1.4 和 esbuild 0.28.2；开发校验直接声明 ICU 解析器 @formatjs/icu-messageformat-parser 3.5.22。配置依据为 [i18next 官方选项](https://www.i18next.com/overview/configuration-options) 和 [i18next-icu 官方说明](https://github.com/i18next/i18next-icu)。

tests/icu-cases.json 是 PHP ICU 与 JavaScript ICU 的共同样本。npm run test:i18n 会用 PHP 先验证期望值，再验证 JavaScript 得到相同结果；覆盖英语、简中、俄语、日语、法语、复数、select、引号和原样参数。

数据库与浏览器验证使用项目的隔离测试环境：

~~~powershell
. ./storage/local-test/env.ps1
$env:D2S_TEST_PHP = 'C:\Users\LaiYangLi\AppData\Local\Programs\PHP\php-8.4.27-1\php.exe'
& $env:D2S_TEST_PHP tests/localized-operations.php
~~~

该命令运行商品/语言配置、后台功能页、购买/钱包与账户页面四组浏览器检查。购买/钱包检查使用真实的价格设置、USD 下单、余额不足校验、取消订单和钱包调整接口，验证语言切换不改变协议币种与金额，以及 ICU 流水单复数和二维码辅助说明。微信付款及钱包充值的原有浏览器套件分别为 tests/browser-wechat-shop.php 和 tests/browser-wallet-recharge.php；它们使用本地支付替身和签名回执验证原交易流程，不代表生产支付验收。

后台检查覆盖角色/模块消息键、员工编辑、第三语言文章源标题、待办、任务、维护、升级、财务和订单页面。账户检查关闭旧翻译器，验证真实的验证码生成、验证码入队、资料保存和退出接口。成功登录响应使用模拟，用于检查客户端跳转；目标资料页通过 Fixture 创建的真实会话访问。

它使用 Fixture 创建自己的数据库和临时语言快照目录，结束后清理；不会发布到日常 4186 服务使用的快照目录。隔离测试 MySQL 使用 3307。

授权页的独立检查命令为 `php tests/browser-localized-licensing.php`（先载入上述隔离环境）。初始无试用状态使用真实接口；授权类型/到期/撤销、设备与会话冲突等展示分支使用明确的本地响应替身，不作为真实客户端授权协议的验收。

旧支付浏览器测试使用 tests/BrowserCaptcha.php 在本地生成真实挑战，通过测试进程传入对应会话 Cookie 和点击数据；HTTP 登录仍执行原验证码与密码校验。该助手没有 HTTP 路由，也不关闭生产验证码。微信二维码模块通过模板生成的 SHA-256 URL 按需加载，避免缓存旧模块的语言说明；移动支付页客服入口在内容下方。

邀请返利独立检查：`php tests/browser-localized-referrals.php`。使用真实的本地 USD 购买产生佣金，再验证结算、提现请求、批准、打款记录和驳回；不会向外部账户转账。审核原因和收款信息使用 textContent 原样显示。邀请链接统一使用 `/register?ref=…`。注册页优先采用访客已保存的语言，其次采用浏览器语言；旧的带语言前缀的链接仍可使用。

## 语言设置保存与发布

/admin/locales 管理已登记语言的启用状态、公开页/后台范围、回退链和排序。源清单中的默认语言必须保持 UI、公开页和后台可用。目标语言可以使用静态 common.json，也可以先导入完整的数据库 common 语言包；只有源文哈希有效且每个 common 键都有非空译文时，才允许启用数据库语言包。

保存只更新数据库中的设置；发布把当前已保存设置原子替换为运行时快照。界面会显示有无尚未发布的变更。保存和发布均携带内容版本 hash，并在数据库行锁内检查，旧编辑页面不能覆盖或发布较新的设置。

发布时先根据静态基础包和数据库覆盖生成不可变的 UI 语言包文件，再把 catalog_revision 与语言注册表一起写入 published.json；该文件是 PHP 与浏览器共同读取的原子版本指针。PHP translator 和 browserPayload 使用同一个 revision，已发布目录不会在后续请求中读取覆盖表。UI 词条的逐条保存、导入和语言设置修改都是草稿，点击发布后生效。编译或文件写入失败时不切换 published.json，之前的有效指针继续使用；注册表快照损坏或语义校验失败时回到随代码部署的静态清单。

本地 4186 演示服务的既有环境加载脚本为 storage/local-test/i18n-demo-env.ps1。2026-11-09 的升级前备份位于 storage/local-test/backups/demo-before-locales-20261109-090831，已通过 BackupSetVerifier；它对应升级前的 0028 状态，不能当作后续版本数据的备份。演示库只在 127.0.0.1:3307。现有账户更新语言表权限使用权限分类中指定的增量授权；DatabasePermissions::grant() 仅用于尚无权限的新账户，不能作为现有账户升级命令。

默认快照位置为 storage/locales/published.json。受信任的服务器环境变量 D2S_LOCALE_SNAPSHOT_DIR 可覆盖目录，用于隔离测试或部署目录安排；不能从 HTTP 请求设置这个值。PHP 进程需要对该目录有写权限。

语言管理可登记语言及其别名、URL 前缀、原生名称、文字方向和回退链。新语言默认关闭。界面语言包按目标语言一次导出全部消息分组。JSON 格式为 d2s-ui-catalog v2，包含 `locale`、`source_locale` 和 `namespaces`。每条文案包含 `source`、`value`、`source_hash` 与 `lock_version`。离线修改 `value` 后导入，再发布语言设置。导入校验全文的源文摘要、ICU 参数和版本冲突，任一分组失败则整包回滚。整包中未修改的非空译文不新增覆盖，空 `value` 清除已有覆盖。旧版按单组导出的 v1 JSON 仍可导入。单包上限为 2 MB，每个分组最多 5000 条，每条最多 10000 字符。

新语言可以先保持关闭、逐命名空间导入包；编译时将有效数据库译文与静态基础包合并进版本化 catalog bundle，无需为目标语言部署静态 JSON 文件。启用 UI 前必须完成 common 命名空间全部词条；启用公开页或后台还需同时启用 UI。语言 UI 包不会自动生成文章、商品或通知译文。

## 后台消息键与脚本缓存

员工管理 API 的 role_labels、modules.label 和模块权限标签返回 permissions 命名空间下的消息键；运营待办和诊断提示返回 operations 消息键。客户端必须通过共享翻译入口显示，业务权限标识仍原样使用。新旧脚本不能随意混配。

d2s_asset() 为 public/assets 内存在的文件生成 SHA-256 查询版本；相关账户、后台、编辑器和 i18n 运行时引用采用该入口。生产 CDN 是否按版本缓存需部署时验收。

## 源文摘要规范与旧数据修复

ContentSourceHash 固定使用 SHA-256 与紧凑 UTF-8 JSON 数组。文章字段顺序是 title、summary、body；商品是 title、description。保留字符和换行的原始内容，不做 Unicode 归一化。JSON 不转义普通 Unicode 和斜杠，但按 PHP 约定转义 U+2028/U+2029。摘要是文本内容标识；实际版本与发布状态仍由 source_revision、修订 ID 和发布指针决定。

0029 的种子摘要使用了分隔拼接。0030 从历史简中第一个迁移修订重算，不能从现在的主表或当前草稿重算旧译文；人工保存记录已经使用同一 JSON 规范，修复迁移不会重写它们。0029 文件保持原样以通过已应用迁移的校验。运行账户仍只有历史修订表的 SELECT/INSERT 权限，修复通过迁移账户执行。

检查命令：php tests/translation-source-hashes.php 或 composer test:translation-source-hashes（先加载隔离环境）。它先安装 0028、放入旧数据，再逐次应用 0029 与 0030，验证摘要、历史内容、指针和权限；不是只测试一个哈希函数。

DeepL 请求/响应处理验证：`php tests/deepl-provider.php` 或 `composer test:deepl-provider`，不需要数据库或凭据；测试通过注入离线请求处理器，不连接 DeepL。

本地演示库应用 0030 前的已校验备份：storage/local-test/backups/demo-before-hash-fix-20261109-092609，对应 0029 状态。应用后已比对主表及翻译头记录未变，迁移摘要与 PHP 结果一致。

## 业务内容和 API 语言

帮助文章和商品分别使用按 locale 存储的修订记录，草稿与已发布指针独立。帮助文章缺译时，仅跳转到已开放公开入口的实际内容语言；若该语言入口未开放，则在请求入口显示原文、标注 lang/dir、说明实际语言并返回 X-Robots-Tag: noindex, follow，避免循环跳转。该响应不等于该请求语言的已翻译版本，不能用于目标语言的 hreflang/sitemap。管理员通过一个语言选择器切换单个编辑表单；内容语言与后台 UI 语言分别处理。

商店首个 HTML 即包含当前请求语言对应的已发布商品文案，后续商品目录 API 使用相同解析服务。API URL 仍不加语言前缀。浏览器在商品、账户和后台操作请求中携带 X-D2S-Locale；服务端只接受注册表中已启用的语言，后台 API 还检查后台启用状态。未提供或无效时使用默认语言。

该请求头只决定展示文案，不改变订单币种、金额、权限或支付协议字段。API 默认语言不依赖浏览器 Cookie，避免协议调用因残留 Cookie 改变展示语言。
