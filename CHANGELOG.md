# Changelog

## 1.1.2 — 2026-10-13

- Add statically linked Linux musl binaries and SHA-256 checksums for x86_64 and aarch64/arm64.
- Automatically detect the loaded libc; use separate musl cache paths and a static musl fallback when libc is unknown.
- Validate ELF architecture and absence of dynamic loader/shared-library dependencies; execute fixture parity tests natively and PHP runtime checks in Alpine for both architectures.
- Preserve engine 0.3.0 and use Architecture Graph 1.3.2 as the shared asset source.

## 1.1.1 — 2026-10-13

- Add Linux aarch64/arm64 release binaries and SHA-256 checksums, with automatic shared-cache installation.
- Build and run the complete extension fixture suite on native Linux ARM runners with minimum and latest supported Mago; validate uploaded executable architecture.
- Preserve engine 0.3.0 and existing analysis behavior; use the stable Architecture Graph 1.3.1 assets for shared downloads.

## 1.1.0 — 2026-10-13

- Publish the tested RC3 functionality as a stable release with Rust engine 0.3.0 as the default computational backend.
- Download verified Linux x86_64 and macOS arm64/x86_64 engine assets from the stable Architecture Graph 1.3.0 release.
- Preserve corrected callable binding, real recursion checks, conservative higher-order query bounds and opt-in native tracing.

## 1.1.0-RC3 — pending validation

- Fix false query-budget recursion and query counts caused by executing first-class callable targets during binding. Preserve binding-expression effects, actual recursive-call detection and incomplete higher-order bounds.
- Use engine 0.3.0 and its separate cache so the corrected evaluator is selected; synchronize the shared kernel across all three extensions.

- Add twelve neutral typed forwarding controllers with same-named methods, shared timers, branching delegation and a real cycle control; assert exact PHP/Rust report parity.
- Retain native recursion paths in an operator-selected trace file because Mago captures worker stderr internally.
- The first-class callable pattern is reproduced and corrected; full parity on the external project, including its three other incomplete differences, still needs a rerun.

## 1.1.0-RC2 — pending validation

- Make Rust the default computational backend; require a compatible engine and remove automatic PHP grammar fallback.
- Install verified platform assets into one shared cache and provide offline/preinstall controls.
- Test Linux and both macOS architectures, default selection and complete reference-report parity.
- Add opt-in recursion traces and neutral delegation regressions; project-specific parity investigation remains pending.

## 1.1.0-RC1 — 2026-10-12

- Add an opt-in shared Rust computational backend selected by `MAGO_NATIVE_ENGINE`, retaining the version-matched PHP SDK adapter.
- Verify native strict mode and complete fixture-report parity in CI against the minimum and latest supported Mago versions.
- See `NATIVE_BACKEND.md` for migrated components, bounded transport, compatibility fallback and platform status.

## 1.0.1 — 2026-10-12

- Allow Mago >=1.50.0,<2.0.0 instead of restricting dependencies to the 1.50 minor series.
- Use a major-version pin in executable Mago configurations and test the supported API floor plus latest compatible 1.x in CI.

## 1.0.0 — 2026-10-12

- Give the complete 20,000-source inspection benchmark a 180-second worker deadline on shared CI runners; ordinary extension configuration is unchanged.
- Publish the existing analyzer and inspection APIs as the first stable release.
- Set extension and analysis-attestation version metadata to `1.0.0`.
- Update installation instructions to use the stable package version.
- Preserve conservative analysis, explicit incomplete evidence, and existing bounds.

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
