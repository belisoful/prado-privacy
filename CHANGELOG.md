# Changelog

All notable changes to `belisoful/prado-privacy` are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- The package, stamped from the PRADO extension skeleton (`belisoful/prado-extension`), with
  ganalytics' tooling: 100% line and branch coverage (`composer coverage-branches`), fully
  qualified native calls, a Playwright harness, and CI on PHP 8.1 – 8.5 against PRADO `master`.
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
