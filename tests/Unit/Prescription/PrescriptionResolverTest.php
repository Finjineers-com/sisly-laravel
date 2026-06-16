<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\Prescription;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Sisly\DTOs\GeoContext;
use Sisly\DTOs\Prescription;
use Sisly\DTOs\ResolvedPrescription;
use Sisly\DTOs\Session;
use Sisly\Enums\CoachId;
use Sisly\Enums\ContentType;
use Sisly\Prescription\PrescriptionResolver;
use Sisly\Tests\TestCase;

class PrescriptionResolverTest extends TestCase
{
    private PrescriptionResolver $resolver;
    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->resolver = new PrescriptionResolver();
        
        $this->session = Session::create(
            id: 'test-session-123',
            coachId: CoachId::MEETLY,
            geo: new GeoContext(country: 'AE')
        );

        Cache::forget("sisly:content_pool:test-session-123");
        Cache::forget("sisly:served_content:test-session-123");
    }

    public function test_it_warms_and_caches_content_pool(): void
    {
        $mockData = [
            [
                'content_id' => 12,
                'title' => 'Calming Meetly audio',
                'description' => 'A short session',
                'duration' => 2,
                'media_category' => 'Meditation',
                'media' => [
                    'audio_path' => 'https://example.com/audio1.mp3',
                    'audio_thumbnail' => 'https://example.com/thumb1.png',
                ]
            ]
        ];

        Http::fake([
            '*/v1/insights/by-type*' => Http::response($mockData, 200),
        ]);

        $pool = $this->resolver->getContentPool($this->session, 'Meetings');

        $this->assertCount(1, $pool);
        $this->assertEquals(12, $pool[0]['content_id']);
        
        // Assert cached
        $this->assertTrue(Cache::has("sisly:content_pool:test-session-123"));
        $this->assertEquals($mockData, Cache::get("sisly:content_pool:test-session-123"));
    }

    public function test_it_resolves_prescription(): void
    {
        $mockData = [
            [
                'content_id' => 45,
                'title' => 'Calm Morning',
                'description' => 'Start your day right',
                'duration' => 5,
                'media_category' => 'Meditation',
                'media' => [
                    'audio_path' => 'https://example.com/morning.mp3',
                    'audio_thumbnail' => 'https://example.com/morning.png',
                ]
            ]
        ];

        Http::fake([
            '*/v1/insights/by-type*' => Http::response($mockData, 200),
        ]);

        $prescription = new Prescription(
            contentType: ContentType::MEETINGS,
            reason: 'Relax before the meeting'
        );

        $resolved = $this->resolver->resolve($this->session, $prescription);

        $this->assertInstanceOf(ResolvedPrescription::class, $resolved);
        $this->assertEquals(45, $resolved->contentId);
        $this->assertEquals('Calm Morning', $resolved->title);
        $this->assertEquals('Meditation', $resolved->mediaCategory);
        $this->assertEquals('https://example.com/morning.mp3', $resolved->audioPath);
        $this->assertEquals('https://example.com/morning.png', $resolved->audioThumbnail);
        $this->assertEquals('Relax before the meeting', $resolved->reason);

        // Verify it was marked as served
        $served = Cache::get("sisly:served_content:test-session-123");
        $this->assertContains(45, $served);
    }

    public function test_it_does_not_suggest_already_served_content(): void
    {
        $mockData = [
            [
                'content_id' => 101,
                'title' => 'Content 1',
                'media' => ['audio_path' => '', 'audio_thumbnail' => '']
            ],
            [
                'content_id' => 102,
                'title' => 'Content 2',
                'media' => ['audio_path' => '', 'audio_thumbnail' => '']
            ]
        ];

        // Seed pool directly to cache to avoid API call
        Cache::put("sisly:content_pool:test-session-123", $mockData, 1800);
        
        // Mark 101 as served
        Cache::put("sisly:served_content:test-session-123", [101], 1800);

        $prescription = new Prescription(ContentType::MEETINGS, 'Test');

        $resolved = $this->resolver->resolve($this->session, $prescription);

        // Must suggest 102 since 101 was served
        $this->assertEquals(102, $resolved->contentId);

        // Served must now contain both
        $served = Cache::get("sisly:served_content:test-session-123");
        $this->assertContains(101, $served);
        $this->assertContains(102, $served);
    }

    public function test_it_resets_served_cache_when_exhausted(): void
    {
        $mockData = [
            [
                'content_id' => 201,
                'title' => 'Content 1',
                'media' => ['audio_path' => '', 'audio_thumbnail' => '']
            ]
        ];

        // Seed pool directly
        Cache::put("sisly:content_pool:test-session-123", $mockData, 1800);
        
        // Mark all as served
        Cache::put("sisly:served_content:test-session-123", [201], 1800);

        $prescription = new Prescription(ContentType::MEETINGS, 'Test');

        // Since it's exhausted, it must reset served cache and suggest 201 again
        $resolved = $this->resolver->resolve($this->session, $prescription);

        $this->assertEquals(201, $resolved->contentId);
        $served = Cache::get("sisly:served_content:test-session-123");
        $this->assertEquals([201], $served);
    }
}
