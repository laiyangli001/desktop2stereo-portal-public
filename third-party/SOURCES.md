# Source provenance

## 东方商城系统

用户提供位置：../desktop2stereo-site/webserver_admin/sell_system/。
用户说明：购买下载，用于本站改造。原作者许可范围尚未独立核实；购买与许可材料需留档。

- public/assets/css/commerce.css：原 assets/css/admin.css，保持原文件内容与头注释。
- includes/repositories/Database.php：参考原 includes/lib/DB.php 的PDO预处理及事务组织，重新实现最小MySQL连接/事务，移除分站和动态SQL构造。
- admin/、user/、includes/、template/：沿用商城模块组织，按阶段迁移适用代码，不复制原安装器或配置。
- 页面外观沿用原商城色彩与字体，当前只提供公开基础入口。

## 支付宝协议对照

AlipayClient、AlipayRSA和RawJson为本站独立实现，通过OpenSSL使用RSA2；没有复制或执行下载的支付宝SDK源码。
协议依据：支付宝[电脑网站支付参数](https://ideservice.alipay.com/cms/site/0izblh)、
[自研商家流程](https://ideservice.alipay.com/cms/site/0izbjt)及
[官方PHP SDK AopClient](https://github.com/alipay/alipay-sdk-php-all/blob/master/v2/aop/AopClient.php)。
旧Go站的D2S订单号、参数配置、回调路径及校验清单仅用作迁移对照。新实现独立验证原始API响应节点，不照搬旧站的响应签名拼接。

支付宝Logo：public/assets/img/alipay-logo-square.png复用旧站web/public/alipay-logo-square.png；按用户要求展示在支付宝支付选项。未修改图片内容。

## 本地二维码生成

public/assets/js/qrcodegen-v1.8.0-es6.js：Project Nayuki QR-Code-generator v1.8.0 的官方预编译 ES6 发布资产，MIT License，源码头部许可完整保留，仅移除上游5处行尾空格，算法与许可未改。
来源：https://github.com/nayuki/QR-Code-generator/releases/tag/v1.8.0
下载：https://github.com/nayuki/QR-Code-generator/releases/download/v1.8.0/qrcodegen-v1.8.0-es6.js
上游原文件SHA-256：6a1116192ed1dd67fa1bf31e77f5817103d71c23bbac24c382e698b7668bdd01。
仅清理行尾空格后的本站文件SHA-256：5d65f10cbda9bc5f61df20e4dfb716ef3203b19f7daedb6cc7c0ae51193f033f。
微信二维码在浏览器本地生成，无第三方制码请求；本站SVG渲染器独立实现，不把URI作为HTML插入。

## 其他来源

软件授权参考：../desktop2stereo-site/webserver_admin/software_auth/，尚未复制其验证器。
反馈系统参考：../desktop2stereo-site/webserver_admin/feedback/，加密代码不执行、不解密、不复制。
现有D2S协议：../desktop2stereo-site/；尚未复制AGPL业务源码。PHP迁移如复制或改编受许可约束源码，必须保留相应声明及履行适用义务。
