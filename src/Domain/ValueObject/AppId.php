<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\ValueObject;

use InvalidArgumentException;
use Override;
use Stringable;

final readonly class AppId implements Stringable
{
    private function __construct(private string $id)
    {
    }

    public static function fromString(string $id): self
    {
        if ('' === $id) {
            throw new InvalidArgumentException('App id must not be empty');
        }

        return new self($id);
    }

    #[Override]
    public function __toString(): string
    {
        return $this->id;
    }
}
