# Account Usage Cache and Availability Design

## Goal

Make `codex-auth-proxy` show which ChatGPT accounts are actually usable before Codex CLI hits a quota error. An account is available only when all of these are true:

- The account file is enabled.
- The account is not under scheduler cooldown.
- The latest known 5h Codex quota has more than 0% left.
- The latest known weekly Codex quota has more than 0% left.

The feature should keep the default account overview fast and avoid calling OpenAI on every `accounts` invocation.

## Non-Goals

- Do not hot-switch an active Codex CLI process.
- Do not auto-login accounts.
- Do not replace the existing quota-error fallback path.
- Do not require live quota data before an account can ever be used. Accounts with unknown usage remain selectable until refreshed or proven exhausted.

## User Interface

`accounts` remains the default overview command, but it becomes a cached availability dashboard. It shows one row per account:

- Name
- Email
- Plan
- Enabled
- Cooldown
- 5h left
- Weekly left
- Available
- Reason
- Checked at

`accounts --json` includes the same fields as structured data.

`accounts refresh` refreshes all accounts by calling the existing Codex app-server usage reader for each account, then writes the results to local state.

`accounts refresh <name>` refreshes one account.

`accounts status` remains supported as a detailed live view, but it should also update the local usage cache with successful results and errors.

## Availability Rules

Availability is calculated from account metadata, scheduler state, and usage cache:

- `disabled`: account `enabled` is false.
- `cooldown`: `cooldown_until` is in the future.
- `usage_exhausted`: cached 5h or weekly left percent is less than or equal to 0.
- `usage_error`: latest refresh failed and there is no usable prior successful quota snapshot.
- `usage_unknown`: no usage cache exists. This is not treated as unavailable for routing, but is shown explicitly in CLI output.
- `ok`: enabled, not cooled down, and cached 5h plus weekly usage both have quota left.

For display, `usage_unknown` should not be labeled as confidently available. For routing, `usage_unknown` remains selectable to avoid blocking accounts that have never been refreshed.

## Data Model

Extend the existing state file with a `usage` map keyed by ChatGPT account id:

```json
{
  "accounts": {
    "acct_123": {
      "cooldown_until": 1776760000
    }
  },
  "sessions": {
    "session-key": "acct_123"
  },
  "cursor": 2,
  "usage": {
    "acct_123": {
      "plan_type": "plus",
      "checked_at": 1776753000,
      "error": null,
      "primary": {
        "used_percent": 93.0,
        "left_percent": 7.0,
        "window_minutes": 300,
        "resets_at": 1776756600
      },
      "secondary": {
        "used_percent": 15.0,
        "left_percent": 85.0,
        "window_minutes": 10080,
        "resets_at": 1777338600
      }
    }
  }
}
```

The cache belongs in `StateStore` because it is runtime state, not account credentials.

## Components

`StateStore`

- Add read/write methods for account usage snapshots.
- Preserve existing `accounts`, `sessions`, and `cursor` behavior.
- Keep the file format backward compatible when `usage` is absent.

`AccountAvailability`

- New small value object or service that combines `CodexAccount`, cooldown state, and cached usage into a reasoned availability result.
- This keeps `AccountsCommand` and `Scheduler` from duplicating the same rules.

`AccountsCommand`

- Render cached overview by default.
- Add `refresh` action for all accounts or one account.
- Keep `status` as a detailed live query and write refreshed data back to cache.
- Keep `--json` consistent across list-like output.

`Scheduler`

- Continue session stickiness and cursor-based round robin.
- Skip accounts that are disabled, cooled down, or cached as quota exhausted.
- Do not skip accounts with unknown usage.
- When a hard quota failure occurs, keep writing cooldown as today.

`CodexUsageClient`

- No protocol change. It remains the single live usage reader.

## Error Handling

Refreshing usage should be best-effort per account:

- A refresh failure writes `checked_at` and `error`.
- If a previous successful quota snapshot exists, a refresh failure preserves that snapshot and only updates the error metadata.
- If no previous successful quota snapshot exists, the account displays `usage_error` and is not confidently available in the CLI dashboard.
- A successful refresh clears `error`.
- If one account fails, refresh continues for the remaining accounts.
- CLI exit code is non-zero when any requested account refresh fails.

Token invalidation behavior remains the same as the current `accounts status`: force refresh token once, save the account, and retry once.

## Testing

Add tests for:

- State file backward compatibility when `usage` is absent.
- Writing and reading cached usage snapshots.
- Availability rules for `disabled`, `cooldown`, `usage_exhausted`, `usage_error`, `usage_unknown`, and `ok`.
- `accounts` default output showing cached quota and availability without calling `UsageClient`.
- `accounts refresh` calling `UsageClient`, updating cache, and continuing after one account fails.
- `Scheduler` skipping cached exhausted accounts while still allowing unknown accounts.
- `accounts status` updating cache after live quota fetch.

## Rollout

Implement in one focused change set:

- Add state usage cache.
- Add availability calculation.
- Update `accounts` command output and refresh action.
- Teach scheduler to consult cached exhausted state.
- Update README and Chinese README.

The existing committed `accounts status` command remains the fallback for live detailed checks throughout the rollout.
