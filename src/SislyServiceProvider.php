<?php

declare(strict_types=1);

namespace Sisly;

use Illuminate\Support\ServiceProvider;
use Sisly\Arabic\LanguageDetector;
use Sisly\Coaches\CoachRegistry;
use Sisly\Coaches\PromptLoader;
use Sisly\Contracts\AssetResolverInterface;
use Sisly\Contracts\LLMProviderInterface;
use Sisly\Contracts\SessionStoreInterface;
use Sisly\Dispatcher\Dispatcher;
use Sisly\Dispatcher\HandoffDetector;
use Sisly\FSM\StateMachine;
use Sisly\LLM\LLMManager;
use Sisly\LLM\MockProvider;
use Sisly\LLM\Providers\AnthropicProvider;
use Sisly\LLM\Providers\GeminiProvider;
use Sisly\LLM\Providers\OpenAIProvider;
use Sisly\Safety\CrisisDetector;
use Sisly\Safety\CrisisHandler;
use Sisly\Safety\CrisisResourceProvider;
use Sisly\Safety\PostResponseValidator;
use Sisly\Safety\SafetyClassifier;
use Sisly\Session\Adapters\LaravelCacheAdapter;

class SislyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/sisly.php',
            'sisly'
        );

        $this->registerSessionStore();
        $this->registerSafetyComponents();
        $this->registerFSMComponents();

        // Register asset resolver if configured
        $this->app->singleton(AssetResolverInterface::class, function ($app) {
            $resolverClass = $app['config']->get('sisly.prescription.asset_resolver');

            if ($resolverClass !== null && class_exists($resolverClass)) {
                return $app->make($resolverClass);
            }

            return null;
        });

        // Main manager
        $this->app->singleton(SislyManager::class, function ($app) {
            return new SislyManager(
                config: $app['config']->get('sisly'),
                sessionStore: $app->make(SessionStoreInterface::class),
                crisisDetector: $app->make(CrisisDetector::class),
                crisisHandler: $app->make(CrisisHandler::class),
                responseValidator: $app->make(PostResponseValidator::class),
                stateMachine: $app->make(StateMachine::class),
                dispatcher: $app->make(Dispatcher::class),
                handoffDetector: $app->make(HandoffDetector::class),
                coachRegistry: $app->make(CoachRegistry::class),
                safetyClassifier: $app->make(SafetyClassifier::class),
                languageDetector: $app->make(LanguageDetector::class),
                assetResolver: $app->make(AssetResolverInterface::class),
            );
        });

        $this->app->alias(SislyManager::class, 'sisly');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/sisly.php' => config_path('sisly.php'),
            ], 'sisly-config');

            $this->publishes([
                __DIR__ . '/../resources/data' => resource_path('sisly/data'),
            ], 'sisly-data');

            $this->publishes([
                __DIR__ . '/../resources/prompts' => resource_path('sisly/prompts'),
            ], 'sisly-prompts');
        }
    }

    protected function registerSessionStore(): void
    {
        $this->app->singleton(SessionStoreInterface::class, function ($app) {
            $driver = $app['config']->get('sisly.session.driver', 'cache');
            $config = $app['config']->get('sisly.session', []);

            return match ($driver) {
                'cache' => new LaravelCacheAdapter($config),
                'redis' => new \Sisly\Session\Adapters\RedisAdapter($config),
                default => new LaravelCacheAdapter($config),
            };
        });
    }

    protected function registerSafetyComponents(): void
    {
        $this->app->singleton(CrisisDetector::class, function ($app) {
            $customPath = $app['config']->get('sisly.safety.crisis_lexicon_path');

            if ($customPath !== null && file_exists($customPath)) {
                $lexicon = json_decode(file_get_contents($customPath), true);
                return new CrisisDetector($lexicon);
            }

            return new CrisisDetector();
        });

        $this->app->singleton(CrisisResourceProvider::class, function ($app) {
            $useDefaults = $app['config']->get('sisly.crisis_resources.use_package_defaults', true);
            $customPath  = $app['config']->get('sisly.crisis_resources.custom_path');

            if (!$useDefaults && $customPath !== null && file_exists($customPath)) {
                $resources = json_decode(file_get_contents($customPath), true);
                return new CrisisResourceProvider($resources);
            }

            return new CrisisResourceProvider();
        });

        $this->app->singleton(CrisisHandler::class, function ($app) {
            return new CrisisHandler(
                resourceProvider: $app->make(CrisisResourceProvider::class),
            );
        });

        $this->app->singleton(PostResponseValidator::class, function ($app) {
            return new PostResponseValidator();
        });

        // Safety classifier uses the cheaper/faster "safety_model" tier
        $this->app->singleton(SafetyClassifier::class, function ($app) {
            $parallelEnabled = $app['config']->get('sisly.safety_classifier.parallel_enabled', true);

            if (!$parallelEnabled) {
                // Return a no-op classifier that always returns "ok"
                return new class extends SafetyClassifier {
                    public function __construct() {
                        // no-op constructor (testing only)
                    }
                    public function classify(string $userMessage): \Sisly\DTOs\SafetyVerdict {
                        return \Sisly\DTOs\SafetyVerdict::ok();
                    }
                };
            }

            $driver = $app['config']->get('sisly.llm.driver', 'anthropic');
            $failClosed = $app['config']->get(
                'sisly.safety_classifier.fail_closed_verdict',
                \Sisly\DTOs\SafetyVerdict::VERDICT_CHECKING
            );

            // Use the dedicated safety_model (cheaper tier)
            $safetyProvider = $this->createSafetyProvider($driver, $app);

            return new SafetyClassifier($safetyProvider, $failClosed);
        });
    }

    protected function registerFSMComponents(): void
    {
        $this->app->singleton(StateMachine::class, function ($app) {
            return new StateMachine($app['config']->get('sisly.fsm', []));
        });

        // Primary LLM provider (coach model tier)
        $this->app->singleton(LLMProviderInterface::class, function ($app) {
            $driver         = $app['config']->get('sisly.llm.driver', 'anthropic');
            $failoverEnabled = $app['config']->get('sisly.llm.failover_enabled', true);

            if ($driver === 'mock') {
                return new MockProvider();
            }

            $primaryProvider = $this->createLLMProvider($driver, $app);

            if (!$failoverEnabled) {
                return $primaryProvider;
            }

            $failureThreshold = $app['config']->get('sisly.llm.failure_threshold', 5);
            $manager = new LLMManager([], true, $failureThreshold);
            $manager->addProvider($primaryProvider);

            $fallbackDrivers = match ($driver) {
                'openai'    => ['anthropic', 'gemini'],
                'gemini'    => ['anthropic', 'openai'],
                'anthropic' => ['openai', 'gemini'],
                default     => [],
            };

            foreach ($fallbackDrivers as $fallbackDriver) {
                $fallbackProvider = $this->createLLMProvider($fallbackDriver, $app);
                if ($fallbackProvider->isAvailable()) {
                    $manager->addProvider($fallbackProvider);
                }
            }

            return $manager;
        });

        $this->app->singleton(OpenAIProvider::class, fn ($app) => $this->createLLMProvider('openai', $app));
        $this->app->singleton(GeminiProvider::class, fn ($app) => $this->createLLMProvider('gemini', $app));
        $this->app->singleton(AnthropicProvider::class, fn ($app) => $this->createLLMProvider('anthropic', $app));

        $this->app->singleton(LanguageDetector::class, function ($app) {
            return new LanguageDetector();
        });

        $this->app->singleton(PromptLoader::class, function ($app) {
            $overridePath = $app['config']->get('sisly.prompts.override_path');
            return new PromptLoader($overridePath);
        });

        $this->app->singleton(CoachRegistry::class, function ($app) {
            return new CoachRegistry(
                llm: $app->make(LLMProviderInterface::class),
                promptLoader: $app->make(PromptLoader::class),
                enabledCoaches: $app['config']->get('sisly.coaches.enabled', []),
            );
        });

        $this->app->singleton(Dispatcher::class, function ($app) {
            $useSafetyModel = $app['config']->get('sisly.dispatcher.use_safety_model', true);

            // Dispatcher uses the cheaper model for classification
            $driver = $app['config']->get('sisly.llm.driver', 'anthropic');
            $llm = $useSafetyModel
                ? $this->createSafetyProvider($driver, $app)
                : $app->make(LLMProviderInterface::class);

            return new Dispatcher(
                llm: $llm,
                config: [
                    'enabled_coaches'       => $app['config']->get('sisly.coaches.enabled'),
                    'default_coach'         => $app['config']->get('sisly.coaches.default', 'meetly'),
                    'confidence_threshold'  => $app['config']->get('sisly.dispatcher.confidence_threshold', 0.7),
                    'prompt'                => $this->loadDispatcherPrompt($app),
                ],
            );
        });

        $this->app->singleton(HandoffDetector::class, function ($app) {
            return new HandoffDetector();
        });
    }

    /**
     * Create an LLM provider using the COACH model tier (full quality).
     */
    protected function createLLMProvider(string $driver, $app): LLMProviderInterface
    {
        return match ($driver) {
            'openai'    => new OpenAIProvider($app['config']->get('sisly.llm.openai', [])),
            'gemini'    => new GeminiProvider($app['config']->get('sisly.llm.gemini', [])),
            'anthropic' => new AnthropicProvider($app['config']->get('sisly.llm.anthropic', [])),
            'mock'      => new MockProvider(),
            default     => new MockProvider(),
        };
    }

    /**
     * Create an LLM provider using the SAFETY model tier (cheap + fast).
     *
     * Each provider config has a dedicated safety_model field.
     * Falls back to the standard model if safety_model is not set.
     */
    protected function createSafetyProvider(string $driver, $app): LLMProviderInterface
    {
        $config = $app['config']->get("sisly.llm.{$driver}", []);

        // Swap model to the cheaper safety_model tier
        if (isset($config['safety_model'])) {
            $config['model'] = $config['safety_model'];
        }

        return match ($driver) {
            'openai'    => new OpenAIProvider($config),
            'gemini'    => new GeminiProvider($config),
            'anthropic' => new AnthropicProvider($config),
            'mock'      => new MockProvider(),
            default     => new MockProvider(),
        };
    }

    /**
     * Load the dispatcher prompt from resources/prompts/global/dispatcher.md.
     */
    private function loadDispatcherPrompt($app): ?string
    {
        $overridePath = $app['config']->get('sisly.prompts.override_path');
        $promptPath   = $overridePath
            ? $overridePath . '/global/dispatcher.md'
            : __DIR__ . '/../resources/prompts/global/dispatcher.md';

        if (file_exists($promptPath)) {
            return file_get_contents($promptPath) ?: null;
        }

        return null;
    }

    public function provides(): array
    {
        return [
            SislyManager::class,
            'sisly',
            SessionStoreInterface::class,
            CrisisDetector::class,
            CrisisResourceProvider::class,
            CrisisHandler::class,
            PostResponseValidator::class,
            StateMachine::class,
            LLMProviderInterface::class,
            OpenAIProvider::class,
            GeminiProvider::class,
            AnthropicProvider::class,
            LLMManager::class,
            PromptLoader::class,
            CoachRegistry::class,
            Dispatcher::class,
            HandoffDetector::class,
            SafetyClassifier::class,
            LanguageDetector::class,
        ];
    }
}
