# Changelog

All notable changes to `belisoful/prado-privacy` are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

The first release, 0.1.0.

### Fixed

- Outside opt-in regions an opt-out stands: a new `Version` or an expired choice still held by the
  store no longer turns denied categories back to granted, and `withdraw()` stores every category
  denied (and logs that) instead of clearing the choice. Erasure keeps that stored opt-out.
- `EU` includes the outermost regions and Åland (`GF`, `GP`, `MQ`, `YT`, `RE`, `MF`, `AX`), which
  geolocation reports under their own codes.
- A region known only by its country (`US`) takes the strictest mode of its listed subdivisions,
  so `HonorGpc="required"` applies to a US visitor whose state is unknown;
  `TRegionGroups::overlaps()`.
- `TConsentGate` no longer pre-renders denied content, so a control inside it cannot register a
  script or bind data without consent.
- A partial `setConsent()` no longer turns a grant that Global Privacy Control overrides into an
  explicit grant.
- `TDbConsentStore::clear()` deletes all of the logged-in user's rows, so a withdrawal is not
  undone by another device's choice; `deleteBySubject()` takes an id to keep.
- In opt-in regions, a category added without a new `Version` is asked: the visitor counts as
  undecided until a choice covers it.
- `dyConsent` can no longer deny a required category, drop or add categories, or return an invalid
  state.
- On a shared device, `TDbConsentStore` no longer re-tags another user's stored choice to whoever
  is logged in, and a guest's save keeps the row's user.
- A failing IP lookup or cache (a missing or corrupt `.mmdb` file, a custom locator, a cache error)
  no longer breaks the page: `TIpRegionResolver` logs a warning without the address and the region
  is unknown.
- IPv4-mapped IPv6 addresses (`::ffff:10.0.0.1`) are matched against `TrustedProxies` and validated
  as IPv4.
- The web API transport no longer follows redirects, bounds the whole request by `Timeout`, and
  refuses an answer above 64 KiB.
- `X-Forwarded-For` hops with a port or brackets are read; `TMaxMindDbLocator` no longer logs the
  visitor's address; two `TIpRegionResolver`s on one cache no longer share answers.
- `TConsentLink` and the banner's Customize link to `PreferencesUrl` on a page without
  `TConsentPreferences`, and the banner stays until the visitor chooses; with no `PreferencesUrl`
  either, rendering throws.
- Focus follows callbacks: the preferences heading on open, back to the opener on close, the
  consent link after the banner's choice (`assets/consent.js`).
- Accept all and Reject all in the preferences record source `preferences`.
- Dark mode: links and focus outlines meet 4.5:1 contrast, and the panels declare `color-scheme`;
  the fixed banner no longer covers focused content (`--prado-consent-scroll-padding`).
- An erasure error or a refused rectification no longer counts as `completed`; rectify reports a
  provider that threw as false.
- The request-log purge keeps open requests; `exportJson()` no longer fails on invalid UTF-8;
  provider exception messages reach the application log only in Debug mode; `requested_by` is
  cut to 255 characters.
- The records of processing show both an activity's `RetentionPeriod` and its policy, and warn
  when a named policy does not resolve; the created-tables cache no longer reuses an entry for a
  new connection after garbage collection.
- `composer integration` finds the PRADO checkout from an argument, `PRADO_DIR`, or
  `prado.master` beside the main checkout (including from a git worktree).

### Changed

- `LICENSE` names prado-privacy and its copyright holder (Copyright 2026, Brad Anderson) instead
  of the PRADO framework's; the license stays BSD-3-Clause.
- Consent cookies are signed with `TSecurityManager::hashCookieData()` (CookieValidationKey).
  `TPrivacyHash` keys its HMAC with a key derived from ValidationKey; all hash values change.
- `IConsentStore` gains `reset()`, and `TConsentModule::reset()` makes the store read again.
- A consent source must match `TConsentModule::SOURCE_PATTERN` (lowercase, at most 16
  characters).
- `US-GPC` includes Nebraska (`US-NE`). `TIpRegionResolver` reads `Forwarded` (RFC 7239) behind a
  trusted proxy. `TChainRegionResolver` asks the next resolvers when one gives only a country whose
  subdivisions a group lists, keeping the country as the fallback.
- Category titles and `TConsentLink`'s text are HTML-encoded by default; `PolicyUrl` and
  `PreferencesUrl` accept only http, https or relative URLs.
- `TPrivacyModule.ResponseDays` is now `ResponsePeriod`, an ISO 8601 duration counted in calendar
  terms in UTC (`P1M` by default; `P45D` for the CCPA). `extendRequest()` and
  `prado-cli privacy/extend` move a due date and record the reason.
- `IPrivacyRequestLog::findPending()` is now `findOpen()`: `pending`, `partial` and `failed`
  requests are open, and overdue. `export()`, `erase()` and `rectify()` answer an existing open
  request by id (`--request=`). The export carries `status`, `errors` per provider and the
  subject's request history.
