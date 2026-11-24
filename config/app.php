<?php

/**
 * Application settings loaded from backend/.env where possible.
 */
$frontendUrl = rtrim(Env::get("FRONTEND_URL", "http://localhost:5173"), "/");

return [
    "env" => Env::get("APP_ENV", "development"),
    "url" => rtrim(Env::get("APP_URL", "http://localhost/printing-management-system/backend/public"), "/"),
    "frontend_url" => $frontendUrl,
    "cors_origins" => array_values(array_unique([
        $frontendUrl,
        "http://localhost:5173",
        "http://127.0.0.1:5173",
        "http://localhost:5174",
        "http://127.0.0.1:5174",
    ])),
    "session_idle_seconds" => (int) Env::get("SESSION_IDLE_SECONDS", "7200"),
    "upload_max_bytes" => (int) Env::get("UPLOAD_MAX_BYTES", "2097152"),
    "upload_dir" => Env::get("UPLOAD_DIR", "uploads/logos"),
];
