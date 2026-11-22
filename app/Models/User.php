<?php

class User
{
    private $db;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->connect();
    }

    public function findByEmail($email)
    {
        $sql = "SELECT id, name, email, password, role, status
                FROM users
                WHERE email = :email
                LIMIT 1";

        $statement = $this->db->prepare($sql);
        $statement->execute([
            ":email" => $email,
        ]);

        $user = $statement->fetch();

        return $user ?: null;
    }

    public function findById($id)
    {
        $sql = "SELECT id, name, email, role, status
                FROM users
                WHERE id = :id
                LIMIT 1";

        $statement = $this->db->prepare($sql);
        $statement->execute([
            ":id" => $id,
        ]);

        $user = $statement->fetch();

        return $user ?: null;
    }

    public function superAdminExists()
    {
        $sql = "SELECT id FROM users WHERE role = 'super_admin' LIMIT 1";
        $statement = $this->db->query($sql);

        return (bool) $statement->fetch();
    }

    public function countAll()
    {
        $statement = $this->db->query("SELECT COUNT(*) FROM users");

        return (int) $statement->fetchColumn();
    }

    public function create($name, $email, $password, $role)
    {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $sql = "INSERT INTO users (name, email, password, role, status)
                VALUES (:name, :email, :password, :role, 'active')";

        $statement = $this->db->prepare($sql);

        return $statement->execute([
            ":name" => $name,
            ":email" => $email,
            ":password" => $hashedPassword,
            ":role" => $role,
        ]);
    }
}
