<?php
/**
* SSH Command Queue/Pool Handler
*
* This class provides a thread-pooled SSH command execution handler, allowing multiple commands to be executed concurrently with configurable limits on
* the number of simultaneous threads, retry attempts, and more. It also supports command queuing, callbacks for command completion, and handling
* of stdout, stderr, and exit statuses.
*
* Features:
* - Executes multiple SSH commands concurrently.
* - Configurable maximum number of threads.
* - Runs each command over a single ssh session
* - Can add commands to the queue while its processing
* - Get stdOut, stdDErr, exit code, run time and other info back
* - Configurable auitomatic retrying of failed commands
* - Configurable delay between retrying failed commands
* - Configurable command-speicifc timeouts
* - Callbacks for command completion with access to output data.
* - Queues commands to be executed after a specified delay.
* - Support for password and public/private key authentication.
* - Customizable minimum output size to validate command success.
*
* Usage:
* 1. Instantiate the SshPool class with SSH connection parameters.
* 2. Add commands to the queue using the `addCommand` method.
* 3. Run the command pool using the `run` method.
* 4. Optionally set configuration options using setter methods.
*
* ```php
* Example:
* $pool = new SshPool('example.com', 22, 'user', 'password', '/path/to/pubkey', '/path/to/privkey');
* $pool->setMaxThreads(10);
* $pool->setMaxRetries(2);
* $id = $pool->addCommand('ls -l', null, null, function($cmd, $id, $data, $exitStatus, $stdout, $stderr) {
*     echo "Command $id completed with exit status $exitStatus\n";
* });
* $pool->run();
* ```
*
* @author Joe Huss <detain@interserver.net>
* @copyright 2025
* @package   SshPool
* @category  SSH
*/

namespace Detain\SshPool;

class SshPool
{

    /**
    * Maximum number of concurrent threads (commands) to run.
    * @var int
    */
    public int $maxThreads = 50;

    /**
    * Maximum number of retry attempts for failed commands.
    * @var int
    */
    public int $maxRetries = 0;

    /**
    * Delay in seconds between retry attempts.
    * @var int
    */
    public int $waitRetry = 15;

    /**
    * Minimum output size required to consider a command successful.
    * @var int
    */
    public int $minConfigSize = 0;

    /**
    * Queue of commands to be executed.
    * @var array<string, array> Associative array of command IDs and their details.
    */
    public array $cmdQueue = [];

    /**
    * Array of currently running commands.
    * @var array<string, array> Associative array of command IDs and their streams/status.
    */
    public array $running = [];

    /**
    * Data associated with each command for callback purposes.
    * @var array<string, mixed> Associative array of command IDs and their data.
    */
    public array $callbackData = [];

    /**
    * Callback functions for command completion.
    * @var array<string, callable> Associative array of command IDs and their callbacks.
    */
    public array $callbacks = [];

    /**
    * Queue of commands to be executed after a specified time.
    * @var array<string, array> Associative array of command IDs and their retry details.
    */
    public array $queueAfter = [];

    /**
    * Accumulated standard output from commands.
    * @var array<string, string> Associative array of command IDs and their stdout.
    */
    public array $stdout = [];

    /**
    * Accumulated standard error from commands.
    * @var array<string, string> Associative array of command IDs and their stderr.
    */
    public array $stderr = [];

    /**
    * Metadata for commands, such as exit status.
    * @var array<string, mixed> Associative array of command IDs and their metadata.
    */
    public array $metadata = [];

    /**
    * Timestamp when the command execution started.
    * @var int|null
    */
    public ?int $startTime;

    /**
    * SSH connection resource.
    * @var resource|null
    */
    private $conn;

    /**
    * SSH host to connect to.
    * @var string
    */
    private string $host = 'localhost';

    /**
    * SSH port to connect to.
    * @var int
    */
    private int $port = 21;

    /**
    * SSH username for authentication.
    * @var string
    */
    private string $user = 'root';

    /**
    * SSH password for authentication.
    * @var string
    */
    private string $pass = 'password';

    /**
    * Public key file for authentication.
    * @var string
    */
    private string $pubKey = '';

    /**
    * Private key file for authentication.
    * @var string
    */
    private string $privKey = '';

