<?php

namespace Tests\Unit;

use App\Services\ChatEngine;
use PHPUnit\Framework\TestCase;

class ChatEngineTest extends TestCase
{
    /**
     * Test that sanitize escapes HTML entities for XSS protection.
     */
    public function test_sanitize_escapes_html_entities(): void
    {
        $input = '<script>alert("xss")</script>';
        $result = ChatEngine::sanitize($input);

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
        $this->assertStringContainsString('&quot;', $result);
    }

    /**
     * Test that sanitize trims whitespace.
     */
    public function test_sanitize_trims_whitespace(): void
    {
        $input = '  Hello World  ';
        $result = ChatEngine::sanitize($input);

        $this->assertEquals('Hello World', $result);
    }

    /**
     * Test that sanitize handles empty strings.
     */
    public function test_sanitize_handles_empty_string(): void
    {
        $result = ChatEngine::sanitize('');

        $this->assertEquals('', $result);
    }

    /**
     * Test that sanitize escapes single quotes.
     */
    public function test_sanitize_escapes_single_quotes(): void
    {
        $input = "It's a test";
        $result = ChatEngine::sanitize($input);

        $this->assertStringContainsString('&#039;', $result);
    }

    /**
     * Test that sanitize preserves unicode characters.
     */
    public function test_sanitize_preserves_unicode(): void
    {
        $input = '你好 Hello 🎉';
        $result = ChatEngine::sanitize($input);

        $this->assertStringContainsString('你好', $result);
        $this->assertStringContainsString('Hello', $result);
        $this->assertStringContainsString('🎉', $result);
    }

    /**
     * Test that sanitize escapes ampersands.
     */
    public function test_sanitize_escapes_ampersands(): void
    {
        $input = 'Tom & Jerry';
        $result = ChatEngine::sanitize($input);

        $this->assertStringContainsString('&amp;', $result);
    }
}
