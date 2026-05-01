# sshpool

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)

`detain/sshpool` is a small PHP library for running many shell commands
on a single SSH host concurrently. It maintains one SSH session, opens
multiple `ssh2_exec` channels in parallel up to a configurable cap,
collects each command's stdout / stderr / exit status, and fires a
per-command callback on completion. Commands can be queued while the
pool is already running, automatic retries with a back-off delay are
supported, and per-command timeouts kill long-running work.

## Requirements

- PHP **8.0+**
- The [`ext-ssh2`](https://www.php.net/manual/en/book.ssh2.php) PHP extension
- An SSH host you can authenticate to with a key pair

> Password authentication is not currently wired through; the supported
> path is public/private key authentication via `ssh2_auth_pubkey_file`.

## Installation

```bash
composer require detain/sshpool
```

If you do not already have `ext-ssh2` installed:

```bash
# Debian / Ubuntu
sudo apt-get install php-ssh2

# RHEL / Alma / Rocky
sudo dnf install php-pecl-ssh2
```

## Quick Start

```php
require 'vendor/autoload.php';

use Detain\SshPool\SshPool;

$pool = new SshPool(
    'example.com',          // host
    22,                     // port
    'deploy',               // ssh user
    '',                     // password (unused — pubkey auth)
    '/home/me/.ssh/id_rsa.pub',
    '/home/me/.ssh/id_rsa'
);

$pool->setMaxThreads(10);
$pool->setMaxRetries(2);

foreach (['uptime', 'df -h', 'free -m'] as $cmd) {
    $pool->addCommand(
        $cmd,
        null,                               // auto id
        ['command' => $cmd],                // user data passed to callback
        function ($cmd, $id, $data, $exit, $stdout, $stderr) {
            echo "[{$id}] {$cmd} (exit {$exit}):\n{$stdout}\n";
            if ($stderr !== '') {
                fwrite(STDERR, "[{$id}] stderr: {$stderr}\n");
            }
        },
        30                                  // 30s timeout
    );
}

$pool->run();
```

## Usage Patterns

### One-shot synchronous command

For a single command where you want a synchronous result rather than a
callback, use `runCommand()`:

```php
$result = $pool->runCommand('hostname');
// $result === ['cmd' => 'hostname', 'exitStatus' => 0, 'out' => '...', 'err' => '']
```

`runCommand()` returns `false` if the channel cannot be opened.

### Adding commands while the pool is running

`addCommand()` is safe to call from inside a completion callback. New
commands join `$cmdQueue` and the run loop picks them up on its next
iteration.

```php
$pool->addCommand('first', null, null, function ($cmd, $id, $data, $exit) use ($pool) {
    if ($exit === 0) {
        // Chain a follow-up command.
        $pool->addCommand('second');
    }
});
$pool->run();
```

### Non-blocking single iteration

`run(true)` performs one polling iteration (start any newly-available
commands, drain finished output, promote any due retries) and returns.
This is useful when you want to integrate the pool into your own event
loop.

```php
while (!$done) {
    $done = $pool->run(true);
    // ... do other work ...
}
```

### Custom SSH connection methods

`ssh2_connect()` accepts an algorithm/method override array. Pass it
through the constructor's seventh argument when, for example, you need
to whitelist a deprecated host key algorithm against a legacy server:

```php
$pool = new SshPool(
    $host, 22, $user, '', $pub, $priv,
    ['hostkey' => 'ssh-rsa']
);
```

## Configuration

| Setter                       | Default | Effect                                                                                  |
| ---------------------------- | ------- | --------------------------------------------------------------------------------------- |
| `setMaxThreads(int)`         | `50`    | Maximum number of channels open concurrently. Clamped to `>= 1`.                        |
| `setMaxRetries(int)`         | `0`     | Retry attempts on a non-zero exit status (or undersized output). `0` disables retry.    |
| `setWaitRetry(int)`          | `15`    | Seconds to wait before promoting a retry back into the run queue.                       |
| `setMinConfigSize(int)`      | `0`     | Minimum stdout byte count required to consider a command successful. `0` disables.      |
| `$pollInterval` (public int) | `25000` | Microseconds between run-loop polls in blocking mode.                                   |

## Public API

```php
new SshPool(
    string $host,
    int $port,
    string $user,
    string $pass,
    string $pubKey,
    string $privKey,
    array $methods = []
);

string addCommand(
    string $cmd,
    ?string $id = null,
    mixed $data = null,
    ?callable $callback = null,
    int $timeout = 0
);

bool run(bool $once = false);
array|false runCommand(string $cmd);

void connect();
void disconnect();
resource|null getConnection();
```

### Callback signature

```php
function (
    string $cmd,
    string $id,
    mixed  $data,        // user data passed to addCommand()
    int    $exitStatus,  // -1 if unknown / timed out
    string $stdout,
    string $stderr
): void;
```

## Retry Semantics

A command is considered failed (and eligible for retry) when either:

- its exit status is non-zero, or
- `minConfigSize > 0` **and** `strlen(stdout) < minConfigSize`.

Failed commands are deferred for `waitRetry` seconds, then promoted
back into the run queue. The original timeout is preserved across
retries. After `maxRetries` attempts the completion callback is
invoked with the most recent exit status.

## Timeouts

When `addCommand($cmd, ..., $timeout)` is given a non-zero `$timeout`,
the run loop closes the command's streams once
`time() - start_time > $timeout`. The callback is invoked just like
any other completion. If a real exit status was not yet observed, the
command is reported with `exitStatus = -1`.

The `metadata` property captures the actual duration and a boolean
`timed_out` flag per id:

```php
$pool->metadata['my-id'] === [
    'exit_status' => -1,
    'duration'    => 30,
    'timed_out'   => true,
];
```

## Development

```bash
composer install
./vendor/bin/phpunit              # run the test suite
./vendor/bin/phpunit --filter Foo # run one test
./vendor/bin/phpunit --coverage-text
```

The tests use a `FakeSshPool` fixture
(`tests/Fixtures/FakeSshPool.php`) that bypasses `ext-ssh2` so the
queue / callback / retry logic is verified without needing a live SSH
server.

## License

GPL-3.0. See [LICENSE](LICENSE).

## Author

Joe Huss · <detain@interserver.net>
