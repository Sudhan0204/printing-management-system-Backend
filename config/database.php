<?php

/**
 * PDO connection settings.
 * Values come from backend/.env — never hardcode credentials here for production.
 */
return [
    "host" => Env::get("DB_HOST", "localhost"),
    "database" => Env::get("DB_NAME", "printing_management"),
    "username" => Env::get("DB_USER", "root"),
    "password" => Env::get("DB_PASSWORD", Env::get("DB_PASS", "")),
    "charset" => "utf8mb4",
];
