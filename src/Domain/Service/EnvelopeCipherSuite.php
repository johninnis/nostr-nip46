<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\Service;

use Innis\Nostr\Core\Domain\Service\ConversationCipherInterface;
use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;
use Override;

final readonly class EnvelopeCipherSuite implements EnvelopeCipherSuiteInterface
{
    public function __construct(
        private ConversationCipherInterface $nip44,
        private ConversationCipherInterface $nip04,
    ) {
    }

    #[Override]
    public function cipherFor(EnvelopeCipher $cipher): ConversationCipherInterface
    {
        return match ($cipher) {
            EnvelopeCipher::Nip44 => $this->nip44,
            EnvelopeCipher::Nip04 => $this->nip04,
        };
    }
}
