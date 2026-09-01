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

namespace BroCode\UniqueSku\Test\Integration;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ProductFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\Registry;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Pins the two facts the module rests on, against a real database.
 *
 * The lock contract itself is unit-tested in ../Unit/Plugin/LockSkuDuringSaveTest.php;
 * the concurrency it defends against needs two processes and lives in the reproducer
 * documented in the README.
 *
 * @magentoDbIsolation disabled
 */
class UniqueSkuTest extends TestCase
{
    private const SKU = 'brocode-unique-sku-test';

    /**
     * @var \Magento\Catalog\Api\ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var \Magento\Catalog\Model\ProductFactory
     */
    private $productFactory;

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    private $resource;

    /**
     * @var \Magento\Framework\Registry
     */
    private $registry;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        // A fresh repository per test: its local sku cache outlives a test that
        // deletes rows straight out of the table (@magentoDbIsolation disabled).
        $this->productRepository = $objectManager->create(ProductRepositoryInterface::class);
        $this->productFactory = $objectManager->get(ProductFactory::class);
        $this->resource = $objectManager->get(ResourceConnection::class);
        $this->registry = $objectManager->get(Registry::class);
        $this->removeTestProduct();
    }

    protected function tearDown(): void
    {
        $this->removeTestProduct();
    }

    /**
     * The schema change from magento/magento2#33191, applied.
     */
    public function testTheSkuColumnCarriesAUniqueConstraint(): void
    {
        $connection = $this->resource->getConnection();
        $indexes = $connection->getIndexList($this->resource->getTableName('catalog_product_entity'));

        $this->assertArrayHasKey('CATALOG_PRODUCT_ENTITY_SKU', $indexes);
        $this->assertSame(
            'unique',
            $indexes['CATALOG_PRODUCT_ENTITY_SKU']['INDEX_TYPE'],
            'BroCode_UniqueSku must have replaced the btree index with a unique constraint.'
        );
        $this->assertSame(['sku'], $indexes['CATALOG_PRODUCT_ENTITY_SKU']['COLUMNS_LIST']);
    }

    /**
     * Why the lock name lower-cases: the catalog resolves these to one product.
     */
    public function testTwoSkuSpellingsResolveToOneProduct(): void
    {
        $first = $this->saveProduct(self::SKU, 'BroCode Unique Sku Upper');
        $second = $this->saveProduct(strtoupper(self::SKU), 'BroCode Unique Sku Mixed');

        $this->assertSame(
            (int) $first->getId(),
            (int) $second->getId(),
            'A differently cased sku must update the same product, not create a second one.'
        );
        $this->assertCount(1, $this->entityIdsForSku(self::SKU));
    }

    /**
     * The backstop: what the constraint does when the lock is not there to prevent it.
     */
    public function testASecondRowWithAnExistingSkuIsRejectedByTheDatabase(): void
    {
        $this->saveProduct(self::SKU, 'BroCode Unique Sku');

        $connection = $this->resource->getConnection();
        /** @var \Magento\Catalog\Model\Product $prototype */
        $prototype = $this->productFactory->create();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->expectException(DuplicateException::class);
        $connection->insert($this->resource->getTableName('catalog_product_entity'), [
            'attribute_set_id' => (int) $prototype->getDefaultAttributeSetId(),
            'type_id' => Type::TYPE_SIMPLE,
            'sku' => self::SKU,
            'has_options' => 0,
            'required_options' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function saveProduct(string $sku, string $name): Product
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $product = $this->productFactory->create();
        $product->setTypeId(Type::TYPE_SIMPLE)
            ->setAttributeSetId((int) $product->getDefaultAttributeSetId())
            ->setName($name)
            ->setSku($sku)
            ->setUrlKey(strtolower($sku))
            ->setPrice(10)
            ->setVisibility(Visibility::VISIBILITY_BOTH)
            ->setStatus(Status::STATUS_ENABLED)
            ->setWebsiteIds([1]);

        return $this->productRepository->save($product);
    }

    /**
     * @return int[]
     */
    private function entityIdsForSku(string $sku): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('catalog_product_entity'), 'entity_id')
            ->where('sku = ?', $sku)
            ->order('entity_id ASC');

        return array_map('intval', $connection->fetchCol($select));
    }

    private function removeTestProduct(): void
    {
        $ids = $this->entityIdsForSku(self::SKU);
        if ($ids === []) {
            return;
        }
        $connection = $this->resource->getConnection();
        $connection->delete(
            $this->resource->getTableName('url_rewrite'),
            $connection->quoteInto('entity_type = "product" AND entity_id IN (?)', $ids)
        );
        $connection->delete(
            $this->resource->getTableName('catalog_product_entity'),
            $connection->quoteInto('entity_id IN (?)', $ids)
        );
    }
}
