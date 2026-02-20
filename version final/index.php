<?php
/**
 * index.php
 * 
 * Controlador central del Recetario PDpug. 
 * Se encarga de la lógica de enrutamiento, procesamiento de formularios (nueva, editar, eliminar)
 * y coordinación con el motor de plantillas y la base de datos.
 * 
 * Flujo de trabajo:
 * 1. Captura parámetros p (página) y r (slug/receta) de la URL.
 * 2. Ejecuta la lógica correspondiente (SELECT, INSERT, UPDATE, DELETE).
 * 3. Renderiza la vista específica usando pdpug_render().
 * 4. Envuelve el resultado final en un layout común.
 */

declare(strict_types=1);

// Carga de dependencias: Motor de plantillas y Conexión DB
require __DIR__ . "/pdpug.php";
require __DIR__ . "/db.php";

// Captura y saneamiento básico de parámetros de ruta
$page = trim((string)($_GET["p"] ?? ""));
$slug = trim((string)($_GET["r"] ?? ""));

$pageTitle = "Recetario PDpug";
$content = "";

/* ==========================================================================
   Helpers: Funciones de utilidad para manipulación de strings y datos
   ========================================================================== */

/**
 * Convierte un texto multilínea en un array de líneas limpias.
 * Útil para procesar ingredientes y pasos desde un textarea.
 */
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

/**
 * Convierte un array de filas de base de datos en un string multilínea.
 * Realiza la operación inversa a parse_lines para mostrar datos en formularios.
 */
function join_lines(array $rows, string $key = "texto"): string {
  $out = [];
  foreach ($rows as $r) {
    if (isset($r[$key])) $out[] = (string)$r[$key];
  }
  return implode("\n", $out);
}

/**
 * Genera un slug amigable para URL a partir de un título.
 * Ejemplo: "Tortilla de Patatas" -> "tortilla-de-patatas"
 */
function slugify(string $text): string {
  $text = trim($text);
  $text = mb_strtolower($text, "UTF-8");

  // Mapa de caracteres especiales para simplificación
  $map = [
    "á"=>"a","é"=>"e","í"=>"i","ó"=>"o","ú"=>"u","ü"=>"u","ñ"=>"n",
    "à"=>"a","è"=>"e","ì"=>"i","ò"=>"o","ù"=>"u",
  ];
  $text = strtr($text, $map);

  // Reemplaza todo lo que no sea alfanumérico por guiones
  $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? "";
  $text = trim($text, '-');

  return $text !== "" ? $text : "receta";
}

/**
 * Garatiza la unicidad de un slug en la base de datos.
 * Si el slug ya existe, añade un sufijo numérico incremental (-2, -3, etc.).
 */
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

/**
 * Carga los datos básicos de una receta mediante su slug.
 */
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

/**
 * Obtiene el listado de ingredientes de una receta específica.
 */
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

/**
 * Obtiene el listado de pasos de preparación de una receta específica.
 */
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

/* ==========================================================================
   NUEVA RECETA (GET/POST)
   ========================================================================== */
