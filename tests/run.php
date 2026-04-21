<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'CodexAuthProxy\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

final class Expectation
{
    public function __construct(private mixed $value)
    {
    }

    public function toBe(mixed $expected): void
    {
        if ($this->value !== $expected) {
            throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($this->value, true));
        }
    }

    public function toContain(string $needle): void
    {
        if (!is_string($this->value) || !str_contains($this->value, $needle)) {
            throw new RuntimeException('Expected string to contain ' . $needle . ', got ' . var_export($this->value, true));
        }
    }

    public function toBeGreaterThan(int $expected): void
    {
        if (!is_int($this->value) || $this->value <= $expected) {
            throw new RuntimeException('Expected integer greater than ' . $expected . ', got ' . var_export($this->value, true));
        }
    }
}

function expect(mixed $value): Expectation
{
    return new Expectation($value);
}

function it(string $name, callable $test): void
{
    TestRegistry::$tests[] = [$name, $test];
}

final class TestRegistry
{
    /** @var list<array{0:string,1:callable}> */
    public static array $tests = [];
}

function makeJwt(array $payload): string
{
    $encode = static fn (array $data): string => rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

    return $encode(['alg' => 'none', 'typ' => 'JWT']) . '.' . $encode($payload) . '.signature';
}

function accountFixture(string $name = 'alpha', array $overrides = []): array
{
    $payload = [
        'iss' => 'https://auth.openai.com',
        'email' => $name . '@example.com',
        'exp' => time() + 3600,
        'https://api.openai.com/auth' => [
            'chatgpt_account_id' => 'acct-' . $name,
            'chatgpt_plan_type' => 'plus',
            'chatgpt_user_id' => 'user-' . $name,
        ],
    ];

    $base = [
        'schema' => 'codex-auth-proxy.account.v1',
        'provider' => 'openai-chatgpt-codex',
        'name' => $name,
        'enabled' => true,
        'tokens' => [
            'id_token' => makeJwt($payload),
            'access_token' => makeJwt($payload),
            'refresh_token' => 'rt_' . $name,
            'account_id' => 'acct-' . $name,
        ],
        'metadata' => [
            'email' => $name . '@example.com',
            'plan_type' => 'plus',
        ],
    ];

    return array_replace_recursive($base, $overrides);
}

function tempDir(string $prefix): string
{
    $dir = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(4));
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Failed to create temp dir');
    }

    return $dir;
}

function writeJson(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($path, $json) === false) {
        throw new RuntimeException('Failed to write JSON fixture');
    }
}

foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    require $file;
}

$failed = 0;
foreach (TestRegistry::$tests as [$name, $test]) {
    try {
        $test();
        fwrite(STDOUT, "[PASS] {$name}\n");
    } catch (Throwable $throwable) {
        $failed++;
        fwrite(STDERR, "[FAIL] {$name}: {$throwable->getMessage()}\n");
    }
}

if ($failed > 0) {
    fwrite(STDERR, "{$failed} test(s) failed\n");
    exit(1);
}

fwrite(STDOUT, count(TestRegistry::$tests) . " test(s) passed\n");
