<?php

namespace PalakRajput\DataEncryption\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class InjectDisableConsole
{
    /**
     * Routes that should NOT have console disabled (login/auth routes)
     */
    protected $except = [
        'login',
        'logout',
        'auth/*',
        'password/*',
        'register',
        'forgot-password',
        'reset-password',
        'email/*',
        'sanctum/*',
        'oauth/*',
    ];

    public function handle(Request $request, Closure $next)
    {
        // First check if we should skip middleware for authentication routes
        if ($this->shouldSkipMiddleware($request)) {
            return $next($request);
        }

        $response = $next($request);

        if (config('data-encryption.disable_console_logs', false) &&
            str_contains($response->headers->get('Content-Type', ''), 'text/html')) {

            $content = $response->getContent();

            // Only inject script if we have valid HTML content
            if ($content && is_string($content)) {
                $script = <<<HTML
<script>
(function() {
    // Override console methods immediately
    var methods = ['log', 'info', 'warn', 'debug'];
    methods.forEach(function(m) {
        console[m] = function() {};
    });
})();
</script>
HTML;

                // Inject right after <head> if it exists, otherwise at very top
                if (preg_match('/<head.*?>/i', $content)) {
                    $content = preg_replace('/(<head.*?>)/i', "$1$script", $content, 1);
                } else if (stripos($content, '<!DOCTYPE html') !== false || 
                          stripos($content, '<html') !== false) {
                    // For HTML documents without head, inject after doctype/html tag
                    if (preg_match('/<!DOCTYPE[^>]*>/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
                        $pos = $matches[0][1] + strlen($matches[0][0]);
                        $content = substr($content, 0, $pos) . $script . substr($content, $pos);
                    } else if (preg_match('/<html[^>]*>/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
                        $pos = $matches[0][1] + strlen($matches[0][0]);
                        $content = substr($content, 0, $pos) . $script . substr($content, $pos);
                    }
                }

                $response->setContent($content);
            }
        }

        return $response;
    }

    /**
     * Check if the request should skip middleware
     */
    protected function shouldSkipMiddleware(Request $request)
    {
        // Always skip for API routes
        if ($request->expectsJson() || $request->isJson()) {
            return true;
        }

        // Skip for authentication routes
        foreach ($this->except as $route) {
            if ($request->is($route)) {
                return true;
            }
        }

        // Skip for specific authentication-related route names
        $routeName = $request->route() ? $request->route()->getName() : null;
        $authRouteNames = [
            'login',
            'logout',
            'register',
            'password.request',
            'password.email',
            'password.reset',
            'password.update',
            'verification.notice',
            'verification.verify',
            'verification.send',
        ];

        if ($routeName && in_array($routeName, $authRouteNames)) {
            return true;
        }

        return false;
    }
}