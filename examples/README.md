# Why a query budget helps

In [the report example](report), `ReportController::index()` calls the same service twice. That service executes two DBAL statements per call, so the modeled action has a finite bound of **four** statements. A warning threshold of three catches it even though the controller contains no SQL. `OrmController::index()` executes an ORM query; lazy loading and hydration mean the extension cannot prove a finite upper bound, so it reports `query-budget-incomplete` instead of guessing a count.

Run the example from this repository after `composer install`:

```sh
cd examples/report
../../vendor/bin/mago analyze --reporting-format json --minimum-report-level note
```

Look for `query-budget-exceeded`, `query-budget-incomplete` and `analysis-attestation`. Both findings are intentional warnings; whether warnings fail the command depends on your Mago fail level. The tiny Doctrine classes are syntax stand-ins for analysis; this command does not connect to a database or measure runtime traffic. The worker supplies one explicit interface-to-class binding so the example focuses on query estimation. In a Symfony project, pass proven bindings from `mago-symfony-wiring` instead.
