# PRADO Privacy Extension

Privacy compliance for the [PRADO PHP Framework](https://github.com/pradosoft/prado) (version
4.4+), implemented as a PRADO 4 extension: consent management (GDPR, UK GDPR and PECR, the
ePrivacy Directive, CCPA/CPRA and the US state laws that recognize Global Privacy Control), data
subject rights (export, erasure, rectification), retention, and records of processing.

The class names carry no vendor prefix (`TConsentModule`, not `PrivacyConsentModule`), so parts
of the package can be proposed to `pradosoft/prado`.

> **Status: in development.** The design is recorded in
> [agents/working/DESIGN_2026-09-29.md](agents/working/DESIGN_2026-09-29.md). This README grows
> with each phase: consent management, data subject rights, retention and records of processing,
> then the Google Analytics binding.

This package supplies the mechanism. The site owner remains responsible for the policy text, the
categories a site uses, the legal basis of each processing activity, and the advice of counsel.

## Requirements

| Requirement | Scope | Purpose |
|---|---|---|
| PHP 8.1 – 8.5 | required | The runtime. CI runs every minor from 8.1 through 8.5 |
| PRADO Framework `^4.4` | required | Modules, controls and ActiveControls, `THttpCookie`, `TSecurityManager`, `TActiveRecord`, `TCronModule`, the clock seam, the Composer `extra.prado` extension mechanism |
| `ext-pdo_sqlite` | optional | The consent log, the database consent store, the request log and retention purges without a configured database connection |
| `maxmind-db/reader` | optional | Locating visitors from a local MaxMind DB file |

## Installation

```sh
composer require belisoful/prado-privacy
```

`pradosoft/prado` `^4.4@dev` resolves to the framework's development branch, so the root project
needs `"minimum-stability": "dev"` and `"prefer-stable": true` until PRADO 4.4 is released.

Load the extension by package name; the module class comes from `extra.prado.bootstrap`:

```xml
<modules>
	<module id="belisoful/prado-privacy" Version="2026-09" />
</modules>
```

## Development

```sh
composer install
composer fulltest                          # php -l, php-cs-fixer, PHPStan (level 3), PHPUnit
composer coverage-branches                 # every unexecuted branch under src/ (Xdebug)
composer integration                       # install into a throwaway consumer, check extra.prado
npm install && npx playwright install chromium
npx playwright test --project=chromium     # the controls in a real browser
```

CI (`.github/workflows/prado-privacy.yml`) runs lint, php-cs-fixer, PHPStan and PHPUnit on PHP
8.1 through 8.5 against PRADO's `master` branch, measures coverage (every line and branch of
`src/`), runs the Playwright specs in Chromium, Firefox and WebKit, and verifies the Composer
install. See [AGENTS.md](AGENTS.md) for the conventions.

## License

BSD 3-Clause. See [LICENSE](LICENSE).
