# AGENTS.md

## What This Is

`belisoful/prado-privacy`: Privacy compliance for PRADO: consent management, data subject rights, retention and records of processing (GDPR, UK GDPR/PECR, ePrivacy, CCPA/CPRA).

## Commands

| Command | What it does |
| --- | --- |
| `composer lint` | Compile-check `src/` and `tests/` with `php -l` |
| `composer cs` | Check code style without writing (what CI runs) |
| `composer fix` | Apply php-cs-fixer |
| `composer stan` | PHPStan at the level in `phpstan.neon.dist` |
| `composer unittest` | The unit suite |
| `composer coverage` | Unit suite with a text coverage report (Xdebug) |
| `composer coverage-html` | HTML coverage report in `build/coverage` |
| `composer coverage-branches` | Every unexecuted branch under `src/`; narrow with `-- --filter <Test> --coverage-filter src/<Path>/<Class>.php` |
| `npx playwright test --project=chromium` | The controls end to end (`PW_CHROMIUM=<binary>` for another Chromium) |
| `composer integration` | The Composer install test (needs a PRADO checkout, default `../prado.master`) |
| `composer fulltest` | The full check, in order |

Run one test or file with
`vendor/bin/phpunit --testsuite unit --filter <test, class, or directory>`. Never add or change
phpunit options beyond `--filter`; the options in `phpunit.xml` are the project's.

**The full check is `composer fulltest`: `php -l`, php-cs-fixer, PHPStan, PHPUnit, in that
order. All four must pass before code is ready to commit.**

## Architecture

```
src/Consent/               TConsentModule (bootstrap), categories, state, stores, the consent log
src/Consent/Region/        region resolvers, region groups, IP locators
src/Consent/Controls/      TConsentBanner, TConsentPreferences, TConsentLink, TConsentGate (.tpl beside the class)
src/Rights/                IPersonalDataProvider, TPrivacyModule, the request log, built-in providers, the shell action
src/Retention/             retention policies and purgers, the records-of-processing registry
src/Data/                  TActiveRecord classes and the schema helper
config/classes.json        Prado3 short name => FQN, registered by extra.prado.class-map
config/errorMessages.txt   message code => text (privacy_*), registered by extra.prado.error-messages
config/schema/             SQL for sqlite, MySQL and PostgreSQL
tests/unit/                phpunit tests and fixtures, namespace belisoful\Privacy\Test\Unit, mirroring src/
tests/unit/app/            the minimal application the phpunit bootstrap constructs
tests/unit/Package/        the package standard checks, inherited by every extension
tests/playwright/          Playwright specs, helpers, and the app/ PRADO application they drive
tests/integration/         installs the package into a throwaway consumer and checks the wiring
tests/test_tools/          phpunit and phpstan bootstraps, coverage-branches.php
agents/working/            the design record and working files for coding agents (see INDEX.md)
```

The design, its seams, the storage schema and the owner's decisions are in
`agents/working/DESIGN_2026-09-29.md`. Read it before changing a public API.

## Invariants

These are what the package standard checks enforce. Breaking one fails the unit suite.

- Every type in `src/` has a namespace matching its path under the single PSR-4 root, and a file
  name matching the type it declares.
- Classes, traits, and enums are prefixed `T`; interfaces are prefixed `I`; both continue in
  PascalCase.
- Every class, interface, and enum in `src/` is listed in `config/classes.json`, mapped to its
  fully qualified name. Traits are exempt: they have no short name to resolve.
- Every error code raised in `src/` has text in `config/errorMessages.txt`, and every message in
  that file is raised somewhere in `src/`. No duplicate keys.
- Extension settings live under `extra.prado`. The un-nested `extra.bootstrap` is deprecated; do
  not reintroduce it.
- The bootstrap class autoloads, is a `TModule`, and lives under the PSR-4 root.

Two more that the checks cannot see:

- Changes stay backward compatible.
- New public classes and methods carry PHPDoc with `@param`, `@return`, `@throws`, `@author`,
  and `@since` set to the next release version. Dynamic events (`dy*`) are documented with
  `@method` on the class, because no class declares them.

## Conventions

- Tabs for indentation. PascalCase classes, camelCase methods and variables,
  SCREAMING_SNAKE_CASE constants. `php-cs-fixer` settles the rest; run `composer fix` rather
  than hand-formatting.
- Properties are a getter and setter pair (`getPropertyA`/`setPropertyA`), with the setter
  coercing through `TPropertyValue`.
- Throw PRADO exceptions (`TConfigurationException`, `TInvalidDataValueException`,
  `TInvalidOperationException`) with a message code, never a literal string.
- Page templates are `.page`, control templates `.tpl`.
- A package declares its own messages and class map in `config/`. `TPluginModule` also looks for
  an `errorMessages.txt` beside the module class; this package does not use that path, because
  `extra.prado.error-messages` applies even when the module is not configured.

## PRADO framework notes

The framework is at `vendor/pradosoft/prado/`, with its own `AGENTS.md`. What matters most here:

