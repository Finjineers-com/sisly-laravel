<?php

declare(strict_types=1);

namespace Sisly\Exceptions;

/**
 * Exception thrown when a message is invalid.
 */
class InvalidMessageException extends SislyException
{
    public const EMPTY_MESSAGE = 'empty';
    public const TOO_LONG = 'too_long';
    public const INVALID_FORMAT = 'invalid_format';

    private string $reason;
    private ?int $maxLength;

    public function __construct(
        string $reason,
        ?int $maxLength = null,
        string $message = ''
    ) {
        $this->reason = $reason;
        $this->maxLength = $maxLength;

        if (empty($message)) {
            $message = match ($reason) {
                self::EMPTY_MESSAGE => 'Message cannot be empty',
                self::TOO_LONG => "Message exceeds maximum length of {$maxLength} characters",
                self::INVALID_FORMAT => 'Message has invalid format',
                default => 'Invalid message',
            };
        }

        parent::__construct($message);
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getMaxLength(): ?int
    {
        return $this->maxLength;
    }

    public static function empty(): self
    {
        return new self(self::EMPTY_MESSAGE);
    }

    public static function tooLong(int $maxLength, int $actualLength): self
    {
        return new self(
            self::TOO_LONG,
            $maxLength,
            "Message length ({$actualLength}) exceeds maximum allowed ({$maxLength})"
        );
    }
}
