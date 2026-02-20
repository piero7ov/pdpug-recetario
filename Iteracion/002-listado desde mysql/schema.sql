CREATE DATABASE IF NOT EXISTS pdpug_recetario
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE pdpug_recetario;

CREATE TABLE IF NOT EXISTS recetas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(120) NOT NULL,
  titulo VARCHAR(180) NOT NULL,
  descripcion TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_slug (slug)
);

-- Semilla mínima (opcional pero útil)
INSERT INTO recetas (slug, titulo, descripcion)
VALUES
  ('tortilla-de-papas', 'Tortilla de papas', 'Receta clásica y sencilla.'),
  ('arroz-con-pollo', 'Arroz con pollo', 'Rápido, rendidor y buenazo.')
ON DUPLICATE KEY UPDATE slug = slug;
-- Crear usuario local
CREATE USER IF NOT EXISTS 'pdpug'@'localhost' IDENTIFIED BY 'pdpug';

-- Dar permisos SOLO sobre esta base de datos
GRANT ALL PRIVILEGES ON pdpug_recetario.* TO 'pdpug'@'localhost';

-- Aplicar cambios
FLUSH PRIVILEGES;