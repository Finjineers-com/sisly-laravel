<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use Sisly\Enums\SessionState;
use Sisly\Exceptions\CoachNotFoundException;
use Sisly\Exceptions\ConfigurationException;
use Sisly\Exceptions\InvalidMessageException;
use Sisly\Exceptions\LLMException;
use Sisly\Exceptions\SessionNotFoundException;
use Sisly\Exceptions\SislyException;
use Sisly\Exceptions\StateTransitionException;

class ExceptionsTest extends TestCase
{
    public function test_sisly_exception_is_base_exception(): void
    {
        $exception = new SislyException('Test error');

        $this->assertInstanceOf(\Exception::class, $exception);
        $this->assertEquals('Test error', $exception->getMessage());
    }

    public function test_session_not_found_exception(): void
    {
        $exception = new SessionNotFoundException('session-123');

        $this->assertInstanceOf(SislyException::class, $exception);
        $this->assertStringContainsString('session-123', $exception->getMessage());
    }

    // InvalidMessageException tests

    public function test_invalid_message_exception_empty(): void
    {
        $exception = InvalidMessageException::empty();

        $this->assertEquals(InvalidMessageException::EMPTY_MESSAGE, $exception->getReason());
        $this->assertStringContainsString('empty', $exception->getMessage());
    }

    public function test_invalid_message_exception_too_long(): void
    {
        $exception = InvalidMessageException::tooLong(100, 150);

        $this->assertEquals(InvalidMessageException::TOO_LONG, $exception->getReason());
        $this->assertEquals(100, $exception->getMaxLength());
        $this->assertStringContainsString('150', $exception->getMessage());
        $this->assertStringContainsString('100', $exception->getMessage());
    }

    public function test_invalid_message_exception_custom_message(): void
    {
        $exception = new InvalidMessageException(
            InvalidMessageException::INVALID_FORMAT,
            null,
            'Custom error message'
        );

        $this->assertEquals('Custom error message', $exception->getMessage());
    }

    // CoachNotFoundException tests

    public function test_coach_not_found_exception(): void
    {
        $exception = new CoachNotFoundException('invalid-coach');

        $this->assertInstanceOf(SislyException::class, $exception);
        $this->assertEquals('invalid-coach', $exception->getCoachId());
        $this->assertFalse($exception->isDisabled());
        $this->assertStringContainsString('not found', $exception->getMessage());
    }

    public function test_coach_not_found_exception_disabled(): void
    {
        $exception = new CoachNotFoundException('meetly', true);

        $this->assertEquals('meetly', $exception->getCoachId());
        $this->assertTrue($exception->isDisabled());
        $this->assertStringContainsString('disabled', $exception->getMessage());
    }

    // LLMException tests

    public function test_llm_exception_provider_unavailable(): void
    {
        $exception = LLMException::providerUnavailable('openai');

        $this->assertEquals(LLMException::PROVIDER_UNAVAILABLE, $exception->getReason());
        $this->assertEquals('openai', $exception->getProvider());
        $this->assertStringContainsString('openai', $exception->getMessage());
    }

    public function test_llm_exception_provider_unavailable_without_provider(): void
    {
        $exception = LLMException::providerUnavailable();

        $this->assertEquals(LLMException::PROVIDER_UNAVAILABLE, $exception->getReason());
        $this->assertNull($exception->getProvider());
        $this->assertStringContainsString('No LLM provider', $exception->getMessage());
    }

    public function test_llm_exception_request_failed(): void
    {
        $exception = LLMException::requestFailed('gemini', 'Connection timeout');

        $this->assertEquals(LLMException::REQUEST_FAILED, $exception->getReason());
        $this->assertEquals('gemini', $exception->getProvider());
        $this->assertStringContainsString('gemini', $exception->getMessage());
        $this->assertStringContainsString('Connection timeout', $exception->getMessage());
    }

    public function test_llm_exception_rate_limited(): void
    {
        $exception = LLMException::rateLimited('openai');

        $this->assertEquals(LLMException::RATE_LIMITED, $exception->getReason());
        $this->assertEquals('openai', $exception->getProvider());
        $this->assertStringContainsString('rate limited', $exception->getMessage());
    }

    public function test_llm_exception_all_providers_failed(): void
    {
        $exception = LLMException::allProvidersFailed();

        $this->assertEquals(LLMException::ALL_PROVIDERS_FAILED, $exception->getReason());
        $this->assertNull($exception->getProvider());
        $this->assertStringContainsString('All LLM providers failed', $exception->getMessage());
    }

    public function test_llm_exception_custom(): void
    {
        $exception = new LLMException(
            LLMException::INVALID_RESPONSE,
            'openai',
            'Malformed JSON in response'
        );

        $this->assertEquals(LLMException::INVALID_RESPONSE, $exception->getReason());
        $this->assertEquals('Malformed JSON in response', $exception->getMessage());
    }

    // ConfigurationException tests

    public function test_configuration_exception(): void
    {
        $exception = new ConfigurationException('sisly.llm.api_key');

        $this->assertInstanceOf(SislyException::class, $exception);
        $this->assertEquals('sisly.llm.api_key', $exception->getConfigKey());
        $this->assertStringContainsString('sisly.llm.api_key', $exception->getMessage());
    }

    public function test_configuration_exception_missing(): void
    {
        $exception = ConfigurationException::missing('sisly.llm.api_key');

        $this->assertEquals('sisly.llm.api_key', $exception->getConfigKey());
        $this->assertStringContainsString('Missing', $exception->getMessage());
    }

    public function test_configuration_exception_invalid(): void
    {
        $exception = ConfigurationException::invalid('sisly.llm.timeout', 'must be positive');

        $this->assertEquals('sisly.llm.timeout', $exception->getConfigKey());
        $this->assertStringContainsString('Invalid', $exception->getMessage());
        $this->assertStringContainsString('must be positive', $exception->getMessage());
    }

    // StateTransitionException tests

    public function test_state_transition_exception(): void
    {
        $exception = new StateTransitionException(
            SessionState::INTAKE,
            SessionState::CLOSING
        );

        $this->assertInstanceOf(SislyException::class, $exception);
        $this->assertEquals(SessionState::INTAKE, $exception->getFromState());
        $this->assertEquals(SessionState::CLOSING, $exception->getToState());
        $this->assertStringContainsString('intake', $exception->getMessage());
        $this->assertStringContainsString('closing', $exception->getMessage());
    }

    // Exception hierarchy tests

    public function test_all_exceptions_extend_sisly_exception(): void
    {
        $exceptions = [
            new SessionNotFoundException('test'),
            InvalidMessageException::empty(),
            new CoachNotFoundException('test'),
            LLMException::allProvidersFailed(),
            ConfigurationException::missing('test'),
            new StateTransitionException(SessionState::INTAKE, SessionState::CLOSING),
        ];

        foreach ($exceptions as $exception) {
            $this->assertInstanceOf(SislyException::class, $exception);
        }
    }
}
