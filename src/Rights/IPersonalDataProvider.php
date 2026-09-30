<?php

/**
 * IPersonalDataProvider interface file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Rights;

/**
 * IPersonalDataProvider interface.
 *
 * IPersonalDataProvider is implemented by every module (or component) that holds personal data,
 * so `TPrivacyModule` can answer a data subject's requests across the application:
 *
 * | Right | Method | GDPR | CCPA/CPRA |
 * |---|---|---|---|
 * | access and portability | {@see exportPersonalData()} | Art. 15, 20 | §1798.100, .110 |
 * | erasure | {@see erasePersonalData()} | Art. 17 | §1798.105 |
 * | rectification | {@see rectifyPersonalData()} | Art. 16 | §1798.106 |
 *
 * The subject is verified by the caller before `TPrivacyModule` runs a request. A provider keeps
 * what the law requires it to keep (a legal obligation, a legal claim, proof of consent) and
 * reports it as retained with the reason.
 *
 * ```php
 * class TOrderModule extends TModule implements IPersonalDataProvider
 * {
 *     public function getPersonalDataName(): string { return 'orders'; }
 *     public function exportPersonalData(TDataSubject $subject): array { … }
 *     public function erasePersonalData(TDataSubject $subject): TErasureResult
 *     {
 *         $result = new TErasureResult();
 *         $result->addRetained($count, 'invoices: tax law (GDPR Art. 17(3)(b))');
 *         return $result;
 *     }
 *     public function rectifyPersonalData(TDataSubject $subject, array $changes): bool { return false; }
 * }
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
interface IPersonalDataProvider
{
	/**
	 * @return string the provider's name: the key of its data in an export, unique in the application
	 */
	public function getPersonalDataName(): string;

	/**
	 * @param TDataSubject $subject the verified subject
	 * @return array the subject's data, JSON-encodable; an empty array when the provider holds none
	 */
	public function exportPersonalData(TDataSubject $subject): array;

	/**
	 * Erases the subject's data, except what the provider must retain.
	 * @param TDataSubject $subject the verified subject
	 * @return TErasureResult what was erased, what was retained and why, and errors
	 */
	public function erasePersonalData(TDataSubject $subject): TErasureResult;

	/**
	 * Corrects the subject's data.
	 * @param TDataSubject $subject the verified subject
	 * @param array<string, mixed> $changes field => corrected value, in the provider's own field names
	 * @return bool whether the provider applied the changes; false when it holds nothing to correct or cannot
	 */
	public function rectifyPersonalData(TDataSubject $subject, array $changes): bool;
}
