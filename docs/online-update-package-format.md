# GitHub Release 在线升级

网站升级直接使用 GitHub Release 对应提交的源码归档。发布时无需另行生成或上传签名升级附件，也不需要网站更新私钥。Release 标签定位提交；下载完整性通过 GitHub HTTPS、归档 SHA-256、候选目录清单和迁移文件校验。

## 发布与检测

将代码推送到 GitHub，在该提交创建正式 Release 并发布标签。GitHub 会生成源码归档。

后台“系统升级”页可以填写 GitHub 仓库地址，支持 `owner/repository` 和仓库 URL。默认仓库为 `laiyangli001/desktop2stereo-portal-public`。公开仓库无需令牌；私有仓库可使用只读环境变量 `D2S_GITHUB_TOKEN`。

后台操作流程：

1. 点击“检查更新”，获取最新正式 Release 并比较完整提交 SHA；
2. 点击“下载 Release 源码”，将 GitHub 自动生成的源码归档放入私有暂存区；
3. 点击“准备候选目录”，校验代码、依赖锁文件、迁移清单和 PHP 语法；
4. 配置在线安装执行器后，点击“备份并安装”。后台会创建数据库和商品图片备份，执行迁移、切换版本、重启 PHP-FPM 并进行 HTTPS 健康检查，同时显示阶段和进度。

仅“检查更新”不会下载文件；下载和准备也不会更改正在运行的网站。

## 源码校验与候选准备

源码归档最多 128 MiB，解压后最多 512 MiB。后端校验 GZip、归档校验和、统一顶层目录、文件类型、路径安全和重复文件；拒绝符号链接、硬链接、扩展头、绝对路径、反斜杠和路径穿越。候选准备只保留网站运行文件与 Composer 清单，不把测试和文档放入部署候选。

候选版本必须与当前版本使用相同的 `composer.lock`，并复用当前 `vendor/`；锁文件变化会停止安装，避免在服务器运行 Composer。准备流程为运行文件生成发布清单、校验迁移文件清单及 SHA-256，并使用 PHP tokenizer 检查语法，不执行归档代码。

GitHub 源码归档的 SHA-256 用于检测传输或存储损坏，并不是发布者签名。能够发布仓库 Release 的维护者也能够影响网站升级内容，应限制仓库写权限并保护维护者账户。

## 在线安装执行器

Web PHP-FPM 进程不直接获得 root 权限。安装任务由专用的 `d2s-update` 系统账户运行；Web 用户只能请求 systemd 启动带随机任务 ID 的固定模板服务。升级账户仅通过 sudo 重启配置的 PHP-FPM 服务。

仓库提供以下部署示例：

- `deploy/systemd/desktop2stereo-update-worker@.service`
- `deploy/systemd/desktop2stereo-update-worker.sudoers.example`
- `deploy/systemd/d2s-start-update-task.example`

在服务器安装前，需按实际部署路径修改 unit 模板中的路径和 PHP 二进制。创建 `d2s-update` 受限账户及专用目录，配置目录属主和权限。Web 用户与升级账户需通过受限共享组读写任务目录。升级账户还需获准读写部署目录、备份目录、暂存目录、候选目录和共享持久数据目录。不要让这些目录位于网站 `public/` 下。

将 unit 安装到 `/etc/systemd/system/desktop2stereo-update-worker@.service`，将启动校验脚本安装到 `/usr/local/sbin/d2s-start-update-task` 并设为 root 所有、不可被 Web 用户修改；然后运行 `systemctl daemon-reload`。按示例配置 sudoers：Web 用户只能执行此校验脚本，脚本只接受32位小写十六进制任务 ID；升级账户只能重启指定 PHP-FPM 服务。

配置环境变量，并确保 Web PHP-FPM 与 systemd worker 都能读取相同的升级配置：

- `D2S_UPDATE_INSTALL_ENABLED=1`
- `D2S_DEPLOYMENT_ROOT=/srv/desktop2stereo-portal`：其下应有 `current` 符号链接和 `releases/`；
- `D2S_UPDATE_BACKUP_DIR=/var/backups/desktop2stereo-portal`
- `D2S_UPDATE_TASK_DIR=/var/lib/desktop2stereo-portal/update-tasks`
- `D2S_UPDATE_STAGING_DIR=/var/lib/desktop2stereo-portal/update-staging`
- `D2S_UPDATE_CANDIDATE_DIR=/var/lib/desktop2stereo-portal/update-candidates`
- `D2S_UPDATE_HEALTH_URL=https://站点域名/api/v2/health`
- `D2S_UPDATE_RESTART_SERVICE=d2s-php-fpm.service`

还需向 worker 提供应用数据库连接和主密钥配置（或确保部署的共享 `storage/config.local.json` 可读），并按 `deploy/systemd/desktop2stereo-update-worker.sudoers.example` 配置仅限指定 systemd 操作的 sudoers 规则。环境文件应由 root 持有，权限限制为 root 和升级组可读；不得把凭据提交到仓库。

安装器要求当前部署使用受管理的 `current -> releases/<sha>` 符号链接布局。升级时它会先校验完整备份（包括商品图片），复制候选版本、执行数据库迁移，再原子切换 `current`。切换或健康检查失败时会恢复旧代码版本；如果迁移已经执行，数据库仍需要从本次备份恢复，任务会在结果中标记此情况。保留备份直到确认站点和业务流程正常。

## 本地验证与生产边界

本仓库包含执行器代码和服务器配置模板；提交代码不会自动修改服务器 systemd、sudoers、PHP-FPM 环境或目录权限。只有完成服务器端配置后，后台才会显示可用的在线安装状态。生产服务器配置和重启需要单独执行，不能仅凭页面显示“候选已准备”认定升级完成。
