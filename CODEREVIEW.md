# Code Review: codraw/dependency-injection

Reviewed: 2026-07-20. Scope: all package-owned PHP source, composer.json, and test suite. Vendor and git metadata excluded.

## Fixes applied (2026-07-20)

- **composer.json:** PHP version constraint changed from unbounded `>=8.5` to `^8.5` (version-compatibility debt: prevents a future PHP 9 from installing against this package; no effect on any currently existing PHP version).
- **composer.json — added `codraw/core: ^0.39` to `require`** (fixes High: undeclared dependency on `codraw/core`). `TagIfExpressionCompilerPass` hard-imports `Draw\Component\Core\Reflection\ReflectionAccessor`; standalone installs no longer fatal at compile time.
- **composer.json — added `suggest` entries** for `symfony/expression-language` (needed by `TagIfExpressionCompilerPass` when `ifExpression` is used; fixes Medium: missing expression-language requirement — suggest chosen over hard require since the feature is opt-in) and `phpunit/phpunit` (the shipped `IntegrationTestCase` requires it, but only from consumer test suites, so it stays in `require-dev`).
- **`Integration/Test/ServiceConfiguration.php` — `ServiceConfiguration` moved out of `IntegrationTestCase.php` into its own PSR-4-compliant file** (fixes Medium: PSR-4 violation). Same FQCN, no behavior change.
- **`README.md` — example rewritten to compiling code** (fixes Medium: README example is not working code): correct `ExtendableExtensionTrait` import from the `Integration\` namespace, class is now an `Extension` implementing `PrependExtensionInterface` instead of a `Bundle`, all referenced classes imported, `MyCompnentIntegration` typo corrected.
- **`Integration/IntegrationTrait.php` — `renameDefinitions()` namespace branch now uses `substr_replace()` anchored at position 0** instead of unanchored `str_replace()` (fixes Low: unanchored replacement). Identical result for all normal ids.
- **`Integration/Test/IntegrationTestCase.php` — `testDefaultConfiguration()` expected/actual arguments swapped to the correct order and `json_encode()` now uses `\JSON_THROW_ON_ERROR`** (fixes Low: swapped expected/actual and unhandled `false` return). Pass/fail outcome unchanged; only failure diagnostics improve.

Validation pass (2026-07-20): `composer install`, the full PHPUnit suite (3 tests, 7 assertions, OK), PHPStan, and markdownlint were run against the fixes above. No test or lint fallout was caused by the fixes; no code changes were needed. PHPStan reports 3 pre-existing errors (two `trait.unused` on `ExtendableExtensionTrait`/`IntegrationTrait` and one `argument.type` at `Integration/Test/IntegrationTestCase.php:101`) that occur identically without the fixes and were left untouched.

Deliberately not fixed (risk of disrupting consumers): reflection into Symfony internals (design refactor), the global `container.excluded` sweep (behavior change consumers may rely on), `assertHasExtension()` fallback message (codraw-application, codraw-console and codraw-mailer tests assert the exact `draw_framework_extra.*` text), the PHP/Symfony version posture, the `Tests/` autoload split, and the hard-coded `getDefaultExcludedDirectories()` list.

## Overall Assessment

This is a small (~650 LOC), focused utility package that provides an "integration" abstraction for wiring sub-components into a main bundle extension, plus a conditional-tag compiler pass and a definition finder. The core design (IntegrationInterface + ExtendableExtensionTrait + IntegrationTrait + a reusable IntegrationTestCase) is coherent and pragmatic, and the code is short and readable. However, the package has one real packaging bug (an undeclared dependency on `codraw/core` that will fatal for standalone consumers), leans on reflection into Symfony internals in two places (fragile against minor Symfony updates), globally removes `container.excluded` definitions with side effects beyond its own registrations, ships a README whose example code does not compile as written, and has close to zero test coverage of its actual logic. No exploitable security issues were found (expressions evaluated by the compiler pass come from trusted container configuration, not user input).

## Findings

### High

- **[FIXED]** **Undeclared dependency on `codraw/core` (`ReflectionAccessor`) — fatal for standalone installs.**
  `DependencyInjection/Compiler/TagIfExpressionCompilerPass.php:5,18` imports and calls `Draw\Component\Core\Reflection\ReflectionAccessor::callMethod()`, but `composer.json` requires only `php`, `symfony/config`, and `symfony/dependency-injection`. A consumer who installs `codraw/dependency-injection` alone (as the README instructs) and registers `DependencyInjectionIntegration` / `TagIfExpressionCompilerPass` gets a `Class "Draw\Component\Core\Reflection\ReflectionAccessor" not found` fatal at container compile time. This is masked in the monorepo / framework-extra-bundle context where `codraw/core` happens to be installed. Either add `codraw/core` to `require`, or inline the three lines of reflection needed to call `ContainerBuilder::getExpressionLanguage()`.

### Medium

- **Reflection into private/protected Symfony internals (two sites) — fragile against Symfony patch releases.**
  - `Integration/IntegrationTrait.php:54-56` reads the `container` property of `PhpFileLoader` via `ReflectionProperty` to get the `ContainerBuilder` back out of the loader.
  - `DependencyInjection/Compiler/TagIfExpressionCompilerPass.php:18-21` invokes the **private** method `ContainerBuilder::getExpressionLanguage()` via reflection.
  Both depend on non-API internals of `symfony/dependency-injection`; a rename in a 6.4.x patch or a 7.x upgrade breaks them at runtime with no static-analysis warning (the reflection is invisible to phpstan). For the compiler pass, instantiating an `ExpressionLanguage` directly (as Symfony's own `AbstractRecursivePass` does) would avoid the private-method call; for the loader, passing the `ContainerBuilder` explicitly (it is already a parameter of every `IntegrationInterface::load()` call) would remove the need entirely.

- **`registerClasses()` removes ALL `container.excluded` definitions container-wide, not just its own** — `Integration/IntegrationTrait.php:60-64`.
  After each `registerClasses()` call, the trait iterates every definition in the container and removes any tagged `container.excluded`, including placeholder definitions registered by *other* bundles/extensions for their own excluded classes. In Symfony 6.4 those placeholders exist so that autowiring an excluded class produces a helpful "class X is excluded" error instead of a confusing "service not found"; deleting them silently degrades diagnostics for unrelated code. It is also O(all definitions) on every call, so an extension with many integrations rescans the whole container repeatedly. Restricting the sweep to the ids just registered by this call would fix both issues.

- **[FIXED]** **Missing `symfony/expression-language` requirement for the feature that needs it.**
  `TagIfExpressionCompilerPass` evaluates `ifExpression` tag attributes through the container's expression language. `symfony/expression-language` is neither required nor suggested in `composer.json`; when absent, Symfony's `getExpressionLanguage()` throws a `LogicException` ("Unable to use expressions as the Symfony ExpressionLanguage component is not installed") the first time any definition uses `ifExpression`. At minimum add a `suggest` entry; ideally require it, since the compiler pass is one of the package's two concrete features.

- **[FIXED]** **`ServiceConfiguration` is a second class hidden in `Integration/Test/IntegrationTestCase.php:223-246` — PSR-4 violation.**
  Consumers of the shipped test harness are expected to construct `ServiceConfiguration` objects in their data providers (see the `@param array|ServiceConfiguration[]` docblock at line 132), but the class cannot be PSR-4 autoloaded because it does not live in its own file. It only resolves when `IntegrationTestCase.php` has already been loaded, and behavior differs between plain PSR-4 autoloading and optimized/classmap-authoritative autoloaders. Move it to `Integration/Test/ServiceConfiguration.php`.

- **[FIXED]** **README example is not working code** — `README.md:20-61`.
  The example imports `Draw\Component\DependencyInjection\IntegrationTrait` (wrong namespace — the trait lives under `Integration\`), then actually `use`s `ExtendableExtensionTrait`; the class extends `Bundle` while implementing `load()/getConfiguration()/prepend()` which belong on an `Extension`; `Bundle`, `ContainerBuilder` and `ConfigurationInterface` are referenced without imports; and `MyCompnentIntegration` is a typo carried into the class list. Anyone onboarding from the README, the package's only documentation, will hit compile errors.

### Low

- **`assertHasExtension()` hard-codes another package's config root in its default message** — `Integration/IntegrationTrait.php:76`. The fallback exception text says `...available to configuration [draw_framework_extra.%s]`, which is wrong (and confusing) for any bundle other than framework-extra-bundle that reuses this generic trait. Also, it throws bare `\Exception` rather than a container `LogicException`/`InvalidArgumentException`.

- **[FIXED]** **`renameDefinitions()` namespace branch uses unanchored `str_replace()`** — `Integration/IntegrationTrait.php:118-122`. `str_replace($classOrNamespace, $namePrefix, $id)` replaces *every* occurrence of the namespace string within the id, not just the prefix, so a pathological id containing the namespace twice is mangled. `substr_replace`/`preg_replace('/^.../')` anchored to the start would be exact. Edge case in practice, but cheap to harden.

- **[FIXED]** **`testDefaultConfiguration()` swaps expected and actual** — `Integration/Test/IntegrationTestCase.php:60-63`. The processed configuration is passed as `expected` and `getDefaultConfiguration()` as `actual`; failure messages in every downstream package's test suite will read backwards. `json_encode()`'s possible `false` return is also unhandled.

- **`composer.json` version posture is inconsistent** — `composer.json:18-20`. Requiring `php >= 8.5` (unbounded, bleeding edge) while pinning Symfony to `^6.4.0` only (no `^7.x` allowance) is an odd combination: the package demands the newest PHP but refuses current Symfony majors, which will force downstream conflicts as the ecosystem moves to 7.x/8.x. Nothing in this package's code appears 6.4-specific.

- **`Tests/` is autoloadable in production** — `composer.json:27-31` maps the package root to the namespace with no `autoload-dev` split or `exclude-from-classmap`, so `Draw\Component\DependencyInjection\Tests\*` ships into consumer classmaps. Harmless functionally, but classmap-authoritative builds will scan/emit test classes, and the shipped `Integration/Test/IntegrationTestCase.php` hard-requires PHPUnit at load time (dev-only dependency), which is only acceptable because consumers reference it exclusively from their own test suites.

- **`getDefaultExcludedDirectories()` bakes draw-monorepo conventions into a generic trait** — `Integration/IntegrationTrait.php:14-29`. The hard-coded `Email/`, `Entity/`, `Stamp/`, etc. list is invisible to callers and not overridable (private static). A consumer whose component legitimately has services under `Event/` or `Entity/` will silently get nothing registered from those directories, with no way to opt out short of not using `registerClasses()`.

## Strengths

- Clear, minimal abstraction: `IntegrationInterface` / `PrependIntegrationInterface` / `ContainerBuilderIntegrationInterface` are small, single-purpose contracts, and `ExtendableExtensionTrait` makes the "optional sub-component auto-registration" pattern (`class_exists()` gate in `registerDefaultIntegrations()`, `Integration/ExtendableExtensionTrait.php:26-41`) genuinely easy to adopt.
- `loadIntegrations()` correctly calls `$container->addObjectResource($integration)` for each integration (`Integration/ExtendableExtensionTrait.php:50-52`), so container recompilation is triggered when an integration class changes — a detail many hand-rolled extensions get wrong.
- The shipped `IntegrationTestCase` is exhaustive by design: `assertContainerBuilderServices()` (`Integration/Test/IntegrationTestCase.php:134-206`) fails if any registered service or alias is *not* accounted for by the test, which forces downstream packages to keep their DI tests in sync with their wiring.
- `prependIntegrations()` resolves parameters before processing configuration (`Integration/ExtendableExtensionTrait.php:69-72`) — a subtle correctness detail for prepend-time config.
- Defensive coding in small ways: `serviceIdClassToNameConvention()` checks `preg_replace()`'s null return (`Integration/IntegrationTrait.php:150-152`), `registerDefaultIntegrations()` validates the interface with a clear exception, and the phpstan baseline is empty (no suppressed errors).

## Test Coverage

Coverage is minimal. The suite consists of a single test class, `Tests/DependencyInjection/DependencyInjectionIntegrationTest.php`, which exercises `DependencyInjectionIntegration` — itself an almost-empty class (empty `load()`, empty `addConfiguration()`) — through the shared `IntegrationTestCase` harness. It verifies the config section name, the (empty) default configuration, and that `load()` registers nothing.

Untested entirely:

- `TagIfExpressionCompilerPass` — the package's main runtime behavior (expression evaluation, tag removal, tag retention) has no test, including the interesting cases (false expression removes tag, missing expression-language, multiple tags on one definition). Note `buildContainer()` is also never invoked by any test, so the missing `codraw/core` dependency (High finding) was invisible to CI.
- All of `IntegrationTrait`: `registerClasses()` (including the `container.excluded` sweep), `renameDefinitions()` (both class and namespace branches), `serviceIdClassToNameConvention()`, `arrayToArgumentsArray()`, `assertHasExtension()`, `isConfigEnabled()`.
- All of `ExtendableExtensionTrait`: `registerDefaultIntegrations()` (missing class skip, wrong-interface exception), `loadIntegrations()` (enabled/disabled gating), `prependIntegrations()`.
- `Container/DefinitionFinder`.
- The `IntegrationTestCase` harness itself (its assertions are effectively tested only indirectly by downstream packages).

Recommendation: unit tests for the two traits and the compiler pass would be cheap (pure `ContainerBuilder` fixtures, no kernel needed) and would have caught the undeclared-dependency and the global `container.excluded` sweep.
