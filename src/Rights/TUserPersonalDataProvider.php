<?php

/**
 * TUserPersonalDataProvider class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Rights;

use Prado\Exceptions\TConfigurationException;
use Prado\Security\IUserManager;
use Prado\Security\TAuthManager;
use Prado\Security\TUserManager;
use Prado\TModule;
use Prado\TPropertyValue;

/**
 * TUserPersonalDataProvider class.
 *
 * TUserPersonalDataProvider answers for the application's users, through the user manager
 * (`UserManager`, or the first `TAuthManager`'s, or the first `IUserManager` module):
 *
 * | Request | Result |
 * |---|---|
 * | export | the user's name, roles and the state `TUser` keeps; `onExportUser` handlers add fields |
 * | erase | `onEraseUser` handlers delete the user and record it; PRADO has no user-deletion API |
 * | rectify | `onRectifyUser` handlers apply the changes |
 *
 * Without an `onEraseUser` handler an existing user is reported as retained. A `TUserManager`
 * user is defined in the application configuration and is retained with that reason.
 *
 * ```php
 * $provider->attachEventHandler('onEraseUser', function ($sender, TPersonalDataEventParameter $param) {
 *     $deleted = UserRecord::finder()->deleteAll('username = ?', $param->getSubject()->getName());
 *     $param->getResult()->addErased($deleted);
 * });
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TUserPersonalDataProvider extends TModule implements IPersonalDataProvider
{
	/** @var string the provider's name */
	private string $_name = 'user';

	/** @var string the id of the user manager module */
	private string $_userManager = '';

	/**
	 * @return string the provider's name; `user` by default
	 */
	public function getPersonalDataName(): string
	{
		return $this->_name;
	}

	/**
	 * @param mixed $value the provider's name, the key of its data in an export
	 * @return static the provider
	 */
	public function setPersonalDataName($value): static
	{
		$this->_name = \trim(TPropertyValue::ensureString($value)) ?: 'user';
		return $this;
	}

	/**
	 * Exports the user's name, roles and stored state, and the fields `onExportUser` handlers add.
	 * @param TDataSubject $subject the verified subject
	 * @return array the user's data; empty when the user manager has no such user
	 */
	public function exportPersonalData(TDataSubject $subject): array
	{
		$user = $this->getUserManager()->getUser($subject->getName());
		if ($user === null) {
			return [];
		}
		$state = @\unserialize((string) $user->saveToString(), ['allowed_classes' => false]);
		$param = new TPersonalDataEventParameter($subject);
		$param->setData(['name' => (string) $user->getName(), 'roles' => (array) $user->getRoles(), 'state' => \is_array($state) ? $state : []]);
		$this->onExportUser($param);
		return $param->getData();
	}

	/**
	 * Raises `onEraseUser`; without a handler, reports an existing user as retained.
	 * @param TDataSubject $subject the verified subject
	 * @return TErasureResult what the handlers erased and retained
	 */
	public function erasePersonalData(TDataSubject $subject): TErasureResult
	{
		$result = new TErasureResult();
		$manager = $this->getUserManager();
		if ($manager->getUser($subject->getName()) === null) {
			return $result;
		}
		if ($manager instanceof TUserManager) {
			return $result->addRetained(1, 'the user is defined in the application configuration (TUserManager)');
		}
		if (!$this->hasEventHandler('onEraseUser')) {
			return $result->addRetained(1, 'no onEraseUser handler deletes application users');
		}
		$this->onEraseUser(new TPersonalDataEventParameter($subject, $result));
		return $result;
	}

	/**
	 * Raises `onRectifyUser`.
	 * @param TDataSubject $subject the verified subject
	 * @param array<string, mixed> $changes field => corrected value
	 * @return bool whether a handler applied the changes
	 */
	public function rectifyPersonalData(TDataSubject $subject, array $changes): bool
	{
		$param = new TPersonalDataEventParameter($subject, null, $changes);
		$this->onRectifyUser($param);
		return $param->getApplied();
	}

	/**
	 * Raised by an export: a handler adds fields to `Data`.
	 * @param TPersonalDataEventParameter $param the subject and the data
	 * @return array the handlers' results
	 */
	public function onExportUser($param)
	{
		return $this->raiseEvent('onExportUser', $this, $param);
	}

	/**
	 * Raised by an erasure: a handler deletes the user and records it in `Result`.
	 * @param TPersonalDataEventParameter $param the subject and the result
	 * @return array the handlers' results
	 */
	public function onEraseUser($param)
	{
		return $this->raiseEvent('onEraseUser', $this, $param);
	}

	/**
	 * Raised by a rectification: a handler applies `Changes` and calls `setApplied(true)`.
	 * @param TPersonalDataEventParameter $param the subject and the changes
	 * @return array the handlers' results
	 */
	public function onRectifyUser($param)
	{
		return $this->raiseEvent('onRectifyUser', $this, $param);
	}

	/**
	 * @throws TConfigurationException when no user manager is found
	 * @return IUserManager the user manager: `UserManager`, the first `TAuthManager`'s, or the first `IUserManager` module
	 */
	public function getUserManager(): IUserManager
	{
		$app = $this->getApplication();
		if ($this->_userManager !== '') {
			$manager = $app->getModule($this->_userManager);
		} else {
			$manager = null;
			foreach ($app->getModulesByType(TAuthManager::class) as $auth) {
				// An auth manager holds the configured module id until it initializes.
				$manager = $auth->getUserManager();
				$manager = \is_string($manager) ? $app->getModule($manager) : $manager;
				break;
			}
			foreach ($manager === null ? $app->getModulesByType(IUserManager::class) : [] as $candidate) {
				$manager = $candidate;
				break;
			}
		}
		if (!($manager instanceof IUserManager)) {
			throw new TConfigurationException('privacy_user_manager_missing', $this->_userManager);
		}
		return $manager;
	}

	/**
	 * @param mixed $value the id of the user manager module; empty finds one
	 * @return static the provider
	 */
	public function setUserManager($value): static
	{
		$this->_userManager = \trim(TPropertyValue::ensureString($value));
		return $this;
	}
}
