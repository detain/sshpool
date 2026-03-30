# sshpool

PHP SSH command queue and connection pool — runs commands on an SSH host concurrently with completion callbacks.

## Commands

```bash
composer install                  # install deps (requires ext-ssh2)
./vendor/bin/phpunit               # run all tests
./vendor/bin/phpunit --filter Foo  # run specific test
./vendor/bin/phpunit --coverage-text  # run tests with coverage report
```

## Architecture

- **Namespace:** `Detain\SshPool\` → `src/`
- **Entry:** `src/SshPool.php` — single class, all pool logic
- **Tests:** `tests/SshPoolTest.php` — PHPUnit + Mockery
- **Autoload:** PSR-4 via `composer.json`

**SshPool flow:**
- Construct with `($host, $port, $user, $pass, $pubKey, $privKey)` → calls `connect()` via `ssh2_connect()` + `ssh2_auth_pubkey_file()`
- Queue commands: `addCommand(string $cmd, ?string $id, $data, ?callable $callback, int $timeout): string`
- Execute: `run()` loops — fills slots up to `$maxThreads`, calls `processRunningCommands()`, `retryQueuedCommands()`
- Single synchronous exec: `runCommand(string $cmd): array` → returns `['cmd', 'exitStatus', 'out', 'err']`

**Key properties:**
- `$maxThreads = 50` · `$maxRetries = 0` · `$waitRetry = 15` · `$minConfigSize = 0`
- `$cmdQueue[]` · `$running[]` · `$queueAfter[]` · `$stdout[]` · `$stderr[]` · `$callbacks[]` · `$callbackData[]`

**Callback signature:** `function(string $cmd, string $id, $data, int $exitStatus, string $stdout, string $stderr)`

**Retry logic** (`handleCommandCompletion`): retries when `$exitStatus !== 0` OR `strlen($stdout) < $minConfigSize` and `$retries < $maxRetries`; defers via `$queueAfter` with `time() + $waitRetry`

**Stream pattern** (`setupStreams` / `processRunningCommands`):
```php
$streamOut = ssh2_exec($this->conn, $cmd, 'vt100');
$streamErr = ssh2_fetch_stream($streamOut, SSH2_STREAM_STDERR);
stream_set_blocking($streamOut, false);
stream_set_blocking($streamErr, false);
// on EOF: stream_set_blocking($streamOut, true); stream_get_meta_data() for exit_status
```

**Example usage:**
```php
$pool = new SshPool($host, 22, $user, $pass, $pubKey, $privKey);
$pool->addCommand('uptime', 'cmd1', null, function($cmd, $id, $data, $exitStatus, $stdout, $stderr) {
    echo "Exit: $exitStatus, Output: $stdout\n";
});
$pool->run();
```

## Conventions

- `$id` defaults to `uniqid()` in `addCommand()` — callers can supply stable IDs
- `run(true)` = single-pass (non-blocking poll); `run()` = full blocking loop
- `adjustThreads()` clamps `$maxThreads` to `max(1, count($this->running))` on stream failure
- Output normalization: `rtrim(str_replace("\r", '', $stdout))`
- Tests use `Mockery::mock(SshPool::class)->makePartial()->shouldAllowMockingProtectedMethods()`; always call `Mockery::close()` in `tearDown()`
- Require `ext-ssh2` — document in any environment setup

<!-- caliber:managed:pre-commit -->
## Before Committing

**IMPORTANT:** Before every git commit, you MUST ensure Caliber syncs agent configs with the latest code changes.

First, check if the pre-commit hook is already installed:
```bash
grep -q "caliber" .git/hooks/pre-commit 2>/dev/null && echo "hook-active" || echo "no-hook"
```

- If **hook-active**: the hook handles sync automatically — just commit normally. Tell the user: "Caliber will sync your agent configs automatically via the pre-commit hook."
- If **no-hook**: run Caliber manually before committing:
  1. Tell the user: "Caliber: Syncing agent configs with your latest changes..."
  2. Run: `caliber refresh && git add CLAUDE.md .claude/ .cursor/ .cursorrules .github/copilot-instructions.md .github/instructions/ AGENTS.md CALIBER_LEARNINGS.md 2>/dev/null`
  3. After it completes, briefly tell the user what Caliber updated. Then proceed with the commit.

If `caliber` is not found, tell the user: "This project uses Caliber for agent config sync. Run /setup-caliber to get set up."
<!-- /caliber:managed:pre-commit -->

<!-- caliber:managed:learnings -->
## Session Learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns learned from previous sessions.
These are auto-extracted from real tool usage — treat them as project-specific rules.
<!-- /caliber:managed:learnings -->
