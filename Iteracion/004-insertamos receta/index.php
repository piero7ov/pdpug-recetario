<?php
/**
 * - p=nueva (GET) => muestra formulario
 * - p=nueva (POST) => inserta receta + ingredientes + pasos, y redirige al detalle
 * - r=slug => detalle
 * - default => listado
 */

declare(strict_types=1);

require __DIR__ . "/pdpug.php";
require __DIR__ . "/db.php";

$page = trim((string)($_GET["p"] ?? ""));
$slug = trim((string)($_GET["r"] ?? ""));

$pageTitle = "Recetario PDpug";
$content = "";

/* Helpers */
function hline_trim(string $s): string { return trim(str_replace(["\r"], ["\n"], $s)); }

function parse_lines(string $text): array {
  $text = str_replace("\r", "", $text);
  $lines = explode("\n", $text);
  $out = [];
  foreach ($lines as $l) {
    $l = trim($l);
    if ($l !== "") $out[] = $l;
  }
  return $out;
}

function slugify(string $text): string {
  $text = trim($text);
  $text = mb_strtolower($text, "UTF-8");

  // quitar tildes
  $map = [
    "á"=>"a","é"=>"e","í"=>"i","ó"=>"o","ú"=>"u","ü"=>"u","ñ"=>"n",
    "à"=>"a","è"=>"e","ì"=>"i","ò"=>"o","ù"=>"u",
  ];
  $text = strtr($text, $map);

  // reemplazar no alfanum por guión
  $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? "";
  $text = trim($text, '-');

  return $text !== "" ? $text : "receta";
}

function unique_slug(mysqli $db, string $base): string {
  $slug = $base;
  $i = 2;

  $stmt = $db->prepare("SELECT 1 FROM recetas WHERE slug = ? LIMIT 1");
  if (!$stmt) throw new RuntimeException("Prepare slug error: " . $db->error);

  while (true) {
    $stmt->bind_param("s", $slug);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = ($res && $res->fetch_row()) ? true : false;

    if (!$exists) break;

    $slug = $base . "-" . $i;
    $i++;
    if ($i > 200) { $stmt->close(); throw new RuntimeException("No se pudo generar slug único."); }
  }

  $stmt->close();
  return $slug;
}

/* =========================
   NUEVA RECETA (GET/POST)
   ========================= */
if ($page === "nueva") {

  $errors = [];
  $old = [
    "titulo" => "",
    "descripcion" => "",
    "ingredientes" => "",
    "pasos" => ""
  ];

  if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $old["titulo"] = trim((string)($_POST["titulo"] ?? ""));
    $old["descripcion"] = trim((string)($_POST["descripcion"] ?? ""));
    $old["ingredientes"] = (string)($_POST["ingredientes"] ?? "");
    $old["pasos"] = (string)($_POST["pasos"] ?? "");

    if ($old["titulo"] === "") $errors[] = "El título es obligatorio.";

    $ings = parse_lines($old["ingredientes"]);
    $steps = parse_lines($old["pasos"]);

    if (count($ings) === 0) $errors[] = "Agrega al menos 1 ingrediente (uno por línea).";
    if (count($steps) === 0) $errors[] = "Agrega al menos 1 paso (uno por línea).";

    if (empty($errors)) {
      try {
        $db = db();
        $db->begin_transaction();

        $base = slugify($old["titulo"]);
        $newSlug = unique_slug($db, $base);

        // Insert receta
        $stmt = $db->prepare("INSERT INTO recetas (slug, titulo, descripcion) VALUES (?, ?, ?)");
        if (!$stmt) throw new RuntimeException("Prepare receta error: " . $db->error);

        $desc = $old["descripcion"] !== "" ? $old["descripcion"] : null;
        $stmt->bind_param("sss", $newSlug, $old["titulo"], $old["descripcion"]);
        $ok = $stmt->execute();
        if (!$ok) { $stmt->close(); throw new RuntimeException("Insert receta error: " . $stmt->error); }
        $rid = (int)$stmt->insert_id;
        $stmt->close();

        // Insert ingredientes
        $stmt = $db->prepare("INSERT INTO receta_ingredientes (receta_id, orden, texto) VALUES (?, ?, ?)");
        if (!$stmt) throw new RuntimeException("Prepare ing error: " . $db->error);

        $ord = 1;
        foreach ($ings as $txt) {
          $stmt->bind_param("iis", $rid, $ord, $txt);
          if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException("Insert ing error: " . $stmt->error); }
          $ord++;
        }
        $stmt->close();

        // Insert pasos
        $stmt = $db->prepare("INSERT INTO receta_pasos (receta_id, orden, texto) VALUES (?, ?, ?)");
        if (!$stmt) throw new RuntimeException("Prepare pasos error: " . $db->error);

        $ord = 1;
        foreach ($steps as $txt) {
          $stmt->bind_param("iis", $rid, $ord, $txt);
          if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException("Insert pasos error: " . $stmt->error); }
          $ord++;
        }
        $stmt->close();

        $db->commit();
        $db->close();

        // PRG: redirect al detalle
        header("Location: index.php?r=" . urlencode($newSlug));
        exit;

      } catch (Throwable $e) {
        // rollback seguro
        try { if (isset($db) && $db instanceof mysqli) $db->rollback(); } catch (Throwable $x) {}
        $errors[] = "Error al guardar: " . $e->getMessage();
      }
    }
  }

  $pageTitle = "Nueva receta";
  $content = pdpug_render(__DIR__ . "/views/receta_form.pdpug", [
    "errors" => $errors,
    "old" => $old
  ]);

}
/* =========================
   DETALLE
   ========================= */
