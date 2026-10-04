<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\Enum;

use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;
use PHPUnit\Framework\TestCase;

final class EnvelopeCipherTest extends TestCase
{
    private const string NIP44_SPEC_PAYLOAD = 'AgAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABee0G5VSK0/9YypIObAtDKfYEAjD35uVkHyB0F4DwrcNaCXlCWZKaArsGrY6M9wnuTMxWfp1RTN9Xga8no+kF5Vsb';

    private const string NIP04_SHAPED_PAYLOAD = 'zJxfaJ32rN5Dg1ODjOlEew==?iv=EV5bUjcc4OX2Km/zPp4ndQ==';

    public function testAPayloadCarryingAnIvIsNip04(): void
    {
        $this->assertSame(EnvelopeCipher::Nip04, EnvelopeCipher::ofPayload(self::NIP04_SHAPED_PAYLOAD));
    }

    public function testABase64PayloadIsNip44(): void
    {
        $this->assertSame(EnvelopeCipher::Nip44, EnvelopeCipher::ofPayload(self::NIP44_SPEC_PAYLOAD));
    }

    public function testAPayloadOfNeitherShapeIsNip44(): void
    {
        $this->assertSame(EnvelopeCipher::Nip44, EnvelopeCipher::ofPayload('not-an-envelope'));
    }
}
