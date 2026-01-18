<?php

namespace Tests\Feature;

use App\Contracts\AvatarContract;
use App\Contracts\AvatarData;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvatarServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AvatarContract $avatarService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->avatarService = app(AvatarContract::class);
    }

    /**
     * Test resolving avatar for a user without media returns fallback.
     */
    public function test_resolve_user_without_avatar_returns_fallback(): void
    {
        $user = User::factory()->create(['name' => 'John Doe']);

        $result = $this->avatarService->resolve($user);

        $this->assertInstanceOf(AvatarData::class, $result);
        $this->assertNull($result->url);
        $this->assertNotEmpty($result->fallback);
        $this->assertEquals('JD', $result->initials);
        $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $result->color);
    }

    /**
     * Test resolving avatar for a team.
     */
    public function test_resolve_team_returns_avatar_data(): void
    {
        $team = Team::factory()->create(['name' => 'Acme Corp']);

        $result = $this->avatarService->resolve($team);

        $this->assertInstanceOf(AvatarData::class, $result);
        $this->assertEquals('AC', $result->initials);
    }

    /**
     * Test resolving avatar from array data (API participant format).
     */
    public function test_resolve_from_array_returns_avatar_data(): void
    {
        $data = [
            'name' => 'Jane Smith',
            'avatar_url' => 'https://example.com/avatar.jpg',
            'public_id' => 'abc123',
        ];

        $result = $this->avatarService->resolve($data);

        $this->assertInstanceOf(AvatarData::class, $result);
        $this->assertEquals('https://example.com/avatar.jpg', $result->url);
        $this->assertEquals('JS', $result->initials);
    }

    /**
     * Test resolving null entity returns safe default.
     */
    public function test_resolve_null_returns_default(): void
    {
        $result = $this->avatarService->resolve(null);

        $this->assertInstanceOf(AvatarData::class, $result);
        $this->assertNull($result->url);
        $this->assertEquals('?', $result->initials);
    }

    /**
     * Test getInitials generates correct initials.
     */
    public function test_get_initials_from_name(): void
    {
        $this->assertEquals('JD', $this->avatarService->getInitials('John Doe'));
        $this->assertEquals('A', $this->avatarService->getInitials('Alice'));
        $this->assertEquals('JR', $this->avatarService->getInitials('John Robert Smith'));
        $this->assertEquals('?', $this->avatarService->getInitials(''));
    }

    /**
     * Test getColorFromId returns consistent colors.
     */
    public function test_get_color_from_id_is_consistent(): void
    {
        $color1 = $this->avatarService->getColorFromId('user-123');
        $color2 = $this->avatarService->getColorFromId('user-123');
        $color3 = $this->avatarService->getColorFromId('user-456');

        $this->assertEquals($color1, $color2);
        $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $color1);
        // Different IDs may or may not produce different colors, but format should be valid
        $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $color3);
    }

    /**
     * Test getFallbackUrl returns configured path.
     */
    public function test_get_fallback_url_returns_configured_path(): void
    {
        $fallback = $this->avatarService->getFallbackUrl();

        $this->assertStringContainsString('static/images/avatar/blank.png', $fallback);
    }

    /**
     * Test AvatarData getUrl returns url when set.
     */
    public function test_avatar_data_get_url_returns_url_when_set(): void
    {
        $data = new AvatarData(
            url: 'https://example.com/avatar.jpg',
            fallback: '/fallback.png',
            initials: 'JD',
            color: '#ffffff'
        );

        $this->assertEquals('https://example.com/avatar.jpg', $data->getUrl());
    }

    /**
     * Test AvatarData getUrl returns fallback when url is null.
     */
    public function test_avatar_data_get_url_returns_fallback_when_url_null(): void
    {
        $data = new AvatarData(
            url: null,
            fallback: '/fallback.png',
            initials: 'JD',
            color: '#ffffff'
        );

        $this->assertEquals('/fallback.png', $data->getUrl());
    }

    /**
     * Test AvatarData toArray returns correct structure.
     */
    public function test_avatar_data_to_array(): void
    {
        $data = new AvatarData(
            url: 'https://example.com/avatar.jpg',
            fallback: '/fallback.png',
            initials: 'JD',
            color: '#ffffff'
        );

        $array = $data->toArray();

        $this->assertArrayHasKey('url', $array);
        $this->assertArrayHasKey('fallback', $array);
        $this->assertArrayHasKey('initials', $array);
        $this->assertArrayHasKey('color', $array);
    }
}
