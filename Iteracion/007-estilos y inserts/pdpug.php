<?php
/**
 * - @include, @foreach, @if/@else
 * - FIX hermanos (stack con level)
 * - Interpolación también en atributos (href/value/etc.)
 *   - En atributos: #{var} y !{var} se reemplazan y luego se escapan como atributo.
 */

declare(strict_types=1);

function pdpug_render(string $filePath, array $data = []): string {
  if (!is_file($filePath)) {
    throw new RuntimeException("PDpug: no existe la vista: $filePath");
  }

  $baseDir = dirname($filePath);

  $lines = file($filePath, FILE_IGNORE_NEW_LINES);
  if ($lines === false) {
    throw new RuntimeException("PDpug: no se pudo leer: $filePath");
  }

  $lines = pdpug_expand_includes($lines, $baseDir);

  $out = "";
  $stack = []; // ["tag" => string, "level" => int]
  pdpug_render_lines($lines, $data, $baseDir, $stack, $out);

  pdpug_close_to_level($stack, $out, 0);
  return $out;
}

function pdpug_render_lines(array $lines, array $data, string $baseDir, array &$stack, string &$out): void {
  $n = count($lines);
  $i = 0;

  while ($i < $n) {
    $rawLine = $lines[$i];

    if (trim($rawLine) === "") { $i++; continue; }

    $level = pdpug_level($rawLine);
    $line  = ltrim($rawLine);

    // Cerrar tags al mismo nivel o superior (hermanos)
    pdpug_close_to_level($stack, $out, $level);

    if (str_starts_with($line, "//")) { $i++; continue; }

    if ($line === "doctype html") {
      $out .= "<!doctype html>\n";
      $i++;
      continue;
    }

    if (str_starts_with($line, "|")) {
      $text = ltrim(substr($line, 1));
      $out .= pdpug_interpolate_text($text, $data) . "\n";
      $i++;
      continue;
    }

    // @if var (+ @else)
    if (preg_match('/^@if\s+(\$?[a-zA-Z_][a-zA-Z0-9_.]*)\s*$/', $line, $m)) {
      $path = ltrim($m[1], '$');

      $trueBlock = [];
      $j = $i + 1;
      while ($j < $n && pdpug_level($lines[$j]) > $level) {
        $trueBlock[] = $lines[$j];
        $j++;
      }

      $elseBlock = [];
      if ($j < $n && pdpug_level($lines[$j]) === $level && ltrim($lines[$j]) === "@else") {
        $k = $j + 1;
        while ($k < $n && pdpug_level($lines[$k]) > $level) {
          $elseBlock[] = $lines[$k];
          $k++;
        }
        $j = $k;
      }

      $val = pdpug_get($data, $path);
      $chosen = pdpug_truthy($val) ? $trueBlock : $elseBlock;

      if (!empty($chosen)) {
        pdpug_render_lines($chosen, $data, $baseDir, $stack, $out);
        pdpug_close_to_level($stack, $out, $level + 1);
      }

      $i = $j;
      continue;
    }

    if ($line === "@else") { $i++; continue; }

    // @foreach x as y
    if (preg_match('/^@foreach\s+(\$?[a-zA-Z_][a-zA-Z0-9_.]*)\s+as\s+(\$?[a-zA-Z_][a-zA-Z0-9_]*)\s*$/', $line, $m)) {
      $path = ltrim($m[1], '$');
      $var  = ltrim($m[2], '$');

      $items = pdpug_get($data, $path);
      if (!is_array($items)) $items = [];

      $block = [];
      $j = $i + 1;
      while ($j < $n && pdpug_level($lines[$j]) > $level) {
        $block[] = $lines[$j];
        $j++;
      }

      foreach ($items as $it) {
        $data2 = $data;
        $data2[$var] = is_array($it) ? $it : (array)$it;

        pdpug_render_lines($block, $data2, $baseDir, $stack, $out);
        pdpug_close_to_level($stack, $out, $level + 1);
      }

      $i = $j;
      continue;
    }

    // Elemento normal
    [$tag, $attrs, $inlineText] = pdpug_parse_element($line, $data);

    $out .= "<{$tag}{$attrs}>";

    if ($inlineText !== "") {
      $out .= pdpug_interpolate_text($inlineText, $data);
    }

    $out .= "\n";
    $stack[] = ["tag" => $tag, "level" => $level];

    $i++;
  }
}

function pdpug_truthy($val): bool {
  if (is_array($val)) return count($val) > 0;
  if (is_bool($val)) return $val;
  if (is_int($val) || is_float($val)) return $val != 0;
  $s = trim((string)$val);
  return $s !== "" && $s !== "0";
}

