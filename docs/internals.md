# Internals

## Recovery boundary

Recovery keeps admission, dispatch, observation, supervision, worker execution, and scheduling separate. Stage 7 fairly reconsiders the active/ownership union through bounded source-scoped SQLite keyset pages and opaque cursors bound to the source and last key. SQLite transactions provide durable state transitions and bounded selection; process identity, launch receipts, fencing, deadlines, and recovery classification remain scheduler responsibilities. Terminal ownership is observed independently of current configuration, and only aggregate closure permits atomic redelivery and generation rotation. The original revision, deadline, fence, generation, and at-least-once guarantees remain. Historical configuration loading, distributed coordination, exactly-once effects, provider effects, and framework adapters are not package guarantees.

The default local execution ledger is SQLite-backed through optional `yiisoft/db`, `yiisoft/db-sqlite`, `pdo_sqlite`, and `mbstring` dependencies. Applications that provide both custom command and query ledger ports do not need the default adapter.

## Unit testing

The package is tested with [PHPUnit](https://phpunit.de/). To run tests:

```shell
./vendor/bin/phpunit
```

## Mutation testing

The package tests are checked with the [Infection](https://infection.github.io/) mutation framework. To run it:

```shell
./vendor/bin/infection
```

## Static analysis

The code is statically analyzed with [Psalm](https://psalm.dev/). To run static analysis:

```shell
./vendor/bin/psalm
```

## Code style

Use [Rector](https://github.com/rectorphp/rector) to make codebase follow some specific rules or
use either newest or any specific version of PHP:

```shell
./vendor/bin/rector
```

## Dependencies

Use [Composer Dependency Analyser](https://github.com/shipmonk-rnd/composer-dependency-analyser) to detect unknown,
shadow, and unused [Composer](https://getcomposer.org) dependencies:

```shell
./vendor/bin/composer-dependency-analyser
```
