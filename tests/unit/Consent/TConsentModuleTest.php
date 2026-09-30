<?php

namespace belisoful\Privacy\Test\Unit\Consent;

use belisoful\Privacy\Consent\IConsentLog;
use belisoful\Privacy\Consent\Region\IRegionResolver;
use belisoful\Privacy\Consent\Region\THeaderRegionResolver;
use belisoful\Privacy\Consent\Region\TScopeAllRegionResolver;
use belisoful\Privacy\Consent\TConsentCategory;
use belisoful\Privacy\Consent\TConsentChangedEventParameter;
use belisoful\Privacy\Consent\TConsentLogContext;
use belisoful\Privacy\Consent\TConsentMode;
use belisoful\Privacy\Consent\TConsentModule;
use belisoful\Privacy\Consent\TConsentRecord;
use belisoful\Privacy\Consent\TCookieConsentStore;
use belisoful\Privacy\Consent\TDbConsentLog;
use belisoful\Privacy\Consent\TGpcMode;
use belisoful\Privacy\Consent\TSessionConsentStore;
use belisoful\Privacy\Test\Unit\PrivacyTestCase;
use belisoful\Privacy\Test\Unit\ProbeConsentModule;
use belisoful\Privacy\Test\Unit\TestDb;
use belisoful\Privacy\TPrivacyHash;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\TComponent;
use Prado\Web\THttpRequest;
use Prado\Xml\TXmlDocument;

class TConsentModuleTest extends PrivacyTestCase
{
	/** @var TConsentChangedEventParameter[] */
	private array $events = [];

	private function module(): ProbeConsentModule
	{
		$module = new ProbeConsentModule();
		$module->setStore(new TSessionConsentStore());
		$module->attachEventHandler('onConsentChanged', function ($sender, $param) {
			$this->events[] = $param;
		});
		return $module;
	}

	private function region(TConsentModule $module, ?string $region): void
	{
		$module->setRegionResolver(new class ($region) extends TComponent implements IRegionResolver {
			public function __construct(private ?string $answer)
			{
				parent::__construct();
			}

			public function resolveRegion(THttpRequest $request): ?string
			{
				return $this->answer;
			}
		});
	}

	// --- configuration -----------------------------------------------------------------------

	public function testDefaults()
	{
		$module = new TConsentModule();
		$this->assertSame(TConsentModule::DEFAULT_VERSION, $module->getVersion());
		$this->assertSame(180, $module->getExpires());
		$this->assertSame(['necessary', 'analytics', 'marketing', 'functional', 'personalization'], \array_keys($module->getCategories()));
		$this->assertTrue($module->getCategory('necessary')->getRequired());
		$this->assertNull($module->getCategory('video'));
		$this->assertInstanceOf(TCookieConsentStore::class, $module->getStore());
		$this->assertInstanceOf(TScopeAllRegionResolver::class, $module->getRegionResolver());
		$this->assertInstanceOf(TDbConsentLog::class, $module->getLog());
		$this->assertFalse($module->getLogEnabled());
		$this->assertFalse($module->getLogUserAgent());
		$this->assertCount(30 + 1 + 1, $module->getRequireConsentFor());
		$this->assertContains('US-CA', $module->getOptOutFor());
		$this->assertSame(TGpcMode::Everywhere, $module->getHonorGpc());
		$this->assertSame(['marketing'], $module->getGpcCategories());
		$this->assertFalse($module->getPreferencesOpen());
		$this->assertSame([], $module->getConsentControls());
	}