if ($page === "nueva") {

  $errors = [];
  $old = [
    "titulo" => "",
    "descripcion" => "",
    "ingredientes" => "",
    "pasos" => ""
  ];

  // Procesamiento del formulario de creación
  if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $old["titulo"] = trim((string)($_POST["titulo"] ?? ""));
    $old["descripcion"] = trim((string)($_POST["descripcion"] ?? ""));
    $old["ingredientes"] = (string)($_POST["ingredientes"] ?? "");
    $old["pasos"] = (string)($_POST["pasos"] ?? "");

    // Validación básica
    if ($old["titulo"] === "") $errors[] = "El título es obligatorio.";

    $ings = parse_lines($old["ingredientes"]);
    $steps = parse_lines($old["pasos"]);

    if (count($ings) === 0) $errors[] = "Agrega al menos 1 ingrediente (uno por línea).";
    if (count($steps) === 0) $errors[] = "Agrega al menos 1 paso (uno por línea).";

    if (empty($errors)) {
      try {
        $db = db();
        $db->begin_transaction(); // Transacción atómica: o se guarda todo o nada

        // Generación de slug único
        $base = slugify($old["titulo"]);
        $newSlug = unique_slug($db, $base);

        // 1. Insertar receta principal
        $stmt = $db->prepare("INSERT INTO recetas (slug, titulo, descripcion) VALUES (?, ?, ?)");
        if (!$stmt) throw new RuntimeException("Prepare receta error: " . $db->error);

        $stmt->bind_param("sss", $newSlug, $old["titulo"], $old["descripcion"]);
        if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException("Insert receta error: " . $stmt->error); }
        $rid = (int)$stmt->insert_id;
        $stmt->close();

        // 2. Insertar ingredientes uno a uno
        $stmt = $db->prepare("INSERT INTO receta_ingredientes (receta_id, orden, texto) VALUES (?, ?, ?)");
        if (!$stmt) throw new RuntimeException("Prepare ing error: " . $db->error);

        $ord = 1;
        foreach ($ings as $txt) {
          $stmt->bind_param("iis", $rid, $ord, $txt);
          if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException("Insert ing error: " . $stmt->error); }
          $ord++;
        }
        $stmt->close();

        // 3. Insertar pasos uno a uno
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

        // Redirigir al detalle de la nueva receta
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

/* ==========================================================================
   EDITAR RECETA (GET/POST)
   ========================================================================== */
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

      // Procesamiento de la actualización
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

          // 1. Actualizar datos básicos
          $stmt = $db->prepare("UPDATE recetas SET titulo = ?, descripcion = ? WHERE id = ?");
          if (!$stmt) throw new RuntimeException("Prepare update receta error: " . $db->error);
          $stmt->bind_param("ssi", $old["titulo"], $old["descripcion"], $rid);
          if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException("Update receta error: " . $stmt->error); }
          $stmt->close();

          // 2. Repoblar ingredientes: eliminar antiguos e insertar nuevos
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

          // 3. Repoblar pasos: eliminar antiguos e insertar nuevos
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
        // Cargar datos para mostrar en el formulario de edición
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

/* ==========================================================================
   ELIMINAR RECETA (GET/POST)
   ========================================================================== */
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

      // Confirmación de eliminación vía POST
      if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $db->begin_transaction();

        // Borrado de la receta (asumiendo ON DELETE CASCADE en la BD para hijos)
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

/* ==========================================================================
   DETALLE DE RECETA
   ========================================================================== */
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

/* ==========================================================================
   LISTADO PRINCIPAL (+ Buscador + Ordenación)
   ========================================================================== */
else {
  $recetas = [];
  $errorDb = "";

  // Parámetros de búsqueda y ordenación
  $q = trim((string)($_GET["q"] ?? ""));
  $ord = trim((string)($_GET["ord"] ?? "recientes"));

  // Definición de criterios de ordenación (Whitelist para evitar inyección SQL)
  $orderSql = "id DESC";
  if ($ord === "antiguos") $orderSql = "id ASC";
  if ($ord === "az") $orderSql = "titulo ASC";
  if ($ord === "za") $orderSql = "titulo DESC";

  try {
    $db = db();

    if ($q !== "") {
      // Búsqueda por término de texto
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
      // Listado completo sin filtros
      $res = $db->query("SELECT slug, titulo, descripcion FROM recetas ORDER BY $orderSql");
      if ($res === false) throw new RuntimeException("Query error: " . $db->error);

      while ($row = $res->fetch_assoc()) $recetas[] = $row;
      $res->free();
    }

    $db->close();

  } catch (Throwable $e) {
    $errorDb = $e->getMessage();
  }

  $pageTitle = "Listado de Recetas";
  $content = pdpug_render(__DIR__ . "/views/recetas_lista.pdpug", [
    "recetas" => $recetas,
    "total" => count($recetas),
    "error_db" => $errorDb,
    "q" => $q,
    "ord" => $ord
  ]);
}

/* ==========================================================================
   RENDERIZADO FINAL (LAYOUT)
   ========================================================================== */

// Envuelve el contenido generado en la estructura base (layout.pdpug)
echo pdpug_render(__DIR__ . "/views/layout.pdpug", [
  "page_title" => $pageTitle,
  "content" => $content
]);