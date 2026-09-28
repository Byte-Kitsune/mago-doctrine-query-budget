# Mago Doctrine Query Budget

[![Tests](https://github.com/Byte-Kitsune/mago-doctrine-query-budget/actions/workflows/check.yml/badge.svg?branch=main)](https://github.com/Byte-Kitsune/mago-doctrine-query-budget/actions/workflows/check.yml)
[![Security Check](https://github.com/Byte-Kitsune/mago-doctrine-query-budget/actions/workflows/security.yml/badge.svg?branch=main)](https://github.com/Byte-Kitsune/mago-doctrine-query-budget/actions/workflows/security.yml)

Conservative database-statement estimates for PHP entrypoints in [Mago](https://mago.carthage.software/1.50.0/en/). This beta is an **Analyzer** plugin. It follows modeled calls from controllers and commands, reports lower and upper bounds, and marks unknown paths explicitly. It never executes PHP or connects to a database.

The [runnable report example](examples/README.md) shows why two harmless-looking service calls can cross a query budget, and why an ORM result cannot always get a finite static upper bound.

## Install and run

Requires PHP 8.2+ and Mago 1.50. Pin the beta in your project:

```sh
composer require --dev carthage-software/mago:1.50.0 byte-kitsune/mago-doctrine-query-budget:0.1.0-beta.8
```

Add an extension host to `mago.toml`:

```toml
[extension-hosts.php]
command = ["php", ".mago/extensions.php"]
```

Create `.mago/extensions.php`:

```php
<?php

use ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension;
use Mago\Sdk\Worker;

require dirname(__DIR__) . '/vendor/autoload.php';

(new Worker(QueryBudgetExtension::create(
    entrypointSuffixes: ['Controller.php', 'Command.php'],
    warningThreshold: 10,
    errorThreshold: 25,
)))->run();
```

Run `vendor/bin/mago analyze`. A selector must be a PHP filename suffix. Both the filename and class name must end with its stem. Controller methods are public actions; for `Command.php`, only public `execute()` and `__invoke()` are entrypoints. Set thresholds to positive integers with `errorThreshold >= warningThreshold`.

If your project uses [mago-symfony-wiring](https://github.com/Byte-Kitsune/mago-symfony-wiring), pass proven dev type bindings so the call graph can follow injected interfaces:

```php
use ByteKitsune\MagoSymfonyWiring\ServiceConfigLoader;

$root = dirname(__DIR__);
$map = (new ServiceConfigLoader($root, [
    'config/services.yaml',
    'config/services.dev.yaml',
]))->load();

$queryBudget = QueryBudgetExtension::create(
    classBindings: $map->classBindings(),
    warningThreshold: 10,
    errorThreshold: 25,
);
(new Worker($queryBudget))->run();
```

Use one `Worker` for all your installed extensions if they share a host. Pass `classBindings()` to this extension explicitly: installing the Symfony extension beside it does not transfer bindings automatically. If a budget stops at an injected interface, inspect the service map's `incomplete` reasons and the exact binding key. Symfony bindings resolve injected service types; they do not resolve free PHP function calls.

## What the estimate means

The model counts selected Doctrine DBAL `Connection`, `Statement`, and DBAL query-builder execution methods as one statement each. Constructing a query is not execution. ORM `Query` executions and standard repository methods are recognized, but cache, hydration and lazy-loading effects leave their upper bound unknown. Calls to project-defined top-level functions and unadapted trait methods are followed through the same source snapshot. `self::` and `parent::` resolve against source-visible classes. Trait adaptations and late-bound `static::` remain incomplete. Conditional early returns keep a finite upper bound when all reachable calls are modeled. `try/catch/finally` bodies are followed with a conservative upper bound: a catch may run after part of the try body, and finally runs afterward. A source-visible non-Doctrine search method inside a try block can therefore be proven query-free.

Explicit global calls to `\mb_trim`, `\max`, and `\min` are treated as query-free only for literal scalar arguments or variables that Mago proves scalar. Unqualified namespaced calls may resolve to application functions outside the analyzed snapshot, so they remain incomplete unless the function is source-visible. Callback functions such as `array_map` and `array_filter`, external collection methods, and response serialization are not assumed query-free. Their callbacks or lazy work can reach Doctrine; unresolved calls keep their names in the evidence. Unknown receivers, dynamic calls, other unsupported control flow and unbounded loops also remain incomplete. Static estimates are not measured SQL counts.

Findings include:

| Code | Meaning |
| --- | --- |
| `query-budget-exceeded` | The bound exceeds a configured threshold. A guaranteed lower bound above the error threshold is an error; a possible upper bound above the warning threshold is a warning. |
| `query-budget-incomplete` | A finite upper bound could not be proven. |
| `recursive-call` | A reached recursive cycle has no proven breaker. |

Thresholds use **greater than**, so exactly 10 does not exceed a warning threshold of 10. The `query-budget-evidence` note contains `entrypoint`, `lower_bound`, nullable `upper_bound`, `unknown` reasons and `cycles`. A narrow guarded decreasing self-call can avoid a recursion error, but does not by itself prove a finite query bound.

The source model accepts at most 25,000 PHP files and 512 MiB of PHP source bytes. Each file is limited to 1 MiB; per-entrypoint call work and depth are also bounded. Exceeding a project limit fails the analysis instead of publishing a partial estimate. Benchmark and review incomplete findings on your own codebase before making this a required CI gate.

After a PHP source run, the Analyzer emits one `analysis-attestation` note with a bounded `extension-attestation` payload. It identifies the version, `query_budget` capability and source-file count. `complete` confirms that the source model finished; an unknown estimate is reported separately as `query-budget-incomplete` and follows the configured warning/error threshold. A gate that requires query-budget coverage should check the attestation even when no threshold is exceeded.

## Develop

```sh
composer install
sh tests/smoke.sh
sh tests/scale-smoke.sh
```

The [fictional fixture](tests/corpus) covers bounded DBAL calls, ORM uncertainty and recursion. The scale check generates more than 20,000 PHP files and confirms an explicit failure above the 25,000-file limit. Licensed under [MIT](LICENSE).
