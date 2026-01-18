<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dangerous Regex Patterns
    |--------------------------------------------------------------------------
    |
    | These patterns are used to guard against malicious inputs in URLs.
    | They check for common attack vectors like XSS, protocol wrappers, etc.
    |
    */

    'patterns' => [
        // Protocol wrappers that can execute code or bypass security
        '/^javascript:/i',
        '/^data:/i',
        '/^vbscript:/i',
        '/^file:/i',

        // Common XSS vectors in URL parameters
        '/<script/i',
        '/onload\s*=/i',
        '/onerror\s*=/i',
        '/onclick\s*=/i',
        '/onmouseover\s*=/i',
        '/eval\s*\(/i',
        '/alert\s*\(/i',
    ],

];
