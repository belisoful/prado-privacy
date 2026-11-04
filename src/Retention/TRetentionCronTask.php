<?php

/**
 * TRetentionCronTask class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Retention;

use Prado\Exceptions\TCancelledException;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidOperationException;
use Prado\Util\Cron\TCronTask;

/**
 * TRetentionCronTask class.
 *
 * TRetentionCronTask runs {@see TRetentionModule::purge()} for `TCronModule` with the run's
 * cancellation token. `TCronModule.TaskTimeout`, or the job's own `Timeout`, sets the deadline;
 * the purge checks it before each policy and stops with a `TCancelledException` once it passes.
 * `ModuleId` names the retention module; without it the task uses the application's first
 * `TRetentionModule`.
 *
 * ```xml
 * <module id="cron" class="Prado\Util\Cron\TCronModule" TaskTimeout="600">
 *   <job Name="privacy-retention" Schedule="17 3 * * *" Task="belisoful\Privacy\Retention\TRetentionCronTask" ModuleId="retention" />
 * </module>
 * ```
 *
 * A policy that fails makes the task fail, so `TCronModule` logs it as an error. A running
 * `DELETE` statement is not interrupted: the deadline takes effect between policies.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TRetentionCronTask extends TCronTask
{
	/**
	 * Purges every policy of the retention module with the run's cancellation token.
	 * @param \Prado\Util\Cron\TCronModule $cronModule the module running the task
	 * @throws TConfigurationException when there is no retention module
	 * @throws TCancelledException when the run's deadline passes
	 * @throws TInvalidOperationException when a policy failed
	 */
	public function execute($cronModule)
	{
		$this->getRetentionModule()->purge($this->getCancellationToken());
	}

	/**
	 * @throws TConfigurationException when `ModuleId` names no module or a module that is not a `TRetentionModule`, or there is none
	 * @return TRetentionModule the module named by `ModuleId`, or the application's first `TRetentionModule`
	 */
	public function getRetentionModule(): TRetentionModule
	{
		$id = (string) $this->getModuleId();
		if ($id !== '') {
			$module = $this->getApplication()->getModule($id);
		} else {
			$modules = $this->getApplication()->getModulesByType(TRetentionModule::class);
			$module = \reset($modules);
		}
		if (!($module instanceof TRetentionModule)) {
			throw new TConfigurationException('privacy_retention_module_missing', $id);
		}
		return $module;
	}
}
