# Codex Auth Proxy

[English](README.md)

Codex Auth Proxy 是一个面向 Codex CLI 的轻量 PHP/Swoole 本地代理。它使用多个已授权的 ChatGPT OAuth 账号为 Codex CLI 提供请求转发、会话亲和和额度耗尽后的账号切换。

本项目不读取 `~/.codex/auth.*.json`，不重构 `~/.codex` 目录，也不会接管 Codex CLI 的会话文件。每个账号可以通过官方浏览器 OAuth 流程授权，或从当前 Codex CLI 的 `auth.json` 手动导入一次，之后由本工具维护自己的账号文件。

## 能力边界

- 支持 Codex CLI 的 HTTP/SSE 请求和 WebSocket 请求。
- 默认把 Codex CLI 的 `/v1/*` 请求映射到 ChatGPT Codex backend：`https://chatgpt.com/backend-api/codex`。
- 同一个会话保持账号亲和，除非上游返回额度或授权类硬错误，否则不会主动切换账号。
- HTTP/SSE 首帧错误会在向 Codex CLI 写出前拦截，并切换到可用账号重试。
- WebSocket 在尚未向 Codex CLI 转发有效上游数据前遇到额度或授权错误时，会切换账号、重建连接并重放当前请求。
- 刷新 OAuth token 后会校验 token 格式和 ChatGPT account id，避免刷新结果串号污染账号文件。

## 账号文件

代理只读取显式匹配以下格式的账号文件：

```text
~/.config/codex-auth-proxy/accounts/*.account.json
```

账号文件必须使用本项目自定义 schema：

```json
{
  "schema": "codex-auth-proxy.account.v1",
  "provider": "openai-chatgpt-codex",
  "name": "account-a",
  "enabled": true,
  "tokens": {
    "id_token": "...",
    "access_token": "...",
    "refresh_token": "...",
    "account_id": "..."
  },
  "metadata": {
    "email": "optional@example.com",
    "plan_type": "plus"
  }
}
```

只有 `provider` 为 `openai-chatgpt-codex` 且 token claims 校验通过的文件会被加载。普通 `~/.codex/auth.json` 或 API key 格式不会被当作代理账号读取。

## 安装

需要 PHP 8.3、Swoole、Redis 扩展和 Composer：

```bash
composer install
```

检查环境和账号文件：

```bash
bin/codex-auth-proxy doctor
```

## 授权和导入账号

使用官方 OpenAI 浏览器流程授权一个新账号：

```bash
bin/codex-auth-proxy login account-a
```

从当前 Codex CLI 的 ChatGPT 登录状态导入账号：

```bash
bin/codex-auth-proxy import account-a --from="$HOME/.codex/auth.json"
```

`account-a` 和 `--from` 都是可选参数。省略 `--from` 时默认读取 `$HOME/.codex/auth.json`。省略账号名时，工具会从 token 里的 email 自动生成本地账号名；如果没有 email，则回退到 ChatGPT account id：

```bash
bin/codex-auth-proxy login
bin/codex-auth-proxy import
```

如果有多个账号，重复执行 `login` 或 `import`，使用不同名称即可：

```bash
bin/codex-auth-proxy login account-b
bin/codex-auth-proxy login account-c
```

## 账号管理和额度查询

查看已导入账号及本地缓存的冷却时间、额度可用性：

```bash
bin/codex-auth-proxy accounts
bin/codex-auth-proxy accounts list
```

刷新本地缓存的额度状态：

```bash
bin/codex-auth-proxy accounts refresh
bin/codex-auth-proxy accounts refresh account-a
```

查询账号额度：

```bash
bin/codex-auth-proxy accounts status
bin/codex-auth-proxy accounts status account-a
```

`accounts` 只展示本地缓存里的冷却时间和额度可用性，不会额外拉取远端状态。

`accounts refresh` 会复用现有的 usage reader 读取实时 Codex quota，然后更新本地 state cache。可以针对单个账号刷新，也可以不带账号名一次刷新全部账号。

