<?php

/**
 * Application settings.
 * Database credentials live in config/database.php and are never sent to the frontend.
 */
return [
    "cors_origins" => [
        "http://localhost:5173",
        "http://127.0.0.1:5173",
        "http://localhost:5174",
        "http://127.0.0.1:5174",
    ],
    // Idle timeout for an authenticated session, in seconds (2 hours).
    "session_idle_seconds" => 7200,
];
