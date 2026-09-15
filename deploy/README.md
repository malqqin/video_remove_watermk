# OVH 部署（Ubuntu / Debian）

使用 PHP 8.4 + Apache、Node.js 22 的 Docker 容器，无需数据库。默认通过服务器的 `8000` 端口访问；解析请求由仓库中的本地解析器处理。

先将本目录、根目录 `Dockerfile`、`.dockerignore`、`compose.yaml` 推送到 GitHub 的 `main` 分支，再在已 SSH 登录的服务器终端执行：

```bash
curl -fsSL https://raw.githubusercontent.com/malqqin/video_remove_watermk/main/deploy/ovh.sh | sudo bash
```

如果登录用户已经是 root，可把 `sudo bash` 改为 `bash`。首次安装会通过系统包管理器安装 Git、Docker 和 Compose，将仓库克隆到 `/opt/video_remove_watermk`，构建镜像并启动服务。需要 Ubuntu/Debian、root 或 sudo 权限，以及可访问 GitHub、Docker Hub 和系统软件源的网络。

后续代码推送到 GitHub 后，再运行相同命令即可更新。脚本只接受 `main` 的快进更新；服务器目录有未提交改动时会停止。构建失败时不会执行容器替换；容器启动后的健康检查失败会输出日志，需要处理错误后重跑。

## 访问与检查

浏览器打开 `http://服务器IP:8000/short_videos/sv2.php?url=https%3A%2F%2Fwww.bilibili.com%2Fvideo%2FBV1RiY56SEbU%2F`。

也可在服务器执行，cURL 会保留原视频链接中所有参数：

```bash
curl --get --data-urlencode 'url=https://www.bilibili.com/video/BV1RiY56SEbU/' \
  http://127.0.0.1:8000/short_videos/sv2.php
```

不传 `url` 返回 HTTP 400 是预期行为，容器健康检查也使用此方式，不会反复请求视频平台。公网访问需要主机防火墙和已启用的 OVH 网络防火墙允许 TCP 8000。平台接口是否可访问仍取决于 OVH 出口 IP、视频链接有效性和平台登录要求。

## 端口、域名和 Cookie

端口占用时，例如改用 8080：

```bash
curl -fsSL https://raw.githubusercontent.com/malqqin/video_remove_watermk/main/deploy/ovh.sh | sudo env PORT=8080 bash
```

如果已有 Nginx/Caddy 用于域名和 HTTPS，部署时设置 `BIND_ADDRESS=127.0.0.1`，反向代理到 `127.0.0.1:8000`。使用自定义端口或绑定地址时，之后每次更新也传相同变量。

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
