<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\ValueObject;

use Override;
use Stringable;

final readonly class RequestId implements Stringable
{
    private function __construct(private string $id)
    {
    }

    public static function tryFromString(mixed $value): ?self
    {
        if (!is_string($value) || '' === $value) {
            return null;
        }

        return new self($value);
    }

    // Deliberate: reads the entropy source directly, not via an injected port; no random-dependent output under test — see ADR-0007
    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(8)));
    }

    public function equals(self $other): bool
    {
        return $this->id === $other->id;
    }

    #[Override]
    public function __toString(): string
    {
        return $this->id;
    }
}
