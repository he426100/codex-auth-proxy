# Codex Auth Proxy

[中文文档](README.zh-CN.md)

Codex Auth Proxy is a small PHP/Swoole proxy for Codex CLI. It lets Codex use a local proxy backed by multiple manually imported ChatGPT OAuth accounts.

It does not automate browser login, does not read `~/.codex/auth.*.json`, and does not restructure `~/.codex`.

## Account Files

The proxy reads only files matching:

```text
~/.config/codex-auth-proxy/accounts/*.account.json
```

Each file must use the explicit schema:

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

## Usage

Authorize a new ChatGPT Codex account with the official OpenAI browser flow:

```bash
bin/codex-auth-proxy login account-a
```

Import the currently active Codex ChatGPT login:

```bash
bin/codex-auth-proxy import account-a --from="$HOME/.codex/auth.json"
```

Both `account-a` and `--from` are optional. When `--from` is omitted, the importer reads `$HOME/.codex/auth.json`. When the name is omitted, the proxy infers a local account name from the token email, falling back to the ChatGPT account ID:

```bash
bin/codex-auth-proxy login
bin/codex-auth-proxy import
```

Export Codex CLI files for manual switching:

```bash
bin/codex-auth-proxy export config
bin/codex-auth-proxy export auth account-a
bin/codex-auth-proxy export all account-a
```

Exports are written to:

```text
~/.config/codex-auth-proxy/config.toml
~/.config/codex-auth-proxy/auth.json
```

`export config` reads `~/.codex/config.toml`, replaces or prepends only the leading `openai_base_url`, and preserves the rest of the file, including project and MCP settings.

Use `--apply` to prompt before backing up and overwriting Codex CLI's active files:

```bash
bin/codex-auth-proxy export all account-a --apply
```

Existing files are backed up as `~/.codex/config.toml.bak.YYYYmmddHHMMSS` and `~/.codex/auth.json.bak.YYYYmmddHHMMSS` before being overwritten.

Validate imported accounts:

```bash
bin/codex-auth-proxy doctor
```

Print the Codex config snippet:

```bash
bin/codex-auth-proxy config --port=1456
```

Start the proxy:

```bash
bin/codex-auth-proxy serve --port=1456
```

The proxy supports Codex HTTP/SSE and WebSocket requests. Requests are mapped from Codex CLI's `/v1/*` base URL to ChatGPT's Codex backend (`https://chatgpt.com/backend-api/codex`). HTTP streams are framed as SSE before forwarding to Codex, and first-frame quota/auth errors are intercepted before bytes are sent so the proxy can switch to another available account. WebSocket requests use Codex websocket v2 headers and retry once on a replacement account when a quota/auth error arrives before any upstream data has been forwarded.

## Configuration

Runtime defaults can be overridden with CLI options or `.env` variables:

```dotenv
CODEX_AUTH_PROXY_HOST=127.0.0.1
CODEX_AUTH_PROXY_PORT=1456
CODEX_AUTH_PROXY_CALLBACK_HOST=127.0.0.1
CODEX_AUTH_PROXY_CALLBACK_PORT=1455
CODEX_AUTH_PROXY_ACCOUNTS_DIR=/home/me/.config/codex-auth-proxy/accounts
CODEX_AUTH_PROXY_STATE_FILE=/home/me/.config/codex-auth-proxy/state.json
CODEX_AUTH_PROXY_LOG_LEVEL=warning
CODEX_AUTH_PROXY_CODEX_USER_AGENT="codex_cli_rs/0.114.0 codex-auth-proxy/0.1.0"
CODEX_AUTH_PROXY_CODEX_BETA_FEATURES=multi_agent
```

## Routing Policy

The proxy keeps session affinity: the same Codex session stays on the same account. It switches only when the upstream response is classified as a hard quota/auth failure, then records a cooldown in:

```text
~/.config/codex-auth-proxy/state.json
```

Session IDs are extracted from Codex turn-state/session headers, `metadata.user_id`, conversation/thread IDs, and a stable hash of the first request messages when no explicit session ID exists.

## Development

```bash
composer install
composer test
composer analyse
```