- `prado-cli privacy/erase` is a dry run without `--yes`; `export` and `erase` take `--email=` and
  `--id=kind:value`.
- `TRetentionModule::purge()` throws after `onPurged` when a policy failed, so cron records the
  failure; `purgePolicies()` returns the counts. The consent log is purged by consent id, after
  the id's last change. `TDbRetentionPurger` refuses a zero `Period` unless
  `AllowZeroPeriod="true"`, and quotes its identifiers. `AutoCreateTables` checks that a table
  exists before running DDL.

### Added

- The package, stamped from the PRADO extension skeleton (`belisoful/prado-extension`), with
  ganalytics' tooling: 100% line and branch coverage (`composer coverage-branches`), fully
  qualified native calls, a Playwright harness, and CI on PHP 8.2 – 8.5 against PRADO `master`.
- PHP 8.2 or newer, as PRADO `master` requires, and `pradosoft/prado-activerecord` for
  `TActiveRecord`, which moved out of the framework core.
- `TRetentionCronTask` runs the retention purge with the cron run's cancellation token, so
  `TaskTimeout` stops it between policies.
- `TDbRetentionPurger.BatchSize` and `KeyColumn` delete in batches; `TimeFormat="unix_ms"` for
  millisecond times.
- `TProcessingRegistry.JointControllers` and `Representative` (GDPR Art. 26, 27) in every format
  of the record.
- Consent management. `TConsentModule` (the bootstrap module) holds the categories (`necessary`,
  `analytics`, `marketing`, `functional`, `personalization`, and any `<category>`), the visitor's
  consent (`getConsent()`, `isGranted()`, `getIsDecided()`), the policy `Version`, `Expires`
  (180 days), `setConsent()`, `acceptAll()`, `rejectAll()`, `withdraw()` and `onConsentChanged`.
- Stores: `IConsentStore` with `TCookieConsentStore` (the default; a signed cookie),
  `TSessionConsentStore` and `TDbConsentStore`.
- The consent log: `IConsentLog` and `TDbConsentLog` (`TActiveRecord`), with HMAC hashes of the IP
  address, the user name and the user agent; `TPrivacySchema` and `config/schema/*.sql`.
- Regions: opt-in (`RequireConsentFor`), opt-out (`OptOutFor`) and none; `TRegionGroups`
  (`EU`, `EEA`, `UK`, `CH`, `US-GPC`); `IRegionResolver` with `TScopeAllRegionResolver` (the
  default), `THeaderRegionResolver` and `TChainRegionResolver`.
- IP geolocation: `TIpRegionResolver` (trusted proxies, caching, fail-closed), `IIpLocator`,
  `TMaxMindDbLocator` (local MaxMind DB files) and `THttpIpLocator` (any JSON API, with presets
  for twelve services).
- Global Privacy Control (`Sec-GPC`), honored by region (`HonorGpc`, `GpcCategories`).
- Controls: `TConsentBanner`, `TConsentPreferences`, `TConsentLink` and `TConsentGate`, working by
  callback and by postback, with a packaged stylesheet.
- Data subject rights: `IPersonalDataProvider`, `TDataSubject`, `TErasureResult`, and
  `TPrivacyModule` (provider discovery, `export()`/`exportJson()`, `erase()`, `rectify()`,
  `openRequest()`, `getOverdueRequests()`, `onPrivacyRequest`, `onPrivacyRequestComplete`).
- The request log: `IPrivacyRequestLog` and `TDbPrivacyRequestLog` (`privacy_request`).
- Built-in providers: `TUserPersonalDataProvider` (with `onExportUser`, `onEraseUser`,
  `onRectifyUser`), `TSessionPersonalDataProvider` and `TConsentPersonalDataProvider`.
- `prado-cli privacy/export`, `privacy/erase` and `privacy/requests` (`TPrivacyShellAction`).
- Retention: `IRetentionPurger`, `TRetentionPolicy` (ISO 8601 periods), `TDbRetentionPurger`,
  `TMethodRetentionPurger`, and `TRetentionModule` (`purge()` for `TCronModule`, built-in policies
  for the consent log, expired consent choices and the request log, `onPurged`,
  `fxGetCronTaskInfos`). The consent log, the request log and `TDbConsentStore` are purgers.
- Records of processing: `TProcessingActivity`, `TLegalBasis`, `IProcessingActivityProvider`, and
  `TProcessingRegistry` (`<activity>` elements, module discovery, `fxGetProcessingActivities`,
  JSON and Markdown output). `TConsentModule`, `TPrivacyModule` and `TIpRegionResolver` declare
  their activities.
- `prado-cli privacy/purge` and `privacy/ropa`.
- The Google Consent Mode mapping (README), for `GAnalyticsPrivacyConsentProvider` of
  `belisoful/ganalytics`, with advanced mode as the default and basic mode by
  `ConsentMode="basic"` on the ganalytics module.
- README: erasure, export and the records of processing for Google Analytics through
  `GAnalyticsPersonalDataProvider` of `belisoful/ganalytics`.
