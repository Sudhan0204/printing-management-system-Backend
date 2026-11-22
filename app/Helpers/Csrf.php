<?php

class Csrf
{
    public static function token()
    {
        if (empty($_SESSION["csrf_token"])) {
            $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
        }

        return $_SESSION["csrf_token"];
    }

    public static function rotate()
    {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

        return $_SESSION["csrf_token"];
    }

    public static function validateRequest()
    {
        $supplied = $_SERVER["HTTP_X_CSRF_TOKEN"] ?? "";
        $expected = $_SESSION["csrf_token"] ?? "";

        if (
            !is_string($supplied)
            || $supplied === ""
            || !is_string($expected)
            || $expected === ""
            || !hash_equals($expected, $supplied)
        ) {
            Response::json([
                "success" => false,
                "message" => "Invalid or missing CSRF token. Refresh the page and try again.",
            ], 403);
        }
    }
}
