# PRADO Privacy Extension

Privacy compliance for the [PRADO PHP Framework](https://github.com/pradosoft/prado) (version
4.4+), implemented as a PRADO 4 extension: consent management (GDPR, UK GDPR and PECR, the
ePrivacy Directive, CCPA/CPRA and the US state laws that recognize Global Privacy Control), data
subject rights (export, erasure, rectification), retention, and records of processing.

The class names carry no vendor prefix (`TConsentModule`, not `PrivacyConsentModule`), so parts
of the package can be proposed to `pradosoft/prado`.

> **Status: in development, feature-complete for the first release.** Consent management, data
> subject rights, retention, records of processing, and the Google Analytics binding (in
> belisoful/ganalytics). The design is recorded in
> [agents/working/DESIGN_2026-09-29.md](https://github.com/belisoful/prado-privacy/blob/main/agents/working/DESIGN_2026-09-29.md).

This package supplies the mechanism. The site owner remains responsible for the policy text, the
categories a site uses, the legal basis of each processing activity, and the advice of counsel.

## Requirements

| Requirement | Scope | Purpose |
|---|---|---|
| PHP 8.2 – 8.5 | required | The runtime. CI runs every minor from 8.2 through 8.5 |
| PRADO Framework `^4.4` | required | Modules, controls and ActiveControls, `THttpCookie`, `TSecurityManager`, `TCronModule`, the clock seam, the Composer `extra.prado` extension mechanism |
| `pradosoft/prado-activerecord` `^1.0` | required | `TActiveRecord`, for the consent log, the database consent store and the request log; it left the framework core in 4.4 |
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

## Consent management

`TConsentModule` holds the visitor's consent per category, and the controls ask for it, change it
and gate content by it.

```xml
<modules>
	<module id="belisoful/prado-privacy" Version="2026-09" LogEnabled="true">
		<category Name="video" Title="Video" Description="Embedded YouTube and Vimeo players." />
	</module>
</modules>
```

```xml
<com:TConsentBanner PolicyUrl="/privacy" />
<com:TConsentPreferences />
<com:TConsentGate Category="video">
	<iframe src="https://www.youtube-nocookie.com/embed/…"></iframe>
	<prop:DeniedTemplate><p>This video needs your consent. <com:TConsentLink Text="Allow video" /></p></prop:DeniedTemplate>
</com:TConsentGate>
<footer><com:TConsentLink PreferencesUrl="/privacy-settings" /> <com:TConsentLink Mode="optout" /></footer>
```

```php
$consent = TConsentModule::findModule();
if ($consent->isGranted('analytics')) { /* register the analytics script */ }
$consent->attachEventHandler('onConsentChanged', fn ($sender, $param) => /* $param->getChanged() */ null);
```

### Categories and states

| Category | Default | Meaning |
|---|---|---|
| `necessary` | always granted | storage the site needs: the session, security, this consent choice |
| `analytics` | asked | measuring visits |
| `marketing` | asked | advertising, ad measurement, sharing with ad partners (the "sale or sharing" of CCPA) |
| `functional` | asked | embeds and features: maps, video, chat |
| `personalization` | asked | remembering preferences to adapt content |

`getConsent()` maps each category to `granted`, `denied` or `undecided`; `isGranted()` treats
`undecided` as not granted. A `<category>` element adds a category or retitles a built-in one.
Changing the policy calls for a new `Version`: a choice made under another version counts as
undecided, and the visitor is asked again. In opt-in regions a category added without a new
`Version` is `undecided` on its own, and the banner asks again until a choice covers it.

Outside opt-in regions an opt-out stands: the denials of a choice made under an older version, or
expired while the store still holds it, still apply, and `withdraw()` stores every category denied
instead of clearing the choice (which there would mean `granted`). A region known only by its
country (`US` without a state) takes the strictest mode any of its subdivisions in
`RequireConsentFor` or `OptOutFor` has. `EU` includes the outermost regions and Åland, which
geolocation reports under their own codes (`GF`, `GP`, `MQ`, `YT`, `RE`, `MF`, `AX`).

### Module properties

| Property | Default | Purpose |
|---|---|---|
| `Version` | `1` | the policy version choices are made under |
| `Expires` | `180` | days a choice lasts (1 to 395), for acceptance and rejection alike; lowering it does not shorten choices already stored |
| `Store` | `TCookieConsentStore` | where the choice is kept; `<store class="…"/>`, a module id, or an instance |
| `LogEnabled` | `false` | append every choice to the consent log |
| `Log` | `TDbConsentLog` | the log; `<log class="…" ConnectionID="db"/>` |
| `LogUserAgent` | `false` | also log a hash of the user agent |
| `RegionResolver` | `TScopeAllRegionResolver` | where the visitor is; `<resolver class="…"/>` or a module id |
| `RequireConsentFor` | `EU, EEA, UK, CH` | regions asked before non-necessary storage (opt-in) |
| `OptOutFor` | `US-GPC` | regions with an opt-out regime (California and the GPC states) |
| `HonorGpc` | `everywhere` | where Global Privacy Control applies: `everywhere`, `required`, `off` |
| `GpcCategories` | `marketing` | the categories GPC denies |

The GDPR asks a controller to be able to demonstrate consent (Art. 7(1)). A site in scope sets
`LogEnabled="true"`. Without a `ConnectionID`, the log writes to `protected/runtime/privacy.db`,
which needs `pdo_sqlite`.

### Stores

| Store | Keeps the choice in | Notes |
|---|---|---|
| `TCookieConsentStore` | one cookie, `prado_consent` | signed with `TSecurityManager::hashCookieData()` (the `CookieValidationKey`); HttpOnly, SameSite=Lax, Secure on https |
| `TSessionConsentStore` | the session | lasts as long as the session; reading never starts one |
| `TDbConsentStore` | `privacy_consent_state` | the browser holds only the consent id; a logged-in user gets their choice on another device, and on a shared device not another user's |

The consent cookie holds a choice the visitor asked to be remembered, so it is strictly necessary
storage and needs no consent itself.

Set a fixed `ValidationKey` on `TSecurityManager`, the same on every server: without one, each
server generates its own and keeps it in its runtime state. `TPrivacyHash` derives its key from
it for the hashes of IP addresses, user names and user agents (the consent log, the database
store, the data subject requests) and the IP cache keys; the cookie signature uses the
`CookieValidationKey`, derived from it unless set. Rotating
`ValidationKey` breaks the links between hashes made before and after: a user's earlier log
entries and stored choices no longer match their name. Rotating either key makes the consent
cookies unreadable, so visitors are asked again.

### Regions

| Mode | Regions | Before a choice | Banner |
|---|---|---|---|
| opt-in | `RequireConsentFor`, and every visitor whose region is unknown | undecided | shown |
| opt-out | `OptOutFor` | granted | none; `TConsentLink Mode="optout"` renders |
| none | anywhere else | granted | none |

The default resolver places nobody, so every visitor is in opt-in mode. That is the strictest
reading, and right for a site that serves Europe without locating visitors. To place visitors:

| Resolver | Source | Cost |
|---|---|---|
| `THeaderRegionResolver` | a CDN country header (`CF-IPCountry`, `CloudFront-Viewer-Country`, subdivision headers) | none; trust it only when the CDN sets it |
| `TIpRegionResolver` + `TMaxMindDbLocator` | a local MaxMind DB file: GeoLite2 (free), GeoIP2, DB-IP Lite (free), IPinfo, IP2Location | a local lookup; needs `maxmind-db/reader` |
| `TIpRegionResolver` + `THttpIpLocator` | a web API; presets for MaxMind, IPinfo, ipdata, ipgeolocation.io, Ipregistry, ipapi.co, ipstack, IP2Location.io, Abstract, BigDataCloud and DB-IP, or any JSON API by `Url` and dot paths | a request per visitor, cached |
| `TChainRegionResolver` | the first of several that answers | |
| your own `IRegionResolver` or `IIpLocator` | anything | |

```xml
<module id="geo" class="belisoful\Privacy\Consent\Region\TIpRegionResolver" TrustedProxies="10.0.0.0/8">
	<locator class="belisoful\Privacy\Consent\Region\THttpIpLocator" Service="ipinfo" ApiKey="…" />
</module>
<module id="belisoful/prado-privacy" RegionResolver="geo" />
```

`TIpRegionResolver` reads `X-Forwarded-For`, or without it the `for=` parameters of `Forwarded`
(RFC 7239), only behind a `TrustedProxies` peer, and takes the rightmost hop that is not a trusted
proxy. Ports and brackets are removed from each hop, and an IPv4-mapped IPv6 address
(`::ffff:10.0.0.1`, as a dual-stack listener reports it) is matched and validated as IPv4, so write
the trusted ranges in IPv4. The resolver skips private and reserved addresses, caches each answer
(a day) and each miss (an hour) under an HMAC of the address, in keys that carry its module id,
and fails closed: a visitor it cannot place is asked for consent. A failure of the locator or the
cache (a missing or corrupt `.mmdb` file, a timeout, a cache error) is logged as a warning without
the address and never reaches the page; only a missing `Locator` is thrown. The web API transport follows no
redirects, bounds the whole request by `Timeout` and refuses an answer above 64 KiB.

A lookup through a web API sends the visitor's IP address to the provider before any consent.
Prefer a CDN header or a local database, and list the provider as a recipient in the records of
processing. Check each provider's current terms, quotas and endpoints; the presets reflect them as
of this release. The `ipstack` preset uses https, which ipstack's free plan does not offer: it
needs a paid plan, or a `Url` with `http://`, which sends the address and the key unencrypted.

`TChainRegionResolver` treats a country without its subdivision (`US`, from `CF-IPCountry` or a
country-only database) as provisional when a region group lists subdivisions of it, and asks the
next resolvers for the state; the country is the answer when none gives one. Behaviors can override the region with `dyResolveRegion` and the consent with `dyConsent`.

### Global Privacy Control

A `Sec-GPC: 1` request header is honored as follows (`HonorGpc="everywhere"`):

| Region | Effect |
|---|---|
| opt-out (California; Colorado, Connecticut, Delaware, Maryland, Minnesota, Montana, Nebraska, New Hampshire, New Jersey, Oregon, Texas) | `marketing` is denied, overriding a banner grant or no choice. CCPA regulations §7025 make the signal a valid opt-out of sale and sharing. A grant the visitor makes per category in the preferences stands (§7025(c)(3)) |
| opt-in (EU, EEA, UK, CH, unknown) | `marketing` starts denied and the banner says so; Accept all leaves it denied; only a grant in the preferences overrides the signal |
| anywhere else | as for opt-out |

`HonorGpc="required"` applies the signal in opt-out regions only; `off` ignores it. The `US-GPC`
list follows the state laws in force at release; check it against current law, and extend it with
`TRegionGroups::defineGroup()` or region codes.

### Controls

| Control | Renders | Actions |
|---|---|---|
| `TConsentBanner` | while consent is required and undecided | Reject all, Accept all (the same element and class: equal prominence), Customize |
| `TConsentPreferences` | when opened, or always with `AlwaysVisible` | a checkbox per category (required ones disabled), Reject all, Accept all, Save, Close |
| `TConsentLink` | always; `Mode="optout"` only in opt-out regions | opens the preferences; `PreferencesUrl` is followed without JavaScript |
| `TConsentGate` | its content while `Category` is granted, its `DeniedTemplate` otherwise; denied content is not pre-rendered, so it registers no script | |

Every action works by ActiveControl callback (the banner, the preferences, the gates and the links
re-render in place) and by postback without JavaScript. The packaged stylesheet
(`assets/consent.css`, light and dark) is published unless `DefaultStyle="false"`. Texts are
properties (`Message`, `AcceptText`, `RejectText`, …) passed through `Prado::localize()`.
Withdrawing must be as easy as consenting (Art. 7(3)): put a `TConsentLink` in every page's footer.

### Consent log and schema

`TDbConsentLog` appends a row per choice to `privacy_consent_log`: the consent id (the link to
the visitor's cookie), time, policy version, the full decision, the source (`banner`,
`preferences`, `api`, `withdraw`), the region, whether GPC was present, and HMAC-SHA256 hashes of
the IP address, the user name and (with `LogUserAgent`) the user agent. Nothing identifies a
person in clear. Tables are created on first use (`AutoCreateTables`); `config/schema/` holds the
DDL for sqlite, MySQL and PostgreSQL.

## Data subject rights

`TPrivacyModule` answers access, portability, erasure and rectification requests (GDPR Art. 15–17
and 20; CCPA/CPRA §1798.100–.106) across every module that holds personal data, and records each
request and its outcome.

```xml
<module id="rights" class="belisoful\Privacy\Rights\TPrivacyModule" ResponseDays="30">
	<provider class="belisoful\Privacy\Rights\TUserPersonalDataProvider" />
	<provider class="belisoful\Privacy\Rights\TSessionPersonalDataProvider" />
	<provider class="belisoful\Privacy\Rights\TConsentPersonalDataProvider" />
</module>
```

```php
// The application verifies the requester first; the module does not.
$subject = new TDataSubject('alice', 'alice@example.com');
$json = $rights->exportJson($subject, 'self');          // Art. 15 and 20
$results = $rights->erase($subject, 'operator:dpo');    // Art. 17: TErasureResult per provider
$rights->rectify($subject, ['user' => ['email' => 'new@example.com']]);   // Art. 16
$rights->openRequest('erase', $subject, 'letter');      // a request received by post counts toward the deadline
$late = $rights->getOverdueRequests();
```

A module joins by implementing `IPersonalDataProvider`: `getPersonalDataName()`,
`exportPersonalData()`, `erasePersonalData()` (returning a `TErasureResult`: counts erased, counts
retained with the legal reason, errors) and `rectifyPersonalData()`. Loaded modules that implement
it are discovered (`AutoDiscover`); others are listed with `<provider>`. A provider that throws
does not stop the rest: the request is recorded as `partial` (or `failed`), and the exception
message goes to the application log, not to the request record.

| Built-in provider | Export | Erase |
|---|---|---|
| `TUserPersonalDataProvider` | the user's name, roles and `TUser` state from the user manager; `onExportUser` adds fields | raises `onEraseUser` for the application to delete the user (PRADO has no user-deletion API); without a handler, or for a `TUserManager` user defined in configuration, the user is reported as retained |
| `TSessionPersonalDataProvider` | the current session, when the subject is the logged-in requester | destroys it; other visitors' sessions are unreachable and reported as such |
| `TConsentPersonalDataProvider` | the current choice, `TDbConsentStore` rows, and the subject's consent log entries | withdraws the choice and deletes the rows; the log entries are retained as proof of consent (Art. 7(1), 17(3)(b)) until retention removes them; outside opt-in the stored opt-out is retained so it is still honored |

Every request goes to `privacy_request` (`TDbPrivacyRequestLog`, the runtime sqlite database
unless `<requestlog ConnectionID="…"/>`): type, the subject's hash, who asked, when, the due date
(`ResponseDays`, 30 by default; the GDPR allows one month, the CCPA 45 days), status and the
outcome per provider, without personal data. `onPrivacyRequest` and `onPrivacyRequestComplete`
bracket each request.

From the command line, in a `TShellApplication`:

```sh
prado-cli privacy/export alice --by=dpo
prado-cli privacy/erase alice --by=dpo
prado-cli privacy/requests overdue
prado-cli privacy/purge
prado-cli privacy/ropa json
```

## Retention

`TRetentionModule` holds a retention policy per kind of data (GDPR Art. 5(1)(e)) and purges what
is past it. `TCronModule` runs the purge:

```xml
<module id="retention" class="belisoful\Privacy\Retention\TRetentionModule" ConsentLogPeriod="P3Y" RequestLogPeriod="P3Y">
	<policy Name="orders" Period="P10Y" Description="Invoices: commercial and tax law">
		<purger class="belisoful\Privacy\Retention\TDbRetentionPurger" ConnectionID="db" Table="orders" TimeColumn="created_at" />
	</policy>
	<policy Name="uploads" Period="P90D">
		<purger class="belisoful\Privacy\Retention\TMethodRetentionPurger" Method="uploads->purgeBefore" />
	</policy>
</module>
<module id="cron" class="Prado\Util\Cron\TCronModule">
	<job Name="privacy-retention" Schedule="17 3 * * *" Task="retention->purge" />
</module>
```

A `Period` is an ISO 8601 duration (`P30D`, `P6M`, `P3Y`, `PT12H`). Purgers implement
`IRetentionPurger::purge(int $before): int`:

| Purger | Removes |
|---|---|
| `TDbRetentionPurger` | rows of `Table` whose `TimeColumn` (`unix` seconds or UTC `datetime`) is before the cutoff, and that match `Condition` |
| `TMethodRetentionPurger` | whatever `moduleId->method($before)` removes |
| the consent log, the request log, `TDbConsentStore` | their own entries; the store removes expired choices |

The module adds policies for this package's data (`IncludeBuiltIn`): `consent-log` and
`privacy-requests` (three years by default, the limitation period for claims in many member
states) and `consent-state` (expired `TDbConsentStore` rows). A configured policy of the same name
overrides one. A failing policy is logged and reported as -1 while the others run; `onPurged`
carries the counts, and `prado-cli privacy/purge` runs it by hand. The module lists its task in
`prado-cli cron/tasks` through `fxGetCronTaskInfos`.

## Records of processing

`TProcessingRegistry` assembles the records of processing activities (GDPR Art. 30): the
controller's details and every activity the application declares.

```xml
<module id="ropa" class="belisoful\Privacy\Retention\TProcessingRegistry"
	Controller="Example Ltd, 1 Main Street" Contact="privacy@example.com" DpoContact="dpo@example.com">
	<activity Name="accounts" Purpose="Running user accounts" LegalBasis="contract"
		SubjectCategories="customers" DataCategories="name, email address, password hash"
		RetentionPeriod="until the account is closed" SecurityMeasures="TLS, hashed passwords" />
	<activity Name="orders" Purpose="Selling and shipping" LegalBasis="contract" RetentionPolicy="orders"
		Recipients="Stripe (processor)" Transfers="US: EU-US Data Privacy Framework" />
</module>
```

Activities come from `<activity>` elements, from every loaded module that implements
`IProcessingActivityProvider` (this package's `TConsentModule`, `TPrivacyModule` and
`TIpRegionResolver` declare theirs), and from any component's `fxGetProcessingActivities` global
event handler. A `RetentionPolicy` is resolved against `TRetentionModule`, so the record states
the period the purge enforces. `toArray()`, `toJson()` and `toMarkdown()` give the record;
`prado-cli privacy/ropa` prints it (`json` for JSON).

| `TProcessingActivity` | Art. 30(1) |
|---|---|
| `Name`, `Purpose` | (b) purposes |
| `LegalBasis` (`consent`, `contract`, `legal_obligation`, `vital_interests`, `public_task`, `legitimate_interests`), `LegalBasisNote` | Art. 6(1) |
| `SubjectCategories`, `DataCategories` | (c) |
| `Recipients` | (d) |
| `Transfers` | (e) |
| `RetentionPolicy`, `RetentionPeriod` | (f) |
| `SecurityMeasures` | (g) |

## Google Analytics (belisoful/ganalytics)

[belisoful/ganalytics](https://github.com/belisoful/GAnalytics) binds `TConsentModule` to Google
Consent Mode through its `GAnalyticsPrivacyConsentProvider`. Neither package requires the other:
the provider finds the consent module by its surface (`getConsent()`, `setConsent()`,
`onConsentChanged`).

```xml
<module id="belisoful/prado-privacy" Version="2026-09" />
<module id="privacy-analytics" class="belisoful\GAnalytics\GAnalyticsPrivacyConsentProvider" />
<module id="belisoful/ganalytics" MeasurementId="G-XXXXXXXXXX" ConsentProvider="privacy-analytics"
	ConsentDefaults='{"analytics_storage": "denied", "ad_storage": "denied", "ad_user_data": "denied", "ad_personalization": "denied"}' />
```

| Consent category | Google consent types |
|---|---|
| `analytics` | `analytics_storage` |
| `marketing` | `ad_storage`, `ad_user_data`, `ad_personalization` |
| `functional` | `functionality_storage` |
| `personalization` | `personalization_storage` |
| (always) | `security_storage` = `granted` |

- Each page's `gtag('consent', 'default', …)` carries the visitor's decided categories. An
  undecided category keeps `ConsentDefaults`.
- Every change from the banner, the preferences or a withdrawal sends `gtag('consent', 'update',
  …)` on the page or callback that made it.
- GPC applies before the mapping, so a GPC opt-out denies the three ad types.

**Advanced consent mode is the documented default.** The Google tag loads with the undecided
types denied and sends cookieless pings (IP address, user agent, page) until the visitor decides.
Some EU regulators read those pings as processing that needs consent.

**Basic consent mode** loads no tag and makes no Google request until the visitor grants
`analytics` or `marketing`. Set `ConsentMode="basic"` on the ganalytics module:

```xml
<module id="belisoful/ganalytics" MeasurementId="G-XXXXXXXXXX" ConsentProvider="privacy-analytics" ConsentMode="basic" />
```

- **Postback:** a grant from the banner or the preferences renders the page with the tag.
- **Callback:** a grant loads the tag into the open page through a small loader function, with no
  `eval`, so a nonce CSP is enough.
- **Before consent:** events queued before the visitor consents are dropped.

### Erasure and records of processing

ganalytics' `GAnalyticsPersonalDataProvider` is an `IPersonalDataProvider` and an
`IProcessingActivityProvider`. `TPrivacyModule` and `TProcessingRegistry` discover it as a loaded
module:

```xml
<module id="privacy-google" class="belisoful\GAnalytics\GAnalyticsPersonalDataProvider" />
```

| Request | Effect |
|---|---|
| `erase()` | One GA4 user deletion (Admin API `submitUserDeletion`) per identifier of the subject; Google deletes the events collected before the request |
| `export()` | The property and the identifiers Google Analytics holds the subject's events under |
| `rectify()` | Nothing: Google Analytics data can only be deleted |

The identifiers are the `user_id` ganalytics derives from the user name (with `UserIdFromUser`),
the `TDataSubject` identifiers `ga_user_id`, `ga_client_id` and `ga_app_instance_id`, the
requester's `_ga` cookie, and, with `EraseUserProvidedData`, the subject's email. A user's client
ids from other devices are found only when the application records them and passes them as
`ga_client_id`. The ganalytics credentials need the `analytics.edit` scope and the Editor role on
the property.

The registry gains a "Google Analytics" activity: consent as the legal basis, Google as the
recipient, the transfer to the United States, and the property's data retention setting. The
provider's `Activity` property overrides any field.

**Why the adapters live in ganalytics.** The framework is vendor-neutral and meant for
`pradosoft/prado`, which cannot ship a Google binding. The bindings also depend on the framework,
not the other way round. `belisoful/prado-privacy` is a `suggest` and a development dependency of
ganalytics, and nothing here refers to ganalytics.

## Development

```sh
composer install
composer fulltest                          # php -l, php-cs-fixer, PHPStan (level 3), PHPUnit
composer coverage-branches                 # every unexecuted branch under src/ (Xdebug)
composer integration                       # install into a throwaway consumer, check extra.prado
                                           # (PRADO from $PRADO_DIR or ../prado.master)
npm ci && npx playwright install chromium
npx playwright test --project=chromium     # the controls in a real browser
```

CI (`.github/workflows/prado-privacy.yml`) runs lint, php-cs-fixer, PHPStan and PHPUnit on PHP
8.2 through 8.5 against PRADO's `master` branch, measures coverage (every line and branch of
`src/`), runs the Playwright specs in Chromium, Firefox and WebKit, and verifies the Composer
install. A weekly scheduled run catches changes on PRADO `master`. See [AGENTS.md](AGENTS.md) for
the conventions.

## License

BSD 3-Clause. See [LICENSE](LICENSE).
