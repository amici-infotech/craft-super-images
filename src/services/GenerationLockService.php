<?php
/**
 * Single-flight locks for generation identities.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\services;

use Craft;
use yii\base\Component;

/**
 * Generation Lock Service
 *
 * Provides cache-based single-flight locks so concurrent runtime or queue workers
 * do not regenerate the same derivative identity simultaneously.
 */
final class GenerationLockService extends Component
{
    /**
     * Attempt to acquire an exclusive lock for a generation identity.
     *
     * @param string $identity The generation identity hash.
     * @param int $ttl Lock time-to-live in seconds (default 60).
     *
     * @return bool True when the lock was acquired; false when already held.
     */
    public function acquire(string $identity, int $ttl = 60): bool
    {
        $key = $this->lockKey($identity);

        return Craft::$app->getCache()->add($key, 1, max(1, $ttl));
    }

    /**
     * Release the lock for a generation identity.
     *
     * @param string $identity The generation identity hash.
     *
     * @return void
     */
    public function release(string $identity): void
    {
        Craft::$app->getCache()->delete($this->lockKey($identity));
    }

    /**
     * Wait briefly for another worker, then report whether the lock is still held.
     *
     * Used when acquire() fails so the caller can recheck storage before giving up.
     *
     * @param string $identity The generation identity hash.
     * @param int $waitMs Milliseconds to sleep between poll attempts.
     * @param int $attempts Maximum number of poll attempts.
     *
     * @return bool True when the lock is still held after waiting; false when released.
     */
    public function waitAndCheck(string $identity, int $waitMs = 250, int $attempts = 8): bool
    {
        $key = $this->lockKey($identity);

        for ($i = 0; $i < $attempts; $i++) {
            if (!Craft::$app->getCache()->exists($key)) {
                return false;
            }

            usleep(max(1, $waitMs) * 1000);
        }

        return Craft::$app->getCache()->exists($key);
    }

    /**
     * Build the cache key for a generation identity lock.
     *
     * @param string $identity The generation identity hash.
     *
     * @return string The Craft cache key string.
     */
    private function lockKey(string $identity): string
    {
        return 'super-images:lock:' . $identity;
    }
}
