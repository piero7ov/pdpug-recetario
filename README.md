# PDPUG-RECETARIO

Proyecto en **PHP + MySQL** que implementa un **mini motor de plantillas tipo Pug** llamado **PDpug** (hecho desde cero) y lo usa para construir un **recetario** con CRUD completo.

Incluye:
- Motor **PDpug** (views `.pdpug`)
- Recetario con **Crear / Ver / Editar / Eliminar**
- Recetas con **ingredientes** y **pasos**
- **Buscador** y **orden** en el listado
- Estilo simple (PapayaWhip) + **logo** en el header

---

## ✅ Estructura del proyecto

```

PDPUG-RECETARIO/
├─ index.php
├─ pdpug.php
├─ db.php
├─ style.css
├─ schema.sql
├─ schema2.sql
├─ VIEWS_GUIDE.md
└─ views/
├─ layout.pdpug
├─ recetas_lista.pdpug
├─ receta_detalle.pdpug
├─ receta_form.pdpug
├─ receta_edit.pdpug
├─ receta_delete.pdpug
├─ not_found.pdpug
├─ error.pdpug
└─ partials/
├─ header.pdpug
└─ footer.pdpug

```

---

## 🔧 Requisitos
- **XAMPP** (Apache + MySQL/MariaDB)
- PHP 8.x recomendado

---

## 🚀 Instalación

### 1) Copia el proyecto a `htdocs`
Ejemplo:
```

C:\xampp\htdocs\PDPUG-RECETARIO

```

### 2) Crea la base de datos y tablas
En **phpMyAdmin** (o consola) ejecuta en este orden:

1. `schema.sql`
   - Crea la DB `pdpug_recetario`
   - Crea la tabla `recetas`
   - Crea el usuario MySQL `pdpug` con contraseña `pdpug` (localhost) y da permisos sobre esa DB

2. `schema2.sql`
   - Crea `receta_ingredientes` y `receta_pasos`
   - Añade datos de ejemplo con ingredientes y pasos

### 3) Abre en el navegador
```

[http://localhost/PDPUG-RECETARIO/](http://localhost/PDPUG-RECETARIO/)

```

---

## 🗄️ Base de datos

**Conexión configurada en `db.php`:**
- Host: `localhost`
- Usuario: `pdpug`
- Password: `pdpug`
- DB: `pdpug_recetario`

> Si tu entorno usa otras credenciales, ajusta `db.php`.

---

## 🧭 Rutas (GET/POST)

### Listado
- `index.php`

### Detalle de receta
- `index.php?r=slug-de-la-receta`

### Crear receta
- `index.php?p=nueva`
  - GET: muestra formulario
  - POST: guarda y redirige al detalle

### Editar receta
- `index.php?p=editar&r=slug`
  - GET: carga datos en el form
  - POST: guarda cambios y redirige al detalle

### Eliminar receta (con confirmación)
- `index.php?p=eliminar&r=slug`
  - GET: pantalla de confirmación
  - POST: elimina y vuelve al listado

### Buscador + orden (en listado)
- Buscar: `index.php?q=pollo`
- Orden: `index.php?ord=az`
- Combinado: `index.php?q=pollo&ord=recientes`

Valores de `ord`:
- `recientes` (default)
- `antiguos`
- `az`
- `za`

---

## 🧠 PDpug (mini motor tipo Pug)

Las vistas están en `views/` con extensión `.pdpug`.  
El motor vive en `pdpug.php` y soporta:

- **Indentación** (2 espacios) para jerarquía
- `doctype html`
- Tags, clases `.clase`, id `#id`
- Atributos: `a(href="?r=#{r.slug}")`
- Texto: `| texto`
- Interpolación:
  - `#{var}` (escapado)
  - `!{var}` (raw)
- Control de flujo:
  - `@include "partials/header.pdpug"`
  - `@if var` / `@else`
  - `@foreach lista as item`

Guía ampliada: **`VIEWS_GUIDE.md`**

---

## 🎨 Estilo + Logo
- CSS en `style.css`
- El header carga el logo desde URL en `views/partials/header.pdpug`:
  - `https://piero7ov.github.io/pierodev-assets/brand/pierodev/logos/solologo_negro.png`

> Si estás sin internet, el proyecto funciona igual (solo no se verá el logo).

---

## 👨‍💻 Desarrollado por
**Piero Olivares — PieroDev**
