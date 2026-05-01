<?php
/**
 * SSH Command Queue / Connection Pool Handler
 *
 * Provides a thread-pooled SSH command execution handler that runs many
 * commands concurrently across a single SSH session, with configurable
 * limits on concurrency, retries, per-command timeouts, and completion
 * callbacks.
 *
 * Features:
 * - Executes multiple SSH commands concurrently over one connection.
 * - Configurable maximum number of concurrent commands.
 * - Add commands to the queue while it is processing.
 * - Returns stdout, stderr, exit status, and timing per command.
 * - Configurable automatic retry of failed commands with delay.
 * - Configurable per-command timeouts.
 * - Per-command completion callbacks with access to output data.
 * - Defers retry attempts until a specified time has passed.
 * - Public/private key authentication.
 * - Optional minimum stdout size to validate command success.
 *
 * Usage:
 * ```php
 * $pool = new SshPool('example.com', 22, 'user', '', '/path/to/pubkey', '/path/to/privkey');
 * $pool->setMaxThreads(10);
 * $pool->setMaxRetries(2);
 * $id = $pool->addCommand('ls -l', null, null, function ($cmd, $id, $data, $exitStatus, $stdout, $stderr) {
 *     echo "Command {$id} completed with exit status {$exitStatus}\n";
 * });
 * $pool->run();
 * ```
 *
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2025
 * @package   Detain\SshPool
 * @category  SSH
 * @license   GPL-3.0
 */

namespace Detain\SshPool;

class SshPool
{
    /**
     * Maximum number of concurrent commands to run.
     *
     * @var int
     */
    public int $maxThreads = 50;

    /**
     * Maximum number of retry attempts for failed commands.
     *
     * @var int
     */
    public int $maxRetries = 0;

    /**
     * Delay in seconds between retry attempts.
     *
     * @var int
     */
    public int $waitRetry = 15;

    /**
     * Minimum stdout size required to consider a command successful.
     * Set to 0 to disable the check.
     *
     * @var int
     */
    public int $minConfigSize = 0;

    /**
     * Sleep interval (microseconds) between polling iterations of the run
     * loop. Defaults to 25,000 (1/40th of a second).
     *
     * @var int
     */
    public int $pollInterval = 25000;

    /**
     * Queue of commands awaiting execution.
     *
     * @var array<string, array{cmd: string, retries: int, timeout: int}>
     */
    public array $cmdQueue = [];

    /**
     * Currently running commands, keyed by command id.
     *
     * @var array<string, array{cmd: string, retries: int, stdout: resource, stderr: resource, start_time: int, timeout: int}>
     */
    public array $running = [];

    /**
     * User data associated with each command, keyed by command id. Passed
     * to the completion callback as the third argument.
     *
     * @var array<string, mixed>
     */
    public array $callbackData = [];

    /**
     * Completion callbacks keyed by command id.
     *
     * @var array<string, callable>
     */
    public array $callbacks = [];

    /**
     * Commands queued for retry after a delay.
     *
     * @var array<string, array{retry: int, cmd: string, retries: int, timeout: int}>
     */
    public array $queueAfter = [];

    /**
     * Accumulated stdout per command id.
     *
     * @var array<string, string>
     */
    public array $stdout = [];

    /**
     * Accumulated stderr per command id.
     *
     * @var array<string, string>
     */
    public array $stderr = [];

    /**
     * Per-command metadata (e.g. exit status), keyed by command id.
     *
     * @var array<string, mixed>
     */
    public array $metadata = [];

    /**
     * Unix timestamp when the most recent blocking run() began.
     *
     * @var int|null
     */
    public ?int $startTime = null;

    /**
     * Optional SSH method/algorithm overrides passed to ssh2_connect().
     * See https://www.php.net/manual/en/function.ssh2-connect.php
     *
     * @var array<string, mixed>
     */
    private array $methods = [];

    /**
     * SSH connection resource.
     *
     * @var resource|null
     */
    private $conn = null;

    /**
     * SSH host to connect to.
     *
     * @var string
     */
    private string $host = 'localhost';

    /**
     * SSH port to connect to.
     *
     * @var int
     */
    private int $port = 22;

    /**
     * SSH username for authentication.
     *
     * @var string
     */
    private string $user = 'root';

    /**
     * SSH password (currently unused; pubkey auth is the supported path).
     *
     * @var string
     */
    private string $pass = '';

    /**
     * Path to the public key file used for authentication.
     *
     * @var string
     */
    private string $pubKey = '';

