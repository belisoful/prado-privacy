<?php

namespace belisoful\Privacy\Test\Unit\Rights;

use belisoful\Privacy\Rights\TDataSubject;
use belisoful\Privacy\Rights\TErasureResult;
use belisoful\Privacy\Rights\TPersonalDataEventParameter;
use belisoful\Privacy\Rights\TPrivacyRequestEventParameter;
use belisoful\Privacy\Test\Unit\FakeUser;
use belisoful\Privacy\Test\Unit\PrivacyTestCase;
use belisoful\Privacy\TPrivacyHash;

class TDataSubjectTest extends PrivacyTestCase
{
	public function testSubject()
	{
		$subject = new TDataSubject('alice', 'alice@example.test', ['customer' => 'C-1']);
		$this->assertSame('alice', $subject->getName());
		$this->assertSame('alice@example.test', $subject->getEmail());
		$this->assertSame(['customer' => 'C-1'], $subject->getIdentifiers());
		$this->assertSame('C-1', $subject->getIdentifier('customer'));
		$this->assertNull($subject->getIdentifier('phone'));
		$this->assertSame(TPrivacyHash::hash('alice', TPrivacyHash::SUBJECT), $subject->getHash());
		$this->assertSame('bob', TDataSubject::fromUser(new FakeUser('bob', false))->getName());
		$this->assertNull((new TDataSubject('x'))->getEmail());
	}

	public function testIsCurrentUser()
	{
		$subject = new TDataSubject('alice');
		$this->assertFalse($subject->getIsCurrentUser(), 'a guest');
		$this->login('alice');
		$this->assertTrue($subject->getIsCurrentUser());
		$this->login('bob');
		$this->assertFalse($subject->getIsCurrentUser());
	}

	public function testErasureResult()
	{
		$result = new TErasureResult(2);
		$this->assertSame($result, $result->addErased(3)->addRetained(4, 'tax law')->addError('RuntimeException'));
		$this->assertSame(5, $result->getErased());
		$this->assertSame([['count' => 4, 'reason' => 'tax law']], $result->getRetained());
		$this->assertSame(['RuntimeException'], $result->getErrors());
		$this->assertSame(['erased' => 5, 'retained' => [['count' => 4, 'reason' => 'tax law']], 'errors' => ['RuntimeException']], $result->toArray());
		$this->assertSame(0, (new TErasureResult())->getErased());
	}

	public function testEventParameters()
	{
		$subject = new TDataSubject('alice');
		$request = new TPrivacyRequestEventParameter('erase', $subject, 7, 'completed', ['a' => []]);
		$this->assertSame('erase', $request->getType());
		$this->assertSame($subject, $request->getSubject());
		$this->assertSame(7, $request->getRequestId());
		$this->assertSame('completed', $request->getStatus());
		$this->assertSame(['a' => []], $request->getOutcome());
		$pending = new TPrivacyRequestEventParameter('export', $subject);
		$this->assertSame(0, $pending->getRequestId());
		$this->assertNull($pending->getStatus());
		$this->assertSame([], $pending->getOutcome());

		$result = new TErasureResult();
		$data = new TPersonalDataEventParameter($subject, $result, ['email' => 'x']);
		$this->assertSame($subject, $data->getSubject());
		$this->assertSame($result, $data->getResult());
		$this->assertSame(['email' => 'x'], $data->getChanges());
		$this->assertSame([], $data->getData());
		$data->setData(['a' => 1]);
		$this->assertSame(['a' => 1], $data->getData());
		$this->assertFalse($data->getApplied());
		$data->setApplied(true);
		$this->assertTrue($data->getApplied());
		$this->assertNull((new TPersonalDataEventParameter($subject))->getResult());
	}
}
