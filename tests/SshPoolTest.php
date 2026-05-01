<?php
/**
 * SshPool unit tests.
 *
 * Most tests use a FakeSshPool fixture (see tests/Fixtures/FakeSshPool.php)
 * that bypasses real SSH I/O so the queue / retry / callback / timeout
 * logic can be exercised in isolation. A small number of tests still
 * touch the real ssh2_* functions to verify network failure handling;
 * those use clearly-invalid hosts so they fail fast.
 *
 * @package Detain\SshPool\Tests
 */

namespace Detain\SshPool\Tests;

use Detain\SshPool\SshPool;
use Detain\SshPool\Tests\Fixtures\FakeSshPool;
use PHPUnit\Framework\TestCase;

class SshPoolTest extends TestCase
{
    /**
     * Build a fake pool with a default scripted result for every command.
     *
     * @param array<string, mixed> $defaultScript Default scripted result for any id.
     * @return FakeSshPool
     */
    private function newPool(array $defaultScript = ['exitStatus' => 0, 'stdout' => 'ok', 'stderr' => '']): FakeSshPool
    {
        $pool = new FakeSshPool();
        $pool->scriptedResults['*'] = $defaultScript;
        // Disable polling sleeps in tests.
        $pool->pollInterval = 0;
        return $pool;
    }

    // ---- Connection / construction ------------------------------------

    /**
     * A real SshPool that cannot reach its host should throw.
     *
     * Uses an obviously-invalid IPv4 address so the connect attempt fails
     * immediately rather than hitting DNS for "fakehost".
     */
    public function testFailedConnectionThrows(): void
    {
        $this->expectException(\Exception::class);
        // 0.0.0.0 + invalid port forces an immediate connect failure.
        @new SshPool('0.0.0.0', 1, 'user', '', '/nonexistent/pub', '/nonexistent/priv');
    }

    /**
     * The fake subclass should construct without touching the network.
     */
    public function testFakeConstructsWithoutConnecting(): void
    {
        $pool = $this->newPool();
        $this->assertInstanceOf(SshPool::class, $pool);
    }

    /**
     * disconnect() must be safe to call when no connection has been opened.
     */
    public function testDisconnectIsSafeWithoutConnection(): void
    {
        $pool = $this->newPool();
        $pool->disconnect();
        $pool->disconnect(); // idempotent
        $this->assertNull($pool->getConnection());
    }

    // ---- addCommand / queue --------------------------------------------

    public function testAddCommandReturnsGeneratedId(): void
    {
        $pool = $this->newPool();
        $id   = $pool->addCommand('uptime');
        $this->assertNotEmpty($id);
        $this->assertArrayHasKey($id, $pool->cmdQueue);
        $this->assertSame('uptime', $pool->cmdQueue[$id]['cmd']);
    }

    public function testAddCommandRespectsCallerSuppliedId(): void
    {
        $pool = $this->newPool();
        $id   = $pool->addCommand('uptime', 'my-id');
        $this->assertSame('my-id', $id);
        $this->assertArrayHasKey('my-id', $pool->cmdQueue);
    }

    public function testAddCommandStoresTimeoutAndCallback(): void
    {
        $pool = $this->newPool();
        $cb   = function () {};
        $id   = $pool->addCommand('uptime', null, ['user' => 'data'], $cb, 30);
        $this->assertSame(30, $pool->cmdQueue[$id]['timeout']);
        $this->assertSame(['user' => 'data'], $pool->callbackData[$id]);
        $this->assertSame($cb, $pool->callbacks[$id]);
    }

    // ---- Setters --------------------------------------------------------

    public function testSetMaxThreadsClampsToOne(): void
    {
        $pool = $this->newPool();
        $pool->setMaxThreads(0);
        $this->assertSame(1, $pool->maxThreads);
        $pool->setMaxThreads(-5);
        $this->assertSame(1, $pool->maxThreads);
        $pool->setMaxThreads(8);
        $this->assertSame(8, $pool->maxThreads);
    }

    public function testSettersUpdateValues(): void
    {
        $pool = $this->newPool();
        $pool->setWaitRetry(7);
        $pool->setMaxRetries(4);
        $pool->setMinConfigSize(100);
        $this->assertSame(7, $pool->waitRetry);
        $this->assertSame(4, $pool->maxRetries);
        $this->assertSame(100, $pool->minConfigSize);
    }

    // ---- run() success path --------------------------------------------

    public function testRunInvokesCallbackOnSuccess(): void
    {
        $pool             = $this->newPool();
        $callbackInvoked  = false;
        $observedCmd      = null;
        $observedExit     = null;
        $observedStdout   = null;
        $observedStderr   = null;
        $observedData     = null;

        $callback = function ($cmd, $id, $data, $exitStatus, $stdout, $stderr) use (
            &$callbackInvoked,
            &$observedCmd,
            &$observedExit,
            &$observedStdout,
            &$observedStderr,
            &$observedData
        ) {
            $callbackInvoked = true;
            $observedCmd     = $cmd;
            $observedExit    = $exitStatus;
            $observedStdout  = $stdout;
            $observedStderr  = $stderr;
            $observedData    = $data;
        };

        $pool->scriptedResults['*'] = ['exitStatus' => 0, 'stdout' => 'hello', 'stderr' => ''];
        $pool->addCommand('echo hello', 'cb1', 'user-data', $callback);
        $this->assertTrue($pool->run());

        $this->assertTrue($callbackInvoked);
        $this->assertSame('echo hello', $observedCmd);
        $this->assertSame(0, $observedExit);
        $this->assertSame('hello', $observedStdout);
        $this->assertSame('', $observedStderr);
        $this->assertSame('user-data', $observedData);
        $this->assertEmpty($pool->cmdQueue);
        $this->assertEmpty($pool->running);
    }