    /**
     * Path to the private key file used for authentication.
     *
     * @var string
     */
    private string $privKey = '';

    /**
     * Constructor. Stores connection parameters and opens the SSH session.
     *
     * @param string                $host    SSH host address.
     * @param int                   $port    SSH port number (default 22).
     * @param string                $user    SSH username.
     * @param string                $pass    SSH password (reserved; pubkey auth is used).
     * @param string                $pubKey  Path to the public key file.
     * @param string                $privKey Path to the private key file.
     * @param array<string, mixed>  $methods Optional ssh2_connect() methods array (key exchange, hostkey, etc).
     *
     * @throws \Exception When the SSH connection or authentication fails.
     */
    public function __construct(string $host, int $port, string $user, string $pass, string $pubKey, string $privKey, array $methods = [])
    {
        $this->host    = $host;
        $this->port    = $port;
        $this->user    = $user;
        $this->pass    = $pass;
        $this->pubKey  = $pubKey;
        $this->privKey = $privKey;
        $this->methods = $methods;
        $this->connect();
    }

    /**
     * Destructor — closes the SSH session if still open.
     */
    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Establishes the SSH connection and authenticates with the configured
     * public/private key pair.
     *
     * @return void
     *
     * @throws \Exception When the SSH connection or authentication fails.
     */
    public function connect(): void
    {
        $this->conn = empty($this->methods)
            ? ssh2_connect($this->host, $this->port)
            : ssh2_connect($this->host, $this->port, $this->methods);
        if (!$this->conn) {
            throw new \Exception("SSH connection failed to {$this->host}:{$this->port}");
        }
        if (!ssh2_auth_pubkey_file($this->conn, $this->user, $this->pubKey, $this->privKey)) {
            $this->conn = null;
            throw new \Exception("SSH connection or authentication failed");
        }
    }

    /**
     * Closes the SSH session if one is open. Safe to call multiple times.
     *
     * The ext-ssh2 extension does not expose a real disconnect function, so
     * we issue an `exit` over the channel and drop the resource so PHP can
     * garbage-collect it.
     *
     * @return void
     */
    public function disconnect(): void
    {
        if ($this->conn) {
            @ssh2_exec($this->conn, 'exit');
            $this->conn = null;
        }
    }

    /**
     * Sets the delay in seconds before retrying a failed command.
     *
     * @param int $waitRetry Delay in seconds.
     *
     * @return void
     */
    public function setWaitRetry(int $waitRetry): void
    {
        $this->waitRetry = $waitRetry;
    }

    /**
     * Sets the maximum number of concurrent commands to run.
     *
     * @param int $maxThreads Maximum concurrency (must be >= 1).
     *
     * @return void
     */
    public function setMaxThreads(int $maxThreads): void
    {
        $this->maxThreads = max(1, $maxThreads);
    }

    /**
     * Sets the maximum number of retry attempts for failed commands.
     *
     * @param int $maxRetries Maximum retry attempts (0 disables retry).
     *
     * @return void
     */
    public function setMaxRetries(int $maxRetries): void
    {
        $this->maxRetries = $maxRetries;
    }

    /**
     * Sets the minimum stdout size required to consider a command successful.
     *
     * @param int $minConfigSize Minimum stdout size in bytes (0 disables the check).
     *
     * @return void
     */
    public function setMinConfigSize(int $minConfigSize): void
    {
        $this->minConfigSize = $minConfigSize;
    }

    /**
     * Returns the underlying SSH connection resource (or null if not connected).
     *
     * @return resource|null
     */
    public function getConnection()
    {
        return $this->conn;
    }

    /**
     * Adds a command to the execution queue.
     *
     * @param string        $cmd      The command to execute on the remote host.
     * @param string|null   $id       Optional stable command id; defaults to a unique id.
     * @param mixed         $data     Optional user data passed to the completion callback.
     * @param callable|null $callback Optional callback invoked when the command completes.
     *                                Signature: function (string $cmd, string $id, mixed $data, int $exitStatus, string $stdout, string $stderr): void
     * @param int           $timeout  Optional command timeout in seconds (0 disables the timeout).
     *
     * @return string The id assigned to (or supplied for) the queued command.
     */
    public function addCommand(string $cmd, ?string $id = null, $data = null, ?callable $callback = null, int $timeout = 0): string
    {
        if (is_null($id)) {
            $id = uniqid('', true);
        }
        $this->cmdQueue[$id] = [
            'cmd'     => $cmd,
            'retries' => 0,
            'timeout' => $timeout,
        ];
        $this->callbackData[$id] = $data;
        if ($callback) {
            $this->callbacks[$id] = $callback;
        }
        return $id;
    }

