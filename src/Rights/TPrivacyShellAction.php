<?php

/**
 * TPrivacyShellAction class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Rights;

use Prado\Prado;
use Prado\Shell\TShellAction;
use Prado\Shell\TShellWriter;

/**
 * TPrivacyShellAction class.
 *
 * TPrivacyShellAction runs data subject requests from the command line, for an operator who has
 * verified the requester out of band. `TPrivacyModule` registers it in a shell application.
 *
 * | Command | Does |
 * |---|---|
 * | `prado-cli privacy/export <user>` | prints the subject's export as JSON |
 * | `prado-cli privacy/erase <user>` | erases the subject's data and prints the result per provider |
 * | `prado-cli privacy/requests [overdue]` | lists the pending requests, or the overdue ones |
 *
 * `--by=<name>` records who ran the request (`operator` by default).
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TPrivacyShellAction extends TShellAction
{
	protected $action = 'privacy';
	protected $methods = ['export', 'erase', 'requests'];
	protected $parameters = ['user', 'user', null];
	protected $optional = [null, null, 'overdue'];
	protected $description = [
		'Privacy: answer data subject requests.',
		'Prints the export of a user\'s personal data as JSON.',
		'Erases a user\'s personal data and prints the result per provider.',
		'Lists the pending requests, or with "overdue" those past their due date.',
	];

	/** @var ?TPrivacyModule the module */
	private ?TPrivacyModule $_module = null;

	/** @var string who runs the requests */
	private string $_by = 'operator';

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
		if (($module = $this->requireModule()) !== null) {
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
		if (($module = $this->requireModule()) === null) {
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
		if (($module = $this->requireModule()) === null) {
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
	 * @return ?TPrivacyModule the module; null, after printing an error, when none is configured
	 */
	protected function requireModule(): ?TPrivacyModule
	{
		$module = $this->getModule();
		if ($module === null) {
			$this->getWriter()->writeError('No TPrivacyModule is configured.');
		}
		return $module;
	}
}
