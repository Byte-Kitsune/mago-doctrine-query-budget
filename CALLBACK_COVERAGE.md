# Callback and hook coverage

This candidate models source-visible invocation effects. A declaration, attribute or container tag is evidence of a potential hook, not proof of activation in a particular request. Query bounds describe one modeled invocation or the supported consumer's invocation range. Physical SQL requires runtime instrumentation.

## PHP invocation families

| Family | Modeled behavior | Conservative limits |
| --- | --- | --- |
| Closures, arrows, first-class/string/array callables, invokable objects | Bind targets and captured values; execute only at invocation; preserve left-to-right argument order | Mutable/reference captures, unresolved dynamic targets and arbitrary returned callables |
| `call_user_func`, `call_user_func_array` | Forward proven evaluated argument arrays and named arguments | Unpacking or unknown argument maps |
| `array_map/filter/walk/reduce`, `array_any/all/find/find_key` | Propagate callback effects and proven literal cardinalities; retain short-circuit ranges | Dynamic counts and unsupported array/value shapes |
| `array_walk_recursive` | Proven literal leaves, nested arrays and forwarded extra argument | Unknown nested values, duplicate/numeric key ambiguities and mutations |
| `usort/uasort/uksort`, callback diff/intersect variants | Follow all comparator targets, including two-comparator variants | Comparison multiplicity is not fabricated from array size |
| `preg_replace_callback`, `preg_replace_callback_array` | Follow callback/mapping targets and preserve argument evaluation effects | Match counts and result conversion remain uncertain |
| `iterator_apply/count/to_array`, generator consumption | Separate generator creation from draining and `yield from`; follow source-visible deferred bodies | Arbitrary iterator implementations, repeated consumption and unknown multiplicity |
| `Closure::fromCallable/bind/bindTo/call` | Track creation, supported binding and invocation | Dynamic scope rebinding and inaccessible targets |
| `ReflectionFunction/ReflectionMethod::invoke/invokeArgs` | Follow proven source targets and actual receivers; preserve reflected base-body selection | Dynamic reflection targets and unresolved argument arrays |
| Readonly promoted callback fields | Forward a proven callback through a fresh source object with an empty constructor | Mutable/general callback properties |
| Shutdown/error/exception/autoload registration | Recognize deferred registration without charging the callback body as immediately executed | Future activation remains incomplete |
| PHP magic and property hooks | Expose declared magic methods as separate per-invocation candidates; keep possible conversion/access effects uncertain | Native property-hook syntax, including promoted hooks, fails explicitly rather than erasing bodies |

All ordinary source-visible methods that accept callables can be followed through their bodies. Unknown external consumers retain uncertainty; arbitrary third-party invocation semantics are not assumed. A hook registration does not prove that its body ran.

## Framework declarations and contracts

Automatic discovery matches fully qualified attributes and exact framework ancestry, including import aliases and source-visible base classes/interfaces:

- Symfony event listeners/subscribers, common event constants and literal subscriber registration arrays.
- Messenger handlers/middleware; console commands and lifecycle methods.
- Validators, serializer normalizers/denormalizers, authenticators, voters and user providers.
- Form types/extensions, transformers/mappers and expression-function providers.
- Cache warmers/clearers, container compiler passes/extensions and bundle lifecycle methods.
- Twig extension provider methods and DBAL middleware.
- DoctrineBundle event/entity listener attributes, Doctrine subscribers and ORM lifecycle attributes.
- PHP-defined magic hooks such as `__invoke`, `__serialize`, `__toString`, `__get`, `__set` and `__clone`.

Discovery returns `activation: declaration-only` and `budgetScope: one-invocation`. Query Budget emits `query-budget-hook-inspection` notes even for methods outside Controller/Command filenames. `discoverFrameworkHooks: false` disables automatic candidate discovery. Explicit `hookEntrypoints: ['Namespace\\Service::method']` supports compiled registrations or custom hooks, including protected methods.

All three extensions expose `inspectFrameworkHooks($analysis)` against the exact source snapshot. Query Budget's default Rust path uses the existing native project index and does not refetch/parse every source file for hook discovery.

Symfony Wiring additionally preserves an allowlist of compiled hook tags in container reference schema 3. References without hook tags retain schema 2; the new loader accepts both. `frameworkHookRegistrations()` returns registration evidence and unresolved defaults/subscribers; `frameworkHookEntrypoints()` returns only exact method targets. Pass these targets alongside `classBindings()` and `constructorClassBindings()` to Query Budget. Arbitrary tag attributes are removed by the exporter and rejected in sanitized references.

Framework activation, async Messenger routing, service autoconfiguration, event aliases, subscriber factories, trait adaptations, complex callback registries and custom attributes can require additional evidence. They are not a blanket proof of execution or a measured zero-query budget. Dynamic methods in otherwise recognized declarations produce discovery uncertainty instead of silently falling back to a different target.

## Architecture graph

The companion graph follows literal eager callback bodies and targets (closures/arrows, first-class method references, string/array targets and fresh invokable objects). Empty/deferred registration and generator creation are distinguished from eager execution. Unsupported dynamic callbacks retain graph gaps. These edges are possible source invocation paths, not measured request traces; registration alone does not add its deferred body as an immediate path.

## Verification

- 60 callable/provenance entrypoints plus 45 PHP-consumer entrypoints compare complete PHP/Rust reports.
- 16 framework/language hook entrypoints assert exact per-invocation budgets and declaration/activation boundaries.
- Eleven eager architecture callback paths have four deferred/empty controls.
- Compiled hook metadata tests verify sanitization, backward compatibility and exact method targets.
- Rust unit tests cover source-index hook selection, compact metadata, evaluation order, generator syntax and explicit promoted property-hook rejection.

Fixture parity is not production parity. Unresolved dynamic or third-party hooks remain visible as incomplete evidence. This is a defined, extensible set of contracts, not an exhaustive guarantee for every library or runtime-generated callback.
