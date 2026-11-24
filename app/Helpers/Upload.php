<?php

class Upload
{
    private const ALLOWED_MIME = [
        "image/jpeg" => "jpg",
        "image/png" => "png",
        "image/webp" => "webp",
    ];

    /**
     * Store a business logo under public/uploads/logos and return the relative path.
     * Returns null when no file was uploaded.
     */
    public static function storeLogo($fileField = "logo")
    {
        if (
            !isset($_FILES[$fileField])
            || !is_array($_FILES[$fileField])
            || (int) ($_FILES[$fileField]["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
        ) {
            return null;
        }

        $file = $_FILES[$fileField];
        $error = (int) ($file["error"] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            Response::json([
                "success" => false,
                "message" => "Logo upload failed. Please try again.",
            ], 400);
        }

        $config = Config::get("app");
        $maxBytes = (int) $config["upload_max_bytes"];

        if ((int) $file["size"] <= 0 || (int) $file["size"] > $maxBytes) {
            Response::json([
                "success" => false,
                "message" => "Logo must be an image up to 2 MB.",
            ], 400);
        }

        $tmpPath = $file["tmp_name"];

        if (!is_uploaded_file($tmpPath)) {
            Response::json([
                "success" => false,
                "message" => "Invalid logo upload.",
            ], 400);
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpPath);

        if (!isset(self::ALLOWED_MIME[$mime])) {
            Response::json([
                "success" => false,
                "message" => "Logo must be JPG, PNG, or WebP.",
            ], 400);
        }

        $extension = self::ALLOWED_MIME[$mime];
        $original = strtolower((string) ($file["name"] ?? ""));
        $originalExt = pathinfo($original, PATHINFO_EXTENSION);

        $allowedExtensions = ["jpg", "jpeg", "png", "webp"];

        if ($originalExt === "" || !in_array($originalExt, $allowedExtensions, true)) {
            Response::json([
                "success" => false,
                "message" => "Logo file extension is not allowed.",
            ], 400);
        }

        if ($originalExt === "jpeg") {
            $originalExt = "jpg";
        }

        // Extension must agree with detected MIME family.
        if ($extension === "jpg" && !in_array($originalExt, ["jpg", "jpeg"], true)) {
            Response::json([
                "success" => false,
                "message" => "Logo file type mismatch.",
            ], 400);
        }

        if ($extension !== "jpg" && $originalExt !== $extension) {
            Response::json([
                "success" => false,
                "message" => "Logo file type mismatch.",
            ], 400);
        }

        $filename = bin2hex(random_bytes(16)) . "." . $extension;
        $relativeDir = trim($config["upload_dir"], "/");
        $absoluteDir = dirname(__DIR__, 2) . "/public/" . $relativeDir;

        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
            Response::json([
                "success" => false,
                "message" => "Could not prepare upload directory.",
            ], 500);
        }

        $absolutePath = $absoluteDir . "/" . $filename;

        if (!move_uploaded_file($tmpPath, $absolutePath)) {
            Response::json([
                "success" => false,
                "message" => "Could not save the logo file.",
            ], 500);
        }

        @chmod($absolutePath, 0644);

        return $relativeDir . "/" . $filename;
    }

    public static function deleteLogo($relativePath)
    {
        if (!is_string($relativePath) || $relativePath === "") {
            return;
        }

        $relativePath = str_replace("\\", "/", $relativePath);

        if (
            str_contains($relativePath, "..")
            || !str_starts_with($relativePath, "uploads/logos/")
        ) {
            return;
        }

        $absolutePath = dirname(__DIR__, 2) . "/public/" . $relativePath;

        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    public static function publicUrl($relativePath)
    {
        if (!is_string($relativePath) || $relativePath === "") {
            return null;
        }

        $base = rtrim(Config::get("app")["url"], "/");

        return $base . "/" . ltrim(str_replace("\\", "/", $relativePath), "/");
    }
}
