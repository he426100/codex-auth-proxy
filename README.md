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

`accounts refresh` fetches the current Codex quota for one account or all accounts through the direct ChatGPT usage endpoint, then updates the local state cache.

`serve` reloads the account directory before handling each new request, so `accounts refresh`, `login`, `import`, or external `.account.json` updates take effect without restarting the proxy.

`accounts status` uses the same direct usage reader and prints the live plan plus quota windows for the selected account. When no account name is provided, it checks every imported account.

It prints the account plan plus the 5h and weekly quota windows:

```text
account-a
  Account: account-a@example.com (Plus)
  5h limit: 7% left (resets 2026-04-21 18:10)
  Weekly limit: 85% left (resets 2026-04-28 13:10)
```

Add `--json` for machine-readable output. `accounts delete` archives the account file as `.deleted.YYYYmmddHHMMSS` after confirmation; use `--yes` to skip the prompt.

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

The proxy supports Codex HTTP/SSE and WebSocket requests. Requests are mapped from Codex CLI's `/v1/*` base URL to ChatGPT's Codex backend (`https://chatgpt.com/backend-api/codex`). `serve` stays on the direct upstream proxy path; it consults cached quota availability and cooldown state to choose an account, and periodically refreshes quota snapshots without using `codex app-server`. HTTP streams are framed as SSE before forwarding to Codex, and first-frame quota/auth errors are intercepted before bytes are sent so the proxy can switch to another available account. WebSocket requests use Codex websocket v2 headers and can fail over across multiple replacement accounts while no upstream data has been forwarded yet.

## Configuration

Copy `.env.example` to `.env` when you need local overrides. `.env` is optional and is not committed. By default the loader reads the `.env` next to this project; set `CODEX_AUTH_PROXY_DOTENV_FILE=/path/to/.env` when running a PHAR or when you want an explicit config file.

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
CODEX_AUTH_PROXY_CODEX_USER_AGENT="codex-auth-proxy/0.1.0"
CODEX_AUTH_PROXY_CODEX_BETA_FEATURES=
CODEX_AUTH_PROXY_CODEX_ORIGINATOR=codex-auth-proxy
CODEX_AUTH_PROXY_CODEX_RESIDENCY=
CODEX_AUTH_PROXY_CODEX_UPSTREAM_BASE_URL=https://chatgpt.com/backend-api/codex
CODEX_AUTH_PROXY_USAGE_BASE_URL=https://chatgpt.com/backend-api
CODEX_AUTH_PROXY_USAGE_REFRESH_INTERVAL_SECONDS=600
CODEX_AUTH_PROXY_LOG_FILE=
CODEX_AUTH_PROXY_LOG_LEVEL=warning
CODEX_AUTH_PROXY_TRACE_FILE=
CODEX_AUTH_PROXY_TRACE_LEVEL=info
CODEX_AUTH_PROXY_TRACE_MUTATIONS=true
CODEX_AUTH_PROXY_TRACE_TIMINGS=false
CODEX_AUTH_PROXY_HTTP_PROXY=http://127.0.0.1:7890
CODEX_AUTH_PROXY_HTTPS_PROXY=http://127.0.0.1:7890
CODEX_AUTH_PROXY_NO_PROXY=localhost,127.0.0.1,::1
```

The project intentionally reads only the namespaced proxy variables above. It does not treat shell-level `HTTP_PROXY`, `HTTPS_PROXY`, `NO_PROXY`, `ALL_PROXY`, or lowercase variants as application configuration.

`CODEX_AUTH_PROXY_CODEX_USER_AGENT`, `CODEX_AUTH_PROXY_CODEX_ORIGINATOR`, and `CODEX_AUTH_PROXY_CODEX_BETA_FEATURES` are only fallback values. When Codex CLI sends those headers itself, the proxy forwards the downstream values unchanged.

Outbound proxy settings are applied to OAuth token exchange, token refresh, `serve` upstream HTTP/SSE and WebSocket connections, and direct usage reads from `accounts status`, `accounts refresh`, and the `serve` background refresher. Proxy URLs support `http://` and `socks5://`.

`CODEX_AUTH_PROXY_NO_PROXY` supports exact hosts/IPs, `localhost`, loopback addresses, host values with ports, `*`, and suffix matching with either `openai.com` or `.openai.com`.

Set `CODEX_AUTH_PROXY_USAGE_REFRESH_INTERVAL_SECONDS=0` to disable the `serve` background usage refresher.

When `CODEX_AUTH_PROXY_LOG_FILE` and `CODEX_AUTH_PROXY_TRACE_FILE` are left empty, source runs write logs to `runtime/logs` under the project root, and PHAR runs write logs to `runtime/logs` next to the `.phar` file. Set `CODEX_AUTH_PROXY_TRACE_MUTATIONS=true` to record normalization events, and `CODEX_AUTH_PROXY_TRACE_TIMINGS=true` to record request timing. Trace logs do not store prompt content, OAuth tokens, or raw authorization headers.

Run `php bin/codex-auth-proxy trace` to summarize WebSocket retries, HTTP fallbacks, `stream disconnected before response.completed` terminal events, and lineage errors from the trace log. Use `--file=/path/to/trace.jsonl` for a specific trace file.

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
