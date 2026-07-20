<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\ValueObject;

use InvalidArgumentException;
use Override;
use SensitiveParameter;
use Stringable;

final readonly class ConnectSecret implements Stringable
{
    private function __construct(#[SensitiveParameter] private string $secret)
    {
    }

    public static function fromString(#[SensitiveParameter] string $raw): self
    {
        return self::tryFromString($raw) ?? throw new InvalidArgumentException('A connect secret must not be empty');
    }

    public static function tryFromString(#[SensitiveParameter] string $raw): ?self
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
