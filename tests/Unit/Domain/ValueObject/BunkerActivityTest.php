<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Nip46\Domain\Enum\BunkerActivityOutcome;
use Innis\Nostr\Nip46\Domain\Enum\Nip46Method;
use Innis\Nostr\Nip46\Domain\ValueObject\AppId;
use Innis\Nostr\Nip46\Domain\ValueObject\BunkerActivity;
use Innis\Nostr\Nip46\Domain\ValueObject\CryptoDetail;
use Innis\Nostr\Nip46\Domain\ValueObject\GetPublicKeyDetail;
use Innis\Nostr\Nip46\Domain\ValueObject\IncomingRequest;
use Innis\Nostr\Nip46\Domain\ValueObject\Nip46Request;
use Innis\Nostr\Nip46\Domain\ValueObject\Nip46Response;
use Innis\Nostr\Nip46\Domain\ValueObject\PendingRequestDetailInterface;
use Innis\Nostr\Nip46\Domain\ValueObject\RequestId;
use Innis\Nostr\Nip46\Tests\Support\TestKeys;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BunkerActivityTest extends TestCase
{
    public function testAnActivityForADetailTakesItsMethodFromThePermission(): void
    {
        $activity = BunkerActivity::forDetail(AppId::fromString('app-1'), new GetPublicKeyDetail(), $this->answered());

        $this->assertSame('get_public_key', $activity->getMethod());
    }

    public function testAnActivityForACryptoDetailCarriesTheCounterparty(): void
    {
        $activity = BunkerActivity::forDetail(AppId::fromString('app-1'), $this->cryptoDetail(), $this->answered());

        $this->assertTrue($activity->getCounterparty()?->equals(TestKeys::clientPubkey()));
    }

    public function testAnActivityForARequestHasNoCounterparty(): void
    {
        $activity = BunkerActivity::forRequest(AppId::fromString('app-1'), $this->incoming('ping'), $this->answered());

        $this->assertNull($activity->getCounterparty());
    }

    public function testAnActivityForARequestTakesItsMethodFromTheWire(): void
    {
        $activity = BunkerActivity::forRequest(AppId::fromString('app-1'), $this->incoming('switch_relays'), $this->answered());

        $this->assertSame('switch_relays', $activity->getMethod());
    }

    public function testAResultIsAnsweredAndAnErrorIsFailed(): void
    {
        $appId = AppId::fromString('app-1');

        $this->assertSame(BunkerActivityOutcome::Answered, BunkerActivity::forRequest($appId, $this->incoming('ping'), $this->answered())->getOutcome());
        $this->assertSame(BunkerActivityOutcome::Failed, BunkerActivity::forRequest($appId, $this->incoming('ping'), $this->failed())->getOutcome());
    }

    public function testTheActivityIsAttributedToTheGivenApp(): void
    {
        $activity = BunkerActivity::forRequest(AppId::fromString('app-7'), $this->incoming('ping'), $this->answered());

        $this->assertSame('app-7', (string) $activity->getAppId());
    }

    private function cryptoDetail(): PendingRequestDetailInterface
    {
        return CryptoDetail::tryFromWire(Nip46Method::Nip44Decrypt, TestKeys::clientPubkey()->toHex(), 'ciphertext')
            ?? throw new RuntimeException('invalid fixture detail');
    }

    private function incoming(string $method): IncomingRequest
    {
        $request = Nip46Request::tryFromWire(['id' => 'r1', 'method' => $method, 'params' => []])
            ?? throw new RuntimeException('invalid fixture request');

        return new IncomingRequest($this->carrierId(), TestKeys::clientPubkey(), $request);
    }

    private function carrierId(): EventId
    {
        return EventId::tryFromHex(hash('sha256', 'carrier')) ?? throw new RuntimeException('invalid fixture carrier id');
    }

    private function answered(): Nip46Response
    {
        return Nip46Response::result($this->requestId(), 'ok');
    }

    private function failed(): Nip46Response
    {
        return Nip46Response::failure($this->requestId(), 'nope');
    }

    private function requestId(): RequestId
    {
        return RequestId::tryFromString('r1') ?? throw new RuntimeException('invalid fixture request id');
    }
}
