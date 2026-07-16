<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Nip46\Domain\ValueObject\AppId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AppIdTest extends TestCase
{
    public function testCarriesTheIdVerbatim(): void
    {
        $this->assertSame('app-1', (string) AppId::fromString('app-1'));
    }

    public function testAnEmptyIdIsAFault(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AppId::fromString('');
    }
}
