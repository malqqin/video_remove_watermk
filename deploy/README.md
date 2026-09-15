# OVH 部署（Ubuntu / Debian）

使用 PHP 8.4 + Apache、Node.js 22 的 Docker 容器，无需数据库。默认只监听服务器本机的 `127.0.0.1:8000`，公网请求通过现有 Nginx 的新增路由转发。现有 Nginx 和 `3000` 端口服务继续运行。

先将本目录、根目录 `Dockerfile`、`.dockerignore`、`compose.yaml` 推送到 GitHub 的 `main` 分支，再在已 SSH 登录的服务器终端执行：

```bash
curl -fsSL https://raw.githubusercontent.com/malqqin/video_remove_watermk/main/deploy/ovh.sh | sudo env BIND_ADDRESS=127.0.0.1 PORT=8000 bash
```

如果登录用户已经是 root，可省略 `sudo`。首次安装会通过系统包管理器安装 Git、Docker 和 Compose，将仓库克隆到 `/opt/video_remove_watermk`，构建镜像并启动服务。需要 Ubuntu/Debian、root 或 sudo 权限，以及可访问 GitHub、Docker Hub 和系统软件源的网络。

后续代码推送到 GitHub 后，再运行相同命令即可更新。脚本只接受 `main` 的快进更新；服务器目录有未提交改动时会停止。构建失败时不会执行容器替换；容器启动后的健康检查失败会输出日志，需要处理错误后重跑。

## 访问与检查

先在服务器本机验证，cURL 会保留原视频链接中所有参数：

```bash
curl --get --data-urlencode 'url=https://www.bilibili.com/video/BV1RiY56SEbU/' \
  http://127.0.0.1:8000/short_videos/sv2.php
```

不传 `url` 返回 HTTP 400 是预期行为，容器健康检查也使用此方式，不会反复请求视频平台。`8000` 端口仅本机可访问，无需向公网放行。平台接口是否可访问仍取决于 OVH 出口 IP、视频链接有效性和平台登录要求。

## 端口、域名和 Cookie

先确认 `8000` 端口可用：

```bash
sudo ss -ltnp '( sport = :8000 )'
sudo docker ps --format 'table {{.Names}}\t{{.Ports}}'
```

脚本只更新本项目的容器。如果 `8000` 已由其他服务占用，保留该服务，选择空闲端口，例如 `8080`：

```bash
curl -fsSL https://raw.githubusercontent.com/malqqin/video_remove_watermk/main/deploy/ovh.sh | sudo env BIND_ADDRESS=127.0.0.1 PORT=8080 bash
```

本机验证成功后，确认目标站点尚未使用 `/short_videos/sv2.php` 路径，将 [nginx-location.conf](nginx-location.conf) 中的 `location` 配置加入现有站点的 `server { ... }` 块。保留其他路由，尤其是指向 `3000` 的转发配置。该文件是配置片段，不能直接替换整个站点文件。使用自定义端口时同步修改片段中的 `proxy_pass`，并在之后每次更新传相同的 `PORT`。

配置完成后验证并平滑重载：

```bash
sudo nginx -t && sudo systemctl reload nginx
```

然后通过现有站点的域名或 IP 访问 `/short_videos/sv2.php?url=经过URL编码的视频链接`。不需要停止或禁用 Nginx。

Cookie 保存在 `/etc/video-remove-watermk/parser.env`，不会提交到 GitHub 或打包进镜像。只有平台需要登录时才填写对应值，每行一个变量，例如：

```dotenv
DOUYIN_COOKIE='从自己的登录会话取得的 Cookie'
BILIBILI_COOKIE='从自己的登录会话取得的 Cookie'
KUAISHOU_COOKIE='从自己的登录会话取得的 Cookie'
XIAOHONGSHU_COOKIE='从自己的登录会话取得的 Cookie'
WEIBO_COOKIE='从自己的登录会话取得的 Cookie'
TOUTIAO_COOKIE='从自己的登录会话取得的 Cookie'
```

修改后重跑部署命令即可生效。查看日志：

```bash
cd /opt/video_remove_watermk
sudo docker compose -p video-remove-watermk logs --tail=100 -f api
```

旧版 Debian 若安装的是独立 Compose，使用 `docker-compose` 替代 `docker compose`。

## 本地验证记录

2026-09-15：快手、小红书、抖音、B站提供的真实链接均通过本地 `sv2.php` 返回 `code: 200` 和视频地址；快手、小红书的视频直链经 Range 请求返回 `206 video/mp4`。其余七个平台通过错误入口检查，其中五个旧解析器通过离线回归测试，仍需有效分享链接完成真实视频验证。这些结果不代表 OVH 网络上的解析结果。

PHP 离线测试（不请求平台）：

```bash
for suite in aggregator weibo pipigx pipixia toutiao zuiyou; do
  php tests/platform-regression.php "$suite" || exit 1
done
```
