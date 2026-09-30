<?php

/**
 * TSessionPersonalDataProvider class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Rights;

use Prado\TModule;

/**
 * TSessionPersonalDataProvider class.
 *
 * TSessionPersonalDataProvider answers for the session of the request, when the subject is the
 * logged-in user making it (a self-service request):
 *
 * | Request | Result |
 * |---|---|
 * | export | the session's keys and values; an object is exported as its class name |
 * | erase | the session is destroyed |
 *
 * PHP's session handlers cannot list other visitors' sessions, so for any other subject the
 * provider exports nothing and reports the sessions as retained until they expire.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TSessionPersonalDataProvider extends TModule implements IPersonalDataProvider
{
	/**
	 * @return string `session`
	 */
	public function getPersonalDataName(): string
	{
		return 'session';
	}

	/**
	 * @param TDataSubject $subject the verified subject
	 * @return array the session's keys and values when the subject is the current user; empty otherwise
	 */
	public function exportPersonalData(TDataSubject $subject): array
	{
		$session = $this->getCurrentSession($subject);
		if ($session === null) {
			return [];
		}
		$data = [];
		foreach ($session->toArray() as $key => $value) {
			$data[$key] = self::exportable($value);
		}
		return $data;
	}

	/**
	 * Destroys the session when the subject is the current user.
	 * @param TDataSubject $subject the verified subject
	 * @return TErasureResult one item erased, or the other visitors' sessions reported as retained
	 */
	public function erasePersonalData(TDataSubject $subject): TErasureResult
	{
		$session = $this->getCurrentSession($subject);
		if ($session === null) {
			return (new TErasureResult())->addRetained(0, 'sessions of other requests are not reachable; they end with the session lifetime');
		}
		$session->destroy();
		return new TErasureResult(1);
	}

	/**
	 * @param TDataSubject $subject the verified subject
	 * @param array<string, mixed> $changes field => corrected value
	 * @return bool false: session data is derived, not corrected
	 */
	public function rectifyPersonalData(TDataSubject $subject, array $changes): bool
	{
		return false;
	}

	/**
	 * @param TDataSubject $subject the subject
	 * @return ?\Prado\Web\THttpSession the request's started session when the subject is the current user
	 */
	protected function getCurrentSession(TDataSubject $subject): ?\Prado\Web\THttpSession
	{
		$session = $this->getApplication()->getSession();
		return ($subject->getIsCurrentUser() && $session->getIsStarted()) ? $session : null;
	}

	/**
	 * @param mixed $value a session value
	 * @return mixed the value with objects replaced by their class name, so it encodes as JSON
	 */
	protected static function exportable(mixed $value): mixed
	{
		if (\is_array($value)) {
			return \array_map([self::class, 'exportable'], $value);
		}
		return \is_object($value) ? '[object ' . $value::class . ']' : $value;
	}
}
