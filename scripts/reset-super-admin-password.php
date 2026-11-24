<?php

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once __DIR__ . "/../app/core/Env.php";
Env::load(__DIR__ . "/../.env");
require_once __DIR__ . "/../app/core/Config.php";
require_once __DIR__ . "/../app/Helpers/Response.php";
require_once __DIR__ . "/../app/core/Database.php";

$password = $argv[1] ?? "Admin123!";

if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$db = (new Database())->connect();
$hash = password_hash($password, PASSWORD_DEFAULT);
$statement = $db->prepare(
    "UPDATE users SET password = :password WHERE email = :email AND role = 'super_admin'"
);
$statement->execute([
    ":password" => $hash,
    ":email" => "admin@printing.local",
]);

fwrite(STDOUT, "Super Admin password updated.\n");
