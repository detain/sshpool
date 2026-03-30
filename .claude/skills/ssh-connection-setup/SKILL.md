---
name: ssh-connection-setup
description: Codifies SshPool constructor and connect() pubkey auth patterns from src/SshPool.php. Use when user says 'connect to SSH', 'set up pool', 'configure host/port/keys', or 'instantiate SshPool'. Covers ssh2_connect(), ssh2_auth_pubkey_file(), and exception on failure. Do NOT use for password-based auth or for adding/running commands (see addCommand/run patterns instead).
---
# ssh-connection-setup

## Critical

- **Requires `ext-ssh2`** — verify it is installed (`php -m | grep ssh2`) before writing any code. Without it, `ssh2_connect` is undefined.
- **Never use password-only auth** — this skill covers pubkey auth only. Both `$pubKey` and `$privKey` file paths must be provided and must exist on disk.
- **`connect()` throws on failure** — always wrap instantiation in `try/catch(\Exception $e)` at the call site.
- The `$methods` array passed to `ssh2_connect()` must always include `['hostkey' => 'ssh-rsa']` — do not omit it.

## Instructions

1. **Confirm `ext-ssh2` is available.**
   Run `php -m | grep ssh2`. If missing, add `ext-ssh2` to the environment and document it in `composer.json` under `require`:
   ```json
   "require": { "ext-ssh2": "*" }
   ```
   Verify before proceeding.

2. **Instantiate `SshPool` with all six constructor parameters.**
   Signature from `src/SshPool.php:182`:
   ```php
   use Detain\SshPool\SshPool;

   $pool = new SshPool(
       'example.com',   // $host
       22,              // $port
       'root',          // $user
       'password',      // $pass  (stored but not used for pubkey auth)
       '/path/to/id_rsa.pub',  // $pubKey — absolute path
       '/path/to/id_rsa'       // $privKey — absolute path
   );
   ```
   The constructor immediately calls `connect()` (`src/SshPool.php:190`). No separate call needed.

3. **Wrap instantiation in try/catch.**
   `connect()` throws `\Exception` on both connection failure and auth failure (`src/SshPool.php:203`):
   ```php
   try {
       $pool = new SshPool($host, $port, $user, $pass, $pubKey, $privKey);
   } catch (\Exception $e) {
       // handle: log error, exit, or re-throw
       echo "SSH setup failed: " . $e->getMessage() . "\n";
       exit(1);
   }
   ```

4. **Optionally configure pool settings immediately after construction** (before queueing commands):
   ```php
   $pool->setMaxThreads(10);   // default: 50
   $pool->setMaxRetries(2);    // default: 0
   $pool->setWaitRetry(30);    // default: 15 seconds
   $pool->setMinConfigSize(0); // default: 0 (disabled)
   ```

5. **Disconnect when done.**
   Call `$pool->disconnect()` after `$pool->run()` completes (`src/SshPool.php:210`).

## Examples

**User says:** "Set up an SSH pool to connect to 10.0.0.5 on port 22 as deploy user with my keypair"

**Actions taken:**
1. Confirm `ext-ssh2` present.
2. Instantiate `SshPool` with host/port/user/pass/pubKey/privKey.
3. Wrap in try/catch.
4. Optionally set thread/retry limits.

**Result:**
```php
use Detain\SshPool\SshPool;

try {
    $pool = new SshPool(
        '10.0.0.5',
        22,
        'deploy',
        '',
        '/home/deploy/.ssh/id_rsa.pub',
        '/home/deploy/.ssh/id_rsa'
    );
} catch (\Exception $e) {
    echo "SSH setup failed: " . $e->getMessage() . "\n";
    exit(1);
}

$pool->setMaxThreads(10);
// ready to addCommand() and run()
$pool->disconnect();
```

## Common Issues

- **`Call to undefined function ssh2_connect()`** — `ext-ssh2` is not loaded. Run `php -m | grep ssh2`. Install via `apt-get install php-ssh2` (Ubuntu) or `pecl install ssh2`.
- **`SSH connection or authentication failed` exception** — three possible causes:
  1. Wrong host/port: verify with `ssh -p $port $user@$host` from the shell.
  2. Key file paths are wrong or unreadable: run `ls -la /path/to/id_rsa` and confirm permissions are `600`.
  3. Server does not accept `ssh-rsa` hostkey method: check server's `sshd_config` for `HostKey` directives.
- **`$pass` is stored but ignored** for pubkey auth — passing an empty string `''` is fine. Do not expect password fallback; this class does not implement it.
- **Default port property is `21`** (`src/SshPool.php:145`) — always explicitly pass `22` (or the correct port) to the constructor; do not rely on the default.