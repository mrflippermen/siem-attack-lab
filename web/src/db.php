<?php
// Conexion a la base de datos del laboratorio.
// Las credenciales llegan por variables de entorno (docker-compose).
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
            // Error 500 -> aparecera en error.log y en Kibana
            http_response_code(500);
            die('DB connection error: ' . mysqli_connect_error());
        }
    }
    return $conn;
}
