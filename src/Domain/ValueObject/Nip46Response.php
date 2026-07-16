<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Service\JsonWireFormat;

final readonly class Nip46Response
{
    private const string AUTH_URL_RESULT = 'auth_url';

    private function __construct(
        private RequestId $id,
        private ?string $result,
        private ?string $error,
    ) {
    }

    public static function result(RequestId $id, string $result): self
    {
        return new self($id, $result, null);
    }

    public function isAuthUrlChallenge(): bool
    {
        return self::AUTH_URL_RESULT === $this->result;
    }

    public function getAuthUrl(): ?AuthUrl
    {
        return $this->isAuthUrlChallenge() ? AuthUrl::tryFromString($this->error ?? '') : null;
    }

    public static function failure(RequestId $id, string $error): self
    {
        return new self($id, null, $error);
    }

    public static function tryFromWire(mixed $value): ?self
    {
        if (!is_array($value)) {
            return null;
        }

        $id = RequestId::tryFromString($value['id'] ?? null);

        if (null === $id) {
            return null;
        }

        $result = $value['result'] ?? null;
        $error = $value['error'] ?? null;

        if (null !== $result && !is_string($result)) {
            return null;
        }

        if (null !== $error && !is_string($error)) {
            return null;
        }

        return new self($id, $result, $error);
    }

    public function getId(): RequestId
    {
        return $this->id;
    }

    public function getResult(): ?string
    {
        return $this->result;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $payload = ['id' => (string) $this->id];

        if (null !== $this->result) {
            $payload['result'] = $this->result;
        }

        if (null !== $this->error) {
            $payload['error'] = $this->error;
        }

        return $payload;
    }

    public function toJson(): string
    {
        return JsonWireFormat::encode($this->toArray(), JsonWireFormat::MESSAGE);
    }
}
