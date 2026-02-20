<?php
/**
 * - p=nueva (GET/POST) => crear receta
 * - p=editar&r=slug (GET/POST) => editar receta + ingredientes + pasos
 * - p=eliminar&r=slug (GET) => confirmación
 * - p=eliminar&r=slug (POST) => eliminar (cascade)
 * - r=slug => detalle
 * - default => listado + buscador + orden (GET: q, ord)
 */

declare(strict_types=1);

require __DIR__ . "/pdpug.php";
require __DIR__ . "/db.php";

$page = trim((string)($_GET["p"] ?? ""));
$slug = trim((string)($_GET["r"] ?? ""));

$pageTitle = "Recetario PDpug";
$content = "";

/* =========================
   Helpers
   ========================= */
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

function join_lines(array $rows, string $key = "texto"): string {
  $out = [];
  foreach ($rows as $r) {
    if (isset($r[$key])) $out[] = (string)$r[$key];
  }
  return implode("\n", $out);
}

function slugify(string $text): string {
  $text = trim($text);
  $text = mb_strtolower($text, "UTF-8");

  $map = [
    "á"=>"a","é"=>"e","í"=>"i","ó"=>"o","ú"=>"u","ü"=>"u","ñ"=>"n",
    "à"=>"a","è"=>"e","ì"=>"i","ò"=>"o","ù"=>"u",
  ];
  $text = strtr($text, $map);

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

function load_receta_by_slug(mysqli $db, string $slug): ?array {
  $stmt = $db->prepare("SELECT id, slug, titulo, descripcion FROM recetas WHERE slug = ? LIMIT 1");
  if (!$stmt) throw new RuntimeException("Prepare receta error: " . $db->error);
  $stmt->bind_param("s", $slug);
  $stmt->execute();
  $res = $stmt->get_result();
  $receta = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  return $receta ?: null;
}

function load_ingredientes(mysqli $db, int $rid): array {
  $rows = [];
  $stmt = $db->prepare("SELECT orden, texto FROM receta_ingredientes WHERE receta_id = ? ORDER BY orden ASC, id ASC");
  if (!$stmt) throw new RuntimeException("Prepare ing error: " . $db->error);
  $stmt->bind_param("i", $rid);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($res) while ($row = $res->fetch_assoc()) $rows[] = $row;
  $stmt->close();
  return $rows;
}

function load_pasos(mysqli $db, int $rid): array {
  $rows = [];
  $stmt = $db->prepare("SELECT orden, texto FROM receta_pasos WHERE receta_id = ? ORDER BY orden ASC, id ASC");
  if (!$stmt) throw new RuntimeException("Prepare pasos error: " . $db->error);
  $stmt->bind_param("i", $rid);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($res) while ($row = $res->fetch_assoc()) $rows[] = $row;
  $stmt->close();
  return $rows;
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

        $stmt = $db->prepare("INSERT INTO recetas (slug, titulo, descripcion) VALUES (?, ?, ?)");
        if (!$stmt) throw new RuntimeException("Prepare receta error: " . $db->error);

        $stmt->bind_param("sss", $newSlug, $old["titulo"], $old["descripcion"]);
        if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException("Insert receta error: " . $stmt->error); }
        $rid = (int)$stmt->insert_id;
        $stmt->close();

        $stmt = $db->prepare("INSERT INTO receta_ingredientes (receta_id, orden, texto) VALUES (?, ?, ?)");
        if (!$stmt) throw new RuntimeException("Prepare ing error: " . $db->error);

        $ord = 1;
        foreach ($ings as $txt) {
          $stmt->bind_param("iis", $rid, $ord, $txt);
          if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException("Insert ing error: " . $stmt->error); }
          $ord++;
        }
        $stmt->close();

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

        header("Location: index.php?r=" . urlencode($newSlug));
        exit;

      } catch (Throwable $e) {
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
   EDITAR RECETA (GET/POST)
   ========================= */
elseif ($page === "editar" && $slug !== "") {

  $errors = [];
  $old = [
    "titulo" => "",
    "descripcion" => "",
    "ingredientes" => "",
    "pasos" => ""
  ];

  try {
    $db = db();

    $receta = load_receta_by_slug($db, $slug);
    if (!$receta) {
      $db->close();
      $pageTitle = "Receta no encontrada";
      $content = pdpug_render(__DIR__ . "/views/not_found.pdpug", ["slug" => $slug]);
    } else {
      $rid = (int)$receta["id"];

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
          $db->begin_transaction();

          $stmt = $db->prepare("UPDATE recetas SET titulo = ?, descripcion = ? WHERE id = ?");
          if (!$stmt) throw new RuntimeException("Prepare update receta error: " . $db->error);
          $stmt->bind_param("ssi", $old["titulo"], $old["descripcion"], $rid);
          if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException("Update receta error: " . $stmt->error); }
          $stmt->close();

          $stmt = $db->prepare("DELETE FROM receta_ingredientes WHERE receta_id = ?");
          if (!$stmt) throw new RuntimeException("Prepare delete ing error: " . $db->error);
          $stmt->bind_param("i", $rid);
          if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException("Delete ing error: " . $stmt->error); }
          $stmt->close();

          $stmt = $db->prepare("INSERT INTO receta_ingredientes (receta_id, orden, texto) VALUES (?, ?, ?)");
          if (!$stmt) throw new RuntimeException("Prepare insert ing error: " . $db->error);

          $ord = 1;
          foreach ($ings as $txt) {
            $stmt->bind_param("iis", $rid, $ord, $txt);
            if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException("Insert ing error: " . $stmt->error); }
            $ord++;
          }
          $stmt->close();

          $stmt = $db->prepare("DELETE FROM receta_pasos WHERE receta_id = ?");
          if (!$stmt) throw new RuntimeException("Prepare delete pasos error: " . $db->error);
          $stmt->bind_param("i", $rid);
          if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException("Delete pasos error: " . $stmt->error); }
          $stmt->close();

          $stmt = $db->prepare("INSERT INTO receta_pasos (receta_id, orden, texto) VALUES (?, ?, ?)");
          if (!$stmt) throw new RuntimeException("Prepare insert pasos error: " . $db->error);

          $ord = 1;
          foreach ($steps as $txt) {
            $stmt->bind_param("iis", $rid, $ord, $txt);
            if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException("Insert pasos error: " . $stmt->error); }
            $ord++;
          }
          $stmt->close();

          $db->commit();
          $db->close();

          header("Location: index.php?r=" . urlencode($slug));
          exit;
        }

      } else {
        $ingsRows = load_ingredientes($db, $rid);
        $pasosRows = load_pasos($db, $rid);

        $old["titulo"] = (string)$receta["titulo"];
        $old["descripcion"] = (string)$receta["descripcion"];
        $old["ingredientes"] = join_lines($ingsRows);
        $old["pasos"] = join_lines($pasosRows);
      }

      $db->close();

      $pageTitle = "Editar: " . (string)$receta["titulo"];
      $content = pdpug_render(__DIR__ . "/views/receta_edit.pdpug", [
        "slug" => $slug,
        "errors" => $errors,
        "old" => $old
      ]);
    }

  } catch (Throwable $e) {
    try { if (isset($db) && $db instanceof mysqli) $db->rollback(); } catch (Throwable $x) {}
    $pageTitle = "Error";
    $content = pdpug_render(__DIR__ . "/views/error.pdpug", ["error_db" => $e->getMessage()]);
  }

}

