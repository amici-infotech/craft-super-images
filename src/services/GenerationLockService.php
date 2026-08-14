<?php

namespace amici\SuperImages\services;

use Craft;
use yii\base\Component;

/**
 * Single-flight locks for generation identities.
 */
final class GenerationLockService extends Component
{
    public function acquire(string $identity, int $ttl = 60): bool
    {
        $key = $this->lockKey($identity);

        return Craft::$app->getCache()->add($key, 1, max(1, $ttl));
    }

    public function release(string $identity): void
    {
        Craft::$app->getCache()->delete($this->lockKey($identity));
    }

    /**
     * Wait briefly for another worker, then report whether the lock is still held.
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

    private function lockKey(string $identity): string
    {
        return 'super-images:lock:' . $identity;
    }
}
