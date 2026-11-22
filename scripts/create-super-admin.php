<?php

/**
 * One-time Super Admin setup.
 *
 * This script runs only from the command line. It is not a web registration endpoint.
 * After the first Super Admin exists, it refuses to create another one.
 *
 * Example:
 *   C:\xampp\php\php.exe scripts\create-super-admin.php --name="Super Admin" --email="admin@printing.local" --password="your-password"
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once __DIR__ . "/../app/core/Config.php";
require_once __DIR__ . "/../app/Helpers/Response.php";
require_once __DIR__ . "/../app/core/Database.php";
require_once __DIR__ . "/../app/Models/User.php";

$options = getopt("", ["name:", "email:", "password:"]);

$name = trim($options["name"] ?? "");
$email = strtolower(trim($options["email"] ?? ""));
$password = $options["password"] ?? "";

if ($name === "" || $email === "" || $password === "") {
    fwrite(STDERR, "Usage: php create-super-admin.php --name=\"Full Name\" --email=\"admin@example.com\" --password=\"secret\"\n");
    exit(1);
}

if (strlen($name) > 100) {
    fwrite(STDERR, "Name must be 100 characters or fewer.\n");
    exit(1);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Enter a valid email address.\n");
    exit(1);
}

if (strlen($password) < 8 || strlen($password) > 128) {
    fwrite(STDERR, "Password must be between 8 and 128 characters.\n");
    exit(1);
}

try {
    $users = new User();

    if ($users->superAdminExists()) {
        fwrite(STDERR, "A Super Admin account already exists. Setup is closed.\n");
        exit(1);
    }

    if ($users->findByEmail($email)) {
        fwrite(STDERR, "That email is already registered.\n");
        exit(1);
    }

    $users->create($name, $email, $password, "super_admin");
} catch (PDOException $exception) {
    fwrite(STDERR, "Could not create the Super Admin account. Check that MySQL is running and the schema has been imported.\n");
    exit(1);
}

fwrite(STDOUT, "Super Admin account created. This setup command will now refuse further Super Admin accounts.\n");
exit(0);
