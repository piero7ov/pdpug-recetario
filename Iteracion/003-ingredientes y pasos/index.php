<?php
/**
 * index.php (Iteración 2)
 * - Si NO hay ?r=slug => listado desde MySQL
 * - Si hay ?r=slug => detalle + ingredientes + pasos
 */

declare(strict_types=1);

require __DIR__ . "/pdpug.php";
require __DIR__ . "/db.php";

$slug = trim((string)($_GET["r"] ?? ""));

$errorDb = "";
$pageTitle = "Recetario PDpug";
$content = "";

/* =========================
   DETALLE
   ========================= */
if ($slug !== "") {
  try {
    $mysqli = db();

    // Receta
    $stmt = $mysqli->prepare("SELECT id, slug, titulo, descripcion FROM recetas WHERE slug = ? LIMIT 1");
    if (!$stmt) throw new RuntimeException("Prepare error: " . $mysqli->error);

    $stmt->bind_param("s", $slug);
    $stmt->execute();

    $res = $stmt->get_result();
    $receta = $res ? $res->fetch_assoc() : null;

    $stmt->close();

    if (!$receta) {
      $mysqli->close();
      $pageTitle = "Receta no encontrada";
      $content = pdpug_render(__DIR__ . "/views/not_found.pdpug", [
        "slug" => $slug
      ]);
    } else {
      $rid = (int)$receta["id"];

      // Ingredientes
      $ingredientes = [];
      $stmt = $mysqli->prepare("SELECT orden, texto FROM receta_ingredientes WHERE receta_id = ? ORDER BY orden ASC, id ASC");
      if (!$stmt) throw new RuntimeException("Prepare ing error: " . $mysqli->error);
      $stmt->bind_param("i", $rid);
      $stmt->execute();
      $res = $stmt->get_result();
      if ($res) {
        while ($row = $res->fetch_assoc()) $ingredientes[] = $row;
      }
      $stmt->close();

      // Pasos
      $pasos = [];
      $stmt = $mysqli->prepare("SELECT orden, texto FROM receta_pasos WHERE receta_id = ? ORDER BY orden ASC, id ASC");
      if (!$stmt) throw new RuntimeException("Prepare pasos error: " . $mysqli->error);
      $stmt->bind_param("i", $rid);
      $stmt->execute();
      $res = $stmt->get_result();
      if ($res) {
        while ($row = $res->fetch_assoc()) $pasos[] = $row;
      }
      $stmt->close();

      $mysqli->close();

      $pageTitle = (string)$receta["titulo"];

      $content = pdpug_render(__DIR__ . "/views/receta_detalle.pdpug", [
        "receta" => $receta,
        "ingredientes" => $ingredientes,
        "pasos" => $pasos
      ]);
    }

  } catch (Throwable $e) {
    $errorDb = $e->getMessage();
    $pageTitle = "Error";
    $content = pdpug_render(__DIR__ . "/views/error.pdpug", [
      "error_db" => $errorDb
    ]);
  }

/* =========================
   LISTADO
   ========================= */
} else {
  $recetas = [];

  try {
    $mysqli = db();

    $sql = "SELECT slug, titulo, descripcion FROM recetas ORDER BY id DESC";
    $res = $mysqli->query($sql);
    if ($res === false) throw new RuntimeException("Query error: " . $mysqli->error);

    while ($row = $res->fetch_assoc()) $recetas[] = $row;

    $res->free();
    $mysqli->close();

  } catch (Throwable $e) {
    $errorDb = $e->getMessage();
  }

  $content = pdpug_render(__DIR__ . "/views/recetas_lista.pdpug", [
    "recetas" => $recetas,
    "total" => count($recetas),
    "error_db" => $errorDb
  ]);
}

/* =========================
   LAYOUT
   ========================= */
echo pdpug_render(__DIR__ . "/views/layout.pdpug", [
  "page_title" => $pageTitle,
  "content" => $content
]);