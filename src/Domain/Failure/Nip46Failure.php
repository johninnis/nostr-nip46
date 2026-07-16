<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\Failure;

use Innis\Nostr\Nip46\Domain\Enum\Nip46CryptoMethod;
use Innis\Nostr\Nip46\Domain\Enum\Nip46FailureReason;
use Innis\Nostr\Nip46\Domain\Enum\Nip46Method;

final readonly class Nip46Failure
{
    private function __construct(
        private Nip46FailureReason $reason,
        private Nip46Method|Nip46CryptoMethod $method,
        private ?string $detail = null,
    ) {
    }

    public function getReason(): Nip46FailureReason
    {
        return $this->reason;
    }

    public function getMethod(): Nip46Method|Nip46CryptoMethod
    {
        return $this->method;
    }

    public function getDetail(): ?string
    {
        return $this->detail;
    }

    public static function encryptionFailed(Nip46Method|Nip46CryptoMethod $method): self
    {
        return new self(Nip46FailureReason::EncryptionFailed, $method);
    }

    public static function timedOut(Nip46Method|Nip46CryptoMethod $method): self
    {
        return new self(Nip46FailureReason::TimedOut, $method);
    }

    public static function rejected(Nip46Method|Nip46CryptoMethod $method, string $error): self
    {
        return new self(Nip46FailureReason::Rejected, $method, $error);
    }

    public static function invalidResponse(Nip46Method|Nip46CryptoMethod $method): self
    {
        return new self(Nip46FailureReason::InvalidResponse, $method);
    }

    public static function identityMismatch(Nip46Method|Nip46CryptoMethod $method): self
    {
        return new self(Nip46FailureReason::IdentityMismatch, $method);
    }

    public static function invalidSignature(Nip46Method|Nip46CryptoMethod $method): self
    {
        return new self(Nip46FailureReason::InvalidSignature, $method);
    }

    public function describe(): string
    {
        return match ($this->reason) {
            Nip46FailureReason::EncryptionFailed => sprintf('the "%s" request could not be encrypted to the remote signer', $this->method->value),
            Nip46FailureReason::TimedOut => sprintf('the remote signer did not answer "%s" in time', $this->method->value),
            Nip46FailureReason::Rejected => sprintf('the remote signer rejected "%s": %s', $this->method->value, $this->detail ?? 'no reason given'),
            Nip46FailureReason::InvalidResponse => sprintf('the remote signer returned an invalid response to "%s"', $this->method->value),
            Nip46FailureReason::IdentityMismatch => sprintf('the remote signer answered "%s" with an event authored by a different identity', $this->method->value),
            Nip46FailureReason::InvalidSignature => sprintf('the remote signer answered "%s" with an event that fails signature verification', $this->method->value),
        };
    }
}
