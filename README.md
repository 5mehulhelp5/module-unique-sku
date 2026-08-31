# BroCode_UniqueSku

Makes `catalog_product_entity.sku` actually unique on Magento 2 **Open Source**, and
serialises concurrent saves of one SKU so two parallel API creates can no longer
produce two products.

> ⚠️ **Do not install on Adobe Commerce with Content Staging.** See [Compatibility](#compatibility).

## The problem

`POST /V1/products` is bound to `ProductRepositoryInterface::save()`, which decides
"create or update" with an **unlocked** `SELECT entity_id FROM catalog_product_entity
WHERE sku = ?` and only then inserts. `catalog_product_entity.sku` carries a plain
non-unique btree index, so nothing below that check rejects a second row.

Two requests that overlap inside that window therefore both succeed. Measured on
2.4.8-p5, fourteen races — eight repository-level, six over REST — produced **two rows
on one SKU every single time**, with all twenty-eight calls returning `200`.

Afterwards, exactly one of the two owns the product's `url_rewrite` row, because
`url_rewrite (request_path, store_id)` *is* a real unique constraint. A SKU always
resolves to the **lowest** `entity_id`, so:

- if the higher row won the rewrite, every later write for that SKU fails forever with
  `400 URL key for specified store already exists.` — ten of the fourteen;
- if the lower row won, writes keep working and you are left with a silent orphan
  product on a live SKU — the other four.

## What this module does

**1. The unique constraint** (`etc/db_schema.xml`) — the change proposed upstream by
[@stormbyte](https://github.com/stormbyte) in
[magento/magento2#33191](https://github.com/magento/magento2/pull/33191), which fixes
[magento/magento2#31125](https://github.com/magento/magento2/issues/31125). That PR
replaced the non-unique btree index on `sku` with a unique constraint of the same name.
It was closed unmerged on 2022-10-25 after the Content Staging objection was raised and
never answered; the issue is still open, confirmed, and reproducible on `2.4-develop`.

This module carries that change verbatim, including the original `referenceId`
`CATALOG_PRODUCT_ENTITY_SKU`, expressed from the outside: a third-party module cannot
delete another module's `<index>` node, so it declares the same node `disabled="true"`
and adds the `<constraint>`. The `1061 Duplicate key name` the PR author reported in
2021 **does not occur on 2.4.8-p5** — the declarative differ drops the index before
adding the constraint. Applied and verified on a 2.4.8-p5 install.

**2. The lock** (`Plugin/LockSkuDuringSave.php`) — an around plugin on
`ProductRepositoryInterface::save()` that holds a per-SKU named lock
(`Magento\Framework\Lock\LockManagerInterface`) across the whole call, from the
existence check to the commit. The constraint turns the losing request into a clean
failure; the lock removes the loser entirely, so the second request becomes the update
it was always meant to be.

The lock name is `md5(mb_strtolower(trim($sku)))`, matching both the case-insensitive
collation of the `sku` column and `ProductRepository::prepareSku()`, so two spellings
the database treats as one row also take one lock.

## Measured, 2.4.8-p5

Two parallel `POST /V1/products` with the identical SKU:

| Installed | Result |
|---|---|
| nothing (stock Magento) | `200` + `200`, **two rows**, and every later write for that SKU may fail permanently |
| constraint only | `200` + `400 Unique constraint violation found`, **one row**, later writes fine |
| constraint + lock | `200` + `200` **with the same `id`**, **one row** — the second call is an update |

Five further repository-level races with both layers installed: one row, five times out
of five.

## Compatibility

**Adobe Commerce with Content Staging is not supported.** `Magento_Staging` replaces the
primary key of `catalog_product_entity` with `row_id` and legitimately stores several
rows per SKU, scoped by `created_in` / `updated_in`. A unique key on `sku` is wrong
there, and that is exactly why the upstream PR could not be merged. On Commerce, install
the module with the schema part removed and keep only the lock, or do not install it.

The lock alone is safe on every edition — take `Plugin/` and `etc/di.xml` and drop
`etc/db_schema.xml` if that is what you need.

## Before you install

`setup:upgrade` will fail with `1062 Duplicate entry` if the catalog already contains
duplicated SKUs. Check first, from a console:

```sql
SELECT sku, COUNT(*) c, GROUP_CONCAT(entity_id)
FROM catalog_product_entity GROUP BY sku HAVING c > 1;
```

Resolve every row it returns before installing. `DELETE /V1/products/{sku}` removes the
**lowest** duplicate — `deleteById()` takes a SKU, not an entity id — which is the right
row when the higher one owns the `url_rewrite`, and the wrong one when it does not.
Check which entity owns the rewrite before firing it:

```sql
SELECT entity_id, request_path, store_id FROM url_rewrite
WHERE entity_type = 'product' AND entity_id IN (<the ids above>);
```

## Install

```bash
composer require brocode/module-unique-sku
bin/magento module:enable BroCode_UniqueSku
bin/magento setup:upgrade
bin/magento setup:di:compile
```

## Configuration

The lock wait defaults to 10 seconds and is a DI argument, never an infinite wait — that
would turn one stuck writer into a stuck PHP-FPM pool. A request that cannot get the lock
in time gets `CouldNotSaveException` naming the SKU and telling the caller to retry.

```xml
<type name="BroCode\UniqueSku\Plugin\LockSkuDuringSave">
    <arguments>
        <argument name="lockTimeout" xsi:type="number">30</argument>
    </arguments>
</type>
```

To turn the lock off without removing the module:

```xml
<type name="Magento\Catalog\Api\ProductRepositoryInterface">
    <plugin name="brocode_unique_sku_lock_during_save" disabled="true"/>
</type>
```

The lock provider follows `app/etc/env.php` (`lock/provider`, default `db`). The `db`
provider uses MySQL `GET_LOCK`, so the lock is shared across every application node
pointing at the same database.

## Deliberate non-goals

- **The `Unique constraint violation found` message is left alone.** With the lock
  installed that path is nearly unreachable, and rewriting a framework-level message for
  one table is a bigger blast radius than the improvement is worth.
- **No admin UI, no config section, no console command.** The detection query above is
  three seconds of SQL and needs no code.

## Credit

The schema change is [@stormbyte](https://github.com/stormbyte)'s, from
[magento/magento2#33191](https://github.com/magento/magento2/pull/33191). The issue it
fixes, [magento/magento2#31125](https://github.com/magento/magento2/issues/31125), was
reported by the same author in December 2020 and is still open. This module packages
that work so it can be installed today, and adds the lock the PR did not carry.

## Licence

MIT — see [LICENSE](LICENSE).
