<?php
/**
 * Copyright (C) 2026 Benjamin Rosenberger <bensch.rosenberger@gmail.com>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * @copyright 2026 Benjamin Rosenberger
 * @author bensch.rosenberger@gmail.com
 * @license MIT
 * @link https://brocode.at
 */

declare(strict_types=1);

namespace BroCode\UniqueSku\Plugin;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Lock\LockManagerInterface;

/**
 * Serialises concurrent saves of one SKU.
 *
 * ProductRepository::save() decides "create or update" with an unlocked
 * `SELECT entity_id FROM catalog_product_entity WHERE sku = ?` and only then
 * inserts. Two requests that both enter that window see no row and both create
 * a product. The unique constraint this module also ships turns the loser into
 * a clean failure; this lock removes the loser entirely, so the second request
 * becomes the update it was always meant to be.
 *
 * AROUND PLUGIN, deliberately: the lock has to span the whole save, from the
 * existence check to the commit, and no before/after pair can express that.
 * `save()` is an admin- and API-side write path, not checkout, cart or product
 * listing rendering, so the around-plugin cost is not on a hot storefront path.
 *
 * Contention is per SKU: two feeds writing different SKUs never wait on each
 * other, and only a feed that sends one SKU twice at once pays anything at all.
 */
class LockSkuDuringSave
{
    /**
     * Namespace for the lock name, so it cannot collide with another consumer's lock.
     */
    private const LOCK_PREFIX = 'brocode_unique_sku:';

    /**
     * @var \Magento\Framework\Lock\LockManagerInterface
     */
    private $lockManager;

    /**
     * Seconds to wait for the lock before giving up. Never negative: an infinite
     * wait would turn a stuck writer into a stuck PHP-FPM pool.
     *
     * @var int
     */
    private $lockTimeout;

    /**
     * Lock names this process already holds, so a nested save of the same SKU
     * does not release a lock its caller is still relying on.
     *
     * @var array<string, int>
     */
    private $heldLocks = [];

    /**
     * @param \Magento\Framework\Lock\LockManagerInterface $lockManager
     * @param int $lockTimeout
     */
    public function __construct(
        LockManagerInterface $lockManager,
        int $lockTimeout = 10
    ) {
        $this->lockManager = $lockManager;
        $this->lockTimeout = max(1, $lockTimeout);
    }

    /**
     * Hold a per-SKU lock for the duration of the wrapped save.
     *
     * @param \Magento\Catalog\Api\ProductRepositoryInterface $subject
     * @param callable $proceed
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param bool $saveOptions
     * @return \Magento\Catalog\Api\Data\ProductInterface
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function aroundSave(
        ProductRepositoryInterface $subject,
        callable $proceed,
        ProductInterface $product,
        $saveOptions = false
    ) {
        $sku = trim((string) $product->getSku());
        if ($sku === '') {
            // An empty sku is the repository's own error to raise, not ours.
            return $proceed($product, $saveOptions);
        }

        $lockName = $this->lockName($sku);
        if (isset($this->heldLocks[$lockName])) {
            return $proceed($product, $saveOptions);
        }

        if (!$this->lockManager->lock($lockName, $this->lockTimeout)) {
            throw new CouldNotSaveException(
                __(
                    'Could not acquire the write lock for SKU "%1" within %2 seconds.'
                    . ' Another process is saving the same SKU. Retry this call.',
                    $sku,
                    $this->lockTimeout
                )
            );
        }

        $this->heldLocks[$lockName] = 1;
        try {
            return $proceed($product, $saveOptions);
        } finally {
            unset($this->heldLocks[$lockName]);
            $this->lockManager->unlock($lockName);
        }
    }

    /**
     * Build the lock name for a SKU.
     *
     * Lowercased and trimmed to match both the case-insensitive collation of
     * `catalog_product_entity.sku` and ProductRepository's own `prepareSku()`,
     * so two spellings the database treats as one row also take one lock.
     * Hashed, and truncated to 32 hex characters, because a lock name is
     * identifier-length bound and a SKU is not.
     *
     * @param string $sku
     * @return string
     */
    private function lockName(string $sku): string
    {
        return self::LOCK_PREFIX . substr(hash('sha256', mb_strtolower($sku)), 0, 32);
    }
}
