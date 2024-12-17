<?php

use PHPUnit\Framework\TestCase;
use Detain\SshPool\SshPool;

class SshPoolTest extends TestCase
{
    private $sshPool;
    private $mockConnection;

    protected function setUp(): void
    {
        // Mock SSH functions using a namespace workaround
        stream_wrapper_register("ssh2", MockSshStream::class);

        // Mock ssh2_connect to always return a valid resource
        $this->mockConnection = $this->createMock('stdClass');
        $this->sshPool = $this->getMockBuilder(SshPool::class)
            ->setConstructorArgs(['localhost', 22, 'user', 'pass', '/path/to/pubkey', '/path/to/privkey'])
            ->onlyMethods(['connect'])
            ->getMock();

        // Mock the connect method to bypass real SSH connection
        $this->sshPool->expects($this->once())->method('connect')->willReturn(true);
    }

    public function testConstructorInitializesProperties(): void
    {
        $this->assertEquals(50, $this->sshPool->maxThreads);
        $this->assertEquals(0, $this->sshPool->maxRetries);
        $this->assertEquals(15, $this->sshPool->waitRetry);
        $this->assertEquals(0, $this->sshPool->minConfigSize);
    }

    public function testSetWaitRetry(): void
    {
        $this->sshPool->setWaitRetry(10);
        $this->assertEquals(10, $this->sshPool->waitRetry);
    }

    public function testSetMaxThreads(): void
    {
        $this->sshPool->setMaxThreads(20);
        $this->assertEquals(20, $this->sshPool->maxThreads);
    }

    public function testSetMaxRetries(): void
    {
        $this->sshPool->setMaxRetries(5);
        $this->assertEquals(5, $this->sshPool->maxRetries);
    }

    public function testSetMinConfigSize(): void
    {
        $this->sshPool->setMinConfigSize(200);
        $this->assertEquals(200, $this->sshPool->minConfigSize);
    }

    public function testAddCommand(): void
    {
        $commandId = $this->sshPool->addCommand('ls -la', 'cmd1', ['data'], null, 30);

        $this->assertArrayHasKey('cmd1', $this->sshPool->cmdQueue);
        $this->assertEquals('ls -la', $this->sshPool->cmdQueue['cmd1']['cmd']);
        $this->assertEquals(['data'], $this->sshPool->callbackData['cmd1']);
        $this->assertEquals('cmd1', $commandId);
    }

    public function testRunExecutesCommands(): void
    {
        // Mock SSH execution output
        $mockOutput = "Mock command output";
        stream_wrapper_register("ssh2.mock", MockSshStream::class);

        // Add a command
        $this->sshPool->addCommand('ls -la', 'cmd1');

        // Run once
        $result = $this->sshPool->run(true);

        $this->assertTrue($result);
        $this->assertArrayHasKey('cmd1', $this->sshPool->stdout);
        $this->assertEquals($mockOutput, $this->sshPool->stdout['cmd1']);
    }

    public function testHandleRetries(): void
    {
        $this->sshPool->setMaxRetries(2);

        $this->sshPool->addCommand('failing-command', 'fail1');
        $this->sshPool->run(true);

        $this->assertEquals(2, $this->sshPool->cmdQueue['fail1']['retries']);
    }
}

/**
 * Mock SSH Stream Class
 */
class MockSshStream
{
    public $context;

    public function stream_open($path, $mode, $options, &$opened_path)
    {
        return true;
    }

    public function stream_read($count)
    {
        return "Mock command output\n";
    }

    public function stream_eof()
    {
        return true;
    }

    public function stream_close()
    {
        return true;
    }
}
