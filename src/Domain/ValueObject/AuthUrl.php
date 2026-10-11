<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\ValueObject;

use Override;
use Stringable;

final readonly class AuthUrl implements Stringable
{
    // Deliberate: judged on the string, not by a URL parser, and kept exactly as received — http(s), a host with no credentials and at most a decimal port, and no whitespace, control character or backslash anywhere — see ADR-0013
    private const string WEB_URL = '~\A[Hh][Tt][Tt][Pp][Ss]?://[^/?#@:]+(?::[0-9]+)?(?:[/?#].*)?\z~su';

    private const string FORBIDDEN_CHARACTER = '~[\p{White_Space}\p{Cc}\\\\]~u';

    private function __construct(private string $url)
    {
    }

    public static function tryFromString(string $raw): ?self
    {
        return 1 === preg_match(self::WEB_URL, $raw) && 0 === preg_match(self::FORBIDDEN_CHARACTER, $raw)
            ? new self($raw)
            : null;
    }

    #[Override]
    public function __toString(): string
    {
        return $this->url;
    }
}
