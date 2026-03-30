---
name: add-ssh-command
description: Codifies the addCommand() + run() lifecycle in src/SshPool.php. Use when user says 'queue a command', 'add SSH command', 'run commands concurrently', or 'use callback'. Covers addCommand() signature, callback shape, ID handling, timeout, and optional pool configuration. Do NOT use for modifying pool internals (setupStreams, processRunningCommands, handleCommandCompletion, retryQueuedCommands).
---
# add-ssh-command

## Critical

- `ext-ssh2` must be installed — `php -m | grep ssh2` before any code runs
- Constructor always calls `connect()` which throws `\Exception` on failure — wrap instantiation in try/catch
- Never call `addCommand()` after `run()` returns `true` — the pool is exhausted; create a new `SshPool` instance
- Callback receives output **after** `rtrim(str_replace("\r", '', ...))` normalization — do not double-strip
- `$id` must be unique per pool instance; duplicate IDs silently overwrite the previous command's queue entry and callback

## Instructions

1. **Instantiate the pool** with all six required parameters:
   ```php
   use Detain\SshPool\SshPool;

   try {
       $pool = new SshPool($host, $port, $user, $pass, $pubKey, $privKey);
   } catch (\Exception $e) {
       // SSH connection or authentication failed
       throw $e;
   }
   ```
   Verify: `$pool instanceof SshPool` before proceeding.

2. **Configure the pool** (optional, before adding commands):
   ```php
   $pool->setMaxThreads(10);    // default 50
   $pool->setMaxRetries(2);     // default 0 (no retries)
   $pool->setWaitRetry(15);     // seconds between retries, default 15
   $pool->setMinConfigSize(0);  // min stdout bytes to count as success, default 0
   ```
   Verify: only call setters before the first `addCommand()`.

3. **Queue commands** with `addCommand()`:
   ```php
   // Signature: addCommand(string $cmd, ?string $id, mixed $data, ?callable $callback, int $timeout): string

   // Minimal — auto-generated uniqid() ID, no callback, no timeout
   $id = $pool->addCommand('ls -l');

   // With stable ID and callback data
   $id = $pool->addCommand(
       'df -h',
       'disk-check-01',          // stable ID; pass null to auto-generate
       ['server' => 'web01'],    // $data passed verbatim to callback
       function (string $cmd, string $id, $data, int $exitStatus, string $stdout, string $stderr) {
           // $stdout/$stderr are already rtrim'd with \r stripped
           echo "[$id] exit=$exitStatus\n";
       },
       30                        // timeout seconds; 0 = no timeout
   );
   ```
   This step uses the pool from Step 1. Verify: `addCommand()` returns the string ID used as key in `$pool->cmdQueue`.

4. **Callback signature** — must match exactly:
   ```php
   function (string $cmd, string $id, $data, int $exitStatus, string $stdout, string $stderr): void {
       if ($exitStatus !== 0) {
           // handle failure — $stderr contains error output
       }
       // process $stdout
   }
   ```
   `$data` is whatever was passed as the third argument to `addCommand()`. Called by `invokeCallback()` in `src/SshPool.php:479`.

5. **Execute the pool**:
   ```php
   // Blocking — runs until all commands complete (or exhaust retries)
   $pool->run();

   // Non-blocking single pass — returns false if work remains
   $done = $pool->run(true);
   ```
   Verify: `run()` (without `true`) always returns `true` when the loop exits.

## Examples

**User says:** "Queue three hostname checks concurrently and print results in a callback"

**Actions taken:**
```php
use Detain\SshPool\SshPool;

try {
    $pool = new SshPool('bastion.example.com', 22, 'deploy', '', '/home/deploy/.ssh/id_rsa.pub', '/home/deploy/.ssh/id_rsa');
} catch (\Exception $e) {
    die('Connection failed: ' . $e->getMessage());
}

$pool->setMaxThreads(3);

$servers = ['web01', 'web02', 'web03'];
foreach ($servers as $server) {
    $pool->addCommand(
        "ssh {$server} hostname",
        $server,                  // stable ID = server name
        ['server' => $server],
        function (string $cmd, string $id, $data, int $exitStatus, string $stdout, string $stderr) {
            if ($exitStatus === 0) {
                echo "{$data['server']}: {$stdout}\n";
            } else {
                echo "{$data['server']} FAILED: {$stderr}\n";
            }
        },
        10                        // 10-second timeout per command
    );
}

$pool->run();
```

**Result:** All three commands run concurrently (up to `$maxThreads`); each callback fires when its command finishes with normalized stdout/stderr.

## Common Issues

- **`Call to undefined function ssh2_connect()`**: `ext-ssh2` is not installed. Run `pecl install ssh2` and add `extension=ssh2.so` to `php.ini`. Confirm with `php -m | grep ssh2`.

- **`SSH connection or authentication failed` on construction**: Verify key paths exist (`file_exists($pubKey)` and `file_exists($privKey)`), that the key pair matches the remote `authorized_keys`, and that port is reachable (`nc -zv $host $port`).

- **Callback never fires**: Check that `$maxRetries > 0` and `$exitStatus !== 0` — the command may be stuck in `$pool->queueAfter` retrying. Set `$pool->setMaxRetries(0)` to disable retries and confirm callback fires on first failure.

- **Duplicate ID silently drops a command**: If you pass the same `$id` string to multiple `addCommand()` calls, the later call overwrites `$cmdQueue[$id]`. Use `null` to auto-generate IDs or ensure IDs are unique per batch.

- **Stdout is empty despite command succeeding**: Output normalization strips `\r` and trailing whitespace (`rtrim(str_replace("\r", '', ...))`). If `$minConfigSize > 0` and the stripped output is shorter than that threshold, the command is treated as failed and retried.