function pdpug_expand_includes(array $lines, string $baseDir, int $depth = 0, array $seen = []): array {
  if ($depth > 20) throw new RuntimeException("PDpug: demasiados includes anidados.");

  $out = [];

  foreach ($lines as $rawLine) {
    $trim = ltrim($rawLine);

    if (preg_match('/^@include\s+"([^"]+)"\s*$/', $trim, $m) || preg_match("/^@include\s+'([^']+)'\s*$/", $trim, $m)) {
      $indent = substr($rawLine, 0, strlen($rawLine) - strlen($trim));
      $rel = $m[1];

      $incPath = pdpug_join_path($baseDir, $rel);
      if (!is_file($incPath)) throw new RuntimeException("PDpug: include no encontrado: $incPath");

      $real = realpath($incPath) ?: $incPath;
      if (in_array($real, $seen, true)) throw new RuntimeException("PDpug: include en bucle: $incPath");

      $incLines = file($incPath, FILE_IGNORE_NEW_LINES);
      if ($incLines === false) throw new RuntimeException("PDpug: no se pudo leer include: $incPath");

      $incLines = pdpug_expand_includes($incLines, dirname($incPath), $depth + 1, array_merge($seen, [$real]));

      foreach ($incLines as $l) $out[] = $indent . $l;
      continue;
    }

    $out[] = $rawLine;
  }

  return $out;
}

function pdpug_level(string $rawLine): int {
  preg_match('/^( *)/', $rawLine, $m);
  return intdiv(strlen($m[1] ?? ""), 2);
}

/** Cierra tags cuyo level sea >= objetivo */
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

function pdpug_parse_element(string $line, array $data): array {
  $tag = "div";
  $i = 0;
  $len = strlen($line);

  // Tag
  if ($len > 0 && preg_match('/[a-zA-Z]/', $line[0])) {
    $tag = "";
    while ($i < $len && preg_match('/[a-zA-Z0-9_-]/', $line[$i])) { $tag .= $line[$i]; $i++; }
    if ($tag === "") $tag = "div";
  }

  $id = "";
  $classes = [];

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

  // Atributos (...)
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

  $inlineText = trim(substr($line, $i));

  $attrs = [];
  if ($id !== "") $attrs[] = 'id="' . htmlspecialchars($id, ENT_QUOTES, "UTF-8") . '"';
  if (!empty($classes)) $attrs[] = 'class="' . htmlspecialchars(implode(" ", $classes), ENT_QUOTES, "UTF-8") . '"';

  if (trim($attrsRaw) !== "") {
    $parts = preg_split('/\s*,\s*|\s+(?=[a-zA-Z0-9_-]+\s*=)/', trim($attrsRaw));
    foreach ($parts as $p) {
      $p = trim($p);
      if ($p === "") continue;

      if (!str_contains($p, "=")) {
        $key = preg_replace('/[^a-zA-Z0-9_-]/', "", $p);
        if ($key !== "") $attrs[] = $key;
        continue;
      }

      [$k, $v] = array_map("trim", explode("=", $p, 2));
      $k = preg_replace('/[^a-zA-Z0-9_-]/', "", $k);

      if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
        $v = substr($v, 1, -1);
      }

      // Interpolación en atributos (sin escape aquí; se escapa al final como atributo)
      $v = pdpug_interpolate_attr($v, $data);
      $v = htmlspecialchars($v, ENT_QUOTES, "UTF-8");

      if ($k !== "") $attrs[] = $k . '="' . $v . '"';
    }
  }

  $attrsStr = empty($attrs) ? "" : " " . implode(" ", $attrs);
  return [$tag, $attrsStr, $inlineText];
}

/** Para texto normal: #{var} escapado, !{var} sin escape */
function pdpug_interpolate_text(string $text, array $data): string {
  $text = preg_replace_callback('/!\{([a-zA-Z_][a-zA-Z0-9_.]*)\}/', function($m) use ($data){
    return (string)pdpug_get($data, $m[1]);
  }, $text);

  $text = preg_replace_callback('/#\{([a-zA-Z_][a-zA-Z0-9_.]*)\}/', function($m) use ($data){
    return htmlspecialchars((string)pdpug_get($data, $m[1]), ENT_QUOTES, "UTF-8");
  }, $text);

  return $text;
}

/** Para atributos: reemplaza variables pero devuelve RAW (luego se escapa como atributo) */
function pdpug_interpolate_attr(string $text, array $data): string {
  $text = preg_replace_callback('/[#!]\{([a-zA-Z_][a-zA-Z0-9_.]*)\}/', function($m) use ($data){
    return (string)pdpug_get($data, $m[1]);
  }, $text);

  return $text;
}

function pdpug_get(array $data, string $path) {
  $parts = explode(".", $path);
  $cur = $data;
  foreach ($parts as $p) {
    if (is_array($cur) && array_key_exists($p, $cur)) $cur = $cur[$p];
    else return "";
  }
  return $cur;
}

function pdpug_join_path(string $baseDir, string $rel): string {
  if (preg_match('/^[A-Za-z]:\\\\/', $rel) || str_starts_with($rel, "/") || str_starts_with($rel, "\\")) {
    return $rel;
  }
  return rtrim($baseDir, "/\\") . DIRECTORY_SEPARATOR . str_replace(["/", "\\"], DIRECTORY_SEPARATOR, $rel);
}