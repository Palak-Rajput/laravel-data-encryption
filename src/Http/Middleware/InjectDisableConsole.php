<?php

namespace PalakRajput\DataEncryption\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class InjectDisableConsole
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (config('data-encryption.disable_console_logs') &&
            str_contains($response->headers->get('Content-Type'), 'text/html')) {

            $content = $response->getContent();

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
            } else {
                $content = $script . $content;
            }

            $response->setContent($content);
        }

        return $response;
    }
}
