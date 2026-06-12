<?php

declare(strict_types=1);

namespace Sisly\Validation;

use Sisly\Exceptions\InvalidMessageException;

/**
 * Validates user messages for edge cases.
 */
class MessageValidator
{
    /**
     * Default maximum message length (characters).
     */
    public const DEFAULT_MAX_LENGTH = 5000;

    /**
     * Minimum message length after trimming.
     */
    public const MIN_LENGTH = 1;

    private int $maxLength;

    public function __construct(int $maxLength = self::DEFAULT_MAX_LENGTH)
    {
        $this->maxLength = $maxLength;
    }

    /**
     * Validate a message and throw if invalid.
     *
     * @throws InvalidMessageException
     */
    public function validate(string $message): string
    {
        // Normalize whitespace
        $normalized = $this->normalize($message);

        // Check for empty message
        if (mb_strlen($normalized) < self::MIN_LENGTH) {
            throw InvalidMessageException::empty();
        }

        // Check for too long message
        $length = mb_strlen($normalized);
        if ($length > $this->maxLength) {
            throw InvalidMessageException::tooLong($this->maxLength, $length);
        }

        return $normalized;
    }

    /**
     * Validate and return result without throwing.
     *
     * @return array{valid: bool, message: string, error: string|null}
     */
    public function validateSafe(string $message): array
    {
        try {
            $normalized = $this->validate($message);
            return [
                'valid' => true,
                'message' => $normalized,
                'error' => null,
            ];
        } catch (InvalidMessageException $e) {
            return [
                'valid' => false,
                'message' => $this->normalize($message),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Normalize the message (trim, collapse whitespace).
     */
    public function normalize(string $message): string
    {
        // Trim leading/trailing whitespace
        $message = trim($message);

        // Collapse multiple spaces/newlines into single space
        // But preserve intentional paragraph breaks (double newlines)
        $message = preg_replace('/[ \t]+/', ' ', $message) ?? $message;
        $message = preg_replace('/\n{3,}/', "\n\n", $message) ?? $message;

        return $message;
    }

    /**
     * Truncate message to max length with ellipsis.
     */
    public function truncate(string $message, ?int $maxLength = null): string
    {
        $max = $maxLength ?? $this->maxLength;
        $normalized = $this->normalize($message);

        if (mb_strlen($normalized) <= $max) {
            return $normalized;
        }

        // Truncate at word boundary if possible
        $truncated = mb_substr($normalized, 0, $max - 3);
        $lastSpace = mb_strrpos($truncated, ' ');

        if ($lastSpace !== false && $lastSpace > $max * 0.8) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return $truncated . '...';
    }

    /**
     * Check if message is empty after normalization.
     */
    public function isEmpty(string $message): bool
    {
        return mb_strlen($this->normalize($message)) === 0;
    }

    /**
     * Check if message exceeds max length.
     */
    public function isTooLong(string $message): bool
    {
        return mb_strlen($this->normalize($message)) > $this->maxLength;
    }

    /**
     * Get the configured max length.
     */
    public function getMaxLength(): int
    {
        return $this->maxLength;
    }

    /**
     * Set the max length.
     */
    public function setMaxLength(int $maxLength): self
    {
        $this->maxLength = $maxLength;
        return $this;
    }

    /**
     * Remove potentially harmful content (basic sanitization).
     * Note: This does NOT replace proper output escaping.
     */
    public function sanitize(string $message): string
    {
        $normalized = $this->normalize($message);

        // Remove null bytes
        $normalized = str_replace("\0", '', $normalized);

        // Remove control characters except newlines and tabs
        $normalized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $normalized) ?? $normalized;

        return $normalized;
    }

    /**
     * Get message statistics.
     *
     * @return array{
     *     length: int,
     *     word_count: int,
     *     line_count: int,
     *     is_valid: bool
     * }
     */
    public function getStats(string $message): array
    {
        $normalized = $this->normalize($message);

        return [
            'length' => mb_strlen($normalized),
            'word_count' => str_word_count($normalized),
            'line_count' => substr_count($normalized, "\n") + 1,
            'is_valid' => !$this->isEmpty($message) && !$this->isTooLong($message),
        ];
    }
}