    /**
    * Constructor to initialize the SSH connection.
    *
    * @param string $host SSH host address.
    * @param int $port SSH port number.
    * @param string $user SSH username.
    * @param string $pass SSH password.
    * @param string $pubKey Path to the public key file.
    * @param string $privKey Path to the private key file.
    * @throws Exception If SSH connection or authentication fails.
    */
    public function __construct($host, $port, $user, $pass, $pubKey, $privKey)
    {
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->pass = $pass;
        $this->pubKey = $pubKey;
        $this->privKey = $privKey;
        $this->connect();
    }

    /**
    * Establishes the SSH connection.
    *
    * @throws Exception If SSH connection or authentication fails.
    */
    public function connect() {
        
        $methods = ['hostkey' => 'ssh-rsa'];
        $this->conn = ssh2_connect($this->host, $this->port, $methods);
        if (!$this->conn || !ssh2_auth_pubkey_file($this->conn, $this->user, $this->pubKey, $this->privKey)) {
            throw new \Exception("SSH connection or authentication failed");
        }
    }

    /**
    * Closes the SSH connection.
    */
    public function disconnect() {
        ssh2_disconnect($this->conn);        
    }

    /**
    * Sets the delay in seconds to wait between retrying a command.
    *
    * @param int $waitRetry Delay in seconds.
    */
    public function setWaitRetry(int $waitRetry) {
        $this->waitRetry = $waitRetry;
    }

    /**
    * Sets the maximum number of concurrent threads (commands) to run.
    *
    * @param int $maxThreads Maximum number of threads.
    */
    public function setMaxThreads(int $maxThreads) {
        $this->maxThreads = $maxThreads;
    }

    /**
    * Sets the maximum number of retry attempts for failed commands.
    *
    * @param int $maxRetries Maximum number of retries.
    */
    public function setMaxRetries(int $maxRetries) {
        $this->maxRetries = $maxRetries;
    }

    /**
    * Sets the minimum output size required to consider a command successful.
    *
    * @param int $minConfigSize Minimum output size in bytes. 0 to disable
    */
    public function setMinConfigSize(int $minConfigSize) {
        $this->minConfigSize = $minConfigSize;
    }

