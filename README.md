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

`export config` reads `~/.codex/config.toml`, replaces or prepends the root `model_provider = "openai"` and `openai_base_url`, and preserves the rest of the file, including project and MCP settings.

Use `--apply` to prompt before backing up and overwriting Codex CLI's active `config.toml`:

```bash
bin/codex-auth-proxy export all account-a --apply
```

The existing config is backed up as `~/.codex/config.toml.bak.YYYYmmddHHMMSS` before being overwritten.

`export auth` only writes `~/.config/codex-auth-proxy/auth.json`. The proxy injects OAuth tokens from its own account store and does not rely on or overwrite `~/.codex/auth.json` through `--apply`.

Validate imported accounts:

```bash
bin/codex-auth-proxy doctor
```

Manage imported accounts and cached quota state:

```bash
bin/codex-auth-proxy accounts
bin/codex-auth-proxy accounts list
bin/codex-auth-proxy accounts bindings
bin/codex-auth-proxy accounts bindings session-key
bin/codex-auth-proxy accounts refresh
bin/codex-auth-proxy accounts refresh account-a
bin/codex-auth-proxy accounts status
bin/codex-auth-proxy accounts status account-a
bin/codex-auth-proxy accounts delete account-a
```

`accounts` shows the locally cached cooldown and quota availability for imported accounts.

`accounts bindings` reads the local `state.json` and shows which session key is currently bound to which account, together with the account plan, cooldown state, availability, and the latest cached usage check time. It does not call any remote endpoint, so it is useful when debugging which account an active session is pinned to. Pass the optional second argument to filter by an exact session key. Add `--json` for machine-readable output.

`accounts refresh` uses the existing usage reader to fetch the current Codex quota for one account or all accounts, then updates the local state cache.

`accounts status` creates a temporary isolated `CODEX_HOME` for the selected account, writes that account's `auth.json`, and calls the local `codex app-server` `account/rateLimits/read` method. This uses Codex CLI's own quota reader instead of guessing remote usage URLs.

It prints the account plan plus the 5h and weekly quota windows:

```text
account-a
  Account: account-a@example.com (Plus)
  5h limit: 7% left (resets 2026-04-21 18:10)
  Weekly limit: 85% left (resets 2026-04-28 13:10)
```

When no account name is provided, it checks every imported account. Add `--json` for machine-readable output. `accounts delete` archives the account file as `.deleted.YYYYmmddHHMMSS` after confirmation; use `--yes` to skip the prompt.

This command requires the `codex` binary to be available and sends the stored ChatGPT OAuth token to OpenAI through Codex CLI's official app-server flow.

Print the Codex config snippet:

```bash
bin/codex-auth-proxy config --port=1456
```

It prints:

```toml
model_provider = "openai"
openai_base_url = "http://127.0.0.1:1456/v1"
```

Start the proxy:

```bash
bin/codex-auth-proxy serve --port=1456
```

The proxy supports Codex HTTP/SSE and WebSocket requests. Requests are mapped from Codex CLI's `/v1/*` base URL to ChatGPT's Codex backend (`https://chatgpt.com/backend-api/codex`). `serve` stays on the direct upstream proxy path; it only consults the cached quota availability and cooldown state to choose an account, and it does not depend on `app-server` being in the main request path. HTTP streams are framed as SSE before forwarding to Codex, and first-frame quota/auth errors are intercepted before bytes are sent so the proxy can switch to another available account. WebSocket requests use Codex websocket v2 headers and can fail over across multiple replacement accounts while no upstream data has been forwarded yet.

## Configuration

Runtime defaults live in `config/defaults.php`. The config file reads project environment variables with `env('NAME', $default)`, similar to Hyperf-style config files. Copy `.env.example` to `.env` when you need local overrides; `.env` is optional and is not committed. By default the loader reads the `.env` next to this project; set `CODEX_AUTH_PROXY_DOTENV_FILE=/path/to/.env` when running a PHAR or when you want an explicit config file.

Runtime defaults can be overridden with CLI options where a command exposes them, or with `CODEX_AUTH_PROXY_*` `.env` variables:

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

The project intentionally reads only the namespaced proxy variables above. It does not treat shell-level `HTTP_PROXY`, `HTTPS_PROXY`, `NO_PROXY`, `ALL_PROXY`, or lowercase variants as application configuration.

Outbound proxy settings are applied to OAuth token exchange, token refresh, `serve` upstream HTTP/SSE and WebSocket connections, and `accounts status` / `accounts refresh` when they spawn `codex app-server`. Proxy URLs support `http://` and `socks5://`. For the `codex app-server` subprocess, shell-level proxy variables are cleared first, then the resolved project proxy settings are exported as standard `HTTP_PROXY`, `HTTPS_PROXY`, and `NO_PROXY` environment variables.

`CODEX_AUTH_PROXY_NO_PROXY` supports exact hosts/IPs, `localhost`, loopback addresses, host values with ports, `*`, and suffix matching with either `openai.com` or `.openai.com`.

When upstream HTTP/WebSocket errors occur, `serve` writes a redacted JSON trace to `CODEX_AUTH_PROXY_TRACE_DIR`. Trace files include request id, transport, phase, session key, account name, status, and a sanitized error summary; they do not store full OAuth tokens or raw authorization headers.

If `serve` logs an upstream WebSocket or HTTPS failure with `status -1`, the Swoole client did not receive an upstream HTTP response. On networks that cannot connect to `chatgpt.com` directly, set `CODEX_AUTH_PROXY_HTTPS_PROXY` to a supported proxy URL such as `http://127.0.0.1:7890` or `socks5://127.0.0.1:7890`. Do not use an `https://` proxy URL for `serve`; Swoole upstream forwarding only supports HTTP and SOCKS5 proxy configuration.

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

Validate the PHAR build configuration:

```bash
composer box:validate
```

Build a distributable PHAR:

```bash
composer build:phar
```

The PHAR is written to:

```text
build/codex-auth-proxy.phar
```

Run it like the source binary:

```bash
php build/codex-auth-proxy.phar --version
```
