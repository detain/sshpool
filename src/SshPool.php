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
    protected static $user = 'root';
    protected static $pass = '';
    protected static $host = '127.0.0.1';
    protected static $port = 22;
    protected static $publicKey = '';
    protected static $privateKey = '';
    protected static $uSecond = 1000000; // 1 second in useconds
    protected static $connectionDelay = 200000; // 2/10th of a second
    protected static $waitDelay = 500000; // 1/2 of a second
    protected static $maxConnections = 200; // max number of simultanious connections
    protected static $lastConnectTime = 0; // hrtime(true) / 1000 = usleep time/microseconds
    protected static $queue = []; // queued commands
    protected static $pool = [];
    protected static $debug = false;

    public static function setDebug($debug)
    {
        self::$debug = $debug;
    }

    public static function setMaxConnections($maxConnections)
    {
        self::$maxConnections = $maxConnections;
    }

    public static function addCommand($cmd, $data, $callable)
    {
        if (self::$debug) {
            echo "Adding Queued Command: {$cmd} Data:".json_encode($data)."\n";
        }
        array_unshift(self::$queue, [$cmd, $data, $callable]);
        //self::$queue[] = [$cmd, $data, $callable];
    }

    public static function disconnectCallback($reason, $message, $language)
    {
        echo 'got disconnect callback with reason code ['.$reason.'] and message: '.$message."\n";
    }

    public static function debugCallback($message, $language, $always_display)
    {
        echo 'got debug callback with message: '.$message."\n";
    }

    public static function disconnect()
    {
        if (self::$debug) {
            echo "Closing SSH Connections:";
        }
        foreach (self::$pool as $idx => $run) {
            if (self::$debug) {
                echo ' '.$idx;
            }
            ssh2_disconnect($run['con']);
        }
        if (self::$debug) {
            echo " done\n";
        }
    }

    public static function init($user, $pass, $host, $port = 22, $publicKey = '', $privateKey = '')
    {
        self::$user = $user;
        self::$pass = $pass;
        self::$host = $host;
        self::$port = $port;
        self::$publicKey = $publicKey;
        self::$privateKey = $privateKey;
        self::$pool = [];
        self::$queue = [];
    }

    public static function connect()
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
        $totalConnections = count(self::$queue) > self::$maxConnections ? self::$maxConnections : count(self::$queue);
        if (self::$debug) {
            echo "Opening {$totalConnections} SSH Connections:";
        }
        for ($idxCon = 0; $idxCon < $totalConnections; $idxCon++) {
            if (self::$debug) {
                echo ' '.$idxCon;
            }
            if (!array_key_exists($idxCon, self::$pool)) {
                self::$pool[$idxCon] = [
                    'con' => ssh2_connect(self::$host, self::$port, $methods, $callbacks),
                    'running' => false,
                    'result' => false,
                    'out_stream' => false,
                    'err_stream' => false,
                    'out' => '',
                    'err' => '',
                    'cmd' => '',
                    'data' => [],
                    'callable' => '',
                ];
                if (!self::$pool[$idxCon]['con']) {
                    die('ipmi_live returned connection:'.var_export(self::$pool[$idxCon]['con'], true));
                }
                if (!ssh2_auth_pubkey_file(self::$pool[$idxCon]['con'], self::$user, self::$publicKey, self::$privateKey)) {
                    echo "ssh2_auth_pubkey_file returned false\n";
                    if (!ssh2_auth_password(self::$pool[$idxCon]['con'], self::$user, self::$pass)) {
                        die("ssh2_auth_password returned false\n");
                    }
                }
                usleep(self::$connectionDelay);
            }
        }
        if (self::$debug) {
            echo " done\n";
        }
    }

    public static function fillPool()
    {
        if (count(self::$queue) > 0) {
            foreach (self::$pool as $idx => $run) {
                if (!$run['running']) {
                    if (count(self::$queue) > 0) {
                        // run command and store the streams and outputs
                        [self::$pool[$idx]['cmd'], self::$pool[$idx]['data'], self::$pool[$idx]['callable']] = array_shift(self::$queue);
                        if (self::$debug) {
                            echo "[{$idx}] Runnning ".self::$pool[$idx]['cmd']."\n";
                        }
                        self::$pool[$idx]['out'] = '';
                        self::$pool[$idx]['err'] = '';
                        self::$pool[$idx]['result'] = false;
                        self::$pool[$idx]['running'] = true;
                        self::$pool[$idx]['out_stream'] = ssh2_exec(self::$pool[$idx]['con'], self::$pool[$idx]['cmd']);
                        if (self::$pool[$idx]['out_stream']) {
                            self::$pool[$idx]['err_stream'] = ssh2_fetch_stream(self::$pool[$idx]['out_stream'],SSH2_STREAM_STDERR);
                            if (self::$pool[$idx]['err_stream']) {
                                // we cannot use stream_select() with SSH2 streams so use non-blocking stream_get_contents() and usleep()
                                if (stream_set_blocking(self::$pool[$idx]['out_stream'],false) && stream_set_blocking(self::$pool[$idx]['err_stream'],false)) {
                                    self::$pool[$idx]['result'] = true;
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    public static function checkPool()
    {
        $stillRunning = false;
        $updates = 0;
        foreach (self::$pool as $idx => $run) {
            if ($run['running']) {
                $stillRunning = true;
                // update out/err
                $eofAll = true;
                foreach (['out', 'err'] as $io) {
                    if (!feof(self::$pool[$idx][$io.'_stream'])) {
                        $eofAll = false;
                        $one = stream_get_contents(self::$pool[$idx][$io.'_stream']);
                        if ($one === false) {
                            $eofAll = true;
                            break;
                        } elseif ($one != '') {
                            self::$pool[$idx][$io] .= $one;
                            $updates++;
                        }
                    }
                }
                // check if ended
                if ($eofAll) {
                    // we need to wait for end of command
                    stream_set_blocking(self::$pool[$idx]['out_stream'],true);
                    stream_set_blocking(self::$pool[$idx]['err_stream'],true);
                    // these will not get any output
                    stream_get_contents(self::$pool[$idx]['out_stream']);
                    stream_get_contents(self::$pool[$idx]['err_stream']);
                    if (self::$pool[$idx]['err_stream'] !== false) {
                        fclose(self::$pool[$idx]['err_stream']);
                    }
                    if (self::$pool[$idx]['out_stream'] !== false) {
                        fclose(self::$pool[$idx]['out_stream']);
                    }
                    self::$pool[$idx]['out'] = rtrim(self::$pool[$idx]['out']);
                    self::$pool[$idx]['err'] = rtrim(self::$pool[$idx]['err']);
                    if (self::$debug) {
                        echo "[{$idx}] Finished running '".self::$pool[$idx]['cmd']."' got '".self::$pool[$idx]['out']."'\n";
                    }
                    // pass to callback
                    call_user_func(self::$pool[$idx]['callable'], self::$pool[$idx]['cmd'], self::$pool[$idx]['data'], self::$pool[$idx]['out'], self::$pool[$idx]['err']);
                    // empty the slot
                    self::$pool[$idx]['running'] = false;
                }
            }
        }
        return [$stillRunning, $updates];
    }

    public static function run()
    {
        // open up connections
        self::connect();
        // loop until finished
        $finished = false;
        while (!$finished) {
            // loop while empty slots and items in queue
            self::fillPool();
            // loop through runs
            [$stillRunning, $updates] = self::checkPool();
            // if no changes then sleep
            if ($updates == 0 && $stillRunning) {
                usleep(self::$waitDelay);
            } elseif (!$stillRunning) {
                $finished = true;
            }
        }
    }
}
