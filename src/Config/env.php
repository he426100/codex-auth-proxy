<?php

declare(strict_types=1);

if (!function_exists('env')) {
    function env(string $name, mixed $default = null): mixed
    {
        if (array_key_exists($name, $_SERVER)) {
            $value = $_SERVER[$name];
        } elseif (array_key_exists($name, $_ENV)) {
            $value = $_ENV[$name];
        } else {
            $value = getenv($name);
        }

        if ($value === false) {
            return $default;
        }
        if (!is_string($value)) {
            return $value;
        }

        $value = trim($value);

        return $value === '' ? $default : $value;
    }
}
