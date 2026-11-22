<?php
/**
 * RelayHostKeyPolicy — one SSH host-key policy for every relay door (B025).
 *
 * Before this class, the two relay clients disagreed:
 *  - Sshwitch pinned `['hostkey' => 'ssh-rsa']` (algorithm preference only,
 *    no key check, legacy SHA-1 downgrade oracle);
 *  - SshPool called bare `ssh2_connect()` (no algorithm restriction at all).
 * Neither ever compared the host key VALUE — both doors were MITM-able.
 *
 * This class unifies them:
 *  1. `connectMethods()` supplies the shared algorithm allowlist (modern
 *     rsa-sha2-512/256 first; ssh-rsa last as a legacy-relay fallback) and
 *     preserves any caller-supplied overrides.
 *  2. `verify()` / `checkFingerprint()` apply OpenSSH `accept-new` semantics
 *     to the MD5 host fingerprint after the handshake and BEFORE auth:
 *     a configured expected fingerprint wins (strict), a known_hosts store
 *     records the first-seen key and hard-fails on any later change, and a
 *     fully unconfigured posture degrades loudly (warning log) to
 *     today's behaviour instead of bricking relay management.
 *
 * Owner bootstrapping (runtime action, out of package scope):
 *  - define SSH_RELAY_KNOWN_HOSTS  — absolute path to a private (0600) store file, and
 *  - define RELAY_SSH_HOST_FINGERPRINTS — array `'host:port' => 'md5hex'`
 *    seeded out-of-band (ssh-keygen on the relay) so FIRST contact is already
 *    strict; without the seed, the first recorded key is trust-on-first-use.
 *
 * ext-ssh2 note: this extension exposes no libssh2 known_hosts flags, so the
 * store below is a PHP-managed `host:port fingerprint` file — the documented
 * idiom is `ssh2_fingerprint()` post-connect (see exact-fix, refactor_bugs.md
 * §4 B025), which this class wraps.
 *
 * Twin disclosure: `detain/sshwitch` and `detain/sshpool` are independent
 * packages with no composer dependency between them (adding one would need a
 * root lock regen — forbidden program-wide), so this class exists byte-
 * identically per package namespace. The estate-root guard test
 * tests/phpunit/unit/Ssh/RelayHostKeyPolicyTest.php pins the twins' identity.
 *
 * @package Detain\SshPool
 * @author  Joe Huss <detain@interserver.net>
 * @license GPL-3.0
 */

namespace Detain\SshPool;

class RelayHostKeyPolicy
{
    /**
     * Shared host-key algorithm allowlist for ssh2_connect() 'hostkey'.
     * Modern rsa-sha2 first; ssh-rsa kept last only as a legacy-relay
     * fallback (the old pin forced ssh-rsa exclusively — a downgrade oracle).
     */
    public const HOSTKEY_ALGORITHMS = 'rsa-sha2-512,rsa-sha2-256,ssh-rsa';

    /**
     * Override for the known_hosts store path (tests / runtime config).
     * Null falls through to the SSH_RELAY_KNOWN_HOSTS constant.
     *
     * @var string|null
     */
    public static ?string $knownHostsFile = null;

    /**
     * Override for the expected-fingerprint map (tests / runtime config).
     * Null falls through to the RELAY_SSH_HOST_FINGERPRINTS constant.
     *
     * @var array<string, string>|null
     */
    public static ?array $expectedFingerprints = null;

    /**
     * Methods array for ssh2_connect(): adds the shared host-key allowlist
     * unless the caller already pinned a 'hostkey' value of their own.
     *
     * @param array<string, mixed> $methods caller-supplied ssh2_connect methods
     *
     * @return array<string, mixed>
     */
    public static function connectMethods(array $methods = []): array
    {
        if (!isset($methods['hostkey'])) {
            $methods['hostkey'] = self::HOSTKEY_ALGORITHMS;
        }
        return $methods;
    }

    /**
     * Canonical fingerprint form: uppercase hex, no colon/space separators.
     *
     * @param string $raw
     *
     * @return string
     */
    public static function normalizeFingerprint(string $raw): string
    {
        return strtoupper(str_replace([':', ' '], '', $raw));
    }

    /**
     * Extract the peer fingerprint from a live connection and apply the policy.
     *
     * @param resource|false $connection result of a successful ssh2_connect()
     * @param string         $host
     * @param int            $port
     *
     * @return string One of: expected-map | known-host | accept-new | unverified
     *
     * @throws \RuntimeException when the observed key cannot be obtained or fails the policy.
     */
    public static function verify($connection, string $host, int $port): string
    {
        $raw = ssh2_fingerprint($connection, SSH2_FINGERPRINT_MD5 | SSH2_FINGERPRINT_HEX);
        return self::checkFingerprint($host, $port, self::normalizeFingerprint((string) $raw));
    }

