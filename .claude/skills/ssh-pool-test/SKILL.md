---
name: ssh-pool-test
description: Codifies the Mockery partial mock pattern from tests/SshPoolTest.php. Use when user says 'write a test', 'add PHPUnit test', or 'mock SshPool'. Covers makePartial(), shouldAllowMockingProtectedMethods(), shouldReceive(), and tearDown Mockery::close(). Do NOT use for integration tests hitting real SSH.
---
# ssh-pool-test

## Critical

- **Never** instantiate a real `SshPool` in unit tests — the constructor calls `connect()` which calls `ssh2_connect()` and will fail without a live host. Always use Mockery partial mocks.
- **Always** call `Mockery::close()` in `tearDown()` — omitting it leaks mock expectations and causes false passes in later tests.
- Tests live in `tests/SshPoolTest.php`. Class must extend `PHPUnit\Framework\TestCase`.
- No `namespace` declaration in the test file — `SshPoolTest` is in the global namespace (matches existing file).

## Instructions

1. **Add use statements at top of test file** (no namespace):
   ```php
   use PHPUnit\Framework\TestCase;
   use Detain\SshPool\SshPool;
   ```
   Verify `vendor/autoload.php` is loaded via `./vendor/bin/phpunit` bootstrap before proceeding.

2. **Declare class-level mock property** and build the partial mock in `setUp()`:
   ```php
   private $sshPool;

   protected function setUp(): void
   {
       $this->sshPool = Mockery::mock(SshPool::class)
           ->makePartial()
           ->shouldAllowMockingProtectedMethods();
   }
   ```
   `makePartial()` lets unmocked methods run real code. `shouldAllowMockingProtectedMethods()` is required to stub `connect()`, `setupStreams()`, `processRunningCommands()`, etc.

3. **Always add tearDown**:
   ```php
   public function tearDown(): void
   {
       Mockery::close();
   }
   ```

4. **Stub `connect()` to prevent real SSH** in any test that would trigger the constructor:
   ```php
   $this->sshPool->shouldReceive('connect')->once()->andReturn(true);
   // then construct a real instance if needed:
   $sshPool = new SshPool('localhost', 22, 'user', 'pass', 'pubkey', 'privkey');
   ```
   For exception tests, use `->andThrow(new Exception("SSH connection or authentication failed"))`.

5. **Stub `runCommand()` using the exact return shape** the real method produces:
   ```php
   $this->sshPool->shouldReceive('runCommand')
       ->with('echo "Hello"')
       ->once()
       ->andReturn([
           'cmd'        => 'echo "Hello"',
           'exitStatus' => 0,
           'out'        => 'Hello',
           'err'        => '',
       ]);
   ```
   Keys are always `cmd`, `exitStatus`, `out`, `err` — match `src/SshPool.php:345`.

6. **Use setter methods directly on the partial mock** — they are real methods and need no stubbing:
   ```php
   $this->sshPool->setMaxThreads(2);
   $this->sshPool->setMaxRetries(3);
   $this->sshPool->setMinConfigSize(10);
   ```

7. **Use `addCommand()` directly** on the partial mock to populate `$cmdQueue` for `run()` tests:
   ```php
   $id = $this->sshPool->addCommand('ls', null, 'my_data', $callback, 0);
   ```
   Signature: `addCommand(string $cmd, ?string $id, $data, ?callable $callback, int $timeout): string`

8. **Verify** tests pass: `./vendor/bin/phpunit` — all green before committing.

## Examples

**User says:** "Write a test that verifies the callback receives the correct stdout when a command succeeds."

**Actions taken:**
```php
public function testCallbackReceivesStdout(): void
{
    $callbackInvoked = false;
    $callback = function ($cmd, $id, $data, $exitStatus, $stdout, $stderr) use (&$callbackInvoked) {
        $callbackInvoked = true;
        $this->assertEquals('uptime', $cmd);
        $this->assertEquals(0, $exitStatus);
        $this->assertStringContainsString('load average', $stdout);
    };

    $this->sshPool->addCommand('uptime', null, null, $callback);
    $this->sshPool->shouldReceive('run')->once()->andReturnUsing(function () use ($callback) {
        $callback('uptime', 'someid', null, 0, ' 10:00  load average: 0.5', '');
        return true;
    });

    $this->sshPool->run();
    $this->assertTrue($callbackInvoked);
}
```

**Result:** Test runs without SSH, callback assertions verified, `Mockery::close()` cleans up in `tearDown()`.

## Common Issues

- **`BadMethodCallException: connect() does not exist on this mock`** — you called `new SshPool(...)` without stubbing `connect()` first. Add `$this->sshPool->shouldReceive('connect')->andReturn(true)` before constructing.
- **`Mockery\Exception\InvalidCountException: Method ... called 0 times`** — you used `->once()` but the method was never called. Either remove the cardinality constraint (`->once()`) or ensure the code path triggers the method.
- **`ext-ssh2` not found on CI** — add `ext-ssh2` to `require` in `composer.json` and ensure the extension is installed: `php -m | grep ssh2`. PHPUnit will error before tests run if the extension is missing.
- **`Class 'Mockery' not found`** — run `composer install`; `mockery/mockery` must be in `require-dev`.
- **Test output contains `Error starting command`** — `adjustThreads()` was triggered by a real `ssh2_exec()` call. Stub `setupStreams` or `runCommand` to prevent real SSH execution.