`status` 会为目标账号创建临时隔离的 `CODEX_HOME`，写入该账号的 `auth.json`，再调用本机 `codex app-server` 的 `account/rateLimits/read` 方法查询 Codex 额度。这样使用 Codex CLI 自己的额度读取逻辑，不依赖本项目猜测远端 usage URL。

输出类似 Codex CLI `/status` 的关键信息：

```text
account-a
  Account: account-a@example.com (Plus)
  5h limit: 7% left (resets 2026-04-21 18:10)
  Weekly limit: 85% left (resets 2026-04-28 13:10)
```

如果不指定账号名，会依次查询所有账号。需要机器可读输出时可以加 `--json`：

```bash
bin/codex-auth-proxy accounts status --json
```

该功能要求当前环境能执行 `codex` 命令，并会使用账号文件中的 ChatGPT OAuth token 向 OpenAI 查询额度。

删除账号会默认交互确认，并把账号文件归档为 `.deleted.YYYYmmddHHMMSS`，避免误删 OAuth 授权：

```bash
bin/codex-auth-proxy accounts delete account-a
bin/codex-auth-proxy accounts delete account-a --yes
```

## 导出和手动切换 Codex CLI

导出 Codex CLI 可用的配置和授权文件：

```bash
bin/codex-auth-proxy export config
bin/codex-auth-proxy export auth account-a
bin/codex-auth-proxy export all account-a
```

默认只写入代理自己的目录，不覆盖 Codex CLI 当前文件：

```text
~/.config/codex-auth-proxy/config.toml
~/.config/codex-auth-proxy/auth.json
```

`export config` 会读取 `~/.codex/config.toml`，只替换或插入文件开头的 `openai_base_url`，保留其他配置，例如 `projects`、`mcp_servers` 等。

如果要手动切换当前 Codex CLI 配置，使用 `--apply`：

```bash
bin/codex-auth-proxy export all account-a --apply
```

命令会先交互确认。确认后只备份并覆盖 Codex CLI 的 `config.toml`：

```text
~/.codex/config.toml.bak.YYYYmmddHHMMSS
```

`export auth` 只导出到 `~/.config/codex-auth-proxy/auth.json`。本项目代理请求时使用自己的账号库注入 OAuth token，不依赖也不会通过 `--apply` 覆盖 `~/.codex/auth.json`。

## 配置 Codex CLI

打印 Codex CLI 配置片段：

```bash
bin/codex-auth-proxy config --port=1456
```

把输出写入 `~/.codex/config.toml`。默认内容类似：

```toml
openai_base_url = "http://127.0.0.1:1456/v1"
```

启动代理：

```bash
bin/codex-auth-proxy serve --port=1456
```

之后 Codex CLI 会请求本地代理，由代理选择账号并转发到 ChatGPT Codex backend。`serve` 仍然只走直连上游代理路径，只利用缓存里的可用性和冷却时间做账号选择，不依赖主请求路径上的 `app-server`。

## 配置项

运行时默认值保存在 `config/defaults.php`。配置文件内部使用类似 Hyperf 的 `env('NAME', $default)` 方式读取项目环境变量。需要本地覆盖时，可以复制 `.env.example` 为 `.env`；`.env` 是本地文件，不应提交。

运行时配置可以通过命令暴露的 CLI 参数，或 `CODEX_AUTH_PROXY_*` `.env` 变量覆盖：

