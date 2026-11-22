<?php

class Session
{
    public static function start()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $config = Config::get("app");
        $idleSeconds = (int) $config["session_idle_seconds"];
        $secure = self::isHttps();

        ini_set("session.use_strict_mode", "1");
        ini_set("session.use_only_cookies", "1");
        ini_set("session.cookie_httponly", "1");
        ini_set("session.cookie_samesite", "Lax");
        ini_set("session.gc_maxlifetime", (string) $idleSeconds);

        if ($secure) {
            ini_set("session.cookie_secure", "1");
        }

        session_set_cookie_params([
            "lifetime" => 0,
            "path" => self::cookiePath(),
            "domain" => "",
            "secure" => $secure,
            "httponly" => true,
            "samesite" => "Lax",
        ]);

        session_start();
        self::enforceIdleTimeout($idleSeconds);

        if (empty($_SESSION["csrf_token"])) {
            $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
        }
    }

    public static function regenerate()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::start();
        }

        session_regenerate_id(true);
    }

    public static function destroy()
    {
        $_SESSION = [];

        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();

            setcookie(session_name(), "", [
                "expires" => time() - 42000,
                "path" => $params["path"],
                "domain" => $params["domain"],
                "secure" => $params["secure"],
                "httponly" => $params["httponly"],
                "samesite" => $params["samesite"] ?? "Lax",
            ]);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function isHttps()
    {
        if (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") {
            return true;
        }

        if (isset($_SERVER["SERVER_PORT"]) && (int) $_SERVER["SERVER_PORT"] === 443) {
            return true;
        }

        return isset($_SERVER["HTTP_X_FORWARDED_PROTO"])
            && $_SERVER["HTTP_X_FORWARDED_PROTO"] === "https";
    }

    private static function enforceIdleTimeout($idleSeconds)
    {
        $now = time();

        if (
            isset($_SESSION["last_activity"])
            && ($now - (int) $_SESSION["last_activity"]) > $idleSeconds
        ) {
            self::destroy();
            session_start();
            $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
        }

        $_SESSION["last_activity"] = $now;
    }

    private static function cookiePath()
    {
        $directory = str_replace("\\", "/", dirname($_SERVER["SCRIPT_NAME"] ?? ""));

        if ($directory === "" || $directory === "." || $directory === "/") {
            return "/";
        }

        return rtrim($directory, "/");
    }
}
