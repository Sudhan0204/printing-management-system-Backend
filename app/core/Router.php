<?php

class Router
{
    private $routes = [];

    public function get($path, $handler)
    {
        $this->add("GET", $path, $handler);
    }

    public function post($path, $handler)
    {
        $this->add("POST", $path, $handler);
    }

    public function put($path, $handler)
    {
        $this->add("PUT", $path, $handler);
    }

    public function patch($path, $handler)
    {
        $this->add("PATCH", $path, $handler);
    }

    public function add($method, $path, $handler)
    {
        $this->routes[] = [
            "method" => strtoupper($method),
            "path" => $this->normalize($path),
            "handler" => $handler,
        ];
    }

    public function dispatch($method, $uri)
    {
        $path = $this->normalize($this->apiPath($uri));
        $method = strtoupper($method);

        foreach ($this->routes as $route) {
            $params = [];

            if ($route["method"] === $method && $this->match($route["path"], $path, $params)) {
                call_user_func($route["handler"], $params);
                return;
            }
        }

        Response::json([
            "success" => false,
            "message" => "API route not found",
        ], 404);
    }

    /**
     * Match /api/admin/clients/{id} style paths and capture params.
     */
    private function match($pattern, $path, &$params)
    {
        $paramNames = [];

        $regex = preg_replace_callback(
            "#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#",
            function ($matches) use (&$paramNames) {
                $paramNames[] = $matches[1];
                return "([^/]+)";
            },
            $pattern
        );

        $regex = "#^" . $regex . "$#";

        if (!preg_match($regex, $path, $matches)) {
            return false;
        }

        array_shift($matches);

        foreach ($paramNames as $index => $name) {
            $params[$name] = $matches[$index] ?? null;
        }

        return true;
    }

    /**
     * The app is served from a subdirectory under XAMPP.
     * Keep only the /api/... portion so routes stay stable.
     */
    private function apiPath($uri)
    {
        $path = parse_url($uri, PHP_URL_PATH);

        if (!is_string($path) || $path === "") {
            return "/";
        }

        $position = strpos($path, "/api/");

        if ($position === false) {
            return $path;
        }

        return substr($path, $position);
    }

    private function normalize($path)
    {
        $path = "/" . trim($path, "/");

        return $path === "/" ? "/" : rtrim($path, "/");
    }
}
