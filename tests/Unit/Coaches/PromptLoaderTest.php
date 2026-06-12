<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\Coaches;

use PHPUnit\Framework\TestCase;
use Sisly\Coaches\PromptLoader;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;

class PromptLoaderTest extends TestCase
{
    private PromptLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loader = new PromptLoader();
    }

    public function test_loads_global_rules_prompt(): void
    {
        $content = $this->loader->loadGlobal('rules');

        $this->assertNotEmpty($content);
        $this->assertStringContainsString('emotional regulation coach', $content);
    }

    public function test_loads_dispatcher_prompt(): void
    {
        $content = $this->loader->loadGlobal('dispatcher');

        $this->assertNotEmpty($content);
        $this->assertStringContainsString('Dispatcher', $content);
    }

    public function test_loads_meetly_system_prompt(): void
    {
        $content = $this->loader->loadCoachSystem(CoachId::MEETLY);

        $this->assertNotEmpty($content);
        $this->assertStringContainsString('MEETLY', $content);
    }

    public function test_loads_meetly_exploration_prompt(): void
    {
        $content = $this->loader->loadCoachState(CoachId::MEETLY, SessionState::EXPLORATION);

        $this->assertNotEmpty($content);
        $this->assertStringContainsString('EXPLORATION', $content);
    }

    public function test_loads_meetly_deepening_prompt(): void
    {
        $content = $this->loader->loadCoachState(CoachId::MEETLY, SessionState::DEEPENING);

        $this->assertNotEmpty($content);
        $this->assertStringContainsString('DEEPENING', $content);
    }

    public function test_loads_meetly_technique_prompt(): void
    {
        $content = $this->loader->loadCoachState(CoachId::MEETLY, SessionState::PROBLEM_SOLVING);

        $this->assertNotEmpty($content);
        $this->assertStringContainsString('PROBLEM_SOLVING', $content);
    }

    public function test_loads_meetly_closing_prompt(): void
    {
        $content = $this->loader->loadCoachState(CoachId::MEETLY, SessionState::CLOSING);

        $this->assertNotEmpty($content);
        $this->assertStringContainsString('CLOSING', $content);
    }

    public function test_caches_loaded_prompts(): void
    {
        // Load same prompt twice
        $content1 = $this->loader->loadGlobal('rules');
        $content2 = $this->loader->loadGlobal('rules');

        $this->assertSame($content1, $content2);
    }

    public function test_clear_cache_clears_cached_prompts(): void
    {
        // Load a prompt
        $this->loader->loadGlobal('rules');

        // Clear cache
        $this->loader->clearCache();

        // Should still work after clear
        $content = $this->loader->loadGlobal('rules');
        $this->assertNotEmpty($content);
    }

    public function test_returns_empty_for_nonexistent_prompt(): void
    {
        $content = $this->loader->loadGlobal('nonexistent_prompt');

        $this->assertEmpty($content);
    }

    public function test_exists_returns_true_for_existing_prompt(): void
    {
        $exists = $this->loader->exists('global/rules.md');

        $this->assertTrue($exists);
    }

    public function test_exists_returns_false_for_nonexistent_prompt(): void
    {
        $exists = $this->loader->exists('global/nonexistent.md');

        $this->assertFalse($exists);
    }

    public function test_override_path_takes_precedence(): void
    {
        // Create a temporary override file
        $tempDir = sys_get_temp_dir() . '/sisly_test_prompts';
        @mkdir($tempDir . '/global', 0777, true);
        file_put_contents($tempDir . '/global/test_override.md', 'OVERRIDE CONTENT');

        $loader = new PromptLoader($tempDir);
        $content = $loader->loadGlobal('test_override');

        $this->assertEquals('OVERRIDE CONTENT', $content);

        // Cleanup
        @unlink($tempDir . '/global/test_override.md');
        @rmdir($tempDir . '/global');
        @rmdir($tempDir);
    }

    public function test_set_override_path_clears_cache(): void
    {
        // Load a prompt
        $content1 = $this->loader->loadGlobal('rules');

        // Set a new override path (even if it doesn't exist)
        $this->loader->setOverridePath('/nonexistent');

        // The cache should be cleared, content should still load from package
        $content2 = $this->loader->loadGlobal('rules');

        // Both should be the same content (from package)
        $this->assertEquals($content1, $content2);
    }

    public function test_strips_yaml_frontmatter(): void
    {
        // Create a temporary file with YAML frontmatter
        $tempDir = sys_get_temp_dir() . '/sisly_test_prompts2';
        @mkdir($tempDir . '/global', 0777, true);

        $contentWithFrontmatter = <<<CONTENT
---
version: 1.0
author: test
---

Actual content here
CONTENT;

        file_put_contents($tempDir . '/global/frontmatter_test.md', $contentWithFrontmatter);

        $loader = new PromptLoader($tempDir);
        $content = $loader->loadGlobal('frontmatter_test');

        $this->assertEquals('Actual content here', $content);

        // Cleanup
        @unlink($tempDir . '/global/frontmatter_test.md');
        @rmdir($tempDir . '/global');
        @rmdir($tempDir);
    }

    public function test_get_available_prompts_for_meetly(): void
    {
        $prompts = $this->loader->getAvailablePrompts(CoachId::MEETLY);

        $this->assertIsArray($prompts);
        $this->assertContains('system', $prompts);
        $this->assertContains('exploration', $prompts);
        $this->assertContains('deepening', $prompts);
        $this->assertContains('technique', $prompts);
        $this->assertContains('closing', $prompts);
    }
}
