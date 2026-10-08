# Changelog

## 0.1.0-beta.15

- Add `QueryBudgetExtension::inspectSnapshot` for up to 50,000 exact source selectors, constructing the complete project model once instead of once per 2,000-file batch. Keep existing batch, source-size and evaluation limits unchanged.
- Isolate file-local inspection failures in the new snapshot API and index parse failures by normalized path. Preserve class/constructor bindings, transitive context, method-local limits and legacy API behavior.
- Verify 17,000- and 20,000-file report equivalence; provide a repeatable native benchmark and CI coverage for snapshot bounds and failed-file isolation.

## 0.1.0-beta.14

- Add a bounded, static `inspectThresholds` API for PHP extension configuration. Resolve named/positional threshold arguments and simple literal assignments/constants without evaluating configuration; preserve the extension defaults and report dynamic, conditional or deferred configurations explicitly unresolved.
- Treat PHP constant names as case sensitive, keep incomplete-issue limits separate from query color boundaries, and verify configuration inspection never executes source code.

## 0.1.0-beta.13

- Add `QueryBudgetExtension::inspectFiles` for bounded project indexing. A batch constructs the full project model once and returns the existing per-file schema, preserving constructor bindings, transitive calls and each method's evaluation limits.
- Keep `inspectFile` compatible and verify batch equivalence for private methods, abstract implementations, cycles, missing source files and compiled constructor overrides.

## 0.1.0-beta.12

- Add `QueryBudgetExtension::inspectFile()` for read-only inspection of arbitrary PHP classes and exact methods from a complete Mago snapshot.
- Return zero and below-threshold estimates for private methods, constructors and services independently of controller/command diagnostic selectors.
- Preserve unknown calls, abstract implementations, recursive paths and missing targets explicitly; do not aggregate unrelated method estimates into a file total.
