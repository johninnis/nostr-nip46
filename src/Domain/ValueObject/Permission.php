<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Nip46\Domain\Enum\Nip46Method;
use Override;
use Stringable;

final readonly class Permission implements Stringable
{
    private const string PARAMETER_SEPARATOR = ':';

    private function __construct(
        private Nip46Method $method,
        private ?EventKind $kind,
    ) {
    }

    public static function forMethod(Nip46Method $method): self
    {
        return new self($method, null);
    }

    public static function forSignEvent(EventKind $kind): self
    {
        return new self(Nip46Method::SignEvent, $kind);
    }

    public static function tryFromString(string $raw): ?self
    {
        $trimmed = trim($raw);
        $separator = strpos($trimmed, self::PARAMETER_SEPARATOR);
        $methodName = false === $separator ? $trimmed : substr($trimmed, 0, $separator);
        $method = Nip46Method::tryFrom($methodName);

        if (null === $method) {
            return null;
        }

        if (false === $separator) {
            return new self($method, null);
        }

        $parameter = substr($trimmed, $separator + 1);

        if (Nip46Method::SignEvent !== $method || !ctype_digit($parameter)) {
            return null;
        }

        $kind = EventKind::tryFromInt((int) $parameter);

        return null === $kind ? null : new self($method, $kind);
    }

    public function getMethod(): Nip46Method
    {
        return $this->method;
    }

    public function getKind(): ?EventKind
    {
        return $this->kind;
    }

    public function covers(self $requested): bool
    {
        if ($this->method !== $requested->method) {
            return false;
        }

        return null === $this->kind
            || (null !== $requested->kind && $this->kind->equals($requested->kind));
    }

    public function equals(self $other): bool
    {
        return $this->method === $other->method && $this->kind?->toInt() === $other->kind?->toInt();
    }

    #[Override]
    public function __toString(): string
    {
        return null === $this->kind
            ? $this->method->value
            : $this->method->value.self::PARAMETER_SEPARATOR.$this->kind->toInt();
    }
}
