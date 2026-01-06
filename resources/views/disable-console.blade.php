@if(config('data-encryption.disable_console_logs', false))
<!-- ============================================ -->
<!-- CONSOLE DISABLED BY DATA-ENCRYPTION PACKAGE -->
<!-- ============================================ -->
<script>
// IMMEDIATE EXECUTION - No function wrapper delay
(function() {
    // CAPTURE console immediately
    var originalConsole = window.console || {};
    
    // OVERRIDE IMMEDIATELY
    window.console = {
        // Keep error and clear functional
        error: originalConsole.error ? originalConsole.error.bind(originalConsole) : function() {},
        clear: originalConsole.clear ? originalConsole.clear.bind(originalConsole) : function() {},
        
        // Disable all logging methods
        log: function() {},
        info: function() {},
        warn: function() {},
        debug: function() {},
        table: function() {},
        dir: function() {},
        dirxml: function() {},
        group: function() {},
        groupCollapsed: function() {},
        groupEnd: function() {},
        time: function() {},
        timeEnd: function() {},
        timeStamp: function() {},
        profile: function() {},
        profileEnd: function() {},
        count: function() {},
        trace: function() {},
        assert: function() {},
        msIsIndependentlyComposed: function() { return false; },
        select: function() {},
        markTimeline: function() {}
    };
    
    // Also override any methods that might be on the prototype
    var methods = ['log', 'info', 'warn', 'debug', 'table', 'dir', 'dirxml', 'group', 'groupCollapsed', 
                   'groupEnd', 'time', 'timeEnd', 'timeStamp', 'profile', 'profileEnd', 'count', 
                   'trace', 'assert'];
    
    methods.forEach(function(method) {
        if (typeof originalConsole[method] === 'function') {
            window.console[method] = function() {};
        }
    });
    
    // DEBUG: Add a marker to verify script ran
    try {
        // This will fail (which is good) because we overrode console.log
        console.log('If you see this, console is NOT disabled!');
    } catch(e) {}
    
    // Add visual indicator
    try {
        if (document && document.body) {
            var indicator = document.createElement('div');
            indicator.id = 'console-disabled-indicator';
            indicator.style.cssText = 'position:fixed;bottom:10px;right:10px;background:#28a745;color:white;padding:5px 10px;border-radius:5px;z-index:9999;font-size:12px;font-family:Arial,sans-serif;box-shadow:0 2px 5px rgba(0,0,0,0.2);';
            indicator.innerHTML = '✅ Console Disabled<br><small style="opacity:0.8;">By DataEncryption</small>';
            indicator.title = 'Console logs are disabled. Press Ctrl+Shift+D to restore.';
            document.body.appendChild(indicator);
            
            // Remove after 5 seconds
            setTimeout(function() {
                var el = document.getElementById('console-disabled-indicator');
                if (el && el.parentNode) {
                    el.parentNode.removeChild(el);
                }
            }, 5000);
        }
    } catch(e) {}
    
    // Restore console with Ctrl+Shift+D
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.shiftKey && e.key === 'D') {
            // Restore original console
            if (originalConsole && originalConsole !== window.console) {
                window.console = originalConsole;
                console.log('✅ Console has been restored!');
                
                // Remove indicator if it exists
                var indicator = document.getElementById('console-disabled-indicator');
                if (indicator && indicator.parentNode) {
                    indicator.parentNode.removeChild(indicator);
                }
            }
        }
    });
})();
</script>
<!-- END CONSOLE DISABLE -->
@endif