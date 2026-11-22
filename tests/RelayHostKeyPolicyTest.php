<?php

declare(strict_types=1);

namespace Detain\SshPool\Tests;

use Detain\SshPool\RelayHostKeyPolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pure-behavior tests for the shared relay host-key policy (B025).
 *
 * These exercise checkFingerprint()/connectMethods() directly — no php-mock,
 * no connection resource — so the policy core is testable even where the
 * php-mock harness cannot run. Twin identity with the sshwitch copy
 * is pinned by the estate-root guard test.
 */
class RelayHostKeyPolicyTest extends TestCase
{
    /** @var string|null Temp store removed in tearDown. */
    private ?string $tmpFile = null;

    protected function setUp(): void
    {
        RelayHostKeyPolicy::$knownHostsFile = null;
        RelayHostKeyPolicy::$expectedFingerprints = null;
    }

    protected function tearDown(): void
    {
        RelayHostKeyPolicy::$knownHostsFile = null;
        RelayHostKeyPolicy::$expectedFingerprints = null;
        if ($this->tmpFile !== null && is_file($this->tmpFile)) {
            @unlink($this->tmpFile);
        }
        $this->tmpFile = null;
    }

    private function tempStore(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'relay-kh-');
        $this->tmpFile = $file;
        return $file;
    }

    public function testConnectMethodsSuppliesAllowlistByDefault(): void
    {
        $methods = RelayHostKeyPolicy::connectMethods();
        $this->assertSame(RelayHostKeyPolicy::HOSTKEY_ALGORITHMS, $methods['hostkey']);
    }

    public function testConnectMethodsPreservesCallerHostkeyOverride(): void
    {
        $methods = RelayHostKeyPolicy::connectMethods(['hostkey' => 'ssh-ed25519']);
        $this->assertSame('ssh-ed25519', $methods['hostkey'], 'A deliberate caller override must win');
    }

    public function testConnectMethodsMergesOtherMethods(): void
    {
        $methods = RelayHostKeyPolicy::connectMethods(['client-username' => 'bob']);
        $this->assertSame('bob', $methods['client-username']);
        $this->assertSame(RelayHostKeyPolicy::HOSTKEY_ALGORITHMS, $methods['hostkey']);
    }

    public function testNormalizeFingerprint(): void
    {
        $this->assertSame('AABBCC', RelayHostKeyPolicy::normalizeFingerprint('aa:bb:cc'));
        $this->assertSame('AABBCC', RelayHostKeyPolicy::normalizeFingerprint(' AA BB CC '));
        $this->assertSame('', RelayHostKeyPolicy::normalizeFingerprint(''));
    }

    public function testAllowlistContainsModernAndLegacyAlgorithms(): void
    {
        $this->assertStringContainsString('rsa-sha2-512', RelayHostKeyPolicy::HOSTKEY_ALGORITHMS);
        $this->assertStringContainsString('rsa-sha2-256', RelayHostKeyPolicy::HOSTKEY_ALGORITHMS);
        $this->assertStringContainsString('ssh-rsa', RelayHostKeyPolicy::HOSTKEY_ALGORITHMS);
    }

    public function testEmptyObservedFingerprintThrows(): void
    {
        $this->expectException(RuntimeException::class);
        RelayHostKeyPolicy::checkFingerprint('relay.example.com', 22, '');
    }

    public function testExpectedMapMatchReturnsVerdict(): void
    {
        RelayHostKeyPolicy::$expectedFingerprints = ['relay.example.com:22' => '0A0B0C0D0E0F00112233445566778899'];
        $this->assertSame(
            'expected-map',
            RelayHostKeyPolicy::checkFingerprint('relay.example.com', 22, '0a:0b:0c:0d:0e:0f:00:11:22:33:44:55:66:77:88:99')
        );
    }

    public function testExpectedMapMismatchThrows(): void
    {
        RelayHostKeyPolicy::$expectedFingerprints = ['relay.example.com:22' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/MISMATCH/');
        RelayHostKeyPolicy::checkFingerprint('relay.example.com', 22, 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB');
    }

    public function testAcceptNewRecordsFirstSeenKey(): void
    {
        $file = $this->tempStore();
        RelayHostKeyPolicy::$knownHostsFile = $file;

        $verdict = RelayHostKeyPolicy::checkFingerprint('relay.example.com', 22, 'CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC');

        $this->assertSame('accept-new', $verdict);
        $this->assertStringContainsString('relay.example.com:22 CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC', file_get_contents($file));
    }

    public function testStoredKnownHostMatchReturnsKnownHost(): void
    {
        $file = $this->tempStore();
        file_put_contents($file, "relay.example.com:22 CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC\n");
        RelayHostKeyPolicy::$knownHostsFile = $file;

        $this->assertSame(
            'known-host',
            RelayHostKeyPolicy::checkFingerprint('relay.example.com', 22, 'cc:cc:cc:cc:cc:cc:cc:cc:cc:cc:cc:cc:cc:cc:cc:cc')
        );
    }

    public function testStoredKnownHostChangeThrows(): void
    {
        $file = $this->tempStore();
        file_put_contents($file, "relay.example.com:22 CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC\n");
        RelayHostKeyPolicy::$knownHostsFile = $file;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/CHANGED/');
        RelayHostKeyPolicy::checkFingerprint('relay.example.com', 22, 'DDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDD');
    }

    public function testLastStoredEntryWinsOnReSeed(): void
    {
        $file = $this->tempStore();
        file_put_contents($file, "# seeded first\nrelay.example.com:22 CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC\nrelay.example.com:22 DDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDD\n");
        RelayHostKeyPolicy::$knownHostsFile = $file;

        $this->assertSame('known-host', RelayHostKeyPolicy::checkFingerprint('relay.example.com', 22, 'DDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDD'));
        // The store validates only against the last entry: the older line is superseded.
        $this->expectException(RuntimeException::class);
        RelayHostKeyPolicy::checkFingerprint('relay.example.com', 22, 'CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC');
    }

    public function testUnconfiguredPostureDegradesToUnverified(): void
    {
        // No expected map, no store: loud-warning fallback, never a brick.
        $this->assertSame(
            'unverified',
            RelayHostKeyPolicy::checkFingerprint('relay.example.com', 22, 'EEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEE')
        );
    }

    public function testUnwritableStoreDegradesToUnverifiedWithoutThrowing(): void
    {
        RelayHostKeyPolicy::$knownHostsFile = '/proc/nonexistent-dir-xyz/known_hosts';
        $this->assertSame(
            'unverified',
            RelayHostKeyPolicy::checkFingerprint('relay.example.com', 22, 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF')
        );
    }

    public function testExpectedMapCheckedBeforeStore(): void
    {
        $file = $this->tempStore();
        file_put_contents($file, "relay.example.com:22 CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC\n");
        RelayHostKeyPolicy::$knownHostsFile = $file;
        RelayHostKeyPolicy::$expectedFingerprints = ['relay.example.com:22' => 'GGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGG'];

        $this->assertSame('expected-map', RelayHostKeyPolicy::checkFingerprint('relay.example.com', 22, 'GGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGG'));
        $this->assertStringNotContainsString('GGGG', file_get_contents($file), 'Map hit must not touch the store');
    }
}
