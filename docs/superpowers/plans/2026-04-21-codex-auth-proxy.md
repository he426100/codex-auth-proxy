# Codex Auth Proxy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a small PHP/Swoole proxy that lets Codex use multiple manually imported ChatGPT OAuth accounts without changing the `~/.codex` directory layout.

**Architecture:** Keep account validation, scheduling, token refresh, and HTTP forwarding in small classes. The proxy reads only explicit `codex-auth-proxy.account.v1` files, binds a Codex session to one account, and switches only on quota/auth hard failures.

**Tech Stack:** PHP 8.3, Swoole CLI server, no runtime Composer packages, lightweight PHP test runner.

---

### Task 1: Core Account Validation

**Files:**
- Create: `tests/run.php`
- Create: `tests/AccountFileValidatorTest.php`
- Create: `src/Support/Jwt.php`
- Create: `src/Account/CodexAccount.php`
- Create: `src/Account/AccountFileValidator.php`

- [ ] **Step 1: Write failing tests for custom account files**

```php
it('accepts a valid codex-auth-proxy account file', function (): void {
    $validator = new AccountFileValidator();
    $account = $validator->validate(accountFixture('alpha'));
    expect($account->name())->toBe('alpha');
});
```

- [ ] **Step 2: Verify RED**

Run: `php tests/run.php`
Expected: FAIL because `AccountFileValidator` does not exist.

- [ ] **Step 3: Implement JWT parsing and strict account validation**

Create focused classes that reject missing schema/provider/tokens, reject invalid JWT payloads, and require OpenAI ChatGPT auth claims.

- [ ] **Step 4: Verify GREEN**

Run: `php tests/run.php`
Expected: PASS for validator tests.

### Task 2: Repository, Scheduler, and Error Classification

**Files:**
- Create: `tests/AccountRepositoryTest.php`
- Create: `tests/SchedulerTest.php`
- Create: `tests/ErrorClassifierTest.php`
- Create: `src/Account/AccountRepository.php`
- Create: `src/Routing/ErrorClassifier.php`
- Create: `src/Routing/Scheduler.php`
- Create: `src/Routing/StateStore.php`

- [ ] **Step 1: Write failing tests**

Tests must verify that only custom account files are loaded, session affinity is stable, cooldown accounts are skipped, and quota/auth errors are classified.

- [ ] **Step 2: Verify RED**

Run: `php tests/run.php`
Expected: FAIL because repository, scheduler, and classifier do not exist.

- [ ] **Step 3: Implement minimal routing state**

Use JSON state with `accounts.<id>.cooldown_until` and `sessions.<session_key>.account_id`.

- [ ] **Step 4: Verify GREEN**

Run: `php tests/run.php`
Expected: PASS.

### Task 3: CLI and Proxy Server

**Files:**
- Create: `bin/codex-auth-proxy`
- Create: `src/Console/Application.php`
- Create: `src/Auth/TokenRefresher.php`
- Create: `src/Proxy/CodexProxyServer.php`
- Create: `README.md`

- [ ] **Step 1: Write CLI smoke tests where practical**

Keep tests focused on config output and import conversion where no network is needed.

- [ ] **Step 2: Implement commands**

Commands: `import`, `doctor`, `config`, `serve`.

- [ ] **Step 3: Implement Swoole forwarding**

Forward HTTP requests to `https://api.openai.com`, replace `Authorization` with the selected account access token, stream chunks using `write_func`, and switch accounts only for hard errors.

- [ ] **Step 4: Verify**

Run: `php tests/run.php` and `find . -name '*.php' -o -path './bin/codex-auth-proxy'` syntax checks.

---

Self-review notes:
- No direct scanning of `~/.codex/auth.*.json`.
- No automatic browser/password login.
- No change to `~/.codex` structure.
- Session affinity is mandatory and switching happens only on hard quota/auth failures.