    /**
     * Drives the command pool until all queued, running, and deferred-retry
     * commands have completed (or performs a single non-blocking iteration
     * when $once is true).
     *
     * @param bool $once When true, perform one polling iteration and return;
     *                   when false (default) loop until everything is drained.
     *
     * @return bool When $once is false, always returns true after draining.
     *              When $once is true, returns true if the pool is now empty.
     */
    public function run(bool $once = false): bool
    {
        if ($once === false) {
            $this->startTime = time();
        }
        while (!empty($this->queueAfter) || !empty($this->cmdQueue) || !empty($this->running)) {
            while (count($this->running) < $this->maxThreads && !empty($this->cmdQueue)) {
                if (!$this->setupStreams(array_key_first($this->cmdQueue))) {
                    break;
                }
            }
            $this->processRunningCommands();
            $this->retryQueuedCommands();
            if ($once === false) {
                usleep($this->pollInterval);
            } else {
                break;
            }
        }
        if ($once === false) {
            return true;
        }
        return empty($this->queueAfter) && empty($this->cmdQueue) && empty($this->running);
    }

    /**
     * Executes a single command synchronously and returns its result.
     *
     * @param string $cmd The command to run on the remote host.
     *
     * @return array{cmd: string, exitStatus: int, out: string, err: string}|false
     *         An associative array on success, or false if the channel
     *         could not be opened.
     */
    public function runCommand(string $cmd)
    {
        if (!$this->conn) {
            return false;
        }
        $streamOut = @ssh2_exec($this->conn, $cmd, 'vt100');
        if (!$streamOut) {
            return false;
        }
        $stdOut    = '';
        $stdErr    = '';
        $streamErr = ssh2_fetch_stream($streamOut, SSH2_STREAM_STDERR);
        stream_set_blocking($streamOut, false);
        stream_set_blocking($streamErr, false);
        while (!(feof($streamOut) && feof($streamErr))) {
            $respOut = stream_get_contents($streamOut);
            $respErr = stream_get_contents($streamErr);
            $updated = false;
            if ($respOut !== false && $respOut !== '') {
                $stdOut .= $respOut;
                $updated = true;
            }
            if ($respErr !== false && $respErr !== '') {
                $stdErr .= $respErr;
                $updated = true;
            }
            if ($updated === false) {
                usleep($this->pollInterval);
            }
        }
        stream_set_blocking($streamOut, true);
        $metadata = stream_get_meta_data($streamOut);
        fclose($streamOut);
        fclose($streamErr);
        $exitStatus = $metadata['exit_status'] ?? -1;
        $stdOut     = rtrim(str_replace("\r", '', $stdOut));
        $stdErr     = rtrim(str_replace("\r", '', $stdErr));
        return [
            'cmd'        => $cmd,
            'exitStatus' => $exitStatus,
            'out'        => $stdOut,
            'err'        => $stdErr,
        ];
    }

    /**
     * Opens streams for a queued command and moves it from $cmdQueue to
     * $running.
     *
     * @param string $id Command id to start.
     *
     * @return bool True when streams were opened; false on ssh2_exec failure.
     */
    protected function setupStreams(string $id): bool
    {
        $cmd       = $this->cmdQueue[$id]['cmd'];
        $streamOut = @ssh2_exec($this->conn, $cmd, 'vt100');
        if (!$streamOut) {
            $this->adjustThreads();
            unset($this->cmdQueue[$id], $this->callbacks[$id], $this->callbackData[$id]);
            return false;
        }

        $this->stdout[$id] = '';
        $this->stderr[$id] = '';

        $streamErr = ssh2_fetch_stream($streamOut, SSH2_STREAM_STDERR);
        stream_set_blocking($streamOut, false);
        stream_set_blocking($streamErr, false);

        $this->running[$id] = [
            'cmd'        => $cmd,
            'retries'    => $this->cmdQueue[$id]['retries'],
            'stdout'     => $streamOut,
            'stderr'     => $streamErr,
            'start_time' => time(),
            'timeout'    => $this->cmdQueue[$id]['timeout'],
        ];

        unset($this->cmdQueue[$id]);
        return true;
    }

