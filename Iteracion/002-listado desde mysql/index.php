<?php
/**
 * index.php
 * - Carga recetas desde MySQL
 * - Renderiza:
 *    1) views/recetas_lista.pdpug (contenido)
 *    2) views/layout.pdpug (layout) insertando !{content}
 */

declare(strict_types=1);

require __DIR__ . "/pdpug.php";
require __DIR__ . "/db.php";

/* 1) Traer recetas */
$recetas = [];
$errorDb = "";

try {
  $mysqli = db();

  $sql = "SELECT slug, titulo, descripcion FROM recetas ORDER BY id DESC";
  $res = $mysqli->query($sql);

  if ($res === false) {
    throw new RuntimeException("Query error: " . $mysqli->error);
  }

  while ($row = $res->fetch_assoc()) {
    $recetas[] = $row;
  }

  $res->free();
  $mysqli->close();

} catch (Throwable $e) {
  $errorDb = $e->getMessage();
}

/* 2) Render del contenido (lista) */
$content = pdpug_render(__DIR__ . "/views/recetas_lista.pdpug", [
  "recetas" => $recetas,
  "error_db" => $errorDb,
]);

/* 3) Render del layout */
echo pdpug_render(__DIR__ . "/views/layout.pdpug", [
  "page_title" => "Recetario PDpug",
  "content" => $content,
]);