<?php

class User
{
    private $db;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->connect();
    }

    public function getPdo()
    {
        return $this->db;
    }

    public function findByEmail($email)
    {
        $sql = "SELECT id, tenant_id, name, email, password, role, status
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
        $sql = "SELECT id, tenant_id, name, email, role, status
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

    public function findOwnerByTenantId($tenantId)
    {
        $sql = "SELECT id, tenant_id, name, email, role, status
                FROM users
                WHERE tenant_id = :tenant_id
                  AND role = 'business_owner'
                ORDER BY id ASC
                LIMIT 1";

        $statement = $this->db->prepare($sql);
        $statement->execute([":tenant_id" => $tenantId]);
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

    public function countTenantUsers()
    {
        $statement = $this->db->query(
            "SELECT COUNT(*) FROM users WHERE tenant_id IS NOT NULL AND role <> 'super_admin'"
        );

        return (int) $statement->fetchColumn();
    }

    public function emailExists($email, $excludeUserId = null)
    {
        if ($excludeUserId) {
            $sql = "SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1";
            $statement = $this->db->prepare($sql);
            $statement->execute([
                ":email" => $email,
                ":id" => $excludeUserId,
            ]);
        } else {
            $sql = "SELECT id FROM users WHERE email = :email LIMIT 1";
            $statement = $this->db->prepare($sql);
            $statement->execute([":email" => $email]);
        }

        return (bool) $statement->fetch();
    }

    public function create($name, $email, $password, $role, $tenantId = null, $status = "active")
    {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $sql = "INSERT INTO users (tenant_id, name, email, password, role, status)
                VALUES (:tenant_id, :name, :email, :password, :role, :status)";

        $statement = $this->db->prepare($sql);

        return $statement->execute([
            ":tenant_id" => $tenantId,
            ":name" => $name,
            ":email" => $email,
            ":password" => $hashedPassword,
            ":role" => $role,
            ":status" => $status,
        ]);
    }

    public function updateProfile($id, $name, $email)
    {
        $sql = "UPDATE users SET name = :name, email = :email WHERE id = :id";
        $statement = $this->db->prepare($sql);

        return $statement->execute([
            ":name" => $name,
            ":email" => $email,
            ":id" => $id,
        ]);
    }

    public function updatePassword($id, $password)
    {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $sql = "UPDATE users SET password = :password WHERE id = :id";
        $statement = $this->db->prepare($sql);

        return $statement->execute([
            ":password" => $hashedPassword,
            ":id" => $id,
        ]);
    }

    public function lastInsertId()
    {
        return (int) $this->db->lastInsertId();
    }
}
