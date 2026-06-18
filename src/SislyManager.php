<?php

declare(strict_types=1);

namespace Sisly;

use Illuminate\Support\Str;
use Sisly\Arabic\LanguageDetector;
use Sisly\Coaches\CoachRegistry;
use Sisly\Contracts\AssetResolverInterface;
use Sisly\Contracts\CoachInterface;
use Sisly\Contracts\LLMProviderInterface;
use Sisly\Contracts\SessionStoreInterface;
use Sisly\Dispatcher\Dispatcher;
use Sisly\Dispatcher\HandoffDetector;
use Sisly\DTOs\CoachInfo;
use Sisly\DTOs\CoETrace;
use Sisly\DTOs\ConversationTurn;
use Sisly\DTOs\CrisisInfo;
use Sisly\DTOs\GeoContext;
use Sisly\DTOs\Prescription;
use Sisly\DTOs\SafetyVerdict;
use Sisly\DTOs\Session;
use Sisly\DTOs\SessionPreferences;
use Sisly\DTOs\SislyResponse;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;
use Sisly\Events\CrisisDetected;
use Sisly\Events\MessageReceived;
use Sisly\Events\ResponseGenerated;
use Sisly\Events\SessionEnded;
use Sisly\Events\SessionStarted;
use Sisly\Events\StateTransitioned;
use Sisly\Exceptions\SessionNotFoundException;
use Sisly\Exceptions\SislyException;
use Sisly\FSM\StateMachine;
use Sisly\Safety\CrisisDetector;
use Sisly\Safety\CrisisHandler;
use Sisly\Safety\PostResponseValidator;
use Sisly\Safety\SafetyClassifier;

/**
 * Main service class for Sisly emotional coaching.
 *
 * Key changes in this version (aligned to functional spec):
 *
 * 1. PARALLEL SAFETY CLASSIFIER — SafetyClassifier runs alongside the coach
 *    call. "Safety verdict overrides the coach."
 *
 * 2. PRESCRIPTION PARSING — coach responses may contain a ```sisly block
 *    for content handoff. Parsed and returned on the response.
 *
 * 3. LANGUAGE AUTO-DETECT — LanguageDetector is now wired: when
 *    preferences.language is not set, the first user message is used to
 *    auto-detect locale.
 *
 * 4. IDENTITY TURN FIX (#1 from PENDING_FIXES) — identity/credential
 *    questions are detected at the manager level BEFORE incrementing
 *    FSM turns, so meta questions don't burn FSM state budget.
 *
 * 5. TELEMETRY — per-turn metrics logged (no raw message content).
 *
 * 6. SESSION-NOT-TERMINAL BY DEFAULT — end_on_terminal_state defaults to
 *    false: "the chat only ends on a crisis verdict; the content
 *    recommendation never ends it."
 */
