<?php

/**
 * Minimal .env loader. No Composer packages required.
 * Values are loaded into putenv() and $_ENV so existing getenv() calls keep working.
 */
class Env
{
    public static function load($path)
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === "" || str_starts_with($line, "#")) {
                continue;
            }

            $position = strpos($line, "=");

            if ($position === false) {
                continue;
            }

            $name = trim(substr($line, 0, $position));
            $value = trim(substr($line, $position + 1));

            if ($name === "") {
                continue;
            }

            if (
                (str_starts_with($value, "\"") && str_ends_with($value, "\""))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            putenv($name . "=" . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    public static function get($key, $default = null)
    {
        $value = getenv($key);

        if ($value === false || $value === "") {
            return $default;
        }

        return $value;
    }
}
