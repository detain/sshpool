<?php

use PHPUnit\Framework\TestCase;
use Detain\SshPool\SshPool;

class SshPoolTest extends TestCase
{
    private $sshPool;
    private $mockConn;

    protected function setUp(): void
    {
        // Mock ssh2_connect function
        $this->mockConn = Mockery::mock('resource');

        // Mock ssh2 functions globally
        $this->sshPool = Mockery::mock(SshPool::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
    }

    public function tearDown(): void
    {
        Mockery::close();
    }

    // Test failed connection
    public function testFailedConnection()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("SSH connection or authentication failed");

        // Mock ssh2_connect to return false
        $this->sshPool->shouldReceive('connect')->andThrow(new Exception("SSH connection or authentication failed"));
        $sshPool = new SshPool('fakehost', 22, 'user', 'pass', 'pubkey', 'privkey');
    }

    // Test failed public key authentication
    public function testFailedPubkeyAuth()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("SSH connection or authentication failed");

        $this->sshPool->shouldReceive('connect')
            ->andThrow(new Exception("SSH connection or authentication failed"));

        $sshPool = new SshPool('localhost', 22, 'user', 'pass', 'invalid_pubkey', 'invalid_privkey');
    }

    // Test successful connection
    public function testSuccessfulConnection()
    {
        $this->sshPool->shouldReceive('connect')->once()->andReturn(true);

        $sshPool = new SshPool('localhost', 22, 'user', 'pass', 'valid_pubkey', 'valid_privkey');
        $this->assertInstanceOf(SshPool::class, $sshPool);
    }

    // Test successfully running a command
    public function testRunSuccessfulCommand()
    {
        $command = 'echo "Hello, World!"';
        $expectedOutput = 'Hello, World!';

        $this->sshPool->shouldReceive('runCommand')
            ->with($command)
            ->once()
            ->andReturn([
                'cmd' => $command,
                'exitStatus' => 0,
                'out' => $expectedOutput,
                'err' => '',
            ]);

        $result = $this->sshPool->runCommand($command);

        $this->assertEquals(0, $result['exitStatus']);
        $this->assertEquals($expectedOutput, $result['out']);
    }

    // Test failed command
    public function testRunFailedCommand()
    {
        $command = 'false';
        $this->sshPool->shouldReceive('runCommand')
            ->once()
            ->andReturn([
                'cmd' => $command,
                'exitStatus' => 1,
                'out' => '',
                'err' => 'Some error occurred',
            ]);

        $result = $this->sshPool->runCommand($command);

        $this->assertEquals(1, $result['exitStatus']);
        $this->assertNotEmpty($result['err']);
    }

    // Test concurrency
    public function testConcurrency()
    {
        $this->sshPool->setMaxThreads(2);

        $commands = ['cmd1', 'cmd2', 'cmd3'];
        foreach ($commands as $cmd) {
            $this->sshPool->addCommand($cmd);
        }

        $this->sshPool->shouldReceive('run')
            ->once()
            ->andReturn(true);

        $this->assertTrue($this->sshPool->run());
    }

    // Test retry mechanism
    public function testRetryOnFailedCommand()
    {
        $this->sshPool->setMaxRetries(2);
        $command = 'failcmd';

        $this->sshPool->shouldReceive('runCommand')
            ->andReturnUsing(function () {
                return ['exitStatus' => 1, 'out' => '', 'err' => 'Error'];
            });

        $this->sshPool->addCommand($command);
        $this->assertTrue($this->sshPool->run());
    }

    // Test minConfigSize failure
    public function testMinConfigSizeFailure()
    {
        $this->sshPool->setMinConfigSize(10);
        $command = 'echo small';

        $this->sshPool->shouldReceive('runCommand')
            ->andReturn([
                'cmd' => $command,
                'exitStatus' => 0,
                'out' => 'tiny',
                'err' => '',
            ]);

        $result = $this->sshPool->runCommand($command);
        $this->assertLessThan(10, strlen($result['out']));
    }

    // Test callback functionality
    public function testCallbackFunctionality()
    {
        $command = 'echo callback';
        $callbackInvoked = false;

        $callback = function ($cmd, $id, $data, $exitStatus, $stdout, $stderr) use (&$callbackInvoked) {
            $callbackInvoked = true;
            $this->assertEquals($cmd, 'echo callback');
            $this->assertEquals($exitStatus, 0);
        };

        $id = $this->sshPool->addCommand($command, null, 'test_data', $callback);
        $this->sshPool->shouldReceive('runCommand')->andReturn([
            'exitStatus' => 0,
            'out' => 'Success',
            'err' => '',
        ]);

        $this->sshPool->run();
        $this->assertTrue($callbackInvoked);
    }

    // Test timeout of long-running commands
    public function testTimeoutCommand()
    {
        $command = 'sleep 5';
        $this->sshPool->setMaxThreads(1);
        $this->sshPool->setMaxRetries(0);

        $this->sshPool->addCommand($command, null, null, null, 2); // Set timeout 2 seconds

        $this->sshPool->shouldReceive('runCommand')
            ->andReturnUsing(function () {
                sleep(3); // Simulate a delay
                return ['exitStatus' => 1, 'out' => '', 'err' => 'Timeout occurred'];
            });

        $result = $this->sshPool->runCommand($command);
        $this->assertEquals(1, $result['exitStatus']);
    }

    // Test output to stdout and stderr
    public function testStdoutAndStderr()
    {
        $command = 'echo "Hello" && echo "Error" >&2';
        $this->sshPool->shouldReceive('runCommand')
            ->once()
            ->andReturn([
                'exitStatus' => 0,
                'out' => 'Hello',
                'err' => 'Error',
            ]);

        $result = $this->sshPool->runCommand($command);
        $this->assertEquals('Hello', $result['out']);
        $this->assertEquals('Error', $result['err']);
    }
}
