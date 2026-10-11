<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Nip46\Domain\ValueObject\AuthUrl;
use Innis\Nostr\Nip46\Tests\Support\AuthUrlVectors;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthUrlTest extends TestCase
{
    private const int SHARED_VECTOR_COUNT = 71;

    public function testLoadsEverySharedVector(): void
    {
        $this->assertCount(self::SHARED_VECTOR_COUNT, AuthUrlVectors::all());
    }

    #[DataProvider('sharedVectorsCarryingAUrl')]
    public function testKeepsEachSharedVectorsUrlExactlyAsReceivedOrRefusesIt(string $error, ?string $handedOver): void
    {
        $this->assertSame($handedOver, AuthUrl::tryFromString($error)?->__toString());
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function sharedVectorsCarryingAUrl(): iterable
    {
        foreach (AuthUrlVectors::cases() as $name => [$error, $handedOver]) {
            if (null !== $error) {
                yield $name => [$error, $handedOver];
            }
        }
    }

    public function testRefusesAStringThatIsNotUtf8(): void
    {
        $this->assertNull(AuthUrl::tryFromString("https://bunker.example/\xFF"));
    }
}
