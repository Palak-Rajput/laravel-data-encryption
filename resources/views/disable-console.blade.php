<!-- @if (config(key: 'data-encryption.disable_console_logs')) -->

<script>
    (function() {
        if (typeof console !== 'undefined') {
            console.log = function() {};
            console.info = function() {};
            console.warn = function() {};
            // Keep console.error so real errors still appear
        }
    })();
</script>
<!-- @endif -->