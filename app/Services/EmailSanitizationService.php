<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Mews\Purifier\Facades\Purifier;

/**
 * Email Sanitization Service
 *
 * Defense-in-depth sanitization for email HTML content.
 * Handles source-specific pre-processing (stricter for IMAP),
 * encoding normalization, and HTMLPurifier-based sanitization.
 *
 * @security This service is critical for preventing XSS attacks.
 */
class EmailSanitizationService
{
    /**
     * Trusted providers that send reasonably clean HTML.
     */
    protected const TRUSTED_PROVIDERS = ['gmail', 'outlook', 'microsoft'];

    /**
     * Sanitize email HTML content.
     *
     * @param  string  $html  Raw HTML content
     * @param  string  $source  Provider source (gmail, outlook, imap, etc.)
     * @return string Sanitized HTML safe for display
     */
    public function sanitize(string $html, string $source = 'imap'): string
    {
        if (empty($html)) {
            return '';
        }

        try {
            // Step 1: Normalize encoding
            $html = $this->normalizeEncoding($html);

            // Step 2: Source-specific pre-processing
            $html = $this->preProcess($html, $source);

            // Step 3: Preserve CID images before purification (HTMLPurifier strips them)
            $cidPlaceholders = [];
            $html = preg_replace_callback(
                '/<img\s+[^>]*src\s*=\s*(["\'])cid:([^"\']+)\1[^>]*>/i',
                function ($matches) use (&$cidPlaceholders) {
                    // Use text placeholder that survives HTMLPurifier
                    $index = count($cidPlaceholders);
                    $placeholder = '[[CID_PLACEHOLDER_'.$index.']]';
                    $cidPlaceholders[$index] = $matches[0];

                    return $placeholder;
                },
                $html
            );

            // Step 4: HTMLPurifier sanitization
            $html = $this->purify($html, $source);

            // Step 5: Restore CID images
            foreach ($cidPlaceholders as $index => $originalImg) {
                $placeholder = '[[CID_PLACEHOLDER_'.$index.']]';
                $html = str_replace($placeholder, $originalImg, $html);
            }

            // Step 6: Post-processing (external image blocking, etc.)
            $html = $this->postProcess($html);

            return $html;
        } catch (\Throwable $e) {
            Log::error('[EmailSanitizationService] Failed to sanitize email', [
                'source' => $source,
                'error' => $e->getMessage(),
            ]);

            // Return safe fallback (strip all HTML)
            return strip_tags($html);
        }
    }

    /**
     * Normalize character encoding to UTF-8.
     *
     * Handles common email encodings: ISO-8859-1, Windows-1252, etc.
     */
    protected function normalizeEncoding(string $html): string
    {
        // Detect encoding
        $encoding = mb_detect_encoding($html, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);

        if ($encoding && $encoding !== 'UTF-8') {
            $html = mb_convert_encoding($html, 'UTF-8', $encoding);
        }

        // Remove NULL bytes and control characters (except newlines/tabs)
        $html = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $html);

        return $html;
    }

    /**
     * Pre-process HTML based on source.
     *
     * IMAP sources get stricter processing as they can come from anywhere.
     */
    protected function preProcess(string $html, string $source): string
    {
        // Always strip scripts and event handlers early
        $html = $this->stripDangerousPatterns($html);

        // Stricter processing for untrusted sources
        if (! in_array($source, self::TRUSTED_PROVIDERS)) {
            // Remove data: URIs with base64 (potential XSS vector)
            $html = preg_replace('/data:[^;]+;base64,[a-zA-Z0-9+\/=]+/i', '', $html);

            // Remove CSS expressions (IE-specific XSS vector)
            $html = preg_replace('/expression\s*\([^)]*\)/i', '', $html);

            // Remove javascript: URIs
            $html = preg_replace('/javascript\s*:/i', '', $html);

            // Remove vbscript: URIs
            $html = preg_replace('/vbscript\s*:/i', '', $html);
        }

        return $html;
    }

    /**
     * Strip dangerous patterns from HTML.
     */
    protected function stripDangerousPatterns(string $html): string
    {
        // Remove script tags and content
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);

        // Remove style tags with dangerous content
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);

        // Remove event handlers (onclick, onerror, onload, etc.)
        $html = preg_replace('/\s+on\w+\s*=\s*(["\'])[^"\']*\1/i', '', $html);
        $html = preg_replace('/\s+on\w+\s*=\s*[^\s>]+/i', '', $html);

        return $html;
    }

    /**
     * Purify HTML using HTMLPurifier.
     */
    protected function purify(string $html, string $source): string
    {
        // Use the 'email' config from config/purifier.php
        // If not defined, use 'default'
        $configName = config('purifier.settings.email') ? 'email' : 'default';

        return Purifier::clean($html, $configName);
    }

    /**
     * Post-process sanitized HTML.
     *
     * Handles image blocking by converting src to data-original-src.
     */
    protected function postProcess(string $html): string
    {
        // Convert external image sources to data attributes for frontend toggle
        // This allows the frontend to show/hide images based on user preference
        $html = preg_replace_callback(
            '/<img\s+([^>]*?)src\s*=\s*(["\'])([^"\']+)\2([^>]*)>/i',
            function ($matches) {
                $src = $matches[3];
                $before = $matches[1];
                $quote = $matches[2];
                $after = $matches[4];

                // Skip data: URIs (already inline) and cid: (inline attachments)
                if (str_starts_with($src, 'data:') || str_starts_with($src, 'cid:')) {
                    return $matches[0];
                }

                // Convert to data-original-src for frontend to handle
                return "<img {$before}data-original-src={$quote}{$src}{$quote} src={$quote}{$quote}{$after}>";
            },
            $html
        );

        return $html;
    }

    /**
     * Extract Content-ID from attachment for inline image matching.
     *
     * @param  mixed  $attachment  IMAP attachment object
     * @return string|null Content-ID without angle brackets
     */
    public function extractContentId($attachment): ?string
    {
        if (! $attachment) {
            return null;
        }

        $contentId = null;

        // Try different methods to get Content-ID
        if (method_exists($attachment, 'getContentId')) {
            $contentId = $attachment->getContentId();
        } elseif (method_exists($attachment, 'getId')) {
            $contentId = $attachment->getId();
        }

        if (! $contentId) {
            return null;
        }

        // Remove angle brackets if present
        $contentId = trim($contentId, '<>');

        return $contentId ?: null;
    }
}