```dotenv
CODEX_AUTH_PROXY_HOST=127.0.0.1
CODEX_AUTH_PROXY_PORT=1456
CODEX_AUTH_PROXY_COOLDOWN_SECONDS=18000
CODEX_AUTH_PROXY_CALLBACK_HOST=localhost
CODEX_AUTH_PROXY_CALLBACK_PORT=1455
CODEX_AUTH_PROXY_CALLBACK_TIMEOUT_SECONDS=300
CODEX_AUTH_PROXY_ACCOUNTS_DIR=/home/me/.config/codex-auth-proxy/accounts
CODEX_AUTH_PROXY_STATE_FILE=/home/me/.config/codex-auth-proxy/state.json
CODEX_AUTH_PROXY_LOG_LEVEL=warning
CODEX_AUTH_PROXY_CODEX_USER_AGENT="codex_cli_rs/0.114.0 codex-auth-proxy/0.1.0"
CODEX_AUTH_PROXY_CODEX_BETA_FEATURES=multi_agent
CODEX_AUTH_PROXY_TRACE_DIR=/home/me/.config/codex-auth-proxy/traces
CODEX_AUTH_PROXY_HTTP_PROXY=http://127.0.0.1:7890
CODEX_AUTH_PROXY_HTTPS_PROXY=http://127.0.0.1:7890
CODEX_AUTH_PROXY_NO_PROXY=localhost,127.0.0.1,::1
```

本项目只读取上面的项目专用代理变量，不会把 shell 里的 `HTTP_PROXY`、`HTTPS_PROXY`、`NO_PROXY` 或小写变体当作应用配置，避免系统环境变量意外改变本工具行为。

出站代理配置会作用于 OAuth token exchange、token refresh、`serve` 上游 HTTP/SSE 和 WebSocket 连接，以及 `accounts status` / `accounts refresh` 调用 `codex app-server` 的路径。代理 URL 支持 `http://` 和 `socks5://`。对于 `codex app-server` 子进程，本工具会先清理 shell 环境里的标准代理变量，再把解析后的项目代理配置显式导出为标准 `HTTP_PROXY`、`HTTPS_PROXY`、`NO_PROXY` 环境变量。

`CODEX_AUTH_PROXY_NO_PROXY` 支持精确 host/IP、`localhost`、loopback 地址、带端口的 host、`*`，以及 `openai.com` 或 `.openai.com` 形式的域名后缀匹配。

当上游 HTTP/WebSocket 出错时，`serve` 会向 `CODEX_AUTH_PROXY_TRACE_DIR` 写入脱敏 JSON trace。trace 文件包含 request id、传输类型、阶段、session key、账号名、状态码和脱敏错误摘要；不会保存完整 OAuth token 或原始 authorization header。

如果 `serve` 日志里出现 upstream WebSocket 或 HTTPS `status -1`，说明 Swoole client 没有拿到上游 HTTP 响应。当前网络不能直连 `chatgpt.com` 时，需要把 `CODEX_AUTH_PROXY_HTTPS_PROXY` 设置为支持的代理 URL，例如 `http://127.0.0.1:7890` 或 `socks5://127.0.0.1:7890`。不要给 `serve` 配置 `https://` 代理 URL；Swoole 上游转发只支持 HTTP 和 SOCKS5 proxy 配置。

## 路由策略

代理会优先从 Codex turn-state/session headers、`metadata.user_id`、conversation/thread id 提取会话标识。如果没有显式会话 id，则使用首个请求消息内容生成稳定 hash。

同一个会话会绑定到同一个账号，直到该账号触发额度耗尽或授权失败等硬错误。硬错误发生后，代理会记录冷却时间：

```text
~/.config/codex-auth-proxy/state.json
```

新会话会避开仍在冷却中的账号。已有会话只有在自己的账号触发硬错误时才切换，避免无意义轮换影响缓存命中。

## 开发

运行测试：

```bash
composer test
```

运行静态分析：

```bash
composer analyse
```

校验 PHAR 打包配置：

```bash
composer box:validate
```

构建可分发的 PHAR：

```bash
composer build:phar
```

输出文件：

```text
build/codex-auth-proxy.phar
```

运行方式与源码入口一致：

```bash
php build/codex-auth-proxy.phar --version
```

## 设计取舍

本项目只实现 Codex CLI 代理和多 ChatGPT OAuth 账号切换所需的核心路径，不复刻 CLIProxyAPI 的多协议网关能力。它不包含 Claude/OpenAI/Responses 多格式互转、usage reporter、token counting、通用 payload config 等功能。

这个范围是刻意收敛的：保持目录结构简单，账号文件独立，降低代理行为对 Codex CLI 原生会话和 `resume` 的影响。