    /**
     * Pure policy step (the test seam — no connection needed).
     *
     * @param string $host
     * @param int    $port
     * @param string $observed normalized or raw MD5-hex fingerprint from the peer
     *
     * @return string verdict: expected-map | known-host | accept-new | unverified
     *
     * @throws \RuntimeException on any mismatch, or when the fingerprint is missing.
     */
    public static function checkFingerprint(string $host, int $port, string $observed): string
    {
        $observed = self::normalizeFingerprint($observed);
        if ($observed === '') {
            throw new \RuntimeException("relay host key for {$host}:{$port} could not be obtained — refusing to proceed unverified.");
        }
        $key      = $host . ':' . $port;
        $expected = self::expectedMap()[$key] ?? null;
        if ($expected !== null) {
            if (!hash_equals(self::normalizeFingerprint($expected), $observed)) {
                throw new \RuntimeException("SSH host key MISMATCH for {$key}: configured fingerprint is " . self::normalizeFingerprint($expected) . ", peer presented {$observed} — relay identity cannot be trusted.");
            }
            return 'expected-map';
        }
        $file = self::storePath();
        if ($file === null || $file === '') {
            self::log('warning', "relay host key policy for {$key}: no RELAY_SSH_HOST_FINGERPRINTS entry and no SSH_RELAY_KNOWN_HOSTS store — key {$observed} accepted UNVERIFIED (bootstrap the seed before trusting this relay)");
            return 'unverified';
        }
        $stored = self::lookup($file, $key);
        if ($stored === null) {
            if (!self::record($file, $key, $observed)) {
                self::log('error', "relay host key policy for {$key}: accept-new record could not be written to {$file} — key passed this once but was NOT persisted");
                return 'unverified';
            }
            self::log('warning', "accept-new: recorded first-seen host key for {$key} ({$observed}) — verify it out-of-band");
            return 'accept-new';
        }
        if (!hash_equals($stored, $observed)) {
            throw new \RuntimeException("SSH host key CHANGED for {$key}: known_hosts store says {$stored}, peer presented {$observed} — possible man-in-the-middle; refusing connection.");
        }
        return 'known-host';
    }

    /**
     * Configured known_hosts path: override static first, then the estate constant.
     */
    private static function storePath(): ?string
    {
        if (self::$knownHostsFile !== null) {
            return self::$knownHostsFile;
        }
        return defined('SSH_RELAY_KNOWN_HOSTS') ? (string) SSH_RELAY_KNOWN_HOSTS : null;
    }

    /**
     * Configured expected-fingerprint map: override static first, then the estate constant.
     *
     * @return array<string, string>
     */
    private static function expectedMap(): array
    {
        if (self::$expectedFingerprints !== null) {
            return self::$expectedFingerprints;
        }
        return defined('RELAY_SSH_HOST_FINGERPRINTS') && is_array(RELAY_SSH_HOST_FINGERPRINTS) ? RELAY_SSH_HOST_FINGERPRINTS : [];
    }

    /**
     * Last matching entry wins (a re-seed appended later supersedes older lines).
     */
    private static function lookup(string $file, string $key): ?string
    {
        $body = @file_get_contents($file);
        if ($body === false) {
            return null;
        }
        $found = null;
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if ($line === '' || strncmp($line, '#', 1) === 0) {
                continue;
            }
            [$storedKey, $storedFp] = array_pad(preg_split('/\s+/', $line), 2, '');
            if ($storedKey === $key && $storedFp !== '') {
                $found = self::normalizeFingerprint($storedFp);
            }
        }
        return $found;
    }

    /**
     * Append one `key fingerprint` line under an exclusive lock (web, queue
     * and CLI lanes share this store concurrently).
     */
    private static function record(string $file, string $key, string $fingerprint): bool
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }
        $fh = @fopen($file, 'ab');
        if ($fh === false) {
            return false;
        }
        if (!@flock($fh, LOCK_EX)) {
            fclose($fh);
            return false;
        }
        $written = fwrite($fh, $key . ' ' . $fingerprint . "\n");
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        return $written !== false;
    }

    /**
     * Log through the estate logger when present; silent standalone (library
     * may run outside MyAdmin).
     */
    private static function log(string $level, string $message): void
    {
        if (function_exists('myadmin_log')) {
            \myadmin_log('ssh', $level, 'relay host-key policy: ' . $message, __LINE__, __FILE__);
        }
    }
}
