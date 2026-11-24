<?php

class Tenant
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

    public function findById($id)
    {
        $sql = "SELECT id, business_name, owner_name, email, phone, logo,
                       address, city, state, pincode, status, created_at, updated_at
                FROM tenants
                WHERE id = :id
                LIMIT 1";

        $statement = $this->db->prepare($sql);
        $statement->execute([":id" => $id]);
        $tenant = $statement->fetch();

        return $tenant ?: null;
    }

    public function create(array $data)
    {
        $sql = "INSERT INTO tenants (
                    business_name, owner_name, email, phone, logo,
                    address, city, state, pincode, status
                ) VALUES (
                    :business_name, :owner_name, :email, :phone, :logo,
                    :address, :city, :state, :pincode, :status
                )";

        $statement = $this->db->prepare($sql);
        $statement->execute([
            ":business_name" => $data["business_name"],
            ":owner_name" => $data["owner_name"],
            ":email" => $data["email"],
            ":phone" => $data["phone"],
            ":logo" => $data["logo"] ?? null,
            ":address" => $data["address"],
            ":city" => $data["city"],
            ":state" => $data["state"],
            ":pincode" => $data["pincode"],
            ":status" => $data["status"] ?? "active",
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update($id, array $data)
    {
        $sql = "UPDATE tenants SET
                    business_name = :business_name,
                    owner_name = :owner_name,
                    email = :email,
                    phone = :phone,
                    address = :address,
                    city = :city,
                    state = :state,
                    pincode = :pincode,
                    status = :status
                WHERE id = :id";

        $statement = $this->db->prepare($sql);

        return $statement->execute([
            ":business_name" => $data["business_name"],
            ":owner_name" => $data["owner_name"],
            ":email" => $data["email"],
            ":phone" => $data["phone"],
            ":address" => $data["address"],
            ":city" => $data["city"],
            ":state" => $data["state"],
            ":pincode" => $data["pincode"],
            ":status" => $data["status"],
            ":id" => $id,
        ]);
    }

    public function updateLogo($id, $logoPath)
    {
        $sql = "UPDATE tenants SET logo = :logo WHERE id = :id";
        $statement = $this->db->prepare($sql);

        return $statement->execute([
            ":logo" => $logoPath,
            ":id" => $id,
        ]);
    }

    public function updateStatus($id, $status)
    {
        $sql = "UPDATE tenants SET status = :status WHERE id = :id";
        $statement = $this->db->prepare($sql);

        return $statement->execute([
            ":status" => $status,
            ":id" => $id,
        ]);
    }

    public function listFiltered($search, $status, $page, $perPage)
    {
        $where = [];
        $params = [];

        if ($search !== "") {
            $where[] = "(business_name LIKE :search
                        OR owner_name LIKE :search
                        OR email LIKE :search
                        OR phone LIKE :search)";
            $params[":search"] = "%" . $search . "%";
        }

        if ($status === "active" || $status === "suspended") {
            $where[] = "status = :status";
            $params[":status"] = $status;
        }

        $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

        $countSql = "SELECT COUNT(*) FROM tenants {$whereSql}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;

        $sql = "SELECT id, business_name, owner_name, email, phone, logo,
                       address, city, state, pincode, status, created_at, updated_at
                FROM tenants
                {$whereSql}
                ORDER BY created_at DESC, id DESC
                LIMIT {$perPage} OFFSET {$offset}";

        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return [
            "items" => $statement->fetchAll(),
            "total" => $total,
            "page" => $page,
            "per_page" => $perPage,
            "total_pages" => max(1, (int) ceil($total / $perPage)),
        ];
    }

    public function countAll()
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
    }

    public function countByStatus($status)
    {
        $statement = $this->db->prepare("SELECT COUNT(*) FROM tenants WHERE status = :status");
        $statement->execute([":status" => $status]);

        return (int) $statement->fetchColumn();
    }
}
