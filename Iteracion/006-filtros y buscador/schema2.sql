USE pdpug_recetario;

CREATE TABLE IF NOT EXISTS receta_ingredientes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  receta_id INT UNSIGNED NOT NULL,
  orden INT UNSIGNED NOT NULL DEFAULT 1,
  texto VARCHAR(255) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_receta_orden (receta_id, orden),
  CONSTRAINT fk_ing_receta
    FOREIGN KEY (receta_id) REFERENCES recetas(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS receta_pasos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  receta_id INT UNSIGNED NOT NULL,
  orden INT UNSIGNED NOT NULL DEFAULT 1,
  texto TEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_receta_paso_orden (receta_id, orden),
  CONSTRAINT fk_pasos_receta
    FOREIGN KEY (receta_id) REFERENCES recetas(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Limpia semillas (solo de estos slugs) para no duplicar
DELETE ri
FROM receta_ingredientes ri
JOIN recetas r ON r.id = ri.receta_id
WHERE r.slug IN ('tortilla-de-papas','arroz-con-pollo');

DELETE rp
FROM receta_pasos rp
JOIN recetas r ON r.id = rp.receta_id
WHERE r.slug IN ('tortilla-de-papas','arroz-con-pollo');

-- Semillas: Tortilla
INSERT INTO receta_ingredientes (receta_id, orden, texto)
SELECT id, 1, '4 huevos' FROM recetas WHERE slug='tortilla-de-papas';
INSERT INTO receta_ingredientes (receta_id, orden, texto)
SELECT id, 2, '3 papas medianas' FROM recetas WHERE slug='tortilla-de-papas';
INSERT INTO receta_ingredientes (receta_id, orden, texto)
SELECT id, 3, '1/2 cebolla (opcional)' FROM recetas WHERE slug='tortilla-de-papas';
INSERT INTO receta_ingredientes (receta_id, orden, texto)
SELECT id, 4, 'Sal' FROM recetas WHERE slug='tortilla-de-papas';
INSERT INTO receta_ingredientes (receta_id, orden, texto)
SELECT id, 5, 'Aceite' FROM recetas WHERE slug='tortilla-de-papas';

INSERT INTO receta_pasos (receta_id, orden, texto)
SELECT id, 1, 'Pela y corta las papas en láminas.' FROM recetas WHERE slug='tortilla-de-papas';
INSERT INTO receta_pasos (receta_id, orden, texto)
SELECT id, 2, 'Fríe las papas a fuego medio hasta que estén tiernas.' FROM recetas WHERE slug='tortilla-de-papas';
INSERT INTO receta_pasos (receta_id, orden, texto)
SELECT id, 3, 'Bate los huevos con sal y mezcla con las papas.' FROM recetas WHERE slug='tortilla-de-papas';
INSERT INTO receta_pasos (receta_id, orden, texto)
SELECT id, 4, 'Cuaja la tortilla por ambos lados.' FROM recetas WHERE slug='tortilla-de-papas';

-- Semillas: Arroz con pollo
INSERT INTO receta_ingredientes (receta_id, orden, texto)
SELECT id, 1, '2 presas de pollo' FROM recetas WHERE slug='arroz-con-pollo';
INSERT INTO receta_ingredientes (receta_id, orden, texto)
SELECT id, 2, '1 taza de arroz' FROM recetas WHERE slug='arroz-con-pollo';
INSERT INTO receta_ingredientes (receta_id, orden, texto)
SELECT id, 3, '1/2 cebolla' FROM recetas WHERE slug='arroz-con-pollo';
INSERT INTO receta_ingredientes (receta_id, orden, texto)
SELECT id, 4, 'Ajo, sal y pimienta' FROM recetas WHERE slug='arroz-con-pollo';

INSERT INTO receta_pasos (receta_id, orden, texto)
SELECT id, 1, 'Dora el pollo y reserva.' FROM recetas WHERE slug='arroz-con-pollo';
INSERT INTO receta_pasos (receta_id, orden, texto)
SELECT id, 2, 'Haz el aderezo (cebolla/ajo), agrega el arroz y nacara.' FROM recetas WHERE slug='arroz-con-pollo';
INSERT INTO receta_pasos (receta_id, orden, texto)
SELECT id, 3, 'Agrega líquido, vuelve a poner el pollo y cocina hasta que el arroz esté listo.' FROM recetas WHERE slug='arroz-con-pollo';