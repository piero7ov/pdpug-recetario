<?php
/**
 * pdpug.php
 * 
 * PDpug: Un motor de plantillas minimalista inspirado en Pug (Jade) para PHP.
 * 
 * Características soportadas:
 * - Estructura basada en indentación (2 espacios).
 * - Directivas: @include, @foreach, @if/@else.
 * - Interpolación de variables: #{var} (escapado) y !{var} (sin escapar).
 * - Atributos entre paréntesis: tag(attr="val").
 * - Atajos para ID (#id) y Clases (.clase).
 * - Manejo de herencia de bloques mediante expansión de includes.
 */

declare(strict_types=1);

/**
 * Función principal para renderizar una vista de PDpug.
 * 
 * @param string $filePath Ruta absoluta al archivo .pdpug.
 * @param array $data Mapa de variables disponibles en la vista.
 * @return string HTML generado.
 */
function pdpug_render(string $filePath, array $data = []): string {
  if (!is_file($filePath)) {
    throw new RuntimeException("PDpug: no existe la vista: $filePath");
  }

  $baseDir = dirname($filePath);

  // Lectura del archivo línea a línea
  $lines = file($filePath, FILE_IGNORE_NEW_LINES);
  if ($lines === false) {
    throw new RuntimeException("PDpug: no se pudo leer: $filePath");
  }

  // 1. Fase de Pre-procesamiento: Expandir directivas @include
  $lines = pdpug_expand_includes($lines, $baseDir);

  $out = "";
  $stack = []; // Pila para rastrear etiquetas abiertas: ["tag" => string, "level" => int]
  
  // 2. Fase de Renderizado: Procesar líneas y generar HTML
  pdpug_render_lines($lines, $data, $baseDir, $stack, $out);

  // Cierre de etiquetas que hayan quedado abiertas en la pila
  pdpug_close_to_level($stack, $out, 0);
  
  return $out;
}

/**
 * Procesa recursivamente un array de líneas para transformarlas en HTML.
 */
function pdpug_render_lines(array $lines, array $data, string $baseDir, array &$stack, string &$out): void {
  $n = count($lines);
  $i = 0;

  while ($i < $n) {
    $rawLine = $lines[$i];

    // Ignorar líneas vacías
    if (trim($rawLine) === "") { $i++; continue; }

    $level = pdpug_level($rawLine);
    $line  = ltrim($rawLine);

    // Lógica de indentación: Si bajamos de nivel, cerramos las etiquetas del nivel actual o superior
    pdpug_close_to_level($stack, $out, $level);

    // Ignorar comentarios de Pug (//)
    if (str_starts_with($line, "//")) { $i++; continue; }

    // Directiva doctype
    if ($line === "doctype html") {
      $out .= "<!doctype html>\n";
      $i++;
      continue;
    }

    // Texto plano precedido por |
    if (str_starts_with($line, "|")) {
      $text = ltrim(substr($line, 1));
      $out .= pdpug_interpolate_text($text, $data) . "\n";
      $i++;
      continue;
    }

    // Directiva condicional: @if variable
    if (preg_match('/^@if\s+(\$?[a-zA-Z_][a-zA-Z0-9_.]*)\s*$/', $line, $m)) {
      $path = ltrim($m[1], '$');

      // Captura el bloque indentado perteneciente al @if
      $trueBlock = [];
      $j = $i + 1;
      while ($j < $n && pdpug_level($lines[$j]) > $level) {
        $trueBlock[] = $lines[$j];
        $j++;
      }

      // Captura el bloque @else si existe inmediatamente después
      $elseBlock = [];
      if ($j < $n && pdpug_level($lines[$j]) === $level && ltrim($lines[$j]) === "@else") {
        $k = $j + 1;
        while ($k < $n && pdpug_level($lines[$k]) > $level) {
          $elseBlock[] = $lines[$k];
          $k++;
        }
        $j = $k;
      }

      // Evaluación del valor de la variable y renderizado del bloque elegido
      $val = pdpug_get($data, $path);
      $chosen = pdpug_truthy($val) ? $trueBlock : $elseBlock;

      if (!empty($chosen)) {
        pdpug_render_lines($chosen, $data, $baseDir, $stack, $out);
        pdpug_close_to_level($stack, $out, $level + 1);
      }

      $i = $j;
      continue;
    }

    // El @else se procesa como parte de la lógica del @if, lo saltamos si aparece solo
    if ($line === "@else") { $i++; continue; }

    // Directiva de bucle: @foreach coleccion as item
    if (preg_match('/^@foreach\s+(\$?[a-zA-Z_][a-zA-Z0-9_.]*)\s+as\s+(\$?[a-zA-Z_][a-zA-Z0-9_]*)\s*$/', $line, $m)) {
      $path = ltrim($m[1], '$');
      $var  = ltrim($m[2], '$');

      $items = pdpug_get($data, $path);
      if (!is_array($items)) $items = [];

      // Captura el bloque indentado perteneciente al @foreach
      $block = [];
      $j = $i + 1;
      while ($j < $n && pdpug_level($lines[$j]) > $level) {
        $block[] = $lines[$j];
        $j++;
      }

      // Ejecución del bucle renderizando el bloque por cada elemento
      foreach ($items as $it) {
        $data2 = $data;
        $data2[$var] = is_array($it) ? $it : (array)$it;

        pdpug_render_lines($block, $data2, $baseDir, $stack, $out);
        pdpug_close_to_level($stack, $out, $level + 1);
      }

      $i = $j;
      continue;
    }

    // Elemento HTML estándar (tag, clases, ids, atributos)
    [$tag, $attrs, $inlineText] = pdpug_parse_element($line, $data);

    $out .= "<{$tag}{$attrs}>";

    // Si hay texto en la misma línea del tag, lo interpolamos y añadimos
    if ($inlineText !== "") {
      $out .= pdpug_interpolate_text($inlineText, $data);
    }

    $out .= "\n";
    // Registramos la etiqueta abierta en la pila
    $stack[] = ["tag" => $tag, "level" => $level];

    $i++;
  }
}

/**
 * Determina si un valor debe tratarse como verdadero (truthy).
 */
function pdpug_truthy($val): bool {
  if (is_array($val)) return count($val) > 0;
  if (is_bool($val)) return $val;
  if (is_int($val) || is_float($val)) return $val != 0;
  $s = trim((string)$val);
  return $s !== "" && $s !== "0";
}

/**
 * Resuelve recursivamente las directivas @include para aplanar el árbol de plantillas.
 */
function pdpug_expand_includes(array $lines, string $baseDir, int $depth = 0, array $seen = []): array {
  if ($depth > 20) throw new RuntimeException("PDpug: demasiados includes anidados.");

  $out = [];

  foreach ($lines as $rawLine) {
    $trim = ltrim($rawLine);

    // Detección de @include "archivo.pdpug"
    if (preg_match('/^@include\s+"([^"]+)"\s*$/', $trim, $m) || preg_match("/^@include\s+'([^']+)'\s*$/", $trim, $m)) {
      $indent = substr($rawLine, 0, strlen($rawLine) - strlen($trim));
      $rel = $m[1];

      $incPath = pdpug_join_path($baseDir, $rel);
      if (!is_file($incPath)) throw new RuntimeException("PDpug: include no encontrado: $incPath");

      // Prevención de bucles infinitos de include
      $real = realpath($incPath) ?: $incPath;
      if (in_array($real, $seen, true)) throw new RuntimeException("PDpug: include en bucle: $incPath");

      $incLines = file($incPath, FILE_IGNORE_NEW_LINES);
      if ($incLines === false) throw new RuntimeException("PDpug: no se pudo leer include: $incPath");

      // Llamada recursiva para expandir niveles inferiores
      $incLines = pdpug_expand_includes($incLines, dirname($incPath), $depth + 1, array_merge($seen, [$real]));

      // Añadimos las líneas inyectadas manteniendo el nivel de indentación del include original
      foreach ($incLines as $l) $out[] = $indent . $l;
      continue;
    }

    $out[] = $rawLine;
  }

  return $out;
}

/**
 * Calcula el nivel de profundidad basado en espacios (2 espacios = 1 nivel).
 */
function pdpug_level(string $rawLine): int {
  preg_match('/^( *)/', $rawLine, $m);
  return intdiv(strlen($m[1] ?? ""), 2);
}

/** 
 * Recorre la pila y cierra etiquetas HTML hasta alcanzar el nivel objetivo.
 */
function pdpug_close_to_level(array &$stack, string &$out, int $levelObjetivo): void {
  while (!empty($stack)) {
    $top = $stack[count($stack) - 1];
    if ($top["level"] >= $levelObjetivo) {
      array_pop($stack);
      $out .= "</{$top["tag"]}>\n";
      continue;
    }
    break;
  }
}

/**
 * Parsea una línea para extraer el nombre del tag, ID, clases, atributos y texto inline.
 */
