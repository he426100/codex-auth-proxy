# Session Anchor Priority Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 让本项目对原生 `conversation_id/session_id` 锚点与 `prompt_cache_key` fallback 的处理保持一致，避免本地会话绑定与上游 payload 语义冲突。

**Architecture:** 只修改 `src/Proxy/SessionKeyExtractor.php` 和 `src/Proxy/ResponsesPayloadNormalizer.php` 两个会话语义入口。前者负责本地 session key 优先级，后者负责请求体里的冲突字段清理，避免把逻辑散到 `CodexProxyServer` 主流程。

**Tech Stack:** PHP 8.3, PHPUnit 11

---

### Task 1: 锁定会话锚点优先级

**Files:**
- Modify: `tests/Proxy/SessionKeyExtractorTest.php`
- Modify: `src/Proxy/SessionKeyExtractor.php`

- [ ] **Step 1: 先写失败测试，描述 general routing 也应优先稳定锚点**

在 `tests/Proxy/SessionKeyExtractorTest.php` 增加两个测试：

```php
public function testExtractPrefersStableSessionHeaderOverTurnState(): void
{
    $extractor = new SessionKeyExtractor();

    $key = $extractor->extract([
        'session_id' => 'native-session-1',
        'x-codex-turn-state' => 'turn-1',
    ], '{}');

    self::assertSame('session_id:native-session-1', $key->primary);
}

public function testExtractPrefersConversationIdInBodyOverTurnStateHeader(): void
{
    $extractor = new SessionKeyExtractor();

    $key = $extractor->extract([
        'x-codex-turn-state' => 'turn-1',
    ], '{"conversation_id":"conv-1","prompt_cache_key":"cache-1","input":[]}');

    self::assertSame('conversation_id:conv-1', $key->primary);
}
```

- [ ] **Step 2: 运行单测确认失败**

Run: `composer test -- tests/Proxy/SessionKeyExtractorTest.php`
Expected: 新增测试失败，说明当前 general routing 仍优先 turn-state。

- [ ] **Step 3: 只改最小优先级逻辑**

在 `src/Proxy/SessionKeyExtractor.php` 中把 `extract()` 调整为：

```php
return $this->extractWithPriority(
    $headers,
    $body,
    ['session_id', 'conversation_id', 'x-session-id', 'x-codex-session-id', 'x-codex-thread-id', 'openai-conversation-id'],
    ['conversation_id', 'thread_id', 'session_id', 'previous_response_id'],
    ['x-codex-turn-state', 'idempotency-key'],
    ['prompt_cache_key'],
);
```

并让 `extractWithPriority()` 支持“稳定 header / 稳定 body / fallback header / fallback body”四段优先级，而不是一组 headerKeys + 一组 bodyKeys。

- [ ] **Step 4: 运行单测确认通过**

Run: `composer test -- tests/Proxy/SessionKeyExtractorTest.php`
Expected: PASS

### Task 2: 清理与原生锚点冲突的 prompt_cache_key

**Files:**
- Modify: `tests/Proxy/ResponsesPayloadNormalizerTest.php`
- Modify: `src/Proxy/ResponsesPayloadNormalizer.php`

- [ ] **Step 1: 先写失败测试，描述 payload 清理行为**

在 `tests/Proxy/ResponsesPayloadNormalizerTest.php` 增加测试：

```php
public function testRemovesPromptCacheKeyWhenNativeAnchorExists(): void
{
    $payload = '{"conversation_id":"conv-1","prompt_cache_key":"cache-1","input":[]}';

    $result = (new ResponsesPayloadNormalizer())->normalizeHttpWithReport($payload);
    $decoded = json_decode($result->payload(), false, flags: JSON_THROW_ON_ERROR);

    self::assertSame('conv-1', $decoded->conversation_id);
    self::assertObjectNotHasProperty('prompt_cache_key', $decoded);
    self::assertContains('anchor.prompt_cache_key_removed', $result->mutations());
}
```

- [ ] **Step 2: 运行单测确认失败**

Run: `composer test -- tests/Proxy/ResponsesPayloadNormalizerTest.php`
Expected: 新增测试失败，说明当前 normalizer 还未清理冲突的 `prompt_cache_key`。

- [ ] **Step 3: 在 normalizer 里增加最小冲突清理**

在 `src/Proxy/ResponsesPayloadNormalizer.php` 中新增一个根对象级别的 helper，例如：

```php
private function normalizeSessionAnchors(mixed $decoded, array &$mutations): void
{
    if (!$decoded instanceof stdClass) {
        return;
    }

    $hasNativeAnchor = $this->hasNonEmptyStringProperty($decoded, 'conversation_id')
        || $this->hasNonEmptyStringProperty($decoded, 'thread_id')
        || $this->hasNonEmptyStringProperty($decoded, 'session_id')
        || $this->hasNonEmptyStringProperty($decoded, 'previous_response_id');

    if ($hasNativeAnchor && property_exists($decoded, 'prompt_cache_key')) {
        unset($decoded->prompt_cache_key);
        $mutations[] = 'anchor.prompt_cache_key_removed';
    }
}
```

并在 `normalize()` 的 HTTP / WebSocket 通用路径里调用它。

- [ ] **Step 4: 运行单测确认通过**

Run: `composer test -- tests/Proxy/ResponsesPayloadNormalizerTest.php`
Expected: PASS

### Task 3: 回归验证

**Files:**
- Test: `tests/Proxy/SessionKeyExtractorTest.php`
- Test: `tests/Proxy/ResponsesPayloadNormalizerTest.php`
- Test: `tests/Proxy/CodexProxyServerIntegrationTest.php`

- [ ] **Step 1: 运行针对性回归**

Run: `composer test -- tests/Proxy/SessionKeyExtractorTest.php tests/Proxy/ResponsesPayloadNormalizerTest.php tests/Proxy/CodexProxyServerIntegrationTest.php`
Expected: PASS

- [ ] **Step 2: 运行全量验证**

Run: `composer test && composer analyse`
Expected: 全部通过
