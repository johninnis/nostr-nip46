<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;

final readonly class SignEventSummary
{
    public function __construct(
        private EventKind $kind,
        private string $content,
    ) {
    }

    public function getKind(): EventKind
    {
        return $this->kind;
    }

    public function getContent(): string
    {
        return $this->content;
    }
}
