<?php
/**
 * db.php
 * 
 * Este archivo gestiona la conexión a la base de datos MySQL utilizando la extensión mysqli.
 * Define una función centralizada para obtener una instancia de conexión configurada.
 * 
 * Credenciales por defecto:
 * - Usuario: pdpug
 * - Contraseña: pdpug
 * - Base de datos: pdpug_recetario
 */

declare(strict_types=1);

/**
 * Establece y devuelve una conexión activa a la base de datos.
 * 
 * Configura el conjunto de caracteres a utf8mb4 para asegurar la compatibilidad con 
 * caracteres especiales y emojis. Lanza una excepción en caso de error de conexión.
 * 
 * @return mysqli Instancia de la conexión MySQL activo.
 * @throws RuntimeException Si ocurre un error de conexión con MySQL.
 */
function db(): mysqli {
  // Configuración del servidor de base de datos
  $host = "localhost";
  $user = "pdpug";
  $pass = "pdpug";
  $name = "pdpug_recetario";

  // Inicialización de la clase mysqli para conectar al servidor
  $mysqli = new mysqli($host, $user, $pass, $name);

  // Verificación de errores de conexión (connect_errno almacena el código de error)
  if ($mysqli->connect_errno) {
    throw new RuntimeException("Error en la conexión MySQL: " . $mysqli->connect_error);
  }

  // Establecimiento del charset a utf8mb4 para evitar problemas de codificación 
  // en los datos enviados y recibidos.
  $mysqli->set_charset("utf8mb4");
  
  return $mysqli;
}