/* =========================
   ELIMINAR RECETA (GET/POST)
   ========================= */
elseif ($page === "eliminar" && $slug !== "") {

  try {
    $db = db();

    $receta = load_receta_by_slug($db, $slug);
    if (!$receta) {
      $db->close();
      $pageTitle = "Receta no encontrada";
      $content = pdpug_render(__DIR__ . "/views/not_found.pdpug", ["slug" => $slug]);
    } else {
      $rid = (int)$receta["id"];

      if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $db->begin_transaction();

        $stmt = $db->prepare("DELETE FROM recetas WHERE id = ? LIMIT 1");
        if (!$stmt) throw new RuntimeException("Prepare delete receta error: " . $db->error);
        $stmt->bind_param("i", $rid);
        if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException("Delete receta error: " . $stmt->error); }
        $stmt->close();

        $db->commit();
        $db->close();

        header("Location: index.php");
        exit;
      }

      $db->close();
      $pageTitle = "Eliminar receta";
      $content = pdpug_render(__DIR__ . "/views/receta_delete.pdpug", [
        "receta" => $receta
      ]);
    }

  } catch (Throwable $e) {
    try { if (isset($db) && $db instanceof mysqli) $db->rollback(); } catch (Throwable $x) {}
    $pageTitle = "Error";
    $content = pdpug_render(__DIR__ . "/views/error.pdpug", ["error_db" => $e->getMessage()]);
  }

}

/* =========================
   DETALLE
   ========================= */
elseif ($slug !== "") {
  try {
    $db = db();

    $receta = load_receta_by_slug($db, $slug);
    if (!$receta) {
      $db->close();
      $pageTitle = "Receta no encontrada";
      $content = pdpug_render(__DIR__ . "/views/not_found.pdpug", ["slug" => $slug]);
    } else {
      $rid = (int)$receta["id"];
      $ingredientes = load_ingredientes($db, $rid);
      $pasos = load_pasos($db, $rid);
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
   LISTADO + BUSCADOR + ORDEN
   ========================= */
else {
  $recetas = [];
  $errorDb = "";

  $q = trim((string)($_GET["q"] ?? ""));
  $ord = trim((string)($_GET["ord"] ?? "recientes"));

  // Whitelist orden
  $orderSql = "id DESC";
  if ($ord === "antiguos") $orderSql = "id ASC";
  if ($ord === "az") $orderSql = "titulo ASC";
  if ($ord === "za") $orderSql = "titulo DESC";

  try {
    $db = db();

    if ($q !== "") {
      $like = "%" . $q . "%";
      $stmt = $db->prepare("
        SELECT slug, titulo, descripcion
        FROM recetas
        WHERE titulo LIKE ? OR descripcion LIKE ?
        ORDER BY $orderSql
      ");
      if (!$stmt) throw new RuntimeException("Prepare search error: " . $db->error);

      $stmt->bind_param("ss", $like, $like);
      $stmt->execute();
      $res = $stmt->get_result();

      if ($res) {
        while ($row = $res->fetch_assoc()) $recetas[] = $row;
      }

      $stmt->close();
    } else {
      $res = $db->query("SELECT slug, titulo, descripcion FROM recetas ORDER BY $orderSql");
      if ($res === false) throw new RuntimeException("Query error: " . $db->error);

      while ($row = $res->fetch_assoc()) $recetas[] = $row;
      $res->free();
    }

    $db->close();

  } catch (Throwable $e) {
    $errorDb = $e->getMessage();
  }

  $pageTitle = "Listado";
  $content = pdpug_render(__DIR__ . "/views/recetas_lista.pdpug", [
    "recetas" => $recetas,
    "total" => count($recetas),
    "error_db" => $errorDb,
    "q" => $q,
    "ord" => $ord
  ]);
}

/* =========================
   LAYOUT
   ========================= */
echo pdpug_render(__DIR__ . "/views/layout.pdpug", [
  "page_title" => $pageTitle,
  "content" => $content
]);