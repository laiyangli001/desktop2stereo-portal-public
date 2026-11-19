# EdgeOne 公共页面缓存失效

该功能只提交 EdgeOne URL 清理任务，不启用公共响应缓存，也不改变 PHP 的 `Cache-Control: no-store`。默认关闭。生产启用前须在 EdgeOne 控制台为确认可公开缓存的页面单独配置缓存规则，并完成页面缓存键、会话隔离和回源验收；本地未连接云服务。

## 配置

将下列变量注入 PHP-FPM 和 CLI 的受限环境：

- `D2S_EDGEONE_PURGE_ENABLED=true`：明确开启队列写入和清理任务。
- `D2S_EDGEONE_ZONE_ID`：EdgeOne 站点 ID。
- `D2S_TENCENT_SECRET_ID`、`D2S_TENCENT_SECRET_KEY`：具备该站点 URL 清理权限的腾讯云 API 密钥。
- `D2S_APP_URL`：规范 HTTPS 源站域名，例如 `https://d2s.site`；不能包含路径、查询、片段或用户信息。

密钥只能由受限密钥注入设施提供，不写入仓库或日志。先保持开关为 false，完成密钥权限、站点 ID 和规范域名复核，再启用。

运行 `php bin/migrate.php` 创建 outbox 表。迁移会按数据库权限白名单为应用账号授予该表的显式权限。

## 队列与调度

文章发布/更新、公告、站点资料、导航、语言注册表发布和目录回滚会在业务数据库事务内写入待清理 URL。交易和账户页面不进入队列。重复目标按事件与 URL 去重；worker 每分钟处理最多 20 个目标，使用数据库租约、最多 8 次尝试和递增退避。API 接受任务后状态记为 `submitted`，表示已提交，不代表 EdgeOne 已完成边缘节点清理。

在 `deploy/tasks.cron.example` 中启用 `purge-edgeone-cache` 的每分钟调度项，并保持 PHP-FPM 与 CLI 使用相同环境变量。运行日志由通用任务运行器写入受限任务日志目录。CLI 可手动检查单次结果：

```text
php bin/purge-edgeone-cache.php
```

禁用开关会使 worker 返回 `disabled`，也会停止新的队列写入；既有队列记录保留，可在重新启用后继续处理。

## 验收边界

隔离数据库可验证队列写入、去重、重试和关闭开关。真实凭据、EdgeOne 控制台缓存规则、供应商回执与边缘节点生效时间须在生产验收阶段另行验证；本地不发送真实云 API 请求。