    public function testRunHandlesMultipleCommands(): void
    {
        $pool = $this->newPool();
        $pool->setMaxThreads(2);
        $finished = [];
        $callback = function ($cmd, $id) use (&$finished) {
            $finished[] = $id;
        };
        $pool->addCommand('cmd1', 'a', null, $callback);
        $pool->addCommand('cmd2', 'b', null, $callback);
        $pool->addCommand('cmd3', 'c', null, $callback);
        $pool->run();
        sort($finished);
        $this->assertSame(['a', 'b', 'c'], $finished);
    }

    // ---- Retry behavior -------------------------------------------------

    public function testFailedCommandIsRetriedUpToMaxRetries(): void
    {
        $pool = $this->newPool();
        $pool->setMaxRetries(2);
        $pool->setWaitRetry(0); // no delay
        $pool->scriptedResults['retryme'] = ['exitStatus' => 1, 'stdout' => '', 'stderr' => 'boom'];

        $callbackCalls = 0;
        $finalExit     = null;
        $pool->addCommand('failcmd', 'retryme', null, function ($cmd, $id, $data, $exitStatus) use (&$callbackCalls, &$finalExit) {
            $callbackCalls++;
            $finalExit = $exitStatus;
        });

        ob_start();
        $pool->run();
        ob_end_clean(); // silence the "Retrying Command" notices

        // Callback fires once, after the final failed retry.
        $this->assertSame(1, $callbackCalls);
        $this->assertSame(1, $finalExit);
        // setupStreams runs three times: initial attempt + 2 retries.
        $this->assertSame(3, $pool->setupStreamsCount);
        // invokeCallback only fires once (at exhaustion).
        $this->assertSame(1, $pool->invokeCount);
    }

    public function testRetryPreservesTimeout(): void
    {
        // Regression: previously handleCommandCompletion() did not pass the
        // timeout into queueAfter, so retries silently lost it.
        $pool = $this->newPool();
        $pool->setMaxRetries(1);
        // Use a non-zero wait so the retry stays in queueAfter long enough
        // to inspect after the first run() iteration.
        $pool->setWaitRetry(60);
        $pool->scriptedResults['t1'] = ['exitStatus' => 1, 'stdout' => '', 'stderr' => ''];

        $pool->addCommand('cmd', 't1', null, null, 42);

        ob_start();
        $pool->run(true);
        ob_end_clean();
        $this->assertArrayHasKey('t1', $pool->queueAfter);
        $this->assertSame(42, $pool->queueAfter['t1']['timeout'], 'Retry must preserve per-command timeout');
        $this->assertSame('cmd', $pool->queueAfter['t1']['cmd']);
        $this->assertSame(1, $pool->queueAfter['t1']['retries']);
    }

    public function testRetryAfterDelayPreservesTimeoutThroughCmdQueue(): void
    {
        // Once the wait elapses, retryQueuedCommands() promotes the entry
        // back into cmdQueue. The timeout must still be preserved there
        // so setupStreams reads the right value into $running.
        $pool = $this->newPool();
        $pool->setMaxRetries(1);
        $pool->setWaitRetry(0);
        $pool->scriptedResults['t2'] = ['exitStatus' => 1, 'stdout' => '', 'stderr' => ''];

        $pool->addCommand('cmd', 't2', null, null, 99);

        // First iteration: attempt fails, queues retry, retry promoted to cmdQueue.
        ob_start();
        $pool->run(true);
        ob_end_clean();

        $this->assertArrayHasKey('t2', $pool->cmdQueue);
        $this->assertSame(99, $pool->cmdQueue['t2']['timeout']);
        $this->assertSame(1, $pool->cmdQueue['t2']['retries']);
    }

    public function testMinConfigSizeTriggersRetry(): void
    {
        $pool = $this->newPool();
        $pool->setMinConfigSize(10);
        $pool->setMaxRetries(1);
        $pool->setWaitRetry(0);
        $pool->scriptedResults['mc'] = ['exitStatus' => 0, 'stdout' => 'tiny', 'stderr' => ''];

        $pool->addCommand('echo tiny', 'mc');

        ob_start();
        $pool->run();
        ob_end_clean();
        // initial attempt + 1 retry = 2 setupStreams calls; callback fires once.
        $this->assertSame(2, $pool->setupStreamsCount);
        $this->assertSame(1, $pool->invokeCount);
    }

    // ---- Channel-open failure ------------------------------------------

    public function testChannelOpenFailureAdjustsThreads(): void
    {
        $pool = $this->newPool();
        $pool->scriptedResults['fail'] = ['simulateOpenFailure' => true];
        $pool->addCommand('whatever', 'fail');
        $pool->run();
        $this->assertSame(1, $pool->adjustThreadsCount);
        $this->assertSame(1, $pool->maxThreads);
        $this->assertEmpty($pool->cmdQueue);
    }

    // ---- runCommand bug regression -------------------------------------

    /**
     * Regression: runCommand() previously referenced an undefined $id.
     * With no live connection ssh2_exec returns false with a warning, so
     * runCommand() must return false cleanly without raising an
     * "undefined variable" error.
     */
    public function testRunCommandReturnsFalseWithoutConnection(): void
    {
        $pool = $this->newPool();
        $result = $pool->runCommand('uptime');
        $this->assertFalse($result);
    }

    // ---- Cleanup -------------------------------------------------------

    public function testCallbackBookkeepingClearedAfterCompletion(): void
    {
        $pool = $this->newPool();
        $id = $pool->addCommand('uptime', null, 'data', function () {});
        $pool->run();
        $this->assertArrayNotHasKey($id, $pool->callbacks);
        $this->assertArrayNotHasKey($id, $pool->callbackData);
    }
}
