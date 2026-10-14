<?php
if (!function_exists('log_security_event')) {
    function log_security_event($message) {
        $log = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message);
        file_put_contents(__DIR__ . '/security.log', $log, FILE_APPEND);
    }
} 