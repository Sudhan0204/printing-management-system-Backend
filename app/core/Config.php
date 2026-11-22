<?php

class Config
{
    public static function get($name)
    {
        static $cache = [];

        if (!isset($cache[$name])) {
            $path = __DIR__ . "/../../config/" . $name . ".php";

            if (!is_file($path)) {
                throw new RuntimeException("Missing config file: " . $name);
            }

            $cache[$name] = require $path;
        }

        return $cache[$name];
    }
}
