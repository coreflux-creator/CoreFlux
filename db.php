<?php
// Database connection via PDO
try {
    $pdo = new PDO(
        "mysql:host=127.0.0.1;dbname=grcudkpvcd;charset=utf8mb4",
        "grcudkpvcd",
        "7DgX7F4RPz",
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    // TEMP for debugging; replace with generic message after you confirm it works
    die("Database connection failed: " . $e->getMessage());
}
