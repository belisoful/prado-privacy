<?php

/**
 * TConsentModule class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Consent;

use belisoful\Privacy\Consent\Controls\IConsentControl;
use belisoful\Privacy\Consent\Region\IRegionResolver;
use belisoful\Privacy\Consent\Region\TRegionGroups;
use belisoful\Privacy\Consent\Region\TScopeAllRegionResolver;
use belisoful\Privacy\TConfiguredComponentTrait;
use belisoful\Privacy\TPrivacyHash;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\TModule;
use Prado\TPropertyValue;
use Prado\Util\Clock\TApplicationClockAwareTrait;
use Prado\Xml\TXmlElement;

/**
 * TConsentModule class.
 *
 * TConsentModule manages the visitor's consent per category: which categories exist, what the
 * visitor chose, where the choice is stored and logged, which law applies to the visitor's region,
 * and the Global Privacy Control signal. Every consent control and every consumer (a tag manager,
 * an embed, the analytics binding) reads the consent here.
 *
 * ```xml
 * <module id="belisoful/prado-privacy" Version="2026-09" Expires="180"
 *     RequireConsentFor="EU, EEA, UK, CH" OptOutFor="US-GPC" HonorGpc="everywhere" LogEnabled="true">
 *   <category Name="video" Title="Video embeds" Description="YouTube and Vimeo players." />
 *   <store class="belisoful\Privacy\Consent\TCookieConsentStore" />
 *   <log class="belisoful\Privacy\Consent\TDbConsentLog" ConnectionID="db" />
 *   <resolver class="belisoful\Privacy\Consent\Region\THeaderRegionResolver" />
 * </module>
 * ```
 *
 * Categories: `necessary` (required, always granted), `analytics`, `marketing`, `functional` and
 * `personalization`, plus any `<category>` element. A `<category>` with a built-in name retitles it.
 *
 * The consent (`getConsent()`, category → `granted`, `denied` or `undecided`) follows the mode of
 * the visitor's region ({@see getMode()}):
 *
 * | Mode | Before a choice | After a choice |
 * |---|---|---|
 * | `optin` (`RequireConsentFor`, or unknown region) | `undecided` | the choice |
 * | `optout` (`OptOutFor`) | `granted` | the choice |
 * | `none` | `granted` | the choice |
 *
 * A choice counts only while its `Version` equals the module's and it has not expired
 * (`Expires` days, 180 by default). Required categories are always `granted`.
 *
 * Global Privacy Control (`Sec-GPC: 1`), where honored ({@see getIsGpcHonored()}), denies the
 * `GpcCategories` (`marketing` by default): the signal overrides a grant from the banner or from
 * no choice at all. A grant the visitor gave per category (source `preferences` or `api`) stands,
 * as CCPA regulations §7025(c)(3) allow. `acceptAll()` leaves those categories denied.
 *
 * `setConsent()`, `acceptAll()`, `rejectAll()` and `withdraw()` store the choice, append it to the
 * consent log when `LogEnabled`, and raise `onConsentChanged` when the effective consent changed.
 *
 * The region comes from `RegionResolver`, `TScopeAllRegionResolver` by default (every visitor
 * unknown, so every visitor is asked). The dynamic event `dyResolveRegion` lets a behavior
 * override it, and `dyConsent` lets a behavior adjust the effective consent.
 *
 * @method ?string dyResolveRegion(?string $region, \Prado\Web\THttpRequest $request) filters the resolved region
 * @method array dyConsent(array $consent) filters the effective consent, category => state
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TConsentModule extends TModule
{
	use TApplicationClockAwareTrait;
	use TConfiguredComponentTrait;

	/** The version a module without a configured one reports. */
	public const DEFAULT_VERSION = '1';

	/** Days a choice lasts by default. */
	public const DEFAULT_EXPIRES = 180;

	/** The most days a choice may last (13 months). */
	public const MAX_EXPIRES = 395;

	/** The required category. */
	public const NECESSARY = 'necessary';

	/** The source of a choice made with the banner's accept-all or reject-all. */
	public const SOURCE_BANNER = 'banner';

	/** The source of a choice made per category in the preferences. */
	public const SOURCE_PREFERENCES = 'preferences';

	/** The source of a choice set by application code. */
	public const SOURCE_API = 'api';

	/** The source of a withdrawal. */
	public const SOURCE_WITHDRAW = 'withdraw';

	/** The built-in categories: name => [title, description, required]. */
	public const BUILTIN_CATEGORIES = [
		'necessary' => ['Necessary', 'Storage the site needs to work: the session, security, and this consent choice.', true],
		'analytics' => ['Analytics', 'Measuring visits and use of the site.', false],
		'marketing' => ['Marketing', 'Advertising, measuring advertising, and sharing data with advertising partners.', false],
		'functional' => ['Functional', 'Features such as embedded maps, video and chat.', false],
		'personalization' => ['Personalization', 'Remembering preferences to adapt content.', false],
	];

	/** @var string the policy version */
	private string $_version = self::DEFAULT_VERSION;

	/** @var int days a choice lasts */
	private int $_expires = self::DEFAULT_EXPIRES;

	/** @var array<string, TConsentCategory> the categories by name */
	private array $_categories = [];

	/** @var mixed the store, or its configuration until first use */
	private mixed $_store = null;

	/** @var mixed the log, or its configuration until first use */
	private mixed $_log = null;

	/** @var bool whether choices are logged */
	private bool $_logEnabled = false;

	/** @var bool whether the user agent's hash is logged */
	private bool $_logUserAgent = false;

	/** @var mixed the region resolver, or its configuration until first use */
	private mixed $_regionResolver = null;

	/** @var string[] the region codes that require consent */
	private array $_requireConsentFor;

	/** @var string[] the region codes with an opt-out regime */
	private array $_optOutFor;

	/** @var string where GPC is honored */
	private string $_honorGpc = TGpcMode::Everywhere;

	/** @var string[] the categories GPC denies */
	private array $_gpcCategories = ['marketing'];

	/** @var bool whether the preferences are open for this request */
	private bool $_preferencesOpen = false;

	/** @var array<int, IConsentControl> the controls that render by the consent */
	private array $_consentControls = [];

	/** @var null|false|string the region of this request; false before resolving */
	private null|false|string $_region = false;

	/** @var null|false|TConsentRecord the stored record; false before loading */
	private null|false|TConsentRecord $_storedRecord = false;

	/** @var ?array<string, string> the effective consent of this request */
	private ?array $_consent = null;

	/**
	 * Creates the module with the built-in categories and the default regions.
	 */
	public function __construct()
	{
		foreach (self::BUILTIN_CATEGORIES as $name => [$title, $description, $required]) {
			$this->_categories[$name] = new TConsentCategory($name, $title, $description, $required);
		}
		$this->_requireConsentFor = TRegionGroups::expand('EU, EEA, UK, CH');
		$this->_optOutFor = TRegionGroups::expand('US-GPC');
		parent::__construct();
	}

	/**
	 * Reads `<category>`, `<store>`, `<log>` and `<resolver>` elements (or the `categories`, `store`,
	 * `log` and `resolver` keys of a PHP configuration).
	 * @param null|array|TXmlElement $config the module configuration
	 * @throws TInvalidDataValueException when a category is not valid
	 * @throws TConfigurationException when a part does not implement its interface
	 */
	public function init($config)
	{
		if ($config instanceof TXmlElement) {
			foreach ($config->getElementsByTagName('category') as $element) {
				$this->addCategory($element->getAttributes()->toArray());
			}
			foreach (['store' => 'setStore', 'log' => 'setLog', 'resolver' => 'setRegionResolver'] as $tag => $setter) {
				if (($element = $config->getElementByTagName($tag)) !== null) {
					$this->$setter($element->getAttributes()->toArray());
				}
			}
		} elseif (\is_array($config)) {
			foreach ($config['categories'] ?? [] as $category) {
				$this->addCategory($category);
			}
			foreach (['store' => 'setStore', 'log' => 'setLog', 'resolver' => 'setRegionResolver'] as $key => $setter) {
				if (isset($config[$key])) {
					$this->$setter($config[$key]);
				}
			}
		}
		parent::init($config);
	}

	/**
	 * Finds the consent module of the application.
	 * @param ?string $id the module id; null or empty finds the first `TConsentModule`
	 * @return ?TConsentModule the module; null when the application has none
	 */
	public static function findModule(?string $id = null): ?TConsentModule
	{
		$app = Prado::getApplication();
		if ($id !== null && $id !== '') {
			$module = $app->getModule($id);
			return $module instanceof TConsentModule ? $module : null;
		}
		foreach ($app->getModulesByType(self::class) as $module) {
			return $module;
		}
		return null;
	}

	// --- consent -----------------------------------------------------------------------------

	/**
	 * Returns the effective consent of the visitor, computed once per request.
	 * @return array<string, string> category => `granted`, `denied` or `undecided`
	 */
	public function getConsent(): array
	{
		if ($this->_consent !== null) {
			return $this->_consent;
		}
		$record = $this->getRecord();
		$optIn = $this->getMode() === TConsentMode::OptIn;
		$gpc = $this->getIsGpcHonored();
		$explicit = $record !== null && \in_array($record->getSource(), [self::SOURCE_PREFERENCES, self::SOURCE_API], true);
		$consent = [];
		foreach ($this->_categories as $name => $category) {
			$stored = $record?->getState($name);
			if ($category->getRequired()) {
				$state = TConsentState::Granted;
			} elseif ($gpc && \in_array($name, $this->_gpcCategories, true) && !($explicit && $stored === TConsentState::Granted)) {
				$state = TConsentState::Denied;
			} else {
				$state = $stored ?? ($optIn ? TConsentState::Undecided : TConsentState::Granted);
			}
			$consent[$name] = $state;
		}
		return $this->_consent = $this->dyConsent($consent);
	}

	/**
	 * @param string $category the category name
	 * @throws TInvalidDataValueException when the category does not exist
	 * @return string `granted`, `denied` or `undecided`
	 */
	public function getCategoryConsent(string $category): string
	{
		$consent = $this->getConsent();
		if (!isset($consent[$category])) {
			throw new TInvalidDataValueException('privacy_category_unknown', $category);
		}
		return $consent[$category];
	}

	/**
	 * @param string $category the category name
	 * @throws TInvalidDataValueException when the category does not exist
	 * @return bool whether the category is granted
	 */
	public function isGranted(string $category): bool
	{
		return $this->getCategoryConsent($category) === TConsentState::Granted;
	}

	/**
	 * @return bool whether the visitor's consent is settled: a current choice exists, or the region does not require one
	 */
	public function getIsDecided(): bool
	{
		return $this->getMode() !== TConsentMode::OptIn || $this->getRecord() !== null;
	}

	/**
	 * @return bool whether the visitor's region requires consent before non-necessary storage
	 */
	public function getIsConsentRequired(): bool
	{
		return $this->getMode() === TConsentMode::OptIn;
	}

	/**
	 * Records a choice, logs it, and raises `onConsentChanged` when the effective consent changed.
	 *
	 * Categories left out keep the stored choice, or are denied without one. Required categories
	 * are always granted.
	 * @param array<string, mixed> $categories category => `granted`, `denied`, or a boolean
	 * @param string $source where the choice came from: `banner`, `preferences` or `api`
	 * @throws TInvalidDataValueException when a category does not exist or a value is not a decision
	 * @return TConsentRecord the stored record
	 */
	public function setConsent(array $categories, string $source = self::SOURCE_API): TConsentRecord
	{
		$choice = $this->normalizeChoice($categories);
		$stored = $this->getStoredRecord();
		$decision = [];
		foreach ($this->_categories as $name => $category) {
			$decision[$name] = $category->getRequired() ? TConsentState::Granted
				: ($choice[$name] ?? ($this->getRecord()?->getState($name) ?? TConsentState::Denied));
		}
		$now = $this->getClock()->time();
		$record = new TConsentRecord(
			$stored?->getId() ?? $this->generateConsentId(),
			$decision,
			$this->getVersion(),
			$now,
			$now + $this->getExpires() * 86400,
			$source
		);
		$previous = $this->getConsent();
		$this->getStore()->save($record);
		$this->_storedRecord = $record;
		$this->logChoice($record);
		$this->raiseIfChanged($previous, $record, $source);
		return $record;
	}

	/**
	 * Grants every category, except those Global Privacy Control denies when it is honored.
	 * @param string $source where the choice came from
	 * @return TConsentRecord the stored record
	 */
	public function acceptAll(string $source = self::SOURCE_BANNER): TConsentRecord
	{
		$gpc = $this->getIsGpcHonored();
		$choice = [];
		foreach ($this->_categories as $name => $category) {
			$choice[$name] = !($gpc && \in_array($name, $this->_gpcCategories, true));
		}
		return $this->setConsent($choice, $source);
	}

	/**
	 * Denies every category that is not required.
	 * @param string $source where the choice came from
	 * @return TConsentRecord the stored record
	 */
	public function rejectAll(string $source = self::SOURCE_BANNER): TConsentRecord
	{
		return $this->setConsent(\array_fill_keys(\array_keys($this->_categories), false), $source);
	}

	/**
	 * Withdraws the visitor's choice: clears the store and logs the withdrawal (every category denied)
	 * when there was a choice. In an opt-in region the visitor is asked again.
	 */
	public function withdraw(): void
	{
		$stored = $this->getStoredRecord();
		$previous = $this->getConsent();
		$this->getStore()->clear();
		$this->_storedRecord = null;
		if ($stored !== null) {
			$now = $this->getClock()->time();
			$denied = [];
			foreach ($this->_categories as $name => $category) {
				$denied[$name] = $category->getRequired() ? TConsentState::Granted : TConsentState::Denied;
			}
			$this->logChoice(new TConsentRecord($stored->getId(), $denied, $this->getVersion(), $now, $now, self::SOURCE_WITHDRAW));
		}
		$this->raiseIfChanged($previous, null, self::SOURCE_WITHDRAW);
	}

	/**
	 * Raised when a choice or a withdrawal changes the effective consent.
	 * @param TConsentChangedEventParameter $param the consent before and after
	 * @return array the handlers' results
	 */
	public function onConsentChanged($param)
	{
		return $this->raiseEvent('onConsentChanged', $this, $param);
	}

	/**
	 * @return ?TConsentRecord the stored choice when it is current: made under this `Version` and not expired
	 */
	public function getRecord(): ?TConsentRecord
	{
		$record = $this->getStoredRecord();
		if ($record === null || $record->getVersion() !== $this->getVersion() || $record->isExpired($this->getClock()->time())) {
			return null;
		}
		return $record;
	}

	/**
	 * @return ?TConsentRecord the choice the store holds, current or not
	 */
	public function getStoredRecord(): ?TConsentRecord
	{
		if ($this->_storedRecord === false) {
			$this->_storedRecord = $this->getStore()->load();
		}
		return $this->_storedRecord;
	}

	/**
	 * @param array<string, mixed> $categories category => decision
	 * @throws TInvalidDataValueException when a category does not exist or a value is not a decision
	 * @return array<string, string> category => `granted` or `denied`
	 */
	protected function normalizeChoice(array $categories): array
	{
		$choice = [];
		foreach ($categories as $name => $value) {
			if (!isset($this->_categories[$name])) {
				throw new TInvalidDataValueException('privacy_category_unknown', $name);
			}
			if (\is_string($value) && \in_array($lower = \strtolower(\trim($value)), [TConsentState::Granted, TConsentState::Denied], true)) {
				$choice[$name] = $lower;
			} elseif (\is_bool($value) || \is_int($value) || \in_array($value, ['0', '1', 'true', 'false'], true)) {
				$choice[$name] = TPropertyValue::ensureBoolean($value) ? TConsentState::Granted : TConsentState::Denied;
			} else {
				throw new TInvalidDataValueException('privacy_consent_value_invalid', $name, \is_scalar($value) ? (string) $value : \get_debug_type($value));
			}
		}
		return $choice;
	}

	/**
	 * Appends a record to the consent log when logging is enabled.
	 * @param TConsentRecord $record the record
	 */
	protected function logChoice(TConsentRecord $record): void
	{
		if ($this->getLogEnabled()) {
			$this->getLog()->record($record, $this->createLogContext());
		}
	}

	/**
	 * Recomputes the consent and raises `onConsentChanged` when it differs from the previous one.
	 * @param array<string, string> $previous the consent before the change
	 * @param ?TConsentRecord $record the record stored
	 * @param string $source where the change came from
	 */
	protected function raiseIfChanged(array $previous, ?TConsentRecord $record, string $source): void
	{
		$this->_consent = null;
		$current = $this->getConsent();
		if ($current !== $previous) {
			$this->onConsentChanged(new TConsentChangedEventParameter($previous, $current, $record, $source));
		}
	}

	/**
	 * @return TConsentLogContext the request facts logged beside a choice
	 */
	public function createLogContext(): TConsentLogContext
	{
		$request = $this->getRequest();
		$ip = (string) $request->getUserHostAddress();
		$user = $this->getApplication()->getUser();
		$agent = (string) $request->getUserAgent();
		return new TConsentLogContext(
			$ip === '' ? null : TPrivacyHash::hash($ip, TPrivacyHash::IP),
			$this->getRegion(),
			$this->getGpcSignal(),
			($user === null || $user->getIsGuest()) ? null : TPrivacyHash::hash((string) $user->getName(), TPrivacyHash::SUBJECT),
			($this->getLogUserAgent() && $agent !== '') ? TPrivacyHash::hash($agent, TPrivacyHash::USER_AGENT) : null
		);
	}

	/**
	 * @return string a new consent id: 32 random hex characters
	 */
	protected function generateConsentId(): string
	{
		return \bin2hex(\random_bytes(16));
	}

	/**
	 * Forgets what this request read and computed: the stored record, the region and the consent.
	 */
	public function reset(): void
	{
		$this->_storedRecord = false;
		$this->_region = false;
		$this->_consent = null;
	}

	// --- region and GPC ----------------------------------------------------------------------

	/**
	 * @return ?string the region of the request (`DE`, `US-CA`), resolved once; null when unknown
	 */
	public function getRegion(): ?string
	{
		if ($this->_region === false) {
			$request = $this->getRequest();
			$region = $this->getRegionResolver()->resolveRegion($request);
			$this->_region = TRegionGroups::normalize($this->dyResolveRegion($region, $request));
		}
		return $this->_region;
	}

	/**
	 * @return string the consent mode of the request's region: `optin`, `optout` or `none` ({@see TConsentMode})
	 */
	public function getMode(): string
	{
		$region = $this->getRegion();
		if ($region === null || TRegionGroups::matches($region, $this->_requireConsentFor)) {
			return TConsentMode::OptIn;
		}
		return TRegionGroups::matches($region, $this->_optOutFor) ? TConsentMode::OptOut : TConsentMode::None;
	}

	/**
	 * @return bool whether the request carries the Global Privacy Control signal (`Sec-GPC: 1`)
	 */
	public function getGpcSignal(): bool
	{
		return \trim((string) $this->getRequest()->getHeader('Sec-GPC')) === '1';
	}

	/**
	 * @return bool whether the request carries the GPC signal and `HonorGpc` applies it in the request's mode
	 */
	public function getIsGpcHonored(): bool
	{
		if (!$this->getGpcSignal()) {
			return false;
		}
		return match ($this->getHonorGpc()) {
			TGpcMode::Everywhere => true,
			TGpcMode::Required => $this->getMode() === TConsentMode::OptOut,
			default => false,
		};
	}

	// --- controls ----------------------------------------------------------------------------

	/**
	 * @return bool whether the preferences are open for this request (set by `TConsentLink`)
	 */
	public function getPreferencesOpen(): bool
	{
		return $this->_preferencesOpen;
	}

	/**
	 * @param mixed $value whether the preferences are open for this request
	 * @return static the module
	 */
	public function setPreferencesOpen($value): static
	{
		$this->_preferencesOpen = TPropertyValue::ensureBoolean($value);
		return $this;
	}

	/**
	 * Registers a control that renders by the consent, so a callback that changes the consent re-renders it.
	 * @param IConsentControl $control the control
	 */
	public function registerConsentControl(IConsentControl $control): void
	{
		$this->_consentControls[\spl_object_id($control)] = $control;
	}

	/**
	 * @return IConsentControl[] the controls that render by the consent
	 */
	public function getConsentControls(): array
	{
		return \array_values($this->_consentControls);
	}

	// --- properties --------------------------------------------------------------------------

	/**
	 * @return string the privacy policy version the visitor's choice must match
	 */
	public function getVersion(): string
	{
		return $this->_version;
	}

	/**
	 * @param mixed $value the privacy policy version, a non-empty string of at most 64 characters
	 * @throws TInvalidDataValueException when the value is empty or longer than 64 characters
	 * @return static the module
	 */
	public function setVersion($value): static
	{
		$value = \trim(TPropertyValue::ensureString($value));
		if ($value === '' || \strlen($value) > 64) {
			throw new TInvalidDataValueException('privacy_version_invalid', $value);
		}
		$this->_version = $value;
		$this->_consent = null;
		return $this;
	}

	/**
	 * @return int days a choice lasts
	 */
	public function getExpires(): int
	{
		return $this->_expires;
	}

	/**
	 * @param mixed $value days a choice lasts, 1 to 395
	 * @throws TInvalidDataValueException when the value is outside 1 to 395
	 * @return static the module
	 */
	public function setExpires($value): static
	{
		$value = TPropertyValue::ensureInteger($value);
		if ($value < 1 || $value > self::MAX_EXPIRES) {
			throw new TInvalidDataValueException('privacy_expires_invalid', $value, self::MAX_EXPIRES);
		}
		$this->_expires = $value;
		return $this;
	}

	/**
	 * @return array<string, TConsentCategory> the categories by name, in declaration order
	 */
	public function getCategories(): array
	{
		return $this->_categories;
	}

	/**
	 * @param string $name the category name
	 * @return ?TConsentCategory the category; null when it does not exist
	 */
	public function getCategory(string $name): ?TConsentCategory
	{
		return $this->_categories[$name] ?? null;
	}

	/**
	 * Adds a category, or replaces the one with the same name.
	 * @param array|TConsentCategory $category the category, or its properties (`Name`, `Title`, `Description`, `Required`)
	 * @throws TInvalidDataValueException when the category has no valid name
	 * @return static the module
	 */
	public function addCategory(array|TConsentCategory $category): static
	{
		if (\is_array($category)) {
			$properties = \array_change_key_case($category, CASE_LOWER);
			$category = new TConsentCategory();
			$category->setName($properties['name'] ?? '');
			$category->setTitle($properties['title'] ?? '');
			$category->setDescription($properties['description'] ?? '');
			$category->setRequired($properties['required'] ?? false);
		} elseif ($category->getName() === '') {
			throw new TInvalidDataValueException('privacy_category_name_invalid', '');
		}
		$this->_categories[$category->getName()] = $category;
		$this->_consent = null;
		return $this;
	}

	/**
	 * @throws TConfigurationException when the configured store does not implement `IConsentStore`
	 * @return IConsentStore the store; a `TCookieConsentStore` when none is configured
	 */
	public function getStore(): IConsentStore
	{
		if (!($this->_store instanceof IConsentStore)) {
			$this->_store = $this->_store === null ? new TCookieConsentStore() : $this->resolveConfiguredComponent($this->_store, IConsentStore::class);
		}
		return $this->_store;
	}

	/**
	 * @param mixed $value a store, a configuration array (`class` and properties), or a module id; null restores the cookie store
	 * @return static the module
	 */
	public function setStore($value): static
	{
		$this->_store = ($value === '' ? null : $value);
		$this->_storedRecord = false;
		$this->_consent = null;
		return $this;
	}

	/**
	 * @throws TConfigurationException when the configured log does not implement `IConsentLog`
	 * @return IConsentLog the log; a `TDbConsentLog` on the runtime sqlite database when none is configured
	 */
	public function getLog(): IConsentLog
	{
		if (!($this->_log instanceof IConsentLog)) {
			$this->_log = $this->_log === null ? new TDbConsentLog() : $this->resolveConfiguredComponent($this->_log, IConsentLog::class);
		}
		return $this->_log;
	}

	/**
	 * @param mixed $value a log, a configuration array (`class` and properties), or a module id; null restores the default
	 * @return static the module
	 */
	public function setLog($value): static
	{
		$this->_log = ($value === '' ? null : $value);
		return $this;
	}

	/**
	 * @return bool whether choices are appended to the consent log
	 */
	public function getLogEnabled(): bool
	{
		return $this->_logEnabled;
	}

	/**
	 * @param mixed $value whether choices are appended to the consent log
	 * @return static the module
	 */
	public function setLogEnabled($value): static
	{
		$this->_logEnabled = TPropertyValue::ensureBoolean($value);
		return $this;
	}

	/**
	 * @return bool whether the log holds a hash of the user agent
	 */
	public function getLogUserAgent(): bool
	{
		return $this->_logUserAgent;
	}

	/**
	 * @param mixed $value whether the log holds a hash of the user agent
	 * @return static the module
	 */
	public function setLogUserAgent($value): static
	{
		$this->_logUserAgent = TPropertyValue::ensureBoolean($value);
		return $this;
	}

	/**
	 * @throws TConfigurationException when the configured resolver does not implement `IRegionResolver`
	 * @return IRegionResolver the region resolver; a `TScopeAllRegionResolver` when none is configured
	 */
	public function getRegionResolver(): IRegionResolver
	{
		if (!($this->_regionResolver instanceof IRegionResolver)) {
			$this->_regionResolver = $this->_regionResolver === null ? new TScopeAllRegionResolver()
				: $this->resolveConfiguredComponent($this->_regionResolver, IRegionResolver::class);
		}
		return $this->_regionResolver;
	}

	/**
	 * @param mixed $value a resolver, a configuration array (`class` and properties), or a module id; null restores the default
	 * @return static the module
	 */
	public function setRegionResolver($value): static
	{
		$this->_regionResolver = ($value === '' ? null : $value);
		$this->_region = false;
		$this->_consent = null;
		return $this;
	}

	/**
	 * @return string[] the region codes whose visitors are asked before non-necessary storage
	 */
	public function getRequireConsentFor(): array
	{
		return $this->_requireConsentFor;
	}

	/**
	 * @param mixed $value groups and region codes, comma-separated or an array (`EU, EEA, UK, CH` by default)
	 * @throws TInvalidDataValueException when an entry is neither a group nor a region code
	 * @return static the module
	 */
	public function setRequireConsentFor($value): static
	{
		$this->_requireConsentFor = TRegionGroups::expand($value);
		$this->_consent = null;
		return $this;
	}

	/**
	 * @return string[] the region codes with an opt-out regime
	 */
	public function getOptOutFor(): array
	{
		return $this->_optOutFor;
	}

	/**
	 * @param mixed $value groups and region codes, comma-separated or an array (`US-GPC` by default)
	 * @throws TInvalidDataValueException when an entry is neither a group nor a region code
	 * @return static the module
	 */
	public function setOptOutFor($value): static
	{
		$this->_optOutFor = TRegionGroups::expand($value);
		$this->_consent = null;
		return $this;
	}

	/**
	 * @return string where the GPC signal is honored: `everywhere`, `required` or `off` ({@see TGpcMode})
	 */
	public function getHonorGpc(): string
	{
		return $this->_honorGpc;
	}

	/**
	 * @param mixed $value `everywhere`, `required` or `off`
	 * @throws TInvalidDataValueException when the value is not a `TGpcMode`
	 * @return static the module
	 */
	public function setHonorGpc($value): static
	{
		$this->_honorGpc = TPropertyValue::ensureEnumValue(\trim((string) $value), TGpcMode::class);
		$this->_consent = null;
		return $this;
	}

	/**
	 * @return string[] the categories the GPC signal denies
	 */
	public function getGpcCategories(): array
	{
		return $this->_gpcCategories;
	}

	/**
	 * @param mixed $value category names, comma-separated or an array (`marketing` by default)
	 * @return static the module
	 */
	public function setGpcCategories($value): static
	{
		$list = \is_array($value) ? $value : \explode(',', (string) $value);
		$this->_gpcCategories = \array_values(\array_filter(\array_map(fn ($name) => \strtolower(\trim((string) $name)), $list), fn ($name) => $name !== ''));
		$this->_consent = null;
		return $this;
	}
}
