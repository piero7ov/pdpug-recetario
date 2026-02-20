<?php
/**
 * pdpug.php (PDpug v0)
 * Motor mínimo tipo pug:
 * - Indentación (2 espacios) define anidación
 * - Soporta:
 *   - doctype html
 *   - tags + .clases + #id
 *   - atributos simples (href="...", class="...")
 *   - texto inline
 *   - interpolación:
 *       #{var}  -> escapado HTML
 *       !{var}  -> sin escape
 */

declare(strict_types=1);

function pdpug_render(string $filePath, array $data = []): string {
  if (!is_file($filePath)) {
    throw new RuntimeException("PDpug: no existe la vista: $filePath");
  }

  $lines = file($filePath, FILE_IGNORE_NEW_LINES);
  if ($lines === false) {
    throw new RuntimeException("PDpug: no se pudo leer: $filePath");
  }

  $out = "";
  $stack = []; // tags abiertos

  foreach ($lines as $rawLine) {
    // Mantener línea original para indentación
    if (trim($rawLine) === "") continue;

    // Detecta nivel por 2 espacios
    preg_match('/^( *)/', $rawLine, $m);
    $spaces = strlen($m[1] ?? "");
    $level = intdiv($spaces, 2);

    $line = ltrim($rawLine);

    // Cierra tags si bajamos de nivel
    while (count($stack) > $level) {
      $tag = array_pop($stack);
      $out .= "</{$tag}>\n";
    }

    // Comentarios tipo // ...
    if (str_starts_with($line, "//")) {
      continue;
    }

    // Doctype
    if ($line === "doctype html") {
      $out .= "<!doctype html>\n";
      continue;
    }

    // Texto crudo con |
    if (str_starts_with($line, "|")) {
      $text = ltrim(substr($line, 1));
      $out .= pdpug_interpolate($text, $data, true) . "\n";
      continue;
    }

    // Elemento
    [$tag, $attrs, $inlineText] = pdpug_parse_element($line);

    $out .= "<{$tag}{$attrs}>";

    if ($inlineText !== "") {
      $out .= pdpug_interpolate($inlineText, $data, true);
    }

    $out .= "\n";
    $stack[] = $tag;
  }

  // Cierra lo que quede abierto
  while (!empty($stack)) {
    $tag = array_pop($stack);
    $out .= "</{$tag}>\n";
  }

  return $out;
}

function pdpug_parse_element(string $line): array {
  // Sintaxis aceptada (simple):
  // tag#id.class1.class2(attr="x" href="y") texto
  // .class (equivale a div.class)
  // #id (equivale a div#id)

  $tag = "div";
  $i = 0;
  $len = strlen($line);

  // Si empieza con letra, leemos tag
  if ($len > 0 && preg_match('/[a-zA-Z]/', $line[0])) {
    $tag = "";
    while ($i < $len && preg_match('/[a-zA-Z0-9_-]/', $line[$i])) {
      $tag .= $line[$i];
      $i++;
    }
    if ($tag === "") $tag = "div";
  }

  $id = "";
  $classes = [];

  // Lee tokens .class y #id
  while ($i < $len) {
    $ch = $line[$i];

    if ($ch === "#") {
      $i++;
      $buf = "";
      while ($i < $len && preg_match('/[a-zA-Z0-9_-]/', $line[$i])) {
        $buf .= $line[$i];
        $i++;
      }
      $id = $buf;
      continue;
    }

    if ($ch === ".") {
      $i++;
      $buf = "";
      while ($i < $len && preg_match('/[a-zA-Z0-9_-]/', $line[$i])) {
        $buf .= $line[$i];
        $i++;
      }
      if ($buf !== "") $classes[] = $buf;
      continue;
    }

    break;
  }

  // Atributos entre paréntesis
  $attrsRaw = "";
  if ($i < $len && $line[$i] === "(") {
    $depth = 0;
    $start = $i;
    while ($i < $len) {
      if ($line[$i] === "(") $depth++;
      if ($line[$i] === ")") {
        $depth--;
        if ($depth === 0) {
          $i++; // incluir ')'
          break;
        }
      }
      $i++;
    }
    $attrsRaw = substr($line, $start + 1, ($i - $start - 2)); // dentro de (...)
  }

  // Texto inline (lo que quede después)
  $inlineText = trim(substr($line, $i));

  // Construye atributos HTML
  $attrs = [];

  if ($id !== "") {
    $attrs[] = 'id="' . htmlspecialchars($id, ENT_QUOTES, "UTF-8") . '"';
  }
  if (!empty($classes)) {
    $attrs[] = 'class="' . htmlspecialchars(implode(" ", $classes), ENT_QUOTES, "UTF-8") . '"';
  }

  // Parse de attrsRaw súper simple: key="value" o key='value'
  // separadas por espacios o comas
  if (trim($attrsRaw) !== "") {
    $parts = preg_split('/\s*,\s*|\s+(?=[a-zA-Z0-9_-]+\s*=)/', trim($attrsRaw));
    foreach ($parts as $p) {
      $p = trim($p);
      if ($p === "") continue;

      // boolean attr (ej: disabled)
      if (!str_contains($p, "=")) {
        $key = preg_replace('/[^a-zA-Z0-9_-]/', "", $p);
        if ($key !== "") $attrs[] = $key;
        continue;
      }

      [$k, $v] = array_map("trim", explode("=", $p, 2));
      $k = preg_replace('/[^a-zA-Z0-9_-]/', "", $k);

      // quitamos comillas si vienen
      if ((str_starts_with($v, '"') && str_ends_with($v, '"')) ||
          (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
        $v = substr($v, 1, -1);
      }

      $v = htmlspecialchars($v, ENT_QUOTES, "UTF-8");
      if ($k !== "") $attrs[] = $k . '="' . $v . '"';
    }
  }

  $attrsStr = empty($attrs) ? "" : " " . implode(" ", $attrs);
  return [$tag, $attrsStr, $inlineText];
}

function pdpug_interpolate(string $text, array $data, bool $escapePlainText): string {
  // Reemplaza:
  //  #{var} -> escapado
  //  !{var} -> sin escape
  // var puede ser: key o key.subkey (dot)
  $text = preg_replace_callback('/!\{([a-zA-Z_][a-zA-Z0-9_.]*)\}/', function($m) use ($data){
    $val = pdpug_get($data, $m[1]);
    return (string)$val; // raw
  }, $text);

  $text = preg_replace_callback('/#\{([a-zA-Z_][a-zA-Z0-9_.]*)\}/', function($m) use ($data){
    $val = pdpug_get($data, $m[1]);
    return htmlspecialchars((string)$val, ENT_QUOTES, "UTF-8");
  }, $text);

  // Si es texto plano sin interpolación, opcionalmente lo escapamos
  // (para v0 lo dejamos como está; el contenido “normal” ya lo escribes tú)
  return $text;
}

function pdpug_get(array $data, string $path) {
  // Soporta "a.b.c"
  $parts = explode(".", $path);
  $cur = $data;

  foreach ($parts as $p) {
    if (is_array($cur) && array_key_exists($p, $cur)) {
      $cur = $cur[$p];
    } else {
      return "";
    }
  }
  return $cur;
}