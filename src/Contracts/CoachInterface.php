<?php

declare(strict_types=1);

namespace Sisly\Contracts;

use Sisly\DTOs\CoETrace;
use Sisly\DTOs\Session;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;

/**
 * Interface for all Sisly coaches.
 *
 * Coaches handle conversation processing for their specific emotional domain.
 */
interface CoachInterface
{
    /**
     * Get the coach's identifier.
     */
    public function getId(): CoachId;

    /**
     * Get the coach's display name.
     */
    public function getName(): string;

    /**
     * Get the coach's description.
     */
    public function getDescription(): string;

    /**
     * Process a user message and generate a response.
     *
     * @return array{response: string, arabic_mirror: ?string, coe_trace: ?CoETrace}
     */
    public function process(Session $session, string $message): array;

    /**
     * Get the system prompt for a specific state.
     */
    public function getSystemPrompt(SessionState $state): string;

    /**
     * Get the state-specific prompt.
     */
    public function getStatePrompt(SessionState $state): string;

    /**
     * Check if this coach can handle the given message.
     */
    public function canHandle(string $message): bool;

    /**
     * Get the emotional domains this coach handles.
     *
     * @return array<string>
     */
    public function getDomains(): array;

    /**
     * Get trigger keywords for this coach.
     *
     * @return array<string>
     */
    public function getTriggers(): array;

    /**
     * Get a randomly selected greeting message for initiating a session.
     *
     * Returns a domain-specific greeting in the specified language.
     * Used when the coach initiates the conversation (coach speaks first).
     *
     * @param string $language The preferred language ('en' or 'ar')
     * @return string The greeting message in the specified language
     */
    public function getGreeting(string $language = 'en'): string;

    /**
     * Get all available greeting pairs for this coach.
     *
     * Each pair contains 'en' and 'ar' keys with pre-written greetings.
     *
     * @return array<array{en: string, ar: string}>
     */
    public function getGreetings(): array;
}
