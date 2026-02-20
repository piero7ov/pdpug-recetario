<?php
/**
 * index.php (Iteración 0)
 * Controlador mínimo:
 * - prueba conexión MySQL
 * - renderiza una vista PDpug (views/index.pdpug)
 */

declare(strict_types=1);

require __DIR__ . "/pdpug.php";
require __DIR__ . "/db.php";

$title = "PDpug Recetario";
$msg   = "Hola gente: PDpug está renderizando HTML desde un .pdpug";

$dbStatus = "NO conectado";
try {
  $mysqli = db();
  $dbStatus = "Conectado OK (" . $mysqli->host_info . ")";
  $mysqli->close();
} catch (Throwable $e) {
  $dbStatus = "Error: " . $e->getMessage();
}

echo pdpug_render(__DIR__ . "/views/index.pdpug", [
  "title" => $title,
  "msg" => $msg,
  "db_status" => $dbStatus,
]);