<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\Service;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;

final class SignedTemplateMatcher
{
    public static function matches(Rumour $template, Event $signed): bool
    {
        return $template->getKind()->equals($signed->getKind())
            && $template->getCreatedAt()->equals($signed->getCreatedAt())
            && $template->getContent()->equals($signed->getContent())
            && $template->getTags()->equals($signed->getTags());
    }
}
