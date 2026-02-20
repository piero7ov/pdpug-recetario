<?php
/**
 * db.php
 * Conexión MySQL mínima (mysqli) usando el usuario creado: pdpug / pdpug
 */

declare(strict_types=1);

function db(): mysqli {
  $host = "localhost";
  $user = "pdpug";
  $pass = "pdpug";
  $name = "pdpug_recetario";

  $mysqli = new mysqli($host, $user, $pass, $name);

  if ($mysqli->connect_errno) {
    throw new RuntimeException("MySQL error: " . $mysqli->connect_error);
  }

  $mysqli->set_charset("utf8mb4");
  return $mysqli;
}