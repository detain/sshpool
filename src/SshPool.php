<?php
/**
* SSH Command Queue/Pool Handler
*
* @author Joe Huss <detain@interserver.net>
* @copyright 2023
* @package   SshPool
* @category  SSH
*/

namespace Detain\SshPool;

class SshPool
{
    protected $user = 'root';
    protected $pass = '';
    protected $host = '127.0.0.1';
    protected $port = 22;
    protected $publicKey = '';
    protected $privateKey = '';
    protected $uSecond = 1000000; // 1 second in useconds
    protected $connectionDelay = 200000; // 2/10th of a second
    protected $waitDelay = 200000; // 1/2 of a second
    protected $maxConnections = 200; // max number of simultanious connections
    protected $lastConnectTime = 0; // hrtime(true) / 1000 = usleep time/microseconds
    protected $queue = []; // queued commands
    protected $pool = [];
    protected $debug = false;


    public function __construct($user, $pass, $host, $port = 22, $publicKey = '', $privateKey = '')
    {
        $this->user = $user;
        $this->pass = $pass;
        $this->host = $host;
        $this->port = $port;
        $this->publicKey = $publicKey;
        $this->privateKey = $privateKey;
        $this->pool = [];
        $this->queue = [];
    }

    public function setDebug($debug)
    {
        $this->debug = $debug;
    }

    public function setMaxConnections($maxConnections)
    {
        $this->maxConnections = $maxConnections;
    }

    public function addCommand($cmd, $data, $callable, $timeout = 0)
    {
        if ($this->debug) {
            echo "Adding Queued Command: {$cmd} Data:".json_encode($data)."\n";
        }
        array_unshift($this->queue, [$cmd, $data, $callable, $timeout]);
        //$this->queue[] = [$cmd, $data, $callable, $timeout];
    }

    public function disconnectCallback($reason, $message, $language)
    {
        echo 'got disconnect callback with reason code ['.$reason.'] and message: '.$message."\n";
    }

    public function debugCallback($message, $language, $always_display)
    {
        echo 'got debug callback with message: '.$message."\n";
    }

    public function disconnect()
    {
        if ($this->debug) {
            echo "Closing SSH Connections:";
        }
        foreach ($this->pool as $idx => $run) {
            if ($this->debug) {
                echo ' '.$idx;
            }
            ssh2_disconnect($run['con']);
        }
        if ($this->debug) {
            echo " done\n";
        }
    }

    public function connect()
    {
        $methods = [
            'hostkey'=>'ssh-rsa',
            'kex' => 'diffie-hellman-group-exchange-sha256',
            'client_to_server' => [
                'crypt' => 'aes256-ctr,aes192-ctr,aes128-ctr,aes256-cbc,aes192-cbc,aes128-cbc,3des-cbc,blowfish-cbc',
                'comp' => 'none'
            ],
            'server_to_client' => [
                'crypt' => 'aes256-ctr,aes192-ctr,aes128-ctr,aes256-cbc,aes192-cbc,aes128-cbc,3des-cbc,blowfish-cbc',
                'comp' => 'none'
            ]
        ];
        $callbacks = [
            'debug' => ['Detain\SshPool\SshPool','debugCallback'],
            'disconnect' => ['Detain\SshPool\SshPool', 'disconnectCallback']
        ];
        // determine connections needed
        $totalConnections = count($this->queue) > $this->maxConnections ? $this->maxConnections : count($this->queue);
        if ($this->debug) {
            echo "Opening {$totalConnections} SSH Connections:";
        }
        for ($idxCon = 0; $idxCon < $totalConnections; $idxCon++) {
            if ($this->debug) {
                echo ' '.$idxCon;
            }
            if (!array_key_exists($idxCon, $this->pool)) {
                $this->pool[$idxCon] = [
                    //'con' => ssh2_connect($this->host, $this->port, $methods, $callbacks),
                    'con' => ssh2_connect($this->host, $this->port),
                    'running' => false,
                    'result' => false,
                    'out_stream' => false,
                    'err_stream' => false,
                    'out' => '',
                    'err' => '',
                    'cmd' => '',
                    'data' => [],
                    'callable' => '',
                    'timeout' => 0,
                    'start' => 0,
                    'stop' => 0,
                ];
                if (!$this->pool[$idxCon]['con']) {
                    die('ipmi_live returned connection:'.var_export($this->pool[$idxCon]['con'], true));
                }
                if (!ssh2_auth_pubkey_file($this->pool[$idxCon]['con'], $this->user, $this->publicKey, $this->privateKey)) {
                    echo "ssh2_auth_pubkey_file returned false\n";
                    if (!ssh2_auth_password($this->pool[$idxCon]['con'], $this->user, $this->pass)) {
                        die("ssh2_auth_password returned false\n");
                    }
                }
                usleep($this->connectionDelay);
            }
        }
        if ($this->debug) {
            echo " done\n";
        }
    }