	public function testXmlConfiguration()
	{
		$doc = new TXmlDocument();
		$doc->loadFromString('<module>
			<category Name="video" Title="Video" Description="Players" />
			<category name="analytics" title="Statistics" />
			<store class="belisoful\Privacy\Consent\TSessionConsentStore" SessionKey="k" />
			<log class="belisoful\Privacy\Consent\TDbConsentLog" ConnectionID="db" />
			<resolver class="belisoful\Privacy\Consent\Region\THeaderRegionResolver" CountryHeaders="X-Geo" />
		</module>');
		$module = new TConsentModule();
		$module->init($doc);
		$this->assertSame('Video', $module->getCategory('video')->getTitle());
		$this->assertSame('Statistics', $module->getCategory('analytics')->getTitle());
		$this->assertSame('k', $module->getStore()->getSessionKey());
		$this->assertSame('db', $module->getLog()->getConnectionID());
		$this->assertSame(['X-Geo'], $module->getRegionResolver()->getCountryHeaders());
		$empty = new TConsentModule();
		$empty->init(new TXmlDocument());
		$this->assertInstanceOf(TCookieConsentStore::class, $empty->getStore());
	}

	public function testPhpConfiguration()
	{
		$module = new TConsentModule();
		$module->init([
			'categories' => [['Name' => 'chat', 'Required' => 'true'], new TConsentCategory('maps')],
			'store' => ['class' => TSessionConsentStore::class],
			'resolver' => new THeaderRegionResolver(),
		]);
		$this->assertTrue($module->getCategory('chat')->getRequired());
		$this->assertNotNull($module->getCategory('maps'));
		$this->assertInstanceOf(TSessionConsentStore::class, $module->getStore());
		$this->assertInstanceOf(THeaderRegionResolver::class, $module->getRegionResolver());
		$module->init(null);
		$this->assertInstanceOf(TSessionConsentStore::class, $module->getStore());
	}

	public function testPropertiesAndTheirValidation()
	{
		$module = new TConsentModule();
		$this->assertSame($module, $module->setVersion(' 2026-09 ')->setExpires('395')->setLogEnabled('true')->setLogUserAgent(true)
			->setRequireConsentFor('EU')->setOptOutFor(['US-CA'])->setHonorGpc('REQUIRED')->setGpcCategories(' Marketing , personalization,')
			->setPreferencesOpen('1'));
		$this->assertSame('2026-09', $module->getVersion());
		$this->assertSame(395, $module->getExpires());
		$this->assertTrue($module->getLogEnabled());
		$this->assertTrue($module->getLogUserAgent());
		$this->assertCount(27, $module->getRequireConsentFor());
		$this->assertSame(['US-CA'], $module->getOptOutFor());
		$this->assertSame(TGpcMode::Required, $module->getHonorGpc());
		$this->assertSame(['marketing', 'personalization'], $module->getGpcCategories());
		$this->assertTrue($module->getPreferencesOpen());
		$module->setGpcCategories(['functional']);
		$this->assertSame(['functional'], $module->getGpcCategories());
		$module->setVersion(\str_repeat('v', 64));
		foreach ([
			fn () => $module->setVersion(' '),
			fn () => $module->setVersion(\str_repeat('v', 65)),
			fn () => $module->setExpires(0),
			fn () => $module->setExpires(396),
			fn () => $module->setHonorGpc('sometimes'),
			fn () => $module->setRequireConsentFor('Europe'),
			fn () => $module->addCategory(new TConsentCategory()),
			fn () => $module->addCategory(['Title' => 'no name']),
		] as $call) {
			try {
				$call();
				$this->fail('refused');
			} catch (TInvalidDataValueException $e) {
				$this->assertNotEmpty($e->getErrorCode());
			}
		}
	}

	public function testPartsResolveFromConfigurationAndModuleIds()
	{
		$module = new TConsentModule();
		$module->setStore('');
		$this->assertInstanceOf(TCookieConsentStore::class, $module->getStore());
		$module->setLog(['class' => TDbConsentLog::class, 'ConnectionID' => 'x']);
		$this->assertSame('x', $module->getLog()->getConnectionID());
		$module->setLog('');
		$this->assertInstanceOf(TDbConsentLog::class, $module->getLog());
		$module->setRegionResolver('');
		$this->assertInstanceOf(TScopeAllRegionResolver::class, $module->getRegionResolver());
		$module->setStore('no-such-store');
		$this->expectException(TConfigurationException::class);
		$module->getStore();
	}

	public function testFindModule()
	{
		$this->assertNull(TConsentModule::findModule());
		$this->assertNull(TConsentModule::findModule('privacy'));
		$module = new TConsentModule();
		$this->addModule('privacy', $module);
		$this->assertSame($module, TConsentModule::findModule());
		$this->assertSame($module, TConsentModule::findModule('privacy'));
		$this->addModule('other', new NotAConsentModule());
		$this->assertNull(TConsentModule::findModule('other'));
	}

	// --- modes and regions -------------------------------------------------------------------

	public function testUnknownRegionIsOptInAndUndecided()
	{
		$module = $this->module();
		$this->assertNull($module->getRegion());
		$this->assertSame(TConsentMode::OptIn, $module->getMode());
		$this->assertTrue($module->getIsConsentRequired());
		$this->assertFalse($module->getIsDecided());
		$this->assertSame(['necessary' => 'granted', 'analytics' => 'undecided', 'marketing' => 'undecided', 'functional' => 'undecided', 'personalization' => 'undecided'], $module->getConsent());
		$this->assertFalse($module->isGranted('analytics'));
		$this->assertTrue($module->isGranted('necessary'));
		$this->assertSame('undecided', $module->getCategoryConsent('analytics'));
	}

	public function testRegionsSelectTheMode()
	{
		$module = $this->module();
		foreach (['de' => TConsentMode::OptIn, 'GB' => TConsentMode::OptIn, 'US-CA' => TConsentMode::OptOut, 'US-NY' => TConsentMode::None, 'JP' => TConsentMode::None, 'Mars' => TConsentMode::OptIn] as $region => $mode) {
			$this->region($module, $region);
			$this->assertSame($mode, $module->getMode(), $region);
		}
		$this->region($module, 'US-CA');
		$this->assertSame('US-CA', $module->getRegion());
		$this->assertTrue($module->getIsDecided());
		$this->assertFalse($module->getIsConsentRequired());
		$this->assertTrue($module->isGranted('marketing'));
	}

	public function testRegionIsResolvedOnceAndResetForgetsIt()
	{
		$module = $this->module();
		$this->request->headers['CF-IPCountry'] = 'DE';
		$module->setRegionResolver(new THeaderRegionResolver());
		$this->assertSame('DE', $module->getRegion());
		$this->request->headers['CF-IPCountry'] = 'JP';
		$this->assertSame('DE', $module->getRegion());
		$module->reset();
		$this->assertSame('JP', $module->getRegion());
	}

	// --- choices -----------------------------------------------------------------------------

	public function testAcceptAllStoresLogsAndRaises()
	{
		$module = $this->module();
		$module->setLogEnabled(true)->setLog((new TDbConsentLog())->setDbConnection(TestDb::reset()));
		$record = $module->acceptAll();
		$this->assertSame(\sprintf('%032x', 1), $record->getId());
		$this->assertSame(self::NOW, $record->getTime());
		$this->assertSame(self::NOW + 180 * 86400, $record->getExpires());
		$this->assertSame('banner', $record->getSource());
		$this->assertTrue($module->getIsDecided());
		$this->assertSame(\array_fill_keys(\array_keys($module->getCategories()), 'granted'), $module->getConsent());
		$this->assertCount(1, $this->events);
		$this->assertSame(['analytics' => 'granted', 'marketing' => 'granted', 'functional' => 'granted', 'personalization' => 'granted'], $this->events[0]->getChanged());
		$this->assertSame($record, $this->events[0]->getRecord());
		$entries = $module->getLog()->findByConsentId($record->getId());
		$this->assertCount(1, $entries);
		$this->assertSame(TPrivacyHash::hash('203.0.113.7', TPrivacyHash::IP), $entries[0]['ip_hash']);
		$this->assertNull($entries[0]['user_agent_hash']);
		$this->assertNull($entries[0]['subject_hash']);
	}

	public function testTheSameChoiceTwiceRaisesOnceAndKeepsTheId()
	{
		$module = $this->module();
		$first = $module->rejectAll();
		$second = $module->rejectAll();
		$this->assertSame($first->getId(), $second->getId());
		$this->assertCount(1, $this->events);
		$this->assertSame('denied', $module->getCategoryConsent('analytics'));
	}

	public function testSetConsentMergesOverTheCurrentChoice()
	{
		$module = $this->module();
		$module->setConsent(['analytics' => 'GRANTED', 'marketing' => 0, 'necessary' => false]);
		$this->assertSame(['necessary' => 'granted', 'analytics' => 'granted', 'marketing' => 'denied', 'functional' => 'denied', 'personalization' => 'denied'], $module->getConsent());
		$module->setConsent(['functional' => 'true'], TConsentModule::SOURCE_PREFERENCES);
		$this->assertSame('granted', $module->getCategoryConsent('analytics'), 'kept');
		$this->assertSame('granted', $module->getCategoryConsent('functional'));
		$this->assertSame('preferences', $module->getRecord()->getSource());
	}

	public function testSetConsentRefusesUnknownCategoriesAndValues()
	{
		$module = $this->module();
		foreach ([['video' => true], ['analytics' => 'maybe'], ['analytics' => null], ['analytics' => []]] as $choice) {
			try {
				$module->setConsent($choice);
				$this->fail('refused');
			} catch (TInvalidDataValueException $e) {
				$this->assertContains($e->getErrorCode(), ['privacy_category_unknown', 'privacy_consent_value_invalid']);
			}
		}
		$this->expectException(TInvalidDataValueException::class);
		$module->getCategoryConsent('video');
	}

	public function testALoggedInUsersChoiceIsLoggedWithTheirHashAndTheUserAgent()
	{
		$this->login('alice');
		$module = $this->module()->setLogEnabled(true)->setLogUserAgent(true);
		$log = new RecordingConsentLog();
		$module->setLog($log);
		$module->acceptAll();
		$context = $log->entries[0][1];
		$this->assertSame(TPrivacyHash::hash('alice', TPrivacyHash::SUBJECT), $context->getSubjectHash());
		$this->assertSame(TPrivacyHash::hash('Mozilla/5.0 (Test)', TPrivacyHash::USER_AGENT), $context->getUserAgentHash());
		$this->assertNull($context->getRegion());
		$this->assertFalse($context->getGpc());
		$this->request->ip = '';
		$this->request->agent = '';
		$context = $module->createLogContext();
		$this->assertNull($context->getIpHash());
		$this->assertNull($context->getUserAgentHash());
	}

	public function testAnotherVersionOrAnExpiredChoiceIsUndecided()
	{
		$module = $this->module();
		$module->acceptAll();
		$module->setVersion('2');
		$this->assertNull($module->getRecord());
		$this->assertNotNull($module->getStoredRecord());
		$this->assertFalse($module->getIsDecided());
		$this->assertSame('undecided', $module->getCategoryConsent('analytics'));
		$record = $module->rejectAll();
		$this->assertSame(\sprintf('%032x', 1), $record->getId(), 'a new version keeps the consent id');
		$this->clock->setTime(self::NOW + 180 * 86400);
		$module->reset();
		$this->assertNull($module->getRecord());
		$this->assertSame('undecided', $module->getCategoryConsent('analytics'));
	}

	public function testANewCategoryWithoutANewVersionIsUndecidedOnItsOwn()
	{
		$module = $this->module();
		$module->acceptAll();
		$module->addCategory(new TConsentCategory('video'));
		$this->assertTrue($module->getIsDecided());
		$this->assertSame('undecided', $module->getCategoryConsent('video'));
		$this->assertSame('granted', $module->getCategoryConsent('analytics'));
	}

	public function testWithdrawClearsLogsAndAsksAgain()
	{
		$module = $this->module()->setLogEnabled(true);
		$log = new RecordingConsentLog();
		$module->setLog($log);
		$module->acceptAll();
		$this->clock->setTime(self::NOW + 60);
		$module->withdraw();
		$this->assertFalse($module->getIsDecided());
		$this->assertSame([], $this->session->data);
		$this->assertCount(2, $log->entries);
		$withdrawal = $log->entries[1][0];
		$this->assertSame('withdraw', $withdrawal->getSource());
		$this->assertSame(self::NOW + 60, $withdrawal->getTime());
		$this->assertSame('granted', $withdrawal->getState('necessary'));
		$this->assertSame('denied', $withdrawal->getState('analytics'));
		$this->assertCount(2, $this->events);
		$this->assertNull($this->events[1]->getRecord());
		$this->assertSame('withdraw', $this->events[1]->getSource());
		$module->withdraw();
		$this->assertCount(2, $log->entries, 'nothing to withdraw logs nothing');
		$this->assertCount(2, $this->events);
	}

	public function testDyConsentAndDyResolveRegionFilter()
	{
		$module = $this->module();
		$module->attachBehavior('policy', new class () extends \Prado\Util\TBehavior {
			public function dyResolveRegion($region, $request, $chain)
			{
				return $chain->dyResolveRegion('us-ny', $request);
			}

			public function dyConsent($consent, $chain)
			{
				$consent['functional'] = 'denied';
				return $chain->dyConsent($consent);
			}
		});
		$this->assertSame('US-NY', $module->getRegion());
		$this->assertSame('denied', $module->getCategoryConsent('functional'));
		$this->assertSame('granted', $module->getCategoryConsent('analytics'));
	}

	// --- Global Privacy Control --------------------------------------------------------------

	public function testGpcSignal()
	{
		$module = $this->module();
		$this->assertFalse($module->getGpcSignal());
		$this->request->headers['Sec-GPC'] = ' 1 ';
		$this->assertTrue($module->getGpcSignal());
		$this->request->headers['Sec-GPC'] = '0';
		$this->assertFalse($module->getGpcSignal());
	}

	public function testGpcHonoredByMode()
	{
		$module = $this->module();
		$this->assertFalse($module->getIsGpcHonored(), 'no signal');
		$this->request->headers['Sec-GPC'] = '1';
		$cases = [
			[TGpcMode::Everywhere, 'DE', true], [TGpcMode::Everywhere, 'JP', true],
			[TGpcMode::Required, 'US-CA', true], [TGpcMode::Required, 'DE', false], [TGpcMode::Required, 'JP', false],
			[TGpcMode::Off, 'US-CA', false],
		];
		foreach ($cases as [$mode, $region, $honored]) {
			$module->setHonorGpc($mode);
			$this->region($module, $region);
			$this->assertSame($honored, $module->getIsGpcHonored(), $mode . ' ' . $region);
		}
	}

	public function testGpcInAnOptOutRegionDeniesMarketingOverABannerGrant()
	{
		$module = $this->module();
		$this->region($module, 'US-CA');
		$this->request->headers['Sec-GPC'] = '1';
		$this->assertSame('denied', $module->getCategoryConsent('marketing'), 'before any choice');
		$this->assertSame('granted', $module->getCategoryConsent('analytics'));
		$module->setConsent(['marketing' => true], TConsentModule::SOURCE_BANNER);
		$this->assertSame('denied', $module->getCategoryConsent('marketing'), 'a banner grant does not override the signal');
		$module->setConsent(['marketing' => true], TConsentModule::SOURCE_PREFERENCES);
		$this->assertSame('granted', $module->getCategoryConsent('marketing'), 'an explicit per-category grant stands (§7025(c)(3))');
	}

	public function testGpcInAnOptInRegionPreDeniesMarketingAndAcceptAllLeavesItDenied()
	{
		$module = $this->module();
		$this->request->headers['Sec-GPC'] = '1';
		$this->assertSame('denied', $module->getCategoryConsent('marketing'));
		$this->assertSame('undecided', $module->getCategoryConsent('analytics'));
		$this->assertFalse($module->getIsDecided());
		$record = $module->acceptAll();
		$this->assertSame('denied', $record->getState('marketing'));
		$this->assertSame('granted', $record->getState('analytics'));
		$module->setConsent(['marketing' => 'granted'], TConsentModule::SOURCE_API);
		$this->assertSame('granted', $module->getCategoryConsent('marketing'));
	}

	// --- controls ----------------------------------------------------------------------------

	public function testConsentControlsRegisterOnce()
	{
		$module = $this->module();
		$control = new \belisoful\Privacy\Consent\Controls\TConsentGate();
		$module->registerConsentControl($control);
		$module->registerConsentControl($control);
		$this->assertSame([$control], $module->getConsentControls());
	}

	public function testRealConsentIdsAreRandomHex()
	{
		$module = new ProbeConsentModule();
		$this->assertMatchesRegularExpression(TConsentRecord::ID_PATTERN, $module->realConsentId());
		$this->assertNotSame($module->realConsentId(), $module->realConsentId());
	}
}

/**
 * An IConsentLog that records its calls.
 */
class RecordingConsentLog extends TComponent implements IConsentLog
{
	/** @var array<int, array{0: TConsentRecord, 1: TConsentLogContext}> */
	public array $entries = [];

	public function record(TConsentRecord $record, TConsentLogContext $context): void
	{
		$this->entries[] = [$record, $context];
	}

	public function findByConsentId(string $consentId): array
	{
		return [];
	}

	public function findBySubject(string $subjectHash): array
	{
		return [];
	}

	public function purge(int $before): int
	{
		return 0;
	}
}

/**
 * A module that is not a consent module.
 */
class NotAConsentModule extends \Prado\TModule
{
}
