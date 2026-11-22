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
            if ($route["method"] === $method && $route["path"] === $path) {
                call_user_func($route["handler"]);
                return;
            }
        }

        Response::json([
            "success" => false,
            "message" => "API route not found",
        ], 404);
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
