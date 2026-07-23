<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;
use Innis\Nostr\Nip46\Domain\Enum\Nip46Method;
use Innis\Nostr\Nip46\Domain\Service\Nip46SignerInterface;
use Override;
use Throwable;

final readonly class CipherDetail implements PendingRequestDetailInterface
{
    private function __construct(
        private Nip46Method $method,
        private EnvelopeCipher $cipher,
        private PublicKey $counterparty,
        private string $payload,
    ) {
    }

    #[Override]
    public function getPayload(): string
    {
        return $this->payload;
    }

    #[Override]
    public function getPermission(): Permission
    {
        return Permission::forMethod($this->method);
    }

    #[Override]
    public function getCounterparty(): PublicKey
    {
        return $this->counterparty;
    }

    // Deliberate: a cipher payload is private correspondence and is never summarised for audit — see ADR-0021
    #[Override]
    public function getSignEventSummary(): ?SignEventSummary
    {
        return null;
    }

    #[Override]
    public function answer(RequestId $id, Nip46SignerInterface $signer, Timestamp $now): Nip46Response
    {
        if (!$this->method->isEncrypt()) {
            $plaintext = $signer->decrypt($this->counterparty, $this->payload, $this->cipher);

            return null === $plaintext
                ? Nip46Response::failure($id, 'decryption failed')
                : Nip46Response::result($id, $plaintext);
        }

        // Deliberate: nip44_encrypt/nip04_encrypt encrypt untrusted client plaintext; a cipher failure is answered to the waiting client as a protocol error, not thrown — see ADR-0003.
        try {
            return Nip46Response::result($id, $signer->encrypt($this->counterparty, $this->payload, $this->cipher));
        } catch (Throwable) {
            return Nip46Response::failure($id, 'encryption failed');
        }
    }

    public static function tryFromWire(Nip46Method $method, ?string $rawCounterparty, ?string $payload): ?self
    {
        $cipher = $method->cipher();
        $counterparty = PublicKey::tryFromHex($rawCounterparty ?? '');

        return null === $cipher || null === $counterparty || null === $payload
            ? null
            : new self($method, $cipher, $counterparty, $payload);
    }
}
