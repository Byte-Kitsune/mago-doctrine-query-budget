# Mago Doctrine Query Budget

**Beta: 0.1.0-beta.1.** The supported Doctrine calls and diagnostic schema may
change before a stable release; pin the exact prerelease version in consumers.

A conservative Mago Analyzer Plugin for database statement estimates per
Controller action or Command invocation. It reports lower/upper bounds and
explicit unknowns, rather than claiming exact runtime SQL counts. It never
executes PHP or connects to a database.

Install `byte-kitsune/mago-doctrine-query-budget` with Mago 1.50 and register
the package factory in `.mago/extensions.php`:

```php
use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension;
use Mago\Sdk\Worker;

require dirname(__DIR__) . '/vendor/autoload.php';
(new Worker(QueryBudgetExtension::create(
    warningThreshold: 10,
    errorThreshold: 25,
)))->run();
```

The consumer owns `[extension-hosts.php] command = ["php",
".mago/extensions.php"]` in `mago.toml`. A Symfony project can pass proven
class bindings from `mago-symfony-wiring`:

```php
$map = (new ServiceConfigLoader($root, ['config/services.yaml', 'config/services.dev.yaml']))->load();
$query = QueryBudgetExtension::create(classBindings: $map->classBindings());
```

The initial adapter recognizes selected Doctrine DBAL/ORM execution APIs and
does not count query construction. Unknown dynamic calls, unbounded loops,
cache/hydration effects, and unsupported repository behavior remain incomplete.
Direct and indirect recursion without a proven breaker is an error. A narrow
decreasing-argument guard avoids the recursion error but still leaves the query
upper bound unknown unless the invocation bound is known.
