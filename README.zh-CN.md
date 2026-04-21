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

命令会先交互确认。确认后先备份当前文件，再覆盖：

```text
~/.codex/config.toml.bak.YYYYmmddHHMMSS
~/.codex/auth.json.bak.YYYYmmddHHMMSS
```

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

之后 Codex CLI 会请求本地代理，由代理选择账号并转发到 ChatGPT Codex backend。

## 配置项

运行时配置可以通过 CLI 参数或 `.env` 覆盖：

```dotenv
CODEX_AUTH_PROXY_HOST=127.0.0.1
CODEX_AUTH_PROXY_PORT=1456
CODEX_AUTH_PROXY_CALLBACK_HOST=localhost
CODEX_AUTH_PROXY_CALLBACK_PORT=1455
CODEX_AUTH_PROXY_ACCOUNTS_DIR=/home/me/.config/codex-auth-proxy/accounts
CODEX_AUTH_PROXY_STATE_FILE=/home/me/.config/codex-auth-proxy/state.json
CODEX_AUTH_PROXY_LOG_LEVEL=warning
CODEX_AUTH_PROXY_CODEX_USER_AGENT="codex_cli_rs/0.114.0 codex-auth-proxy/0.1.0"
CODEX_AUTH_PROXY_CODEX_BETA_FEATURES=multi_agent
```

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