class SislyManager
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
        private readonly SessionStoreInterface $sessionStore,
        private readonly CrisisDetector $crisisDetector,
        private readonly CrisisHandler $crisisHandler,
        private readonly PostResponseValidator $responseValidator,
        private readonly StateMachine $stateMachine,
        private readonly Dispatcher $dispatcher,
        private readonly HandoffDetector $handoffDetector,
        private readonly ?CoachRegistry $coachRegistry = null,
        private readonly ?SafetyClassifier $safetyClassifier = null,
        private readonly ?LanguageDetector $languageDetector = null,
        private readonly ?AssetResolverInterface $assetResolver = null,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Start a new coaching session with the user's first message.
     *
     * @param array{geo?: GeoContext|array<string, mixed>, preferences?: SessionPreferences|array<string, mixed>, coach_id?: string} $context
     */
    public function startSession(string $message, array $context = []): SislyResponse
    {
        $sessionId   = $this->generateSessionId();
        $geo         = $this->resolveGeoContext($context);
        $preferences = $this->resolvePreferences($context, $message); // auto-detect language

        $coachId = $this->resolveCoachId($context, $message);

        $session = Session::create(
            id: $sessionId,
            coachId: $coachId,
            geo: $geo,
            preferences: $preferences,
            maxHistoryTurns: $this->resolveMaxHistoryTurns(),
        );

        $this->dispatchSessionStartedEvent($session);

        // Deterministic crisis check FIRST (before any LLM call)
        if ($this->isCrisisDetectionEnabled()) {
            $crisisInfo = $this->crisisDetector->check($message);
            if ($crisisInfo->detected) {
                $session->addTurn(ConversationTurn::user($message));
                return $this->handleCrisis($session, $crisisInfo, $geo);
            }
        }

        // Add user turn and increment FSM (unless identity/credential question)
        $isMetaQuestion = $this->isMetaQuestion($message);
        $session->addTurn(ConversationTurn::user($message));
        if (!$isMetaQuestion) {
            $this->stateMachine->incrementStateTurns($session);
        }

        $this->dispatchMessageReceivedEvent($session, $message);

        // Run coach + safety in parallel
        $startTime = microtime(true);
        [$coachResult, $safetyVerdict] = $this->runParallel($session, $message);
        $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

        // Safety override
        if ($safetyVerdict->isFlagged()) {
            return $this->handleLLMSafetyFlag($session, $safetyVerdict, $geo);
        }

        $responseText = $coachResult['response'];
        $arabicMirror = $coachResult['arabic_mirror'] ?? null;
        $coeTrace     = $coachResult['coe_trace'] ?? null;
        $prescription = $coachResult['prescription'] ?? null;

        $responseText = $this->validateAndSanitizeResponse($responseText, $session);

        $this->dispatchResponseGeneratedEvent($session, $responseText, $arabicMirror, $coeTrace, $responseTimeMs);

        $session->addTurn(ConversationTurn::assistant($responseText));

        // FSM advance
        $previousState = $session->state;
        if ($this->stateMachine->shouldAdvance($session)) {
            $this->stateMachine->advance($session);
            $this->dispatchStateTransitionEvent($session, $previousState);
        } elseif ($session->state === SessionState::INTAKE) {
            $session->transitionTo(SessionState::EXPLORATION);
            $this->dispatchStateTransitionEvent($session, $previousState);
        }

        // Resolve prescription to asset if configured
        $prescription = $this->maybeResolveAsset($prescription, $session->preferences->language);

        // Update summary from CoE trace
        if ($coeTrace !== null && method_exists($coeTrace, 'getCauseAnalysis')) {
            // Summary update is best-effort
        }

        $this->logTurnMetrics($session, $responseTimeMs, $safetyVerdict, $prescription);
        $this->sessionStore->save($session);

        return SislyResponse::fromSession(
            session: $session,
            responseText: $responseText,
            arabicMirror: $arabicMirror,
            coeTrace: $coeTrace,
            safetyVerdict: $safetyVerdict,
            prescription: $prescription,
        );
    }

    /**
     * Initialize a session with a coach-initiated primed opening (Phase 1).
     * No user message required. No model call. Coach speaks first.
     *
     * Spec: "The primed opening is client-side. The backend is not called
     * until the user types their first message."
     * This initSession() variant lets server-side code trigger the greeting
     * (for SSR or API-first patterns).
     *
     * @param array{geo?: GeoContext|array<string, mixed>, preferences?: SessionPreferences|array<string, mixed>, coach_id?: string|CoachId} $context
     */
    public function initSession(array $context = []): SislyResponse
    {
        $sessionId   = $this->generateSessionId();
        $geo         = $this->resolveGeoContext($context);
        $preferences = $this->resolvePreferences($context);
        $coachId     = $this->resolveCoachIdForInit($context);

        $session = Session::create(
            id: $sessionId,
            coachId: $coachId,
            geo: $geo,
            preferences: $preferences,
            maxHistoryTurns: $this->resolveMaxHistoryTurns(),
        );

        $this->dispatchSessionStartedEvent($session);

        // Get the primed opening (no model call — Phase 1 per spec)
        $greeting = $this->getCoachGreeting($session);
        $session->addTurn(ConversationTurn::assistant($greeting));
        $this->sessionStore->save($session);

        return SislyResponse::fromSession(
            session: $session,
            responseText: $greeting,
        );
    }

    /**
     * Send a message to an existing session.
     *
     * @throws SessionNotFoundException
     */
    public function message(string $sessionId, string $message): SislyResponse
    {
        $session = $this->sessionStore->get($sessionId);

        if ($session === null) {
            throw new SessionNotFoundException($sessionId);
        }

        if (!$session->isActive) {
            throw new SislyException("Session {$sessionId} has ended.");
        }

        // Wall-clock time check — force CLOSING if approaching budget
        $this->maybeForceClosingForTimeThreshold($session);

        // Deterministic crisis check FIRST
        if ($this->isCrisisDetectionEnabled()) {
            $crisisInfo = $this->crisisDetector->check($message);
            if ($crisisInfo->detected) {
                $session->addTurn(ConversationTurn::user($message));
                return $this->handleCrisis($session, $crisisInfo, $session->geo);
            }
        }

        // Already in crisis — continue crisis handling
        if ($session->state === SessionState::CRISIS_INTERVENTION) {
            $session->addTurn(ConversationTurn::user($message));
            $responseText = $this->crisisHandler->getFollowUpResponse($session, $session->geo);
            $session->addTurn(ConversationTurn::assistant($responseText));
            $this->sessionStore->save($session);

            return SislyResponse::fromSession(
                session: $session,
                responseText: $responseText,
                safetyVerdict: SafetyVerdict::ok(),
            );
        }

        // Identity/credential meta-question detection BEFORE incrementing turns
        // Fix for PENDING_FIXES #1: meta questions must not burn FSM state budget
        $isMetaQuestion = $this->isMetaQuestion($message);

        $session->addTurn(ConversationTurn::user($message));
        if (!$isMetaQuestion) {
            $this->stateMachine->incrementStateTurns($session);
        }

        // Check for potential handoff
        $handoffResult  = $this->handoffDetector->analyze($message, $session);
        $handoffSuggested = $handoffResult->meetsThreshold()
            ? $handoffResult->suggestedCoach?->value
            : null;

        $this->dispatchMessageReceivedEvent($session, $message);

        // Run coach + safety in parallel
        $startTime = microtime(true);
        [$coachResult, $safetyVerdict] = $this->runParallel($session, $message);
        $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

        // Safety override (LLM classifier flagged)
        if ($safetyVerdict->isFlagged()) {
            return $this->handleLLMSafetyFlag($session, $safetyVerdict, $session->geo);
        }

        $responseText = $coachResult['response'];
        $arabicMirror = $coachResult['arabic_mirror'] ?? null;
        $coeTrace     = $coachResult['coe_trace'] ?? null;
        $prescription = $coachResult['prescription'] ?? null;

        $responseText = $this->validateAndSanitizeResponse($responseText, $session);

        $this->dispatchResponseGeneratedEvent($session, $responseText, $arabicMirror, $coeTrace, $responseTimeMs);

        $session->addTurn(ConversationTurn::assistant($responseText));

        // FSM state advancement
        $previousState = $session->state;
        if ($this->stateMachine->shouldAdvance($session)) {
            $this->stateMachine->advance($session);
            $this->dispatchStateTransitionEvent($session, $previousState);
        }

        // Session end conditions
        $maxTurns       = $this->config['fsm']['max_total_turns'] ?? 40;
        $endOnTerminal  = $this->config['fsm']['end_on_terminal_state'] ?? false;

        if ($session->turnCount >= $maxTurns) {
            $previousState = $session->state;
            $session->transitionTo(SessionState::CLOSING);
            $this->dispatchStateTransitionEvent($session, $previousState);
            $this->endSessionInternal($session, 'turn_limit');
        } elseif ($this->isWallClockExpired($session)) {
            $this->endSessionInternal($session, 'time_limit');
        } elseif ($endOnTerminal && $this->stateMachine->isTerminal($session->state)) {
            $this->endSessionInternal($session, 'natural');
        }

        // Resolve prescription to asset
        $prescription = $this->maybeResolveAsset($prescription, $session->preferences->language);

        $this->logTurnMetrics($session, $responseTimeMs, $safetyVerdict, $prescription);
        $this->sessionStore->save($session);

        return SislyResponse::fromSession(
            session: $session,
            responseText: $responseText,
            arabicMirror: $arabicMirror,
            coeTrace: $coeTrace,
            handoffSuggested: $handoffSuggested,
            safetyVerdict: $safetyVerdict,
            prescription: $prescription,
        );
    }

    // -------------------------------------------------------------------------
    // Parallel execution
    // -------------------------------------------------------------------------

    /**
     * Run the coach call and (optionally) the safety classifier in "parallel".
     *
     * Spec: "Promise.all is non-negotiable. Sequential calls double latency."
     * In PHP synchronous context, both calls complete sequentially. For async
     * execution (ReactPHP, Swoole, Laravel Octane), swap the implementation
     * inside this method to use fibers/coroutines — the interface stays the same.
     *
     * @return array{0: array{response: string, arabic_mirror: ?string, coe_trace: ?CoETrace, prescription: ?Prescription}, 1: SafetyVerdict}
     */
    private function runParallel(Session $session, string $message): array
    {
        $useParallel = $this->config['safety_classifier']['parallel_enabled'] ?? true;

        if (!$useParallel || $this->safetyClassifier === null) {
            // Safety classifier disabled — run coach only
            $coachResult  = $this->processWithCoach($session, $message);
            $safetyVerdict = SafetyVerdict::ok();
            return [$coachResult, $safetyVerdict];
        }

        // In synchronous PHP: safety first (cheaper model, faster), then coach
        // The LLM safety call is lightweight (haiku/flash model, 200 tokens max)
        $safetyVerdict = $this->safetyClassifier->classify($message);

        // If flagged, skip the coach call entirely (discard coach output per spec)
        if ($safetyVerdict->isFlagged()) {
            return [['response' => '', 'arabic_mirror' => null, 'coe_trace' => null, 'prescription' => null], $safetyVerdict];
        }

        $coachResult = $this->processWithCoach($session, $message);

        return [$coachResult, $safetyVerdict];
    }

    // -------------------------------------------------------------------------
    // Coach processing
    // -------------------------------------------------------------------------

    /**
     * @return array{response: string, arabic_mirror: ?string, coe_trace: ?CoETrace, prescription: ?Prescription}
     */
    private function processWithCoach(Session $session, string $message): array
    {
        if ($this->coachRegistry !== null) {
            try {
                $coach = $this->coachRegistry->get($session->coachId);
                return $coach->process($session, $message);
            } catch (\Throwable $e) {
                if (function_exists('app') && app()->bound('log')) {
                    app('log')->error('Sisly coach processing failed', [
                        'error'      => $e->getMessage(),
                        'session_id' => $session->id,
                        'coach'      => $session->coachId->value,
                    ]);
                }
            }
        }

        return [
            'response'     => $this->generateStubResponse($session),
            'arabic_mirror' => null,
            'coe_trace'    => null,
            'prescription' => null,
        ];
    }

    // -------------------------------------------------------------------------
    // Safety handling
    // -------------------------------------------------------------------------

    /**
     * Handle a crisis detected by the deterministic keyword lexicon.
     */
    private function handleCrisis(Session $session, CrisisInfo $crisisInfo, GeoContext $geo): SislyResponse
    {
        $session->setCrisis($crisisInfo);
        $response = $this->crisisHandler->handle($crisisInfo, $geo, $session);
        $session->addTurn(ConversationTurn::assistant($response->responseText));
        $this->sessionStore->save($session);
        $this->dispatchCrisisEvent($session, $crisisInfo, $geo);

        return $response;
    }

    /**
     * Handle a "flagged" verdict from the LLM safety classifier.
     * Spec: "discard the coach reply entirely, return the crisis response, set ended: true."
     */
    private function handleLLMSafetyFlag(Session $session, SafetyVerdict $verdict, GeoContext $geo): SislyResponse
    {
        // Build a CrisisInfo-equivalent from the safety verdict
        $crisisInfo = new CrisisInfo(
            detected: true,
            severity: \Sisly\Enums\CrisisSeverity::HIGH,
            category: $this->mapSafetyCategory($verdict->category),
            keywordsMatched: [],
            resourcesProvided: false,
        );

        return $this->handleCrisis($session, $crisisInfo, $geo);
    }

    /**
     * Map safety classifier category string to CrisisCategory enum.
     */
    private function mapSafetyCategory(string $category): \Sisly\Enums\CrisisCategory
    {
        return match ($category) {
            'self_harm'        => \Sisly\Enums\CrisisCategory::SELF_HARM,
            'harm_to_others'   => \Sisly\Enums\CrisisCategory::HARM_TO_OTHERS,
            'abuse'            => \Sisly\Enums\CrisisCategory::ABUSE,
            'medical_emergency' => \Sisly\Enums\CrisisCategory::MEDICAL_EMERGENCY,
            default            => \Sisly\Enums\CrisisCategory::SELF_HARM,
        };
    }

    // -------------------------------------------------------------------------
    // Identity / meta-question detection (PENDING_FIXES #1)
    // -------------------------------------------------------------------------

    /**
     * Check if the message is an identity/credential meta-question.
     *
     * These are routed to a deterministic reply and must NOT consume FSM turns.
     * Fix for PENDING_FIXES #1.
     */
    private function isMetaQuestion(string $message): bool
    {
        if ($this->coachRegistry === null) {
            return false;
        }

        // Delegate to a lightweight detector without building the full coach
        static $identityDetector = null;
        static $credentialDetector = null;

        if ($identityDetector === null) {
            $identityDetector   = new \Sisly\Coaches\IdentityQuestionDetector();
            $credentialDetector = new \Sisly\Coaches\CredentialQuestionDetector();
        }

        return $identityDetector->isIdentityQuestion($message)
            || $credentialDetector->isCredentialQuestion($message);
    }

    // -------------------------------------------------------------------------
    // Asset resolution
    // -------------------------------------------------------------------------

    /**
     * Optionally resolve a prescription to a real content library asset.
     */
    private function maybeResolveAsset(?Prescription $prescription, string $locale): ?Prescription
    {
        if ($prescription === null) {
            return null;
        }

        $resolveAssets = $this->config['prescription']['resolve_assets'] ?? false;

        if (!$resolveAssets || $this->assetResolver === null) {
            return $prescription;
        }

        return $this->assetResolver->resolve($prescription, $locale) ?? $prescription;
    }

    // -------------------------------------------------------------------------
    // Telemetry (per spec: log metrics but never raw message content)
    // -------------------------------------------------------------------------

    private function logTurnMetrics(
        Session $session,
        int $responseTimeMs,
        SafetyVerdict $safetyVerdict,
        ?Prescription $prescription,
    ): void {
        $enabled = $this->config['telemetry']['enabled'] ?? true;
        $logTurns = $this->config['telemetry']['log_turn_metrics'] ?? true;

        if (!$enabled || !$logTurns) {
            return;
        }

        if (function_exists('app') && app()->bound('log')) {
            app('log')->info('sisly.turn', [
                'coach_id'         => $session->coachId->value,
                'locale'           => $session->preferences->language,
                'turn'             => $session->turnCount,
                'state'            => $session->state->value,
                'verdict'          => $safetyVerdict->verdict,
                'had_prescription' => $prescription !== null,
                'content_type'     => $prescription?->contentType,
                'latency_ms'       => $responseTimeMs,
                // NOTE: raw message content is intentionally NOT logged (spec privacy pillar)
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Public accessors
    // -------------------------------------------------------------------------

    public function getSession(string $sessionId): ?Session
    {
        return $this->sessionStore->get($sessionId);
    }

    /**
     * @return array{state: string, turn_count: int, is_active: bool, coach_id: string}
     * @throws SessionNotFoundException
     */
    public function getState(string $sessionId): array
    {
        $session = $this->sessionStore->get($sessionId);

        if ($session === null) {
            throw new SessionNotFoundException($sessionId);
        }

        return [
            'state'      => $session->state->value,
            'turn_count' => $session->turnCount,
            'is_active'  => $session->isActive,
            'coach_id'   => $session->coachId->value,
        ];
    }

    /**
     * @throws SessionNotFoundException
     */
    public function endSession(string $sessionId): void
    {
        $session = $this->sessionStore->get($sessionId);

        if ($session === null) {
            throw new SessionNotFoundException($sessionId);
        }

        $this->endSessionInternal($session, 'manual');
        $this->sessionStore->delete($sessionId);
    }

    public function sessionExists(string $sessionId): bool
    {
        return $this->sessionStore->exists($sessionId);
    }

    /**
     * @return array<CoachInfo>
     */
    public function getCoaches(): array
    {
        $enabled = $this->config['coaches']['enabled'] ?? CoachId::values();

        return array_filter(
            CoachInfo::all(),
            fn (CoachInfo $coach) => in_array($coach->id->value, $enabled, true)
        );
    }

    public function getCoach(CoachId $coachId): CoachInfo
    {
        return CoachInfo::byId($coachId);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function generateSessionId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveGeoContext(array $context): GeoContext
    {
        $geo = $context['geo'] ?? null;

        if ($geo instanceof GeoContext) {
            return $geo;
        }

        if (is_array($geo)) {
            return GeoContext::fromArray($geo);
        }

        return new GeoContext(country: 'AE');
    }

    /**
     * Resolve preferences and optionally auto-detect language from user message.
     *
     * Fix for PENDING_FIXES #9: LanguageDetector is now wired.
     *
     * @param array<string, mixed> $context
     */
    private function resolvePreferences(array $context, ?string $message = null): SessionPreferences
    {
        $prefs = $context['preferences'] ?? null;

        if ($prefs instanceof SessionPreferences) {
            return $prefs;
        }

        if (is_array($prefs)) {
            // If language not explicitly set and auto-detect is on, detect from message
            if (!isset($prefs['language']) && $message !== null && $this->isAutoDetectEnabled()) {
                $prefs['language'] = $this->detectLanguage($message);
            }
            return SessionPreferences::fromArray($prefs);
        }

        // Default preferences — auto-detect language from message
        if ($message !== null && $this->isAutoDetectEnabled()) {
            $detectedLang = $this->detectLanguage($message);
            return SessionPreferences::fromArray(['language' => $detectedLang]);
        }

        return new SessionPreferences();
    }

    private function isAutoDetectEnabled(): bool
    {
        return $this->config['language']['auto_detect'] ?? true;
    }

    private function detectLanguage(string $message): string
    {
        if ($this->languageDetector !== null) {
            return $this->languageDetector->detect($message);
        }
        return 'en';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveCoachId(array $context, ?string $message = null): CoachId
    {
        $coachId = $context['coach_id'] ?? null;

        if ($coachId instanceof CoachId) {
            return $coachId;
        }

        if (is_string($coachId)) {
            return CoachId::from($coachId);
        }

        if ($message !== null && $this->isDispatcherEnabled()) {
            $result = $this->dispatcher->classify($message);
            if ($result->success && $result->meetsThreshold()) {
                return $result->coach;
            }
        }

        $default = $this->config['coaches']['default'] ?? 'meetly';
        return CoachId::from($default);
    }

    private function resolveCoachIdForInit(array $context): CoachId
    {
        $coachId = $context['coach_id'] ?? null;

        if ($coachId instanceof CoachId) {
            return $coachId;
        }

        if (is_string($coachId)) {
            return CoachId::from($coachId);
        }

        $default = $this->config['coaches']['default'] ?? 'meetly';
        return CoachId::from($default);
    }

    private function isDispatcherEnabled(): bool
    {
        return $this->config['dispatcher']['enabled'] ?? true;
    }

    private function resolveMaxHistoryTurns(): int
    {
        $configured = $this->config['session']['max_history_turns'] ?? null;

        if (!is_int($configured) || $configured <= 0) {
            return Session::DEFAULT_MAX_HISTORY_TURNS;
        }

        return $configured;
    }

    private function isCrisisDetectionEnabled(): bool
    {
        return $this->config['safety']['crisis_detection'] ?? true;
    }

    private function isPostResponseValidationEnabled(): bool
    {
        return $this->config['safety']['post_response_validation'] ?? true;
    }

    private function validateAndSanitizeResponse(string $responseText, Session $session): string
    {
        if (!$this->isPostResponseValidationEnabled()) {
            return $responseText;
        }

        $result = $this->responseValidator->validate($responseText);

        if (!$result->valid) {
            if (function_exists('app') && app()->bound('log')) {
                app('log')->warning('Sisly: response blocked by post-response validator', [
                    'session_id'      => $session->id,
                    'coach_id'        => $session->coachId->value,
                    'state'           => $session->state->value,
                    'reason'          => $result->reason,
                    'matched_pattern' => $result->matchedPattern,
                ]);
            }

            return $this->responseValidator->getFallbackResponse($session->preferences->language);
        }

        return $responseText;
    }

    private function getCoachGreeting(Session $session): string
    {
        // Use enum-level primed opening first (Phase 1, no model call per spec)
        $lang = $session->preferences->language;
        $opening = $lang === 'ar'
            ? $session->coachId->primedOpeningAr()
            : $session->coachId->primedOpeningEn();

        if (!empty($opening)) {
            return $opening;
        }

        // Fallback: ask CoachRegistry
        if ($this->coachRegistry !== null) {
            try {
                $coach = $this->coachRegistry->get($session->coachId);
                return $coach->getGreeting($lang);
            } catch (\Throwable) {}
        }

        return $this->getDefaultGreeting($session);
    }

    private function getDefaultGreeting(Session $session): string
    {
        $coachName = $session->coachId->displayName();

        if ($session->preferences->language === 'ar') {
            return "مرحباً، أنا {$coachName}. أنا هنا معك.";
        }

        return "Hi, I'm {$coachName}. I'm here with you.";
    }

    private function maybeForceClosingForTimeThreshold(Session $session): void
    {
        $maxSeconds = $this->config['fsm']['max_session_seconds'] ?? null;

        if (!is_int($maxSeconds) || $maxSeconds <= 0) {
            return;
        }

        if ($session->state === SessionState::CLOSING ||
            $session->state === SessionState::CRISIS_INTERVENTION) {
            return;
        }

        $threshold = (float) ($this->config['fsm']['nearing_end_threshold'] ?? 0.85);
        $elapsed   = $this->elapsedSeconds($session);

        if ($elapsed < ($maxSeconds * $threshold)) {
            return;
        }

        $previousState = $session->state;
        $session->transitionTo(SessionState::CLOSING, reason: 'time_threshold');
        $this->dispatchStateTransitionEvent($session, $previousState);
    }

    private function isWallClockExpired(Session $session): bool
    {
        $maxSeconds = $this->config['fsm']['max_session_seconds'] ?? null;

        if (!is_int($maxSeconds) || $maxSeconds <= 0) {
            return false;
        }

        return $this->elapsedSeconds($session) >= $maxSeconds;
    }

    private function elapsedSeconds(Session $session): int
    {
        return (new \DateTimeImmutable())->getTimestamp() - $session->createdAt->getTimestamp();
    }

    private function endSessionInternal(Session $session, string $reason): void
    {
        $session->end();

        event(SessionEnded::fromSession(
            sessionId: $session->id,
            coachId: $session->coachId,
            finalState: $session->state,
            totalTurns: $session->turnCount,
            crisisOccurred: $session->crisis->detected,
            endReason: $reason,
            startedAt: $session->createdAt,
        ));
    }

    private function generateStubResponse(Session $session): string
    {
        $coachName = $session->coachId->displayName();

        return match ($session->state) {
            SessionState::INTAKE          => "Hi, I'm {$coachName}. I hear you. Tell me what's going on.",
            SessionState::EXPLORATION     => "Can you tell me a bit more about what you're experiencing?",
            SessionState::DEEPENING       => "That makes sense. It sounds like you're dealing with something that matters to you.",
            SessionState::PROBLEM_SOLVING => "Let's try something together. Do you have 30 seconds, 1 minute, or 2 minutes?",
            SessionState::CLOSING         => "You've done well to take this time for yourself.",
            SessionState::CRISIS_INTERVENTION => "I hear that you're going through something really difficult. Your safety matters.",
            default                       => "I'm here with you. Tell me more.",
        };
    }

    // -------------------------------------------------------------------------
    // Event dispatchers
    // -------------------------------------------------------------------------

    private function dispatchMessageReceivedEvent(Session $session, string $message): void
    {
        event(MessageReceived::create(
            sessionId: $session->id,
            message: $message,
            coachId: $session->coachId,
            state: $session->state,
            turnCount: $session->turnCount,
        ));
    }

    private function dispatchResponseGeneratedEvent(
        Session $session,
        string $response,
        ?string $arabicMirror,
        ?CoETrace $coeTrace,
        int $responseTimeMs,
    ): void {
        event(ResponseGenerated::create(
            sessionId: $session->id,
            response: $response,
            arabicMirror: $arabicMirror,
            coachId: $session->coachId,
            state: $session->state,
            turnCount: $session->turnCount,
            coeTrace: $coeTrace,
            responseTimeMs: $responseTimeMs,
        ));
    }

    private function dispatchSessionStartedEvent(Session $session): void
    {
        event(SessionStarted::fromSession(
            sessionId: $session->id,
            coachId: $session->coachId,
            country: $session->geo->country,
            language: $session->preferences->language,
        ));
    }

    private function dispatchStateTransitionEvent(Session $session, SessionState $fromState): void
    {
        event(StateTransitioned::fromTransition(
            sessionId: $session->id,
            fromState: $fromState,
            toState: $session->state,
            turnCount: $session->turnCount,
        ));
    }

    private function dispatchCrisisEvent(Session $session, CrisisInfo $crisisInfo, GeoContext $geo): void
    {
        if ($crisisInfo->severity === null || $crisisInfo->category === null) {
            return;
        }

        event(CrisisDetected::fromDetection(
            sessionId: $session->id,
            severity: $crisisInfo->severity,
            category: $crisisInfo->category,
            keywords: $crisisInfo->keywordsMatched,
            country: $geo->country,
            resourcesProvided: true,
        ));
    }
}
