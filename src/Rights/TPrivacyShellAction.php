<?php

/**
 * TPrivacyShellAction class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Rights;

use belisoful\Privacy\Retention\TProcessingRegistry;
use belisoful\Privacy\Retention\TRetentionModule;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\Shell\TShellAction;
use Prado\Shell\TShellApplication;
use Prado\TModule;
use Prado\TPropertyValue;

/**
 * TPrivacyShellAction class.
 *
 * TPrivacyShellAction runs the package's operations from the command line: data subject requests
 * for an operator who has verified the requester out of band, the retention purge, and the records
 * of processing. `TPrivacyModule`, `TRetentionModule` and `TProcessingRegistry` register it in a
 * shell application; each command finds its module by type.
 *
 * | Command | Does |
 * |---|---|
 * | `prado-cli privacy/export <user>` | prints the subject's export as JSON |
 * | `prado-cli privacy/erase <user>` | prints the export item count per provider; with `--yes`, erases the subject's data and prints the result per provider |
 * | `prado-cli privacy/extend <id> <period> <reason>` | moves an open request's due date by an ISO 8601 duration and records the reason |
 * | `prado-cli privacy/requests [overdue]` | lists the open requests (`pending`, `partial`, `failed`), or the overdue ones |
 * | `prado-cli privacy/purge` | runs the retention purge and prints the count per policy |
 * | `prado-cli privacy/ropa [json]` | prints the records of processing as Markdown, or JSON |
 *
 * `export` and `erase` take these options:
 *
 * | Option | Does |
 * |---|---|
 * | `--by=<name>` | records who ran the request (`operator` by default) |
 * | `--email=<address>` | sets the subject's email address |
 * | `--id=<kind>:<value>` | adds an identifier to the subject; repeatable (`--id=customer:C-1 --id=phone:555`) |
 * | `--request=<id>` | answers an open request instead of opening a new one |
 * | `--yes` | `erase` only: erases; without it, `erase` is a dry run |
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TPrivacyShellAction extends TShellAction
{
	protected $action = 'privacy';
	protected $methods = ['export', 'erase', 'extend', 'requests', 'purge', 'ropa'];
	protected $parameters = ['user', 'user', ['id', 'period', 'reason'], null, null, null];
	protected $optional = [null, null, null, 'overdue', null, 'format'];
	protected $description = [
		'Privacy: data subject requests, retention and records of processing.',
		'Prints the export of a user\'s personal data as JSON. Options: --by, --email, --id=kind:value, --request.',
		'Prints what would be erased; with --yes, erases a user\'s personal data and prints the result per provider. Options: --by, --email, --id=kind:value, --request.',
		'Moves an open request\'s due date by an ISO 8601 duration (P2M, P45D) and records the reason.',
		'Lists the open requests, or with "overdue" those past their due date.',
		'Removes personal data past its retention period and prints the count per policy.',
		'Prints the records of processing activities as Markdown, or with "json" as JSON.',
	];

	/** @var ?TPrivacyModule the module */
	private ?TPrivacyModule $_module = null;

	/** @var ?TRetentionModule the retention module */
	private ?TRetentionModule $_retention = null;

	/** @var ?TProcessingRegistry the registry */
	private ?TProcessingRegistry $_registry = null;

	/** @var string who runs the requests */
	private string $_by = 'operator';

	/** @var ?string the subject's email address */
	private ?string $_email = null;

	/** @var array<string, string> the subject's other identifiers, by kind */
	private array $_identifiers = [];

	/** @var ?int the open request to answer */
	private ?int $_request = null;

	/** @var bool whether `erase` erases */
	private bool $_yes = false;

	/**
	 * Registers the action with a shell application, once; a `TPrivacyModule` becomes its module.
	 * @param TModule $module the module registering it
	 * @return bool whether the action was registered now
	 */
	public static function register(TModule $module): bool
	{
		$app = $module->getApplication();
		if (!($app instanceof TShellApplication) || $app->hasShellActionClass(self::class)) {
			return false;
		}
		$app->addShellActionClass(['class' => self::class] + ($module instanceof TPrivacyModule ? ['Module' => $module] : []));
		return true;
	}

	/**
	 * @param string $methodID the command
	 * @return string[] the `--option` names the command accepts
	 */
	public function options($methodID): array
	{
		return match ($methodID) {
			'export' => ['by', 'email', 'id', 'request'],
			'erase' => ['by', 'email', 'id', 'request', 'yes'],
			default => [],
		};
	}

	/**
	 * @return ?TPrivacyModule the module; the application's first `TPrivacyModule` when none is set
	 */
	public function getModule(): ?TPrivacyModule
	{
		if ($this->_module === null) {
			foreach (Prado::getApplication()->getModulesByType(TPrivacyModule::class) as $module) {
				$this->_module = $module;
				break;
			}
		}
		return $this->_module;
	}

	/**
	 * @param ?TPrivacyModule $value the module
	 */
	public function setModule(?TPrivacyModule $value): void
	{
		$this->_module = $value;
	}

	/**
	 * @return ?TRetentionModule the retention module; the application's first when none is set
	 */
	public function getRetentionModule(): ?TRetentionModule
	{
		foreach ($this->_retention === null ? Prado::getApplication()->getModulesByType(TRetentionModule::class) : [] as $module) {
			$this->_retention = $module;
			break;
		}
		return $this->_retention;
	}

	/**
	 * @param ?TRetentionModule $value the retention module
	 */
	public function setRetentionModule(?TRetentionModule $value): void
	{
		$this->_retention = $value;
	}

	/**
	 * @return ?TProcessingRegistry the registry; the application's first when none is set
	 */
	public function getRegistry(): ?TProcessingRegistry
	{
		foreach ($this->_registry === null ? Prado::getApplication()->getModulesByType(TProcessingRegistry::class) : [] as $module) {
			$this->_registry = $module;
			break;
		}
		return $this->_registry;
	}

	/**
	 * @param ?TProcessingRegistry $value the registry
	 */
	public function setRegistry(?TProcessingRegistry $value): void
	{
		$this->_registry = $value;
	}

	/**
	 * @return string who runs the requests, recorded in the request log
	 */
	public function getBy(): string
	{
		return $this->_by;
	}

	/**
	 * @param string $value who runs the requests; empty restores `operator`
	 */
	public function setBy($value): void
	{
		$this->_by = \trim((string) $value) ?: 'operator';
	}

	/**
	 * @return ?string the subject's email address
	 */
	public function getEmail(): ?string
	{
		return $this->_email;
	}

	/**
	 * @param mixed $value the subject's email address; empty for none
	 */
	public function setEmail($value): void
	{
		$this->_email = \trim((string) $value) ?: null;
	}

	/**
	 * @return array<string, string> the subject's other identifiers, by kind
	 */
	public function getId(): array
	{
		return $this->_identifiers;
	}

	/**
	 * Adds an identifier to the subject; `--id` repeats.
	 * @param mixed $value an identifier, `kind:value`
	 * @throws TInvalidDataValueException when the value is not `kind:value`
	 */
	public function setId($value): void
	{
		$parts = \explode(':', \trim((string) $value), 2);
		if (\count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
			throw new TInvalidDataValueException('privacy_subject_identifier_invalid', $value);
		}
		$this->_identifiers[$parts[0]] = $parts[1];
	}

	/**
	 * @return ?int the open request to answer; null opens a new one
	 */
	public function getRequest(): ?int
	{
		return $this->_request;
	}

	/**
	 * @param mixed $value the open request to answer; empty opens a new one
	 */
	public function setRequest($value): void
	{
		$this->_request = $value === '' || $value === null ? null : TPropertyValue::ensureInteger($value);
	}

	/**
	 * @return bool whether `erase` erases; false makes it a dry run
	 */
	public function getYes(): bool
	{
		return $this->_yes;
	}

	/**
	 * @param mixed $value whether `erase` erases; a bare `--yes` is true
	 */
	public function setYes($value): void
	{
		$this->_yes = $value === '' || TPropertyValue::ensureBoolean($value);
	}

	/**
	 * Prints a user's export as JSON.
	 * @param array $args the arguments: the action, then the user name
	 * @return bool true
	 */
	public function actionExport($args)
	{
		if (($module = $this->requirePrivacyModule()) !== null) {
			$this->getWriter()->writeLine($module->exportJson($this->subject($args), $this->getBy(), $this->getRequest()));
		}
		return true;
	}

	/**
	 * Erases a user's data and prints the result per provider. Without `--yes`, prints the export
	 * item count per provider and erases nothing.
	 * @param array $args the arguments: the action, then the user name
	 * @return bool true
	 */
	public function actionErase($args)
	{
		if (($module = $this->requirePrivacyModule()) === null) {
			return true;
		}
		$writer = $this->getWriter();
		$subject = $this->subject($args);
		if (!$this->getYes()) {
			$writer->writeLine('Dry run: nothing is erased. Add --yes to erase.');
			foreach ($module->getProviders() as $name => $provider) {
				try {
					$found = \count($provider->exportPersonalData($subject)) . ' items';
				} catch (\Throwable $e) {
					$found = 'export failed (' . $e::class . ')';
				}
				$writer->writeLine($name . ': ' . $found);
			}
			return true;
		}
		foreach ($module->erase($subject, $this->getBy(), $this->getRequest()) as $name => $result) {
			$retained = \array_map(fn ($group) => $group['count'] . ' (' . $group['reason'] . ')', $result->getRetained());
			$writer->writeLine($name . ': erased ' . $result->getErased()
				. ($retained === [] ? '' : '; retained ' . \implode(', ', $retained))
				. ($result->getErrors() === [] ? '' : '; errors ' . \implode(', ', $result->getErrors())));
		}
		return true;
	}

	/**
	 * Moves an open request's due date and prints the new one.
	 * @param array $args the arguments: the action, the request id, the period, then the reason
	 * @return bool true
	 */
	public function actionExtend($args)
	{
		if (($module = $this->requirePrivacyModule()) !== null) {
			$due = $module->extendRequest((int) $args[1], (string) $args[2], \implode(' ', \array_slice($args, 3)));
			$this->getWriter()->writeLine('#' . (int) $args[1] . ' is due ' . \gmdate('Y-m-d', $due) . '.');
		}
		return true;
	}

	/**
	 * Lists the open requests, or the overdue ones.
	 * @param array $args the arguments: the action, then optionally `overdue`
	 * @return bool true
	 */
	public function actionRequests($args)
	{
		if (($module = $this->requirePrivacyModule()) === null) {
			return true;
		}
		$overdue = ($args[1] ?? '') === 'overdue';
		$entries = $overdue ? $module->getOverdueRequests() : $module->getRequestLog()->findOpen();
		$writer = $this->getWriter();
		if ($entries === []) {
			$writer->writeLine($overdue ? 'No overdue requests.' : 'No open requests.');
		}
		foreach ($entries as $entry) {
			$writer->writeLine('#' . $entry['id'] . ' ' . $entry['type'] . ' ' . $entry['status'] . ', requested ' . \gmdate('Y-m-d', $entry['requested_at'])
				. ', due ' . \gmdate('Y-m-d', $entry['due_at']) . ', by ' . ($entry['requested_by'] ?? '-'));
		}
		return true;
	}

	/**
	 * Runs the retention purge and prints the count per policy.
	 * @param array $args the arguments
	 * @return bool true
	 */
	public function actionPurge($args)
	{
		$retention = $this->getRetentionModule();
		if ($retention === null) {
			$this->getWriter()->writeError('No TRetentionModule is configured.');
			return true;
		}
		foreach ($retention->purgePolicies() as $name => $count) {
			$this->getWriter()->writeLine($name . ': ' . ($count < 0 ? 'failed (see the application log)' : $count . ' removed'));
		}
		return true;
	}

	/**
	 * Prints the records of processing activities.
	 * @param array $args the arguments: the action, then optionally `json`
	 * @return bool true
	 */
	public function actionRopa($args)
	{
		$registry = $this->getRegistry();
		if ($registry === null) {
			$this->getWriter()->writeError('No TProcessingRegistry is configured.');
			return true;
		}
		$this->getWriter()->writeLine(($args[1] ?? '') === 'json' ? $registry->toJson() : $registry->toMarkdown());
		return true;
	}

	/**
	 * @param array $args the arguments: the action, then the user name
	 * @return TDataSubject the subject the user name, `--email` and `--id` describe
	 */
	protected function subject(array $args): TDataSubject
	{
		return new TDataSubject((string) $args[1], $this->getEmail(), $this->getId());
	}

	/**
	 * @return ?TPrivacyModule the privacy module; null, after printing an error, when none is configured
	 */
	protected function requirePrivacyModule(): ?TPrivacyModule
	{
		$module = $this->getModule();
		if ($module === null) {
			$this->getWriter()->writeError('No TPrivacyModule is configured.');
		}
		return $module;
	}
}
