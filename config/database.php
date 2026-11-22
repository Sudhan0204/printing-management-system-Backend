<?php

/**
 * PDO connection settings for the local XAMPP MySQL server.
 * Override any value with an environment variable (DB_HOST, DB_NAME, DB_USER, DB_PASS)
 * when this project is deployed. Do not put these values in frontend code.
 */
return [
    "host" => getenv("DB_HOST") ?: "localhost",
    "database" => getenv("DB_NAME") ?: "printing_management",
    "username" => getenv("DB_USER") ?: "root",
    "password" => getenv("DB_PASS") !== false ? getenv("DB_PASS") : "",
    "charset" => "utf8mb4",
];
