# Changelog

## 0.1.0-beta.13

- Add `QueryBudgetExtension::inspectFiles` for bounded project indexing. A batch constructs the full project model once and returns the existing per-file schema, preserving constructor bindings, transitive calls and each method's evaluation limits.
- Keep `inspectFile` compatible and verify batch equivalence for private methods, abstract implementations, cycles, missing source files and compiled constructor overrides.

## 0.1.0-beta.12

- Add `QueryBudgetExtension::inspectFile()` for read-only inspection of arbitrary PHP classes and exact methods from a complete Mago snapshot.
- Return zero and below-threshold estimates for private methods, constructors and services independently of controller/command diagnostic selectors.
- Preserve unknown calls, abstract implementations, recursive paths and missing targets explicitly; do not aggregate unrelated method estimates into a file total.
