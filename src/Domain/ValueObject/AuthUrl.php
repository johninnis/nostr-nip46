<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\ValueObject;

use Override;
use Stringable;

final readonly class AuthUrl implements Stringable
{
    // Deliberate: http(s)-only, stricter than the spec's bare "URL" — the host opens this in a browser and a bunker-supplied javascript:/data:/file: URI must never reach it — see ADR-0013
    private const array ALLOWED_SCHEMES = ['http', 'https'];

    private function __construct(private string $url)
    {
    }

    public static function tryFromString(string $raw): ?self
    {
        $trimmed = trim($raw);

        $scheme = parse_url($trimmed, PHP_URL_SCHEME);
        $host = parse_url($trimmed, PHP_URL_HOST);

        if (!is_string($scheme) || !in_array(strtolower($scheme), self::ALLOWED_SCHEMES, true)) {
            return null;
        }

        if (!is_string($host) || '' === $host) {
            return null;
        }

        return new self($trimmed);
    }

    #[Override]
    public function __toString(): string
    {
        return $this->url;
    }
}
