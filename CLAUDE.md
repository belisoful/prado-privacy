# prado-privacy

A PRADO 4.4 extension for privacy compliance (GDPR, UK GDPR/PECR, ePrivacy, CCPA/CPRA): consent
management (`TConsentModule`, stores, a consent log, regions and IP geolocation, Global Privacy
Control, and the banner, preferences, link and gate controls), data subject rights
(`IPersonalDataProvider`, `TPrivacyModule`), retention (`TRetentionModule`, a `TCronModule` task)
and a records-of-processing registry. Class names are vendor-neutral so parts can move to
`pradosoft/prado`. The design and the owner's decisions are in
`agents/working/DESIGN_2026-09-29.md`.

## Version

- Unreleased; the first release will be **v1.0.0**. Targets PRADO 4.4 (`pradosoft/prado`
  `master`). Symbols released through v1.0.0 carry no `@since` tags; a public symbol added after
  v1.0.0 gets `@since` with the version it ships in.
- Record every user-visible change under `## [Unreleased]` in `CHANGELOG.md` as it lands.
- Supported and CI-tested PHP: **8.1, 8.2, 8.3, 8.4, 8.5**.

## Key facts

- PSR-4 `belisoful\Privacy\` → `src/`, in sub-namespaces that mirror a future core home:
  `Consent`, `Consent\Region`, `Consent\Controls`, `Rights`, `Retention`, `Data`. One type per
  file; classes and enums are prefixed `T`, interfaces `I`.
- `composer.json` `extra.prado` registers the bootstrap (`belisoful\Privacy\Consent\TConsentModule`,
  so `<module id="belisoful/prado-privacy"/>` works), `config/errorMessages.txt` (`privacy_*`
  codes) and `config/classes.json` (every class and interface; traits exempt). The package
  standard checks in `tests/unit/Package/` enforce all of it.
- Inside this repository the package is the root project, so applications here (unit, Playwright)
  configure modules by `class`, not by package id.
- Unit tests are namespaced `belisoful\Privacy\Test\Unit` mirroring `tests/unit/`; the phpunit
  bootstrap constructs a `TApplication` on `tests/unit/app`. No unit test reaches the network, a
  PHP session or randomness; time comes from `TMockClock`, databases are sqlite `:memory:`.
- belisoful/ganalytics is not a dependency. Its adapter (`GAnalyticsPrivacyConsentProvider`)
  lives in ganalytics; this README documents the category → Google consent type mapping.

## Checks (all must pass before commit)

```sh
php -l <file>                                        # syntax (composer lint for all)
composer fix        # php-cs-fixer on src and tests (tabs)
composer stan       # PHPStan level 3, PHP 8.1 – 8.5
composer unittest   # vendor/bin/phpunit --testsuite unit
npx playwright test --project=chromium   # the controls, end to end (PW_CHROMIUM=<binary> if needed)
```

`composer fulltest` runs lint, cs, stan and unittest in order. The coverage target is 100% of
lines and branches: `composer coverage-branches -- --filter <TestClass> --coverage-filter
src/<Path>/<Class>.php` lists one class's unexecuted branches in seconds. Native functions are
written fully qualified (`\trim()`). `composer integration` installs the package into a throwaway
consumer and checks the `extra.prado` wiring.

See [AGENTS.md](AGENTS.md) for the full coding standards, framework conventions, and safeguards.