function pdpug_parse_element(string $line, array $data): array {
  $tag = "div"; // Por defecto es un div
  $i = 0;
  $len = strlen($line);

  // Extracción del Nombre del Tag
  if ($len > 0 && preg_match('/[a-zA-Z]/', $line[0])) {
    $tag = "";
    while ($i < $len && preg_match('/[a-zA-Z0-9_-]/', $line[$i])) { $tag .= $line[$i]; $i++; }
    if ($tag === "") $tag = "div";
  }

  $id = "";
  $classes = [];

  // Extracción de selectores (#id y .clase)
  while ($i < $len) {
    $ch = $line[$i];

    if ($ch === "#") {
      $i++; $buf = "";
      while ($i < $len && preg_match('/[a-zA-Z0-9_-]/', $line[$i])) { $buf .= $line[$i]; $i++; }
      $id = $buf; continue;
    }

    if ($ch === ".") {
      $i++; $buf = "";
      while ($i < $len && preg_match('/[a-zA-Z0-9_-]/', $line[$i])) { $buf .= $line[$i]; $i++; }
      if ($buf !== "") $classes[] = $buf; continue;
    }

    break;
  }

  // Extracción de atributos entre paréntesis: (name="val", id="test")
  $attrsRaw = "";
  if ($i < $len && $line[$i] === "(") {
    $depth = 0; $start = $i;
    while ($i < $len) {
      if ($line[$i] === "(") $depth++;
      if ($line[$i] === ")") {
        $depth--;
        if ($depth === 0) { $i++; break; }
      }
      $i++;
    }
    $attrsRaw = substr($line, $start + 1, ($i - $start - 2));
  }

  // El resto de la línea se considera texto inline
  $inlineText = trim(substr($line, $i));

  $attrs = [];
  if ($id !== "") $attrs[] = 'id="' . htmlspecialchars($id, ENT_QUOTES, "UTF-8") . '"';
  if (!empty($classes)) $attrs[] = 'class="' . htmlspecialchars(implode(" ", $classes), ENT_QUOTES, "UTF-8") . '"';

  // Procesamiento de los atributos extraídos de los paréntesis
  if (trim($attrsRaw) !== "") {
    // Regex para separar por comas o espacios que preceden a una clave de atributo
    $parts = preg_split('/\s*,\s*|\s+(?=[a-zA-Z0-9_-]+\s*=)/', trim($attrsRaw));
    foreach ($parts as $p) {
      $p = trim($p);
      if ($p === "") continue;

      if (!str_contains($p, "=")) { // Atributos booleanos (ej. checked)
        $key = preg_replace('/[^a-zA-Z0-9_-]/', "", $p);
        if ($key !== "") $attrs[] = $key;
        continue;
      }

      [$k, $v] = array_map("trim", explode("=", $p, 2));
      $k = preg_replace('/[^a-zA-Z0-9_-]/', "", $k);

      // Limpieza de comillas en el valor
      if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
        $v = substr($v, 1, -1);
      }

      // Interpolación dinámica dentro de atributos
      $v = pdpug_interpolate_attr($v, $data);
      $v = htmlspecialchars($v, ENT_QUOTES, "UTF-8");

      if ($k !== "") $attrs[] = $k . '="' . $v . '"';
    }
  }

  $attrsStr = empty($attrs) ? "" : " " . implode(" ", $attrs);
  return [$tag, $attrsStr, $inlineText];
}

/** 
 * Maneja la interpolación de texto:
 * - #{var}: Escapa caracteres HTML para seguridad (XSS).
 * - !{var}: Muestra el contenido RAW (sin escapar).
 */
function pdpug_interpolate_text(string $text, array $data): string {
  // Interpolación RAW: !{variable}
  $text = preg_replace_callback('/!\{([a-zA-Z_][a-zA-Z0-9_.]*)\}/', function($m) use ($data){
    return (string)pdpug_get($data, $m[1]);
  }, $text);

  // Interpolación ESCAPADA: #{variable}
  $text = preg_replace_callback('/#\{([a-zA-Z_][a-zA-Z0-9_.]*)\}/', function($m) use ($data){
    return htmlspecialchars((string)pdpug_get($data, $m[1]), ENT_QUOTES, "UTF-8");
  }, $text);

  return $text;
}

/** 
 * Interpolación específica para atributos.
 * Soporta tanto #{} como !{} pero siempre devuelve el valor raw para ser procesado después.
 */
function pdpug_interpolate_attr(string $text, array $data): string {
  $text = preg_replace_callback('/[#!]\{([a-zA-Z_][a-zA-Z0-9_.]*)\}/', function($m) use ($data){
    return (string)pdpug_get($data, $m[1]);
  }, $text);

  return $text;
}

/**
 * Resuelve una ruta de variable en el array de datos (soporta anidación con puntos ej: "user.name").
 */
function pdpug_get(array $data, string $path) {
  $parts = explode(".", $path);
  $cur = $data;
  foreach ($parts as $p) {
    if (is_array($cur) && array_key_exists($p, $cur)) $cur = $cur[$p];
    else return "";
  }
  return $cur;
}

/**
 * Une rutas de archivos de forma compatible con diferentes sistemas operativos.
 */
function pdpug_join_path(string $baseDir, string $rel): string {
  if (preg_match('/^[A-Za-z]:\\\\/', $rel) || str_starts_with($rel, "/") || str_starts_with($rel, "\\")) {
    return $rel;
  }
  return rtrim($baseDir, "/\\") . DIRECTORY_SEPARATOR . str_replace(["/", "\\"], DIRECTORY_SEPARATOR, $rel);
}