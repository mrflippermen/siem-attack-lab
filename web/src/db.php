<?php
function db() {
    static $conn = null;
    if ($conn === null) {
        $conn = @mysqli_connect(
            getenv('DB_HOST') ?: 'db',
            getenv('DB_USER') ?: 'appuser',
            getenv('DB_PASS') ?: 'apppass123',
            getenv('DB_NAME') ?: 'appdb'
        );
        if (!$conn) {
            http_response_code(500);
            die('DB connection error: ' . mysqli_connect_error());
        }
    }
    return $conn;
}
