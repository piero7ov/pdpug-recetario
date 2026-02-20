# Guía de Vistas y Motor de Plantillas (PDpug)

Este documento explica la estructura de la carpeta `views/` y el funcionamiento del motor de plantillas personalizado **PDpug**.

## Comentarios en archivos .pdpug

¡Sí se pueden comentar! El motor PDpug está configurado para ignorar cualquier línea que comience con `//`. 

- **Sintaxis:** `// Esto es un comentario técnico`
- **Comportamiento:** Las líneas que empiezan por `//` no se procesan ni aparecen en el HTML resultante. Son comentarios "silenciosos" ideales para documentar la estructura de la vista.

---

## Estructura de la Carpeta `views/`

La carpeta `views/` organiza las piezas visuales de la aplicación. Se divide en vistas principales y parciales (`partials/`).

### Vistas Principales

| Archivo | Propósito |
| :--- | :--- |
| `layout.pdpug` | Estructura base HTML (Head, Body, links a CSS). Sirve como contenedor para el resto de vistas. |
| `recetas_lista.pdpug` | Pantalla principal que muestra el listado de recetas, buscador y opciones de ordenación. |
| `receta_detalle.pdpug` | Muestra toda la información de una receta específica: ingredientes y pasos. |
| `receta_form.pdpug` | Formulario reutilizable para la creación de nuevas recetas. |
| `receta_edit.pdpug` | Interfaz para editar recetas existentes (precarga los datos en el formulario). |
| `receta_delete.pdpug` | Página de confirmación intermedia antes de eliminar una receta. |
| `not_found.pdpug` | Mensaje amigable cuando se intenta acceder a una receta que no existe. |
| `error.pdpug` | Pantalla genérica para mostrar errores críticos de base de datos o lógica. |

### Carpeta `partials/`

Contiene fragmentos reutilizables que se inyectan en el layout o en otras vistas mediante la directiva `@include`.

- `header.pdpug`: Cabecera del sitio (logo y navegación principal).
- `footer.pdpug`: Pie de página con información de copyright o enlaces secundarios.

---

## Directivas del Motor PDpug

Para entender cómo funcionan estas vistas, recuerda que el motor soporta:

1. **Jerarquía por Indentación:** La estructura se define con espacios (2 espacios por nivel). No se cierran etiquetas manualmente.
2. **`@include "ruta"`**: Inserta el contenido de otro archivo.
3. **`@foreach $coleccion as $item`**: Itera sobre arrays (ej. lista de ingredientes).
4. **`@if $condicion` / `@else`**: Lógica condicional básica.
5. **Interpolación:** 
   - `#{variable}`: Imprime el valor escapado (seguro contra XSS).
   - `!{variable}`: Imprime el valor real (permitiendo HTML).
