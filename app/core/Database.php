<?php

class Database
{
    private static $shared = null;
    private $connection = null;

    public function connect()
    {
        if (self::$shared instanceof PDO) {
            $this->connection = self::$shared;
            return $this->connection;
        }

        if ($this->connection instanceof PDO) {
            return $this->connection;
        }

        $config = Config::get("database");

        $dsn = sprintf(
            "mysql:host=%s;dbname=%s;charset=%s",
            $config["host"],
            $config["database"],
            $config["charset"]
        );

        try {
            $this->connection = new PDO(
                $dsn,
                $config["username"],
                $config["password"],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            self::$shared = $this->connection;
        } catch (PDOException $exception) {
            if (PHP_SAPI === "cli") {
                throw $exception;
            }

            Response::json([
                "success" => false,
                "message" => "Database connection failed",
            ], 500);
        }

        return $this->connection;
    }
}
