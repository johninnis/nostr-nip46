<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\ValueObject;

use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;

final readonly class DecodedEnvelope
{
    /**
     * @param array<mixed> $payload
     */
    public function __construct(
        private array $payload,
        private EnvelopeCipher $cipher,
    ) {
    }

    /**
     * @return array<mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getCipher(): EnvelopeCipher
    {
        return $this->cipher;
    }
}