elseif ($slug !== "") {
  try {
    $db = db();

    $stmt = $db->prepare("SELECT id, slug, titulo, descripcion FROM recetas WHERE slug = ? LIMIT 1");
    if (!$stmt) throw new RuntimeException("Prepare error: " . $db->error);

    $stmt->bind_param("s", $slug);
    $stmt->execute();
    $res = $stmt->get_result();
    $receta = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$receta) {
      $db->close();
      $pageTitle = "Receta no encontrada";
      $content = pdpug_render(__DIR__ . "/views/not_found.pdpug", ["slug" => $slug]);
    } else {
      $rid = (int)$receta["id"];

      $ingredientes = [];
      $stmt = $db->prepare("SELECT orden, texto FROM receta_ingredientes WHERE receta_id = ? ORDER BY orden ASC, id ASC");
      if (!$stmt) throw new RuntimeException("Prepare ing error: " . $db->error);
      $stmt->bind_param("i", $rid);
      $stmt->execute();
      $res = $stmt->get_result();
      if ($res) while ($row = $res->fetch_assoc()) $ingredientes[] = $row;
      $stmt->close();

      $pasos = [];
      $stmt = $db->prepare("SELECT orden, texto FROM receta_pasos WHERE receta_id = ? ORDER BY orden ASC, id ASC");
      if (!$stmt) throw new RuntimeException("Prepare pasos error: " . $db->error);
      $stmt->bind_param("i", $rid);
      $stmt->execute();
      $res = $stmt->get_result();
      if ($res) while ($row = $res->fetch_assoc()) $pasos[] = $row;
      $stmt->close();

      $db->close();

      $pageTitle = (string)$receta["titulo"];
      $content = pdpug_render(__DIR__ . "/views/receta_detalle.pdpug", [
        "receta" => $receta,
        "ingredientes" => $ingredientes,
        "pasos" => $pasos
      ]);
    }

  } catch (Throwable $e) {
    $pageTitle = "Error";
    $content = pdpug_render(__DIR__ . "/views/error.pdpug", ["error_db" => $e->getMessage()]);
  }
}
/* =========================
   LISTADO
   ========================= */
else {
  $recetas = [];
  $errorDb = "";

  try {
    $db = db();
    $res = $db->query("SELECT slug, titulo, descripcion FROM recetas ORDER BY id DESC");
    if ($res === false) throw new RuntimeException("Query error: " . $db->error);

    while ($row = $res->fetch_assoc()) $recetas[] = $row;
    $res->free();
    $db->close();

  } catch (Throwable $e) {
    $errorDb = $e->getMessage();
  }

  $pageTitle = "Listado";
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