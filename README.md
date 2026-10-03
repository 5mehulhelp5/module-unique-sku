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

The lock name is the first 32 hex characters of `sha256(mb_strtolower(trim($sku)))`,
matching both the case-insensitive collation of the `sku` column and
`ProductRepository::prepareSku()`, so two spellings the database treats as one row also
take one lock. That normalization is the one thing Adobe's own lock does not do — see
[Upstream](#upstream-acsd-64118-and-what-it-still-leaves-open).

## Measured, 2.4.8-p5

Two parallel `POST /V1/products` with the identical SKU:

| Installed | Result |
|---|---|
| nothing (stock Magento) | `200` + `200`, **two rows**, and every later write for that SKU may fail permanently |
| constraint only | `200` + `400 Unique constraint violation found`, **one row**, later writes fine |
| constraint + lock | `200` + `200` **with the same `id`**, **one row** — the second call is an update |

Five further repository-level races with both layers installed: one row, five times out
of five.

## Upstream: ACSD-64118, and what it still leaves open

Adobe shipped a lock of its own. **ACSD-64118**, in Quality Patches Tool 1.1.65, adds
`Magento\Catalog\Model\ProductMutex` and an `aroundSave` plugin,
`Magento\Catalog\Plugin\ProductRepositorySaveOperationSynchronizer`, registered in
`Magento_Catalog`'s `etc/di.xml` as `add_mutex_to_save_operation`. It is the same shape
as the plugin here: one `LockManagerInterface` lock per SKU, held across the whole save.
It is **in 2.4.9 core**, and a patch for older releases — `patches-info.json` maps the
one ticket to three files:

| Version range | Patch file |
|---|---|
| `>=2.4.4 <2.4.7` | `os/ACSD-64118_2.4.5-p7.patch` |
| `>=2.4.7 <2.4.7-p10` | `os/ACP2E-3976_2.4.7.patch` (requires ACSD-55100, ACSD-64178) |
| `>=2.4.8 <2.4.8-p5` | `os/ACP2E-3988_2.4.8.patch` |

Three things survive it.

**The constraint.** 2.4.9's `catalog_product_entity` still carries
`<index referenceId="CATALOG_PRODUCT_ENTITY_SKU" indexType="btree">`. Adobe closed the
window; the database still has no opinion about a duplicate SKU. That half of this
module is untouched by the patch, on every version and both editions.

**The lock name is the raw SKU.** `ProductMutex` locks on `'product_mutex_' . $sku`.
`catalog_product_entity.sku` is `utf8mb4_general_ci`, so `abc-1` and `ABC-1` are one
row — but `GET_LOCK` compares names case- and byte-sensitively, so they are two locks.
Measured on MariaDB 10.6, while `brocode_case_test` was held:
`IS_USED_LOCK('brocode_case_test')` returned the connection id,
`IS_USED_LOCK('BROCODE_CASE_TEST')` and `IS_USED_LOCK('brocode_case_test ')` both
returned `NULL`. Two concurrent creates that differ only in case or surrounding
whitespace pass Adobe's mutex side by side and still produce two rows. The lock here
hashes `mb_strtolower(trim($sku))` — what `ProductRepository::prepareSku()` itself
does — so those spellings contend for one lock.

**The timeout is fixed at 60 seconds**, and the failure is
`CouldNotSaveException('The product was unable to be saved. Please try again.')` — no
SKU, no cause, nothing for the caller to act on. Sixty seconds is a long time to hold a
PHP-FPM worker for a writer that may already be gone. This module waits 10 by default,
as a DI argument, and names the SKU and the timeout.

### The version that has neither

On **2.4.8-p5** the patch is out of range (`>=2.4.8 <2.4.8-p5`) and
`magento/module-catalog` `104.0.8-p5` ships no `ProductMutex` — the string does not
occur anywhere in `vendor/`. Which is what the fourteen races above found. Before
assuming an install is covered, ask it:

```bash
ddev exec ./vendor/bin/magento-patches status | grep 64118
```

## Tests

```bash
# unit - the lock contract, including every SKU spelling that must share one lock
ddev exec vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist \
  app/code/BroCode/UniqueSku/Test/Unit

# integration - the constraint, the collation, and the database rejecting a duplicate
ddev exec vendor/bin/phpunit -c dev/tests/integration/phpunit.xml.dist \
  /var/www/html/app/code/BroCode/UniqueSku/Test/Integration
```

`Test/Unit/Plugin/LockSkuDuringSaveTest.php` covers the lock being held across the save
and released afterwards, released again when the save throws, never released when it was
never taken, a nested save of the same SKU taking it only once, an empty SKU passing
straight through — and the spellings that must contend for one lock: identical, different
case, mixed case, leading whitespace, trailing whitespace, and tab-plus-case at once.
`Test/Integration/UniqueSkuTest.php` proves the same normalization against a real
database: a differently cased SKU updates the same product rather than creating a second.

12 unit tests, 3 integration tests, green on 2.4.8-p5. The race itself needs two
processes and lives in the reproducer above, not in PHPUnit.

## Compatibility

**Adobe Commerce with Content Staging is not supported.** `Magento_Staging` replaces the
primary key of `catalog_product_entity` with `row_id` and legitimately stores several
rows per SKU, scoped by `created_in` / `updated_in`. A unique key on `sku` is wrong
there, and that is exactly why the upstream PR could not be merged. On Commerce, install
the module with the schema part removed and keep only the lock, or do not install it.

The lock alone is safe on every edition — take `Plugin/` and `etc/di.xml` and drop
`etc/db_schema.xml` if that is what you need.

### 2.4.9, or an install already carrying ACSD-64118

Both halves still install, and both still do something. Adobe's
`add_mutex_to_save_operation` and this module's `brocode_unique_sku_lock_during_save`
are both `aroundSave` plugins on `ProductRepositoryInterface`; Adobe's runs at the
default sort order and wraps this one. Each save therefore takes two named locks and
releases both. There is no deadlock — the order is fixed and the names never collide —
and the nesting runs the right way round: the two spellings that pass Adobe's raw-SKU
lock side by side meet on the normalized lock inside it.

The cost is one extra `GET_LOCK` round trip per product save. If that is not worth the
case-variant coverage for your feed, keep the constraint and turn the plugin off — see
[Configuration](#configuration).

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

Adobe's own lock, `ProductMutex` (ACSD-64118, and 2.4.9 core), arrived after this
module and independently of it. Where it applies it does most of the same job; the
differences that remain are in [Upstream](#upstream-acsd-64118-and-what-it-still-leaves-open).

## Licence

MIT — see [LICENSE](LICENSE).

---

Docs, background and related modules: [brocode.at](https://brocode.at/modules/module-unique-sku/)
