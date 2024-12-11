<?php
/**
* SSH Command Queue/Pool Handler
*
* @author Joe Huss <detain@interserver.net>
* @copyright 2025
* @package   SshPool
* @category  SSH
* 
*/

namespace Detain\SshPool;

class SshPool
{
    public int $maxThreads = 50;
    public int $maxRetries = 3;
    public int $waitRetry = 15;
    public int $minConfigSize = 0;
    public $cmdQueue = [];
    public $running = [];
    public $callbackData = [];
    public $callbacks = [];
    public $queueAfter = [];
    public $stdout = [];
    public $stderr = [];
    public $metadata = [];
    public $startTime;
    private $conn;
    private $host = 'localhost';
    private $port = 21;
    private $user = 'root';
    private $pass = 'password';
    private $pubKey = '';
    private $privKey = '';
    

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
    
    public function connect() {
        $this->conn = ssh2_connect($this->host, $this->port, ['hostkey' => 'ssh-rsa']);
        if (!$this->conn || !ssh2_auth_pubkey_file($this->conn, $this->user, $this->pubKey, $this->privKey)) {
            throw new Exception("SSH connection or authentication failed");
        }
    }
    
    public function disconnect() {
        ssh2_disconnect($this->conn);        
    }

    /**
    * Sets the delay in seconds to wait between retrying a command if maxretries > 0
    * @param int $waitRetry
    */
    public function setWaitRetry(int $waitRetry) {
        $this->waitRetry = $waitRetry;
    }

    /**
    * Sets the max number of commands that will be ran simultaniouslyi
    * @param int $maxThreads
    */
    public function setMaxThreads(int $maxThreads) {
        $this->maxThreads = $maxThreads;
    }

    /**
    * Sets the number of retries to retry a failed command or 0 to disable
    * @param int $maxRetries
    */
    public function setMaxRetries(int $maxRetries) {
        $this->maxRetries = $maxRetries;
    }

    /**
    * 0 to disable or Sets the min size of the output.  if smaller than this it will considerr it failed and retry if retry is set
    * @param int $minConfigSize
    */
    public function setMinConfigSize(int $minConfigSize) {
        $this->minConfigSize = $minConfigSize;
    }

    public function addCommand($cmd, $id = null, $data = null, $callback = null, $timeout = 0)
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

    public function run($once = false)
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
            echo "All commands completed in " . (time() - $this->startTime) . " seconds.\n";
            return true;
        }
        return !(!empty($this->queueAfter) || !empty($this->cmdQueue) || !empty($this->running));
    }

    private function setupStreams($id)
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
                echo ($currentTime - $streams['start_time']);
                $this->handleCommandCompletion($id, $cmd, $retries, $exitStatus);
            }
        }
    }

    private function handleCommandCompletion($id, $cmd, $retries, $exitStatus)
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

    private function invokeCallback($cmd, $id, $exitStatus, $success)
    {
        if (isset($this->callbacks[$id])) {
            call_user_func($this->callbacks[$id], $cmd, $id, $this->callbackData[$id], $exitStatus, $this->stdout[$id], $this->stderr[$id]);
        }
        unset($this->callbackData[$id]);
        unset($this->callbacks[$id]);
    }

    private function adjustThreads($id)
    {
        echo "Error starting command for $id. Adjusting maxThreads.\n";
        $this->maxThreads = max(1, count($this->running));
        //$this->cmdQueue[$id] = $this->cmdQueue[$id]; // Requeue command
    }
}