    /**
    * Adds a command to the execution queue.
    *
    * @param string $cmd The command to execute.
    * @param string|null $id Optional command ID; defaults to a unique ID.
    * @param mixed $data Optional data to pass to the callback.
    * @param callable|null $callback Optional callback function to invoke upon command completion.
    * @param int $timeout Optional command timeout in seconds; 0 for no timeout.
    * @return string The command ID.
    */
    public function addCommand(string $cmd, ?string $id = null, mixed $data = null, ?callable $callback = null, int $timeout = 0): string
    {
        if (is_null($id)) {
            $id = uniqid();
        }        
        $this->cmdQueue[$id] = [
            'cmd' => $cmd,
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
    * Executes the queued commands, managing the command pool.
    *
    * @param bool $once Whether to run once or continuously manage the queue.
    * @return bool True if all commands completed; false otherwise.
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
                usleep(25000); // 1/40th of a second
            } else {
                break;
            }
        }
        if ($once === false) {
            //echo "All commands completed in " . (time() - $this->startTime) . " seconds.\n";
            return true;
        }
        return !(!empty($this->queueAfter) || !empty($this->cmdQueue) || !empty($this->running));
    }

    public function runCommand(string $cmd)
    {
        $streamOut = ssh2_exec($this->conn, $cmd, 'vt100');
        if (!$streamOut) {
            $this->adjustThreads($id);
            return false;
        }
        $stdOut = '';
        $stdErr = '';
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
                usleep(25000);
            }
        }
        stream_set_blocking($streamOut, true);
        $metadata = stream_get_meta_data($streamOut);
        fclose($streamOut);
        fclose($streamErr);
        $exitStatus = $metadata['exit_status'] ?? -1;
        $stdOut = rtrim(str_replace("\r", '', $stdOut));
        $stdErr = rtrim(str_replace("\r", '', $stdErr));
        return [
            'cmd' => $cmd,
            'exitStatus' => $exitStatus,
            'out' => $stdOut,
            'err' => $stdErr,
        ];
    }

    /**
    * Sets up streams for a command and starts its execution.
    *
    * @param string $id Command ID.
    * @return bool True if streams were successfully set up; false otherwise.
    */
    private function setupStreams(string $id): bool
    {
        $cmd = $this->cmdQueue[$id]['cmd'];
        //echo "Running {$cmd}\n";
        $streamOut = ssh2_exec($this->conn, $cmd, 'vt100');
        if (!$streamOut) {
            $this->adjustThreads($id);
            return false;
        }

        $this->stdout[$id] = '';
        $this->stderr[$id] = '';

        $streamErr = ssh2_fetch_stream($streamOut, SSH2_STREAM_STDERR);
        stream_set_blocking($streamOut, false);
        stream_set_blocking($streamErr, false);

        $this->running[$id] = [
            'cmd' => $cmd,
            'retries' => $this->cmdQueue[$id]['retries'],
            'stdout' => $streamOut,
            'stderr' => $streamErr,
            'start_time' => time(),
            'timeout' => $this->cmdQueue[$id]['timeout'],
        ];

        unset($this->cmdQueue[$id]);
        return true;
    }

    /**
    * Processes the output and status of running commands.
    */
    private function processRunningCommands()
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
            // check if eof stdout/err OR a timeout is set and its past time
            if ((feof($streams['stdout']) && feof($streams['stderr'])) || ($streams['timeout'] > 0 && ($currentTime - $streams['start_time']) > $streams['timeout'])) {
                stream_set_blocking($streams['stdout'], true);
                $metadata = stream_get_meta_data($streams['stdout']);
                fclose($streams['stdout']);
                fclose($streams['stderr']);
                $retries = $streams['retries'];
                $cmd = $streams['cmd'];
                unset($this->running[$id]);
                $exitStatus = $metadata['exit_status'] ?? -1;
                $this->stdout[$id] = rtrim(str_replace("\r", '', $this->stdout[$id]));
                $this->stderr[$id] = rtrim(str_replace("\r", '', $this->stderr[$id]));
                //echo ($currentTime - $streams['start_time']);
                $this->handleCommandCompletion($id, $cmd, $retries, $exitStatus);
            }
        }
    }


    /**
    * Handles the completion of a command, including retry logic and callback invocation.
    *
    * @param string $id Command ID.
    * @param string $cmd Command string.
    * @param int $retries Current retry count.
    * @param int $exitStatus Command exit status.
    */
    private function handleCommandCompletion(string $id, string $cmd, int $retries, int $exitStatus)
    {
        $outputSize = strlen($this->stdout[$id]);

        if ($exitStatus !== 0 || ($this->minConfigSize > 0 && $outputSize < $this->minConfigSize)) {
            if ($retries < $this->maxRetries) {
                $retries++;
                echo "[{$retries}/{$this->maxRetries}] Retrying Command {$cmd}\n";
                $this->queueAfter[$id] = [
                    'retry' => time() + $this->waitRetry,
                    'cmd' => $cmd,
                    'retries' => $retries,
                ];
            } else {
                $this->invokeCallback($cmd, $id, $exitStatus, false);
            }
        } else {
            $this->invokeCallback($cmd, $id, $exitStatus, true);
        }
    }


    /**
    * Retries queued commands after the specified delay.
    */
    private function retryQueuedCommands()
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
    * Invokes the callback function for a completed command.
    *
    * @param string $cmd Command string.
    * @param string $id Command ID.
    * @param int $exitStatus Command exit status.
    * @param bool $success Whether the command was successful.
    */
    private function invokeCallback(string $cmd, string $id, int $exitStatus, bool $success)
    {
        if (isset($this->callbacks[$id])) {
            call_user_func($this->callbacks[$id], $cmd, $id, $this->callbackData[$id], $exitStatus, $this->stdout[$id], $this->stderr[$id]);
        }
        unset($this->callbackData[$id]);
        unset($this->callbacks[$id]);
    }


    /**
    * Adjusts the maximum number of threads if a command fails to start.
    *
    * @param string $id Command ID.
    */
    private function adjustThreads(string $id)
    {
        echo "Error starting command for $id. Adjusting maxThreads.\n";
        $this->maxThreads = max(1, count($this->running));
        //$this->cmdQueue[$id] = $this->cmdQueue[$id]; // Requeue command
    }
}
