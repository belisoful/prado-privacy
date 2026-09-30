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
use Prado\Prado;
use Prado\Shell\TShellAction;
use Prado\Shell\TShellApplication;
use Prado\TModule;

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
 * | `prado-cli privacy/erase <user>` | erases the subject's data and prints the result per provider |
 * | `prado-cli privacy/requests [overdue]` | lists the pending requests, or the overdue ones |
 * | `prado-cli privacy/purge` | runs the retention purge and prints the count per policy |
 * | `prado-cli privacy/ropa [json]` | prints the records of processing as Markdown, or JSON |
 *
 * `--by=<name>` records who ran the request (`operator` by default).
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TPrivacyShellAction extends TShellAction
{
	protected $action = 'privacy';
	protected $methods = ['export', 'erase', 'requests', 'purge', 'ropa'];
	protected $parameters = ['user', 'user', null, null, null];
	protected $optional = [null, null, 'overdue', null, 'format'];
	protected $description = [
		'Privacy: data subject requests, retention and records of processing.',
		'Prints the export of a user\'s personal data as JSON.',
		'Erases a user\'s personal data and prints the result per provider.',
		'Lists the pending requests, or with "overdue" those past their due date.',
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
		return \in_array($methodID, ['export', 'erase'], true) ? ['by'] : [];
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
	 * Prints a user's export as JSON.
	 * @param array $args the arguments: the action, then the user name
	 * @return bool true
	 */
	public function actionExport($args)
	{
		if (($module = $this->requirePrivacyModule()) !== null) {
			$this->getWriter()->writeLine($module->exportJson(new TDataSubject((string) $args[1]), $this->getBy()));
		}
		return true;
	}

	/**
	 * Erases a user's data and prints the result per provider.
	 * @param array $args the arguments: the action, then the user name
	 * @return bool true
	 */
	public function actionErase($args)
	{
		if (($module = $this->requirePrivacyModule()) === null) {
			return true;
		}
		$writer = $this->getWriter();
		foreach ($module->erase(new TDataSubject((string) $args[1]), $this->getBy()) as $name => $result) {
			$retained = \array_map(fn ($group) => $group['count'] . ' (' . $group['reason'] . ')', $result->getRetained());
			$writer->writeLine($name . ': erased ' . $result->getErased()
				. ($retained === [] ? '' : '; retained ' . \implode(', ', $retained))
				. ($result->getErrors() === [] ? '' : '; errors ' . \implode(', ', $result->getErrors())));
		}
		return true;
	}

	/**
	 * Lists the pending requests, or the overdue ones.
	 * @param array $args the arguments: the action, then optionally `overdue`
	 * @return bool true
	 */
	public function actionRequests($args)
	{
		if (($module = $this->requirePrivacyModule()) === null) {
			return true;
		}
		$overdue = ($args[1] ?? '') === 'overdue';
		$entries = $overdue ? $module->getOverdueRequests() : $module->getRequestLog()->findPending();
		$writer = $this->getWriter();
		if ($entries === []) {
			$writer->writeLine($overdue ? 'No overdue requests.' : 'No pending requests.');
		}
		foreach ($entries as $entry) {
			$writer->writeLine('#' . $entry['id'] . ' ' . $entry['type'] . ' requested ' . \gmdate('Y-m-d', $entry['requested_at'])
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
		foreach ($retention->purge() as $name => $count) {
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
