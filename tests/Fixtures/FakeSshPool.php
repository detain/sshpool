<?php
/**
 * Test fixture: an SshPool subclass that bypasses the real SSH layer.
 *
 * This lets us exercise the queue / callback / retry / timeout logic of
 * SshPool without requiring `ext-ssh2` to talk to a real SSH server.
 *
 * @package Detain\SshPool\Tests\Fixtures
 */

namespace Detain\SshPool\Tests\Fixtures;

use Detain\SshPool\SshPool;

class FakeSshPool extends SshPool
{
    /**
     * Scripted results returned by setupStreams() / processRunningCommands().
     * Each entry is an associative array:
     *   [
     *     'exitStatus' => int,    // simulated remote exit status
     *     'stdout'     => string, // simulated stdout
     *     'stderr'     => string, // simulated stderr
     *     'simulateOpenFailure' => bool, // if true, setupStreams() returns false
     *   ]
     *
     * Keyed by command id; the special key '*' is the default for any id
     * not explicitly listed.
     *
     * @var array<string, array{exitStatus?: int, stdout?: string, stderr?: string, simulateOpenFailure?: bool}>
     */
    public array $scriptedResults = [];

    /**
     * Number of times invokeCallback() was called, regardless of whether a
     * callback was actually registered.
     *
     * @var int
     */
    public int $invokeCount = 0;

    /**
     * Number of times setupStreams() has been called — useful for asserting
     * that retries actually re-attempt the command.
     *
     * @var int
     */
    public int $setupStreamsCount = 0;

    /**
     * Number of times adjustThreads() has been triggered.
     *
     * @var int
     */
    public int $adjustThreadsCount = 0;

    /**
     * Override the constructor so we can build the object without opening
     * a real SSH session.
     */
    public function __construct()
    {
        // Intentionally do not call parent::__construct().
    }

    /**
     * Suppress the destructor's disconnect call (no real connection here).
     */
    public function __destruct()
    {
        // no-op
    }

    /**
     * Skip the real SSH handshake.
     *
     * @return void
     */
    public function connect(): void
    {
        // no-op
    }

    /**
     * Simulate opening a channel and immediately mark the command as
     * complete using the scripted result.
     *
     * @param string $id Command id from $cmdQueue.
     *
     * @return bool
     */
    protected function setupStreams(string $id): bool
    {
        $this->setupStreamsCount++;
        $script = $this->scriptedResults[$id] ?? $this->scriptedResults['*'] ?? [];
        if (!empty($script['simulateOpenFailure'])) {
            $this->adjustThreads();
            unset($this->cmdQueue[$id], $this->callbacks[$id], $this->callbackData[$id]);
            return false;
        }

        $cmd     = $this->cmdQueue[$id]['cmd'];
        $retries = $this->cmdQueue[$id]['retries'];
        $timeout = $this->cmdQueue[$id]['timeout'];
        unset($this->cmdQueue[$id]);

        $this->stdout[$id] = $script['stdout'] ?? '';
        $this->stderr[$id] = $script['stderr'] ?? '';

        $this->handleCommandCompletion($id, $cmd, $retries, $script['exitStatus'] ?? 0, $timeout);
        return true;
    }

    /**
     * Nothing is actually running through real streams, so polling is a
     * no-op for the fake.
     *
     * @return void
     */
    protected function processRunningCommands(): void
    {
        // no-op
    }

    /**
     * Wrap invokeCallback to count calls.
     *
     * @param string $cmd        Command string.
     * @param string $id         Command id.
     * @param int    $exitStatus Exit status returned by the remote process.
     *
     * @return void
     */
    protected function invokeCallback(string $cmd, string $id, int $exitStatus): void
    {
        $this->invokeCount++;
        parent::invokeCallback($cmd, $id, $exitStatus);
    }

    /**
     * Wrap adjustThreads to count calls and silence its echo during tests.
     *
     * @return void
     */
    protected function adjustThreads(): void
    {
        $this->adjustThreadsCount++;
        $this->maxThreads = max(1, count($this->running));
    }
}
