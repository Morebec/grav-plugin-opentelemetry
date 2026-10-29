<?php

// The plugin has no Composer dependencies: its own classes load from classes/.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Grav\\Plugin\\OpenTelemetry\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/classes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
