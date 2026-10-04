<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Nip46\Application\Service\CapabilityGate;
use Innis\Nostr\Nip46\Domain\ValueObject\GetPublicKeyDetail;
use Innis\Nostr\Nip46\Domain\ValueObject\IncomingRequest;
use Innis\Nostr\Nip46\Domain\ValueObject\Nip46Request;
use Innis\Nostr\Nip46\Domain\ValueObject\Nip46Response;
use Innis\Nostr\Nip46\Domain\ValueObject\PendingRequest;
use Innis\Nostr\Nip46\Tests\Support\FakeAuthoriser;
use Innis\Nostr\Nip46\Tests\Support\FakeSigner;
use Innis\Nostr\Nip46\Tests\Support\FixedClock;
use Innis\Nostr\Nip46\Tests\Support\TestKeys;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CapabilityGateTest extends TestCase
{
    private const int NOW = 1_700_000_000;

    public function testAGrantedRequestIsAnsweredAsTheUser(): void
    {
        $answer = $this->gate(FakeAuthoriser::granting('get_public_key'))->answerOrDefer($this->incoming(), new GetPublicKeyDetail());

        $this->assertEquals(Nip46Response::result($this->incoming()->getId(), TestKeys::signerPubkey()->toHex()), $answer);
    }

    public function testAnUngrantedRequestIsDeferredForADecision(): void
    {
        $answer = $this->gate(FakeAuthoriser::grantingNothing())->answerOrDefer($this->incoming(), new GetPublicKeyDetail());

        $this->assertInstanceOf(PendingRequest::class, $answer);
    }

    public function testADeferredRequestIsStampedWithTheInjectedClock(): void
    {
        $answer = $this->gate(FakeAuthoriser::grantingNothing())->answerOrDefer($this->incoming(), new GetPublicKeyDetail());

        $this->assertSame(self::NOW, $answer instanceof PendingRequest ? $answer->getReceivedAt()->toInt() : null);
    }

    public function testADeferredRequestIsKeyedByItsCarrierEvent(): void
    {
        $answer = $this->gate(FakeAuthoriser::grantingNothing())->answerOrDefer($this->incoming(), new GetPublicKeyDetail());

        $this->assertSame($this->incoming()->getCarrierId()->toHex(), $answer instanceof PendingRequest ? $answer->getId()->toHex() : null);
    }

    public function testAnApprovedRequestIsAnsweredAsTheUser(): void
    {
        $gate = $this->gate(FakeAuthoriser::grantingNothing());
        $pending = $gate->answerOrDefer($this->incoming(), new GetPublicKeyDetail());
        self::assertInstanceOf(PendingRequest::class, $pending);

        $this->assertEquals(Nip46Response::result($this->incoming()->getId(), TestKeys::signerPubkey()->toHex()), $gate->answer($pending));
    }

    private function gate(FakeAuthoriser $authoriser): CapabilityGate
    {
        return new CapabilityGate(new FakeSigner(TestKeys::signerPubkey()), $authoriser, new FixedClock(self::NOW));
    }

    private function incoming(): IncomingRequest
    {
        $request = Nip46Request::tryFromWire(['id' => 'r1', 'method' => 'get_public_key', 'params' => []])
            ?? throw new RuntimeException('invalid fixture request');

        return new IncomingRequest(
            EventId::tryFromHex(hash('sha256', 'carrier')) ?? throw new RuntimeException('invalid fixture carrier id'),
            TestKeys::clientPubkey(),
            $request,
        );
    }
}
