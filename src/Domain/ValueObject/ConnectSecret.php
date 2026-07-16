<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\ValueObject;

use Override;
use Stringable;

final readonly class ConnectSecret implements Stringable
{
    private function __construct(private string $secret)
    {
    }

    public static function tryFromString(string $raw): ?self
    {
        return '' === $raw ? null : new self($raw);
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->secret, $other->secret);
    }

    #[Override]
    public function __toString(): string
    {
        return $this->secret;
    }
}