    public function fillPool()
    {
        if (count($this->queue) > 0) {
            foreach ($this->pool as $idx => $run) {
                if (!$run['running']) {
                    if (count($this->queue) > 0) {
                        // run command and store the streams and outputs
                        [$this->pool[$idx]['cmd'], $this->pool[$idx]['data'], $this->pool[$idx]['callable'], $this->pool[$idx]['timeout']] = array_shift($this->queue);
                        if ($this->debug) {
                            echo "[{$idx}] Runnning ".$this->pool[$idx]['cmd']."\n";
                        }
                        $this->pool[$idx]['out'] = '';
                        $this->pool[$idx]['err'] = '';
                        $this->pool[$idx]['result'] = false;
                        $this->pool[$idx]['running'] = true;
                        $this->pool[$idx]['start'] = time();
                        $this->pool[$idx]['out_stream'] = ssh2_exec($this->pool[$idx]['con'], $this->pool[$idx]['cmd']);
                        if ($this->pool[$idx]['out_stream']) {
                            $this->pool[$idx]['err_stream'] = ssh2_fetch_stream($this->pool[$idx]['out_stream'],SSH2_STREAM_STDERR);
                            if ($this->pool[$idx]['err_stream']) {
                                // we cannot use stream_select() with SSH2 streams so use non-blocking stream_get_contents() and usleep()
                                if (stream_set_blocking($this->pool[$idx]['out_stream'],false) && stream_set_blocking($this->pool[$idx]['err_stream'],false)) {
                                    $this->pool[$idx]['result'] = true;
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    public function checkPool()
    {
        $stillRunning = false;
        $updates = 0;
        $currentTime = time();
        foreach ($this->pool as $idx => $run) {
            if ($run['running']) {
                $stillRunning = true;                
                // Check for timeout if it's set (non-zero)
                if ($run['timeout'] > 0 && ($currentTime - $run['start']) > $run['timeout']) {
                    // Timeout reached, kill the connection and close streams
                    echo "!";
                    if ($this->debug) {
                        echo "[{$idx}] Timeout reached for command '".$this->pool[$idx]['cmd']."'. Killing connection.\n";
                    }                    
                    // Close any open streams
                    if ($this->pool[$idx]['err_stream'] !== false) {
                        fclose($this->pool[$idx]['err_stream']);
                    }
                    if ($this->pool[$idx]['out_stream'] !== false) {
                        fclose($this->pool[$idx]['out_stream']);
                    }
                    $this->pool[$idx]['out'] = rtrim($this->pool[$idx]['out']);
                    $this->pool[$idx]['err'] = rtrim($this->pool[$idx]['err']);
                    ssh2_disconnect($this->pool[$idx]['con']);
                    // Close the SSH connection and remove it from the pool
                    unset($run['con']);
                    unset($this->pool[$idx]['con']);
                    // Re-open the connection for reuse
                    $this->pool[$idx]['con'] = ssh2_connect($this->host, $this->port);
                    if (!$this->pool[$idx]['con']) {
                        die('Failed to reconnect after timeout.');
                    }
                    if (!ssh2_auth_pubkey_file($this->pool[$idx]['con'], $this->user, $this->publicKey, $this->privateKey)) {
                        if (!ssh2_auth_password($this->pool[$idx]['con'], $this->user, $this->pass)) {
                            die("ssh2_auth_password failed after reconnecting.\n");
                        }
                    }
                    // Call the callback function with timeout result
                    call_user_func($this->pool[$idx]['callable'], $this->pool[$idx]['cmd'], $this->pool[$idx]['data'], $this->pool[$idx]['out'], $this->pool[$idx]['err']);
                    echo ($currentTime - $run['start']); 
                    // empty the slot
                    $this->pool[$idx]['running'] = false;
                } else {
                    // update out/err
                    $eofAll = true;
                    foreach (['out', 'err'] as $io) {
                        if (!feof($this->pool[$idx][$io.'_stream'])) {
                            $eofAll = false;
                            $one = stream_get_contents($this->pool[$idx][$io.'_stream']);
                            if ($one === false) {
                                $eofAll = true;
                                break;
                            } elseif ($one != '') {
                                $this->pool[$idx][$io] .= $one;
                                $updates++;
                            }
                        }
                    }
                    // check if ended
                    if ($eofAll) {
                        // we need to wait for end of command
                        stream_set_blocking($this->pool[$idx]['out_stream'],true);
                        stream_set_blocking($this->pool[$idx]['err_stream'],true);
                        // these will not get any output
                        stream_get_contents($this->pool[$idx]['out_stream']);
                        stream_get_contents($this->pool[$idx]['err_stream']);
                        if ($this->pool[$idx]['err_stream'] !== false) {
                            fclose($this->pool[$idx]['err_stream']);
                        }
                        if ($this->pool[$idx]['out_stream'] !== false) {
                            fclose($this->pool[$idx]['out_stream']);
                        }
                        $this->pool[$idx]['out'] = rtrim($this->pool[$idx]['out']);
                        $this->pool[$idx]['err'] = rtrim($this->pool[$idx]['err']);
                        if ($this->debug) {
                            echo "[{$idx}] Finished running '".$this->pool[$idx]['cmd']."' got '".$this->pool[$idx]['out']."'\n";
                        }
                        $this->pool[$idx]['stop'] = time();
                        // pass to callback
                        call_user_func($this->pool[$idx]['callable'], $this->pool[$idx]['cmd'], $this->pool[$idx]['data'], $this->pool[$idx]['out'], $this->pool[$idx]['err']);
                        echo ($currentTime - $run['start']); 
                        // empty the slot
                        $this->pool[$idx]['running'] = false;
                    }
                }
            }
        }
        return [$stillRunning, $updates];
    }

    public function run()
    {
        // open up connections
        $this->connect();
        // loop until finished
        $finished = false;
        while (!$finished) {
            // loop while empty slots and items in queue
            $this->fillPool();
            // loop through runs
            [$stillRunning, $updates] = $this->checkPool();
            // if no changes then sleep
            if ($updates == 0 && $stillRunning) {
                usleep($this->waitDelay);
            } elseif (!$stillRunning) {
                $finished = true;
            }
        }
    }
}