    /**
     * Polls running commands for new output and finalizes any that have
     * reached EOF or exceeded their timeout.
     *
     * @return void
     */
    protected function processRunningCommands(): void
    {
        foreach ($this->running as $id => $streams) {
            $respOut = stream_get_contents($streams['stdout']);
            $respErr = stream_get_contents($streams['stderr']);

            if ($respOut !== false && $respOut !== '') {
                $this->stdout[$id] .= $respOut;
            }
            if ($respErr !== false && $respErr !== '') {
                $this->stderr[$id] .= $respErr;
            }
            $currentTime = time();
            $timedOut    = $streams['timeout'] > 0 && ($currentTime - $streams['start_time']) > $streams['timeout'];
            if ((feof($streams['stdout']) && feof($streams['stderr'])) || $timedOut) {
                stream_set_blocking($streams['stdout'], true);
                $metadata = stream_get_meta_data($streams['stdout']);
                fclose($streams['stdout']);
                fclose($streams['stderr']);
                $retries    = $streams['retries'];
                $cmd        = $streams['cmd'];
                $timeout    = $streams['timeout'];
                unset($this->running[$id]);
                $exitStatus = $metadata['exit_status'] ?? -1;
                if ($timedOut && $exitStatus === 0) {
                    $exitStatus = -1;
                }
                $this->stdout[$id] = rtrim(str_replace("\r", '', $this->stdout[$id]));
                $this->stderr[$id] = rtrim(str_replace("\r", '', $this->stderr[$id]));
                $this->metadata[$id] = [
                    'exit_status' => $exitStatus,
                    'duration'    => $currentTime - $streams['start_time'],
                    'timed_out'   => $timedOut,
                ];
                $this->handleCommandCompletion($id, $cmd, $retries, $exitStatus, $timeout);
            }
        }
    }

    /**
     * Handles the completion of a command, queuing a retry if applicable
     * or invoking the completion callback otherwise.
     *
     * @param string $id         Command id.
     * @param string $cmd        Command string.
     * @param int    $retries    Current retry count for this attempt.
     * @param int    $exitStatus Exit status returned by the remote process.
     * @param int    $timeout    Per-command timeout (preserved across retries).
     *
     * @return void
     */
    protected function handleCommandCompletion(string $id, string $cmd, int $retries, int $exitStatus, int $timeout = 0): void
    {
        $outputSize = strlen($this->stdout[$id]);

        if ($exitStatus !== 0 || ($this->minConfigSize > 0 && $outputSize < $this->minConfigSize)) {
            if ($retries < $this->maxRetries) {
                $retries++;
                echo "[{$retries}/{$this->maxRetries}] Retrying Command {$cmd}\n";
                $this->queueAfter[$id] = [
                    'retry'   => time() + $this->waitRetry,
                    'cmd'     => $cmd,
                    'retries' => $retries,
                    'timeout' => $timeout,
                ];
            } else {
                $this->invokeCallback($cmd, $id, $exitStatus);
            }
        } else {
            $this->invokeCallback($cmd, $id, $exitStatus);
        }
    }

    /**
     * Promotes deferred retries back into the active queue once their wait
     * time has elapsed.
     *
     * @return void
     */
    protected function retryQueuedCommands(): void
    {
        $now = time();
        foreach ($this->queueAfter as $id => $queueData) {
            if ($now >= $queueData['retry']) {
                unset($queueData['retry']);
                $this->cmdQueue[$id] = $queueData;
                unset($this->queueAfter[$id]);
            }
        }
    }

    /**
     * Invokes the completion callback for a finished command, then frees
     * its callback bookkeeping.
     *
     * @param string $cmd        Command string.
     * @param string $id         Command id.
     * @param int    $exitStatus Exit status returned by the remote process.
     *
     * @return void
     */
    protected function invokeCallback(string $cmd, string $id, int $exitStatus): void
    {
        if (isset($this->callbacks[$id])) {
            call_user_func(
                $this->callbacks[$id],
                $cmd,
                $id,
                $this->callbackData[$id] ?? null,
                $exitStatus,
                $this->stdout[$id] ?? '',
                $this->stderr[$id] ?? ''
            );
        }
        unset($this->callbackData[$id], $this->callbacks[$id]);
    }

    /**
     * Reduces $maxThreads to the current number of running commands when a
     * new channel cannot be opened. Prevents the pool from busy-spinning
     * against an SSH server that is rejecting additional concurrent
     * sessions.
     *
     * @return void
     */
    protected function adjustThreads(): void
    {
        echo "Error starting command. Adjusting maxThreads.\n";
        $this->maxThreads = max(1, count($this->running));
    }
}
