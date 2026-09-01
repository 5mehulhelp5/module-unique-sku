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

namespace BroCode\UniqueSku\Test\Unit\Plugin;

use BroCode\UniqueSku\Plugin\LockSkuDuringSave;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @see \BroCode\UniqueSku\Plugin\LockSkuDuringSave
 */
class LockSkuDuringSaveTest extends TestCase
{
    private const LOCK_TIMEOUT = 7;

    /**
     * @var \BroCode\UniqueSku\Plugin\LockSkuDuringSave
     */
    private $plugin;

    /**
     * @var \Magento\Framework\Lock\LockManagerInterface|MockObject
     */
    private $lockManager;

    /**
     * @var \Magento\Catalog\Api\ProductRepositoryInterface|MockObject
     */
    private $subject;

    /**
     * @var string[]
     */
    private $acquiredLocks = [];

    /**
     * @var string[]
     */
    private $releasedLocks = [];

    /**
     * @var bool
     */
    private $lockAcquisitionSucceeds = true;

    protected function setUp(): void
    {
        $this->acquiredLocks = [];
        $this->releasedLocks = [];
        $this->lockAcquisitionSucceeds = true;

        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->lockManager->method('lock')->willReturnCallback(
            function (string $name, int $timeout = -1): bool {
                $this->acquiredLocks[] = $name;

                return $this->lockAcquisitionSucceeds;
            }
        );
        $this->lockManager->method('unlock')->willReturnCallback(
            function (string $name): bool {
                $this->releasedLocks[] = $name;

                return true;
            }
        );

        $this->subject = $this->createMock(ProductRepositoryInterface::class);
        $this->plugin = new LockSkuDuringSave($this->lockManager, self::LOCK_TIMEOUT);
    }

    public function testHoldsALockAroundTheSaveAndReleasesIt(): void
    {
        $product = $this->productWithSku('ERP-1001');
        $order = [];

        $result = $this->plugin->aroundSave(
            $this->subject,
            function (ProductInterface $saved) use (&$order, $product) {
                $order[] = 'save';
                $this->assertSame($product, $saved);

                return $saved;
            },
            $product
        );

        $this->assertSame($product, $result);
        $this->assertSame(['save'], $order, 'The wrapped save must actually run.');
        $this->assertCount(1, $this->acquiredLocks);
        $this->assertSame($this->acquiredLocks, $this->releasedLocks);
    }

    /**
     * Spellings the catalog resolves to one product must contend for one lock.
     *
     * `catalog_product_entity.sku` is case-insensitive under the default collation
     * and `getIdBySku()` compares with `=`, so these pairs are the same product.
     *
     * @param string $firstSku
     * @param string $secondSku
     * @return void
     */
    #[DataProvider('skuSpellingsThatResolveToOneProductDataProvider')]
    public function testTakesOneLockForSkuSpellingsThatResolveToOneProduct(
        string $firstSku,
        string $secondSku
    ): void {
        $this->save($firstSku);
        $this->save($secondSku);

        $this->assertCount(2, $this->acquiredLocks);
        $this->assertSame(
            $this->acquiredLocks[0],
            $this->acquiredLocks[1],
            sprintf('"%s" and "%s" are one product and must take one lock.', $firstSku, $secondSku)
        );
    }

    /**
     * @return array<string, string[]>
     */
    public static function skuSpellingsThatResolveToOneProductDataProvider(): array
    {
        return [
            'identical' => ['ERP-1001', 'ERP-1001'],
            'different case' => ['ERP-1001', 'erp-1001'],
            'mixed case' => ['ERP-1001', 'Erp-1001'],
            'leading whitespace' => ['ERP-1001', '   ERP-1001'],
            'trailing whitespace' => ['ERP-1001', 'ERP-1001   '],
            'tab and case at once' => ['ERP-1001', "\terp-1001 "],
        ];
    }

    public function testTakesDifferentLocksForDifferentSkus(): void
    {
        $this->save('ERP-1001');
        $this->save('ERP-1002');

        $this->assertCount(2, $this->acquiredLocks);
        $this->assertNotSame($this->acquiredLocks[0], $this->acquiredLocks[1]);
    }

    public function testThrowsWithoutSavingWhenTheLockCannotBeAcquired(): void
    {
        $this->lockAcquisitionSucceeds = false;
        $saved = false;

        try {
            $this->plugin->aroundSave(
                $this->subject,
                function (ProductInterface $product) use (&$saved) {
                    $saved = true;

                    return $product;
                },
                $this->productWithSku('ERP-1001')
            );
            $this->fail('A lock that cannot be acquired must abort the save.');
        } catch (CouldNotSaveException $e) {
            $this->assertStringContainsString('ERP-1001', $e->getMessage());
            $this->assertStringContainsString((string) self::LOCK_TIMEOUT, $e->getMessage());
        }

        $this->assertFalse($saved, 'The save must not run without the lock.');
        $this->assertSame([], $this->releasedLocks, 'A lock never taken must not be released.');
    }

    public function testReleasesTheLockWhenTheSaveThrows(): void
    {
        try {
            $this->plugin->aroundSave(
                $this->subject,
                static function (): void {
                    throw new CouldNotSaveException(__('boom'));
                },
                $this->productWithSku('ERP-1001')
            );
            $this->fail('The exception from the wrapped save must not be swallowed.');
        } catch (CouldNotSaveException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertCount(1, $this->acquiredLocks);
        $this->assertSame($this->acquiredLocks, $this->releasedLocks);
    }

    /**
     * A nested save of the same SKU must not take - and above all must not release -
     * a lock its own caller is still relying on.
     */
    public function testANestedSaveOfTheSameSkuTakesTheLockOnlyOnce(): void
    {
        $product = $this->productWithSku('ERP-1001');
        $innerRan = false;

        $this->plugin->aroundSave(
            $this->subject,
            function (ProductInterface $outer) use (&$innerRan) {
                $this->plugin->aroundSave(
                    $this->subject,
                    function (ProductInterface $inner) use (&$innerRan) {
                        $innerRan = true;
                        $this->assertSame([], $this->releasedLocks, 'The outer lock must still be held.');

                        return $inner;
                    },
                    $this->productWithSku('erp-1001')
                );

                return $outer;
            },
            $product
        );

        $this->assertTrue($innerRan);
        $this->assertCount(1, $this->acquiredLocks);
        $this->assertCount(1, $this->releasedLocks);
    }

    public function testAnEmptySkuProceedsWithoutLocking(): void
    {
        $saved = false;

        $this->plugin->aroundSave(
            $this->subject,
            function (ProductInterface $product) use (&$saved) {
                $saved = true;

                return $product;
            },
            $this->productWithSku('   ')
        );

        $this->assertTrue($saved, 'The repository raises the empty-sku error itself.');
        $this->assertSame([], $this->acquiredLocks);
    }

    private function save(string $sku): void
    {
        $this->plugin->aroundSave(
            $this->subject,
            static function (ProductInterface $product) {
                return $product;
            },
            $this->productWithSku($sku)
        );
    }

    /**
     * @param string $sku
     * @return \Magento\Catalog\Api\Data\ProductInterface|MockObject
     */
    private function productWithSku(string $sku)
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getSku')->willReturn($sku);

        return $product;
    }
}
