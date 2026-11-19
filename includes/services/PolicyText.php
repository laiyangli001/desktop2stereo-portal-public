<?php
declare(strict_types=1);

namespace D2S\services;

final class PolicyText
{
    public static function sections(string $policyType, string $locale, array $site): array
    {
        $isEn = str_starts_with($locale, 'en');
        switch (strtoupper($policyType)) {
            case 'PRIVACY':
                $sections=$isEn?[
                    ['Scope','This policy explains how Yantai Tulishi Software Development Co., Ltd. processes personal information for the Desktop2Stereo website at d2s.site and d2s.top. The two domains provide the same account, licensing and support functions.'],
                    ['Account information','We process username, email address or supported China-mainland phone number, password hash, verified-contact status, region, registration time and security events. Verification proves control of a contact channel; it is not a legal real-identity verification.'],
                    ['Orders, payments and licenses','When purchasing is enabled, we process order number, account, product, integer amount, currency, payment channel, channel-provided payer identifier in masked or keyed-hash form, source IP and payment result. We do not store full card numbers, payment passwords or verification codes. License records include entitlement status, signed offline credentials, device digest and session status.'],
                    ['Support data','Registered and signed-in users may submit private support tickets. We process account, verified contact, optional business order number, category, subject, message, attachments, status and staff replies. Other users cannot view the ticket. Closed tickets and linked personal information are scheduled for deletion after six calendar months.'],
                    ['Security records','We retain account behavior logs, registration snapshots, detailed login history and administrator audit records for at least 366 days. Records include available account identifiers, action, UTC event time, source and destination network information, User-Agent, request path, result and device digest. Passwords, verification codes, full tokens and payment secrets are not recorded.'],
                    ['Login IP and security history','When an account signs in, we record the time, outcome, available source IP, browser or client User-Agent, device digest, session and security identifiers, and related request result. We use these records to prevent credential attacks and abuse, detect abnormal sign-ins, enforce device and single-session controls, investigate account or payment disputes, and protect the security and availability of the service. Source IP is used for security audit and approximate network-region reference; it is not proof of residence, personal identity or authorization. Detailed login history is retained for at least 366 days and is then deleted in controlled batches by a separate least-privilege retention account. The latest successful-login status stored on the user or administrator record may remain while the account exists for security and account-recovery purposes.'],
                    ['Login record scope','Login records apply to both the user account area and the separate administrator console. We record failed and blocked attempts as well as successful sign-ins. We do not intentionally record passwords, one-time verification codes, full session tokens, private keys or payment secrets in login history. Access is limited to authorized security, support and account administrators under role permissions, and administrator access to account security data is audited.'],
                    ['Cookies and similar technologies','The website uses necessary cookies for login sessions, CSRF protection and language preference, including D2S_USER_SESSION and D2S_LANG; administrator sessions use separate cookies. The session token is HttpOnly and the CSRF cookie is readable by the same-origin page. We do not currently use advertising or third-party analytics cookies. If non-essential cookies are introduced, we will update this policy and obtain consent where required. External websites use their own cookie rules.'],
                    ['Service providers','Production application, database and backups are intended to run on Tencent Cloud in Shanghai, China. Tencent Cloud EdgeOne provides acceleration and protection. Tencent Cloud SES in Shanghai sends transactional email. Installers are hosted by external platforms such as GitHub, Quark Drive or Baidu Drive. The Enterprise WeChat service is provided by Tencent.'],
                    ['Downloads and external services','Clicking an external download, payment or Enterprise WeChat link leaves this website. The external service may process access and information you choose to provide under its own terms.'],
                    ['Retention and deletion','Account data is deleted or anonymized after account deletion, while required security and registration records may remain for at least 366 days. Detailed login history is deleted after the minimum retention period by the dedicated retention task; latest-login snapshot fields may remain with the account. Tickets and linked personal information are deleted six calendar months after closure. Backup copies expire under the production backup policy.'],
                    ['Your requests','Signed-in users may submit account-related requests in the account area. You may also email '.$site['contact_email'].'. We aim to provide an initial response within 10 business days after confirming account or contact control.'],
                    ['Minors','The service is primarily intended for adults and is not specifically directed to minors. A guardian may contact us if a minor provided personal information.'],
                    ['Updates and contact','We will publish the version and update date on this page. Registration records the accepted policy version, effective date and acceptance time. Material changes will be communicated as required. Operator: '.$site['company_name'].'; email: '.$site['contact_email'].'.'],
                ]:[
                    ['适用范围','本政策说明烟台图立视软件开发有限公司通过 d2s.site 与 d2s.top 提供 Desktop2Stereo 软件介绍、账户注册与登录、软件授权、设备管理及私密售后时，如何处理个人信息。两个域名提供相同的账户、授权和售后功能。'],
                    ['账户信息','我们处理用户名、电子邮箱或已支持的中国大陆手机号、密码哈希、联系方式验证状态、地区、注册时间和安全事件。邮箱或短信验证仅确认对联系渠道的控制权，不代表法定真实身份核验。'],
                    ['订单、支付与授权','购买功能启用后，我们处理业务订单号、账户、商品、整数金额、币种、支付方式、支付渠道提供的脱敏或带密钥哈希账户标识、来源 IP 和支付结果。我们不保存完整银行卡号、支付密码或验证码。授权记录包括权益状态、签名离线凭证、设备摘要和会话状态。'],
                    ['售后工单','注册并登录的用户可以提交仅本人和授权客服可见的工单。我们处理账户、已验证联系方式、可选业务订单号、问题分类、标题、正文、附件、处理状态和客服回复。其他用户不能查看。工单关闭满6个日历月后，按受控流程删除工单及关联个人信息。'],
                    ['安全记录','用户行为日志、注册快照、详细登录历史和管理操作审计至少保留366天。记录包括可取得的账户标识、操作、UTC时间、来源和目标网络信息、User-Agent、请求路径、结果及设备摘要。密码、验证码、完整Token和支付密钥不写入这些日志。'],
                    ['登录IP与安全历史','账户登录时，我们记录登录时间、结果、可取得的来源IP、浏览器或客户端User-Agent、设备摘要、会话与安全标识及相关请求结果。这些信息用于防暴力破解和凭证攻击、识别异常登录、执行设备与单在线会话控制、调查账户或支付争议，以及维护服务安全与可用性。来源IP仅用于安全审计和近似网络地区参考，不用于认定实际居住地、真实身份或操作授权。详细登录历史至少保留366天，之后由独立最小权限留存账户分批受控删除；用户或管理员记录中的最近成功登录状态可在账户存续期间保留，用于安全与账户恢复。'],
                    ['登录记录范围','登录记录同时适用于用户账户区和独立管理后台，并记录成功、失败及被阻止的登录尝试。登录历史不有意记录密码、一次性验证码、完整会话Token、私钥或支付密钥。访问权限按角色限制给获授权的安全、客服或账户管理员，管理员查阅账户安全数据的行为另行审计。'],
                    ['Cookie及类似技术','本站使用登录会话、跨站请求伪造防护和语言偏好所需的必要Cookie，包括 D2S_USER_SESSION、D2S_LANG 等；管理员会话使用独立Cookie。会话令牌设置为HttpOnly，CSRF Cookie可供同源页面读取。当前不使用广告或第三方统计分析Cookie。如未来引入非必要Cookie，将更新本政策并在需要时征得同意。外部网站适用其自身Cookie规则。'],
                    ['服务商','生产应用、数据库和备份计划部署于中国上海腾讯云；腾讯云 EdgeOne 提供网站加速与防护；腾讯云 SES（上海）发送账户邮件；GitHub、夸克网盘或百度网盘等外部平台托管安装包；企业微信由腾讯提供客服沟通能力。'],
                    ['外部下载与客服','点击外部下载、支付或企业微信链接后将离开本站。外部平台可能按照其自身规则处理访问信息及用户主动提供的内容。'],
                    ['保存和删除','账户注销后删除或匿名化账户资料；依法或为安全目的需要保留的注册与安全记录至少保留366天。详细登录历史在最低保留期届满后由专用留存任务删除，最近登录快照字段可随账户继续保留。工单关闭满6个日历月后删除正文、回复、附件及关联个人信息。备份按生产备份期限到期。'],
                    ['用户请求','已登录用户可在账户内提交相关请求，也可发送邮件至 '.$site['contact_email'].'。在完成账户或联系方式控制权核验后，我们目标在10个工作日内首次回复。'],
                    ['未成年人','本服务主要面向成年人，不专门面向未成年人。监护人如认为未成年人向我们提供了个人信息，可通过上述邮箱联系。'],
                    ['更新与联系','本页面公布版本和更新日期；注册时记录所同意的政策版本、生效日期和同意时间。处理目的、信息种类或用户权利发生实质变化时，我们将依法告知。个人信息处理者：'.$site['company_name'].'；联系邮箱：'.$site['contact_email'].'。'],
                ];
                break;
            case 'TERMS':
                $sections=$isEn?[
                    ['Acceptance and parties','These Terms govern the website accounts, online licensing, orders and support services provided by '.$site['company_name'].' at d2s.site and d2s.top. By registering, purchasing or using these online services, you agree to these Terms and the Privacy Policy. If you do not agree, do not register or use the paid service. Mandatory consumer rights are not excluded.'],
                    ['Definitions','“Service” means the website, account, license management, order, payment, download link and private support functions. “Software” means the Desktop2Stereo client and any accompanying components. “Account” means the registered identity used to access the Service. “Formal license” means the entitlement recorded by us after a verified purchase.'],
                    ['Account registration and security','You must provide a valid contact channel and complete verification. You are responsible for safeguarding your password, device and one-time codes. You must notify us promptly of suspected unauthorized use. Accounts are personal; do not sell, lend or transfer an account without our written consent. We may require additional verification where necessary for security or dispute handling.'],
                    ['Login IP and security records','When you sign in to the user area or administrator console, we process the time, outcome, available source IP, User-Agent, device digest, session and security identifiers, and related request result. We use this information for credential-attack prevention, abnormal-login detection, device and single-session enforcement, account or payment dispute investigation, and service availability protection. Source IP is not treated as proof of identity, residence or authorization. Detailed login history is retained for at least 366 days and then deleted through a separate least-privilege retention process. Latest successful-login status may remain with the account for security and recovery. Passwords, verification codes, full tokens, private keys and payment secrets are not intentionally stored in login history.'],
                    ['Age and capacity','The Service is primarily intended for adults with legal capacity. If you are a minor, use the Service only with the consent and supervision of a parent or guardian, and only to the extent permitted by law.'],
                    ['License scope','Subject to payment of the applicable price and compliance with these Terms, we grant you a limited, personal, non-exclusive and non-transferable right to use a formal license on compatible devices. Unless law or an applicable open-source license permits otherwise, you may not reverse engineer, decompile, modify, resell, sublicense or circumvent technical protection. Third-party and open-source components remain governed by their respective licenses.'],
                    ['Trial, device sessions and offline use','A new account may receive a one-time 30-day trial starting on the first successful client login. The trial is not restarted by changing devices or account details. A new client login may replace the previous online session. After a successful heartbeat, a signed offline credential may permit local operation for up to 24 hours, subject to trial or license expiry. Offline operation is not continuous online authorization and does not extend the applicable expiry.'],
                    ['Orders, prices and payment','Product and price information shown before confirmation forms part of the order. Orders are processed through the payment channel you choose. Payment completion is determined by verified channel evidence, not only by a browser return page. We record the business order number, integer amount, currency and fulfillment result. Duplicate payments or conflicting channel results are preserved for review and are not silently discarded.'],
                    ['Refunds and statutory rights','Refund eligibility and processing follow applicable law, the order information and the actual payment channel. Approved refunds are returned through the original channel where required. We may request information necessary to verify the order and identity of the payer. Nothing in these Terms excludes statutory rights that cannot lawfully be excluded.'],
                    ['Support and user content','Private support tickets are available only to registered users and authorized staff. You must provide accurate and lawful information. You retain rights in the content you submit and grant us a limited right to store, reproduce and process it as necessary to provide support, protect the Service and comply with law. Do not upload unlawful, malicious or third-party content you are not entitled to provide.'],
                    ['Acceptable use','You must not use the Service to violate law, infringe rights, distribute malware, interfere with systems, gain unauthorized access, abuse payment or refund processes, harass staff, or evade account and license controls. We may restrict access where reasonably necessary to address a security, legal or payment risk, subject to applicable law.'],
                    ['Intellectual property','The Service, website design, trademarks, documentation and software not covered by a separate license are protected by applicable intellectual property laws. No ownership is transferred by these Terms. You may not use our name or marks in a way that suggests endorsement or affiliation without permission.'],
                    ['External links and third-party services','Installers may be hosted by GitHub, Quark Drive, Baidu Drive or other external platforms. Payments, enterprise messaging and other third-party services are governed by their own terms. We do not control external sites and are responsible only to the extent required by law.'],
                    ['Availability, maintenance and changes','We may maintain, update or change the Service for security, compliance and operational reasons. We will use reasonable efforts to preserve licenses and order records. We do not promise uninterrupted access where interruption is caused by networks, devices, third-party platforms, force majeure or necessary maintenance.'],
                    ['Disclaimers and liability','The Service is provided with reasonable skill and care. We do not exclude liability that cannot be excluded by law, including liability for intentional misconduct, gross negligence or personal injury caused by us. To the extent permitted by law, we are responsible for direct and foreseeable loss caused by our breach, but not for indirect loss unrelated to the Service.'],
                    ['Suspension, termination and deletion','You may stop using the Service or request account deletion through the private support channel or contact email. We may suspend or terminate access for material breach, unlawful use, payment fraud or security risk. Termination does not affect accrued rights, mandatory records, unresolved orders or refunds.'],
                    ['Privacy','We process personal information as described in the Privacy Policy. The Privacy Policy explains account, payment, device, support and security data, retention, user rights and contact methods.'],
                    ['Complaints and contact','Questions or complaints may be submitted through the private support channel or emailed to '.$site['contact_email'].'. We aim to provide an initial response within 10 business days after confirming account or contact control.'],
                    ['Governing law and updates','These Terms are governed by the laws of the mainland of the People\'s Republic of China. Disputes may be submitted to a court with lawful jurisdiction. For material changes, we will publish the updated version and date and obtain consent where required. If the Chinese and English texts differ, the Chinese text prevails to the extent permitted by law.'],
                ]:[
                    ['协议接受与缔约主体','本协议适用于'.$site['company_name'].'通过 d2s.site、d2s.top 提供的网站账户、在线授权、订单支付及私密售后服务。您注册、购买或使用相关服务，即表示已阅读并同意本协议及《隐私政策》。如您不同意，请勿注册或使用付费服务。本协议不排除消费者依法享有的强制性权利。'],
                    ['定义','“本服务”指网站账户、授权管理、订单、支付、下载入口和私密工单等功能；“软件”指 Desktop2Stereo 客户端及其随附组件；“账户”指用于访问本服务的注册身份；“正式授权”指经核实付款后由本站记录的软件权益。'],
                    ['账户注册与安全','您应提供真实、有效且可验证的联系方式。您应妥善保管密码、设备和验证码，发现异常使用时应及时通知我们。账户仅供本人使用；未经书面同意，不得出售、出租或转让账户。为保护账户、交易或处理争议，我们可在必要时要求补充核验。'],
                    ['登录IP与安全记录','您登录用户账户区或管理后台时，我们处理登录时间、结果、可取得的来源IP、User-Agent、设备摘要、会话与安全标识及相关请求结果。这些信息用于防止凭证攻击、识别异常登录、执行设备与单在线会话限制、调查账户或支付争议及保护服务可用性。来源IP不作为真实身份、实际居住地或操作授权的证明。详细登录历史至少保留366天，之后通过独立最小权限留存流程删除；最近成功登录状态可随账户保留用于安全和账户恢复。登录历史不有意保存密码、验证码、完整Token、私钥或支付密钥。'],
                    ['年龄与行为能力','本服务主要面向具有完全民事行为能力的成年人。未成年人应在父母或其他监护人同意和指导下使用，并仅进行与其年龄和认知能力相适应的操作。'],
                    ['软件许可范围','在支付适用价款并遵守本协议的前提下，我们授予您有限的、个人的、非独占且不可转让的正式授权，允许您在兼容设备上使用软件。除法律或适用的开源许可证允许外，您不得反向工程、反编译、修改、转售、再许可或规避技术保护措施。第三方及开源组件适用其各自许可证。'],
                    ['试用、设备会话与离线使用','新账户可享一次30天试用，自首次成功客户端登录时起算；更换设备或修改资料不重置试用。新的客户端登录可能替换原有在线会话。成功心跳后，本机签发的离线凭证可允许最长24小时本地运行，但不得超过试用或授权到期时间。离线使用不构成持续在线授权，也不延长适用期限。'],
                    ['订单、价格与付款','确认前展示的商品和价格构成订单信息。订单通过您选择的支付渠道处理；到账以经核实的渠道证据为准，不以浏览器返回页单独作为依据。我们记录业务订单号、整数金额、币种和履约结果。重复付款、渠道结果冲突等异常将保留用于核对，不会被静默丢弃。'],
                    ['退款与法定权利','退款条件及流程适用法律规定、订单信息和实际支付渠道。经批准的退款按渠道要求原路退回。我们可要求提供核对订单和付款人身份所必需的信息。本协议不排除依法不得排除或限制的消费者权利。'],
                    ['售后与用户提交内容','私密工单仅向注册用户和获授权客服开放。您应提供准确、合法的信息。您对所提交内容保留合法权利，并授权我们在提供售后、保护服务安全和遵守法律所必需的范围内存储、复制和处理该内容。不得上传违法、恶意或您无权提供的第三方内容。'],
                    ['使用规范','您不得利用本服务违法、侵权、传播恶意程序、干扰系统、未经授权访问、滥用支付或退款流程、骚扰客服，或规避账户、授权及会话控制。为处理安全、法律或支付风险，我们可在合理必要范围内限制相关功能，并依法保留申诉渠道。'],
                    ['知识产权','网站设计、商标、文档及未适用单独许可的软件受法律保护。本协议不转移任何所有权。未经许可，不得以暗示背书或关联的方式使用我们的名称或标识。'],
                    ['外部链接与第三方服务','安装包可能由 GitHub、夸克网盘、百度网盘等外部平台托管；支付、企业微信等由第三方提供并适用其规则。我们无法控制外部网站，仅在法律要求范围内承担责任。'],
                    ['服务可用性、维护与变更','我们可为安全、合规和运营需要维护、更新或调整本服务，并合理努力保留授权和订单记录。对于网络、设备、第三方平台、不可抗力或必要维护造成的暂时中断，我们不承诺绝对不中断。'],
                    ['免责声明与责任限制','我们应以合理技能和谨慎提供服务。本协议不排除依法不得排除的责任，包括故意或重大过失、人身损害等责任。在法律允许范围内，我们对因我方违约造成的直接、可预见损失承担责任；对与使用本服务无合理关联的间接损失不承担责任。'],
                    ['中止、终止与账户删除','您可以停止使用服务，或通过私密工单、联系邮箱申请注销账户。对于重大违约、违法使用、支付欺诈或安全风险，我们可中止或终止相关访问。终止不影响已产生的权利、依法保留的记录、未决订单或退款。'],
                    ['隐私保护','我们依照《隐私政策》处理个人信息。《隐私政策》说明账户、支付、设备、工单和安全信息的处理范围、保存期限、用户权利及联系方式。'],
                    ['投诉与联系','如有问题或投诉，可通过私密工单或发送邮件至 '.$site['contact_email'].'。在完成账户或联系方式控制权核验后，我们目标在10个工作日内首次回复。'],
                    ['法律适用与协议更新','本协议适用中华人民共和国大陆地区法律。争议可提交依法有管辖权的人民法院。协议发生重大变化时，我们将公布新版本及日期，并在法律要求时另行取得同意。中英文版本不一致时，在法律允许范围内以中文版本为准。'],
                ];
                break;
            case 'LEGAL':
                $sections=$isEn?[
                    ['Purpose','This Legal Statement explains the nature of information published on the Desktop2Stereo website and the limits of responsibility for external links, third-party services and software use. It supplements the Terms of Service and Privacy Policy and does not replace them.'],
                    ['Product information','We aim to keep product features, prices, compatibility, version information and service descriptions accurate and current. Actual functionality may depend on the operating system, hardware, GPU, drivers, network, license status and software version. Information on this website is not a guarantee of suitability for a particular professional or production environment.'],
                    ['No professional advice','Technical articles, tutorials, screenshots and support replies are provided for general product use. They do not constitute legal, financial, medical, security or other professional advice. You should independently assess important production data and maintain backups.'],
                    ['Software results and user data','2D-to-3D processing may produce artifacts. You are responsible for checking output quality and for holding lawful rights and necessary backups for source media. To the extent permitted by law, we are not responsible for loss caused by failure to back up data or by using output in circumstances requiring independent professional verification.'],
                    ['External links','This website links to GitHub, Quark Drive, Baidu Drive, payment providers, Enterprise WeChat and other third-party services. Links are provided for convenience. Their availability, content, security and data practices are controlled by their operators. A link does not mean that we endorse every statement or service on the external site.'],
                    ['Third-party components','Software may include third-party or open-source components. Their licenses and notices govern their use and may provide rights that these website documents do not limit. Copyright notices and license obligations must be retained.'],
                    ['Intellectual property','Desktop2Stereo names, website design, original text, graphics and software materials are protected by applicable law. Third-party trademarks, screenshots, fonts and components belong to their respective owners. Unauthorized copying, modification, distribution or commercial exploitation is prohibited except as permitted by law or an applicable license.'],
                    ['Forward-looking information','Statements about future features, releases, service availability or performance are plans, not guarantees. We may change plans due to security, compliance, technical or commercial factors. Published current version information should be obtained from the download page or Release cache.'],
                    ['Security and updates','You should obtain installers and updates only from official links published by us or the software project. We may update the website, software and security controls. Delaying updates may affect compatibility or security. Third-party modification of installers is outside our control.'],
                    ['Responsibility limits','We do not exclude liability that cannot be excluded by law. To the extent permitted by law, responsibility for indirect or unforeseeable loss arising from external links, third-party services or use contrary to documentation is limited accordingly. Nothing here reduces mandatory consumer or personal-information rights.'],
                    ['Complaints and corrections','If you believe information on this website is inaccurate, infringing or unlawful, contact us with the relevant page, facts and supporting material. We may request verification and will handle valid requests in accordance with law.'],
                    ['Contact and language','Questions about this statement may be sent to '.$site['contact_email'].'. If the Chinese and English versions differ, the Chinese version prevails to the extent permitted by law.'],
                ]:[
                    ['声明目的','本法律声明说明 Desktop2Stereo 网站所发布信息的性质，以及外部链接、第三方服务和软件使用方面的责任边界。本声明是《用户协议》和《隐私政策》的补充，不替代前述文件。'],
                    ['产品信息说明','我们合理努力保持产品功能、价格、兼容性、版本信息和服务说明准确、及时。实际功能可能受操作系统、硬件、GPU、驱动、网络、授权状态和软件版本影响。网站信息不构成对特定专业或生产环境适用性的保证。'],
                    ['非专业建议','技术文章、教程、截图和客服回复仅供产品使用参考，不构成法律、财务、医疗、安全或其他专业意见。对于重要生产数据，您应独立评估并做好备份。'],
                    ['软件处理结果与用户数据','2D 转 3D 处理可能产生画面瑕疵。您应自行检查输出质量，并确保对源媒体享有合法权利、保留必要备份。在法律允许范围内，我们不对因未备份数据或将输出用于需独立专业核验的场景所造成的损失负责。'],
                    ['外部链接','本站链接至 GitHub、夸克网盘、百度网盘、支付服务商、企业微信等第三方平台。链接仅为方便用户提供；外部平台的可用性、内容、安全措施和数据规则由其运营者控制。链接不表示我们认可外部网站的全部陈述或服务。'],
                    ['第三方组件','软件可能包含第三方或开源组件。其许可证和声明适用于相应组件，并可能提供本网站文件不能限制的权利。版权声明和许可证义务应予保留。'],
                    ['知识产权','Desktop2Stereo 名称、网站设计、原创文字、图形和软件材料受法律保护。第三方商标、截图、字体和组件归各自权利人所有。除法律或适用许可证允许外，禁止未经授权复制、修改、传播或商业利用。'],
                    ['前瞻性信息','关于未来功能、版本、服务可用性或性能的说明属于计划，不构成保证。我们可因安全、合规、技术或经营因素调整计划。当前正式版本应以下载页或 Release 缓存公布的信息为准。'],
                    ['安全与更新','请仅通过我们或软件项目正式公布的链接获取安装包和更新。我们可能更新网站、软件和安全控制；延迟更新可能影响兼容性或安全性。第三方对安装包的篡改不在我们控制范围内。'],
                    ['责任边界','我们不排除依法不得排除的责任。在法律允许范围内，对于因外部链接、第三方服务或违反文档使用而产生的间接、不可预见损失，责任按法律规定合理限制。本声明不减少消费者和个人信息主体依法享有的强制性权利。'],
                    ['投诉与更正','如您认为本站信息不准确、侵权或违法，请提供具体页面、事实和证明材料联系我们。我们可要求核验，并依法处理有效请求。'],
                    ['联系与语言','有关本声明的问题可发送至 '.$site['contact_email'].'。中英文版本不一致时，在法律允许范围内以中文版本为准。'],
                ];
                break;
            default:
                throw new \InvalidArgumentException('policy_type_invalid');
        }
        return $sections;
    }

    public static function document(string $policyType, string $locale, array $site): array
    {
        $type = strtoupper($policyType);
        $titles = [
            'PRIVACY' => ['zh-Hans'=>'隐私政策','en'=>'Privacy policy'],
            'TERMS' => ['zh-Hans'=>'用户协议','en'=>'Terms of Service'],
            'LEGAL' => ['zh-Hans'=>'法律声明','en'=>'Legal Statement'],
        ];
        if (!isset($titles[$type])) throw new \InvalidArgumentException('policy_type_invalid');
        $sections = self::sections($type,$locale,$site);
        $chunks = [];
        foreach ($sections as $index => [$heading,$body]) {
            $chunks[] = ($index+1).'. '.$heading."\n".$body;
        }
        return ['title'=>$titles[$type][str_starts_with($locale,'en')?'en':'zh-Hans'],
            'body'=>implode("\n\n",$chunks),'locale'=>str_starts_with($locale,'en')?'en':'zh-Hans'];
    }
}