- Everything descends from `TComponent`, which supplies dynamic properties (`__get`/`__set`),
  behaviors, and dynamic events.
- `dy*` methods are dynamic events implemented by attached behaviors, not by the calling class.
  The first parameter is filtered and returned.
- `fx*` methods are global events, registered according to `getAutoGlobalListen()`.
- Events are raised in priority order.
- Application lifecycle: `onInitComplete` → `onBeginRequest` → `onLoadState` →
  `onLoadStateComplete` → `onAuthentication` → `onAuthenticationComplete` → `onAuthorization` →
  `onAuthorizationComplete` → `onPreRunService` → `runService` → `onSaveState` →
  `onSaveStateComplete` → `onPreFlushOutput` → `flushOutput` → `onEndRequest` (or `onError`).
- `TPageService::onPreRunPage` gives a module access to the page lifecycle before it runs.
- Application configuration is XML or PHP.

## Documentation style (enforced)

Docblocks are technical documentation written as direct technical statements, in American
English, present tense: clear, thorough, brief, timeless.

- No antithesis ("does not just X, it Ys"), no em-dash dramatic asides, no editorializing, no
  rhetorical lists of three. One fact per sentence.
- Prefer subject–verb–object declaratives and `condition → result` lists or tables.
- Every public method has a docblock of at least one sentence with `@param`, `@return` and
  `@throws`. Classes have a docblock with examples where they help and `@author`.
- Legal statements in docblocks and the README cite the provision (GDPR Art. 7(1), CCPA
  regulations §7025) and never promise compliance: the package supplies the mechanism, the site
  owner the policy.

## Error handling and logging

- Throw `TConfigurationException` for a configuration that cannot work, `TInvalidDataValueException`
  for a refused property value, `TInvalidOperationException` for a call out of sequence. Every code
  is a `privacy_*` key in `config/errorMessages.txt`.
- Conditions that are not errors (a failed IP lookup, an unreadable consent cookie) are logged
  through `Prado::log()` with `\Prado\Util\Log\TLogger` levels under the class as category, and
  resolve to the safe default (undecided; opt-in mode).
- An event handler on a framework event never throws; it logs.
- Every outbound HTTP request goes through `THttpTransportTrait::transport()`; tests override it or
  run it against `LocalHttpServer` on the loopback.

## PRADO facts this package relies on

- Cookies: `THttpResponse::getCookies()->add(THttpCookie)`; `THttpCookie` defaults to
  `SameSite=Lax`, not HttpOnly, not Secure. The consent cookie is signed with
  `TSecurityManager::hashData()`/`validateData()` whether or not the application enables cookie
  validation.
- Headers: `THttpRequest::getHeader('Sec-GPC')` (case-insensitive). `getUserHostAddress()` is the
  raw `REMOTE_ADDR`, with no proxy handling; the IP resolver implements trusted proxies itself.
- Sessions: `THttpSession::AutoStart` defaults to false; open the session to write, and read only
  when the request carries the session cookie (`getSessionName()`).
- Time is read through `TApplicationClockAwareTrait::getClock()`, never `time()`; tests set a
  `TMockClock`.
- `TActiveRecord` subclasses override `finder()` with `return parent::finder($className);`; bulk
  deletes are `deleteAll($criteria, $parameters)`.
- `TCronModule` runs `task="moduleId->method"` (`TCronMethodTask`); a module advertises a task
  through `fxGetCronTaskInfos`, as `TDbCache` does.
- ActiveControls raise their click on a plain postback too. On a callback, a `TActivePanel`
  re-renders with `render($param->getNewWriter())`.
- PRADO has no user-deletion API; erasure of application users goes through `onEraseUser`.

## Testing

- New code ships with unit tests covering typical cases, edge cases, and error paths.
- Every line and every branch of `src/` is covered. A branch that cannot execute is removed from
  the code rather than left uncovered.
- Unit test classes use `belisoful\Privacy\Test\Unit\…`, mirroring `tests/unit/` (Composer
  `autoload-dev`). A fixture used by several files lives in its own file whose name does not end
  in `Test`.
- Tests are isolated: no shared state between them. Restore the application's service, modules,
  user and clock in `tearDown()`.
- Nothing reaches the network, a real PHP session or randomness. Databases are sqlite
  `:memory:`. The HTTP transport test talks to PHP's built-in server on the loopback only.
- When working on one class or cluster, run only those tests with `--filter`.
- Playwright specs (`tests/playwright/*.spec.js`) never depend on a third-party host.

## Safeguards -- ANTI-PATTERNS

Required without exception:

- NEVER run these `git` commands without asking first: clone, checkout, mv, restore, rm, branch,
  add, commit, merge, rebase, reset, pull, push, fetch.
- NEVER run `rm` on any path without asking first.
- NEVER remove composer `--dev` dependencies; they are required to develop the package.
- NEVER erase or overwrite files while unit testing and fixing. The file changes are the thing
  under test.
- NEVER delete a folder or file until the task it belongs to is completely finished.
