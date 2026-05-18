# Silversea – Cotizador de Envío de Contenedores

Plugin WordPress personalizado para Silversea Containers. Integra un cotizador de transporte de contenedores marítimos (10', 20', 40') con el carrito de YITH Request a Quote (YWRAQ).

---

## Índice

1. [Estructura de archivos](#estructura-de-archivos)
2. [Dependencias](#dependencias)
3. [Base de datos](#base-de-datos)
4. [Panel de administración](#panel-de-administración)
5. [Widget del cotizador](#widget-del-cotizador)
6. [Lógica de cálculo](#lógica-de-cálculo)
7. [Sesión y persistencia](#sesión-y-persistencia)
8. [CPT silversea_quote](#cpt-silversea_quote)
9. [Emails](#emails)
10. [Shortcodes de la página "Mi selección"](#shortcodes-de-la-página-mi-selección)
11. [Extras (YITH WAPO)](#extras-yith-wapo)
12. [Flujo completo del usuario](#flujo-completo-del-usuario)
13. [Modo demo](#modo-demo)
14. [FTP / Despliegue](#ftp--despliegue)

---

## Estructura de archivos

```
wp-content/plugins/silversea/
├── silversea.php                          # Plugin principal: includes, shortcodes auxiliares,
│                                          # integración Salesforce, selector de países/banderas
├── includes/
│   ├── shipping-calculator.php            # Tabla BD, menú admin, página admin, AJAX calc, enqueue JS/CSS
│   ├── shipping-quote-calc.php            # Funciones de cálculo puro (sin WP output)
│   ├── shipping-session.php               # AJAX save-shipping, CPT silversea_quote, emails
│   └── shipping-quote-pages.php          # Shortcodes de la página YWRAQ
└── assets/
    ├── js/
    │   ├── shipping-calculator.js         # Widget cotizador (frontend)
    │   └── scripts.js                     # Selector de banderas/países
    └── css/
        ├── shipping-calculator.css        # Estilos del widget cotizador
        └── silversea-raq.css              # Estilos de la página "Mi selección"
```

---

## Dependencias

| Plugin | Uso |
|--------|-----|
| WooCommerce | Sesión, productos, taxonomías (`pa_tamano`, `pa_condicion`) |
| YITH Request a Quote (YWRAQ) | Carrito de cotización, shortcodes, envío de emails |
| YITH WooCommerce Product Add-Ons (WAPO) | Opciones de extras (seguro, pintura, etc.) en los productos |

---

## Base de datos

**Tabla:** `{prefix}_silversea_tarifas`

| Columna | Tipo | Descripción |
|---------|------|-------------|
| `id` | BIGINT | PK auto-increment |
| `ciudad_origen` | VARCHAR(20) | `barcelona` \| `madrid` \| `madrid2` \| `valencia` |
| `cp_destino` | VARCHAR(10) | Código postal de destino (4–5 dígitos) |
| `municipio_destino` | VARCHAR(120) | Nombre del municipio |
| `km` | SMALLINT | Kilómetros para estimar días de entrega |
| `precio_sin_descarga` | DECIMAL(10,2) | Precio por camión, sin grúa |
| `precio_con_desc_20` | DECIMAL(10,2) | Precio por contenedor 20', con grúa |
| `precio_con_desc_40` | DECIMAL(10,2) | Precio por contenedor 40', con grúa |

**Índices:** `UNIQUE (ciudad_origen, cp_destino)`, `KEY (cp_destino)`.

**Importación:** Panel admin acepta CSV, XLSX o JSON. La búsqueda de tarifa primero intenta coincidencia exacta por CP; si falla, busca por los primeros 4 dígitos (`LIKE '1234%'`).

---

## Panel de administración

**Ruta:** WooCommerce → Cotizador (`/wp-admin/admin.php?page=silversea-tarifas`)

### Configuración disponible

| Opción (`wp_options`) | Default | Descripción |
|-----------------------|---------|-------------|
| `silversea_demo_mode` | `0` | Usa precios ficticios en vez de la BD |
| `silversea_descarga_modo` | `contenedor` | `contenedor` = precio por contenedor; `camion` = precio por camión (igual que sin descarga) |
| `silversea_demo_price_sin` | `786.60` | Precio demo sin descarga (por camión) |
| `silversea_demo_price_c20` | `1644.00` | Precio demo con descarga contenedor 20' |
| `silversea_demo_price_c40` | `1765.28` | Precio demo con descarga contenedor 40' |
| `silversea_extra_truck_cost` | `1350.00` | Costo adicional por cada camión 2+ en modo "con descarga" |
| `silversea_admin_email` | admin email | Destino de emails en modo debug/demo |
| `silversea_email_send_client` | `0` | Si enviar copia al cliente al enviar la cotización |
| `silversea_email_show_prices` | `0` | Si mostrar precios de envío en el email al cliente |
| `silversea_require_quote` | `0` | Si requerir cotización de envío antes de permitir enviar el formulario |
| `silversea_show_consolidated` | `1` | Si mostrar el widget cotizador en la página de selección |

---

## Widget del cotizador

**Archivo JS:** `assets/js/shipping-calculator.js`  
**Archivo CSS:** `assets/css/shipping-calculator.css`  
**Renderizado PHP:** `includes/shipping-calculator.php` (función `silversea_shipping_calculator_shortcode`)  
**Shortcode:** `[silversea_shipping_calculator]`

### Paneles

El widget tiene dos modos que el usuario puede alternar:

- **Retiro en almacén** (`method=pickup`): Selecciona ciudad (Barcelona, Madrid, Madrid 2, Valencia). Envío gratuito. Plazo: 5 días hábiles.
- **Entrega a domicilio** (`method=delivery`): Selecciona ciudad de origen + código postal de destino + tipo de transporte (sin/con descarga).

### Campos

```
Origen:       [Barcelona ▼]
Código postal: [28001___]
Transporte:   (●) Sin descarga   ( ) Con descarga
              [Calcular envío]
```

### Flujo JS

1. `scRestorePrefs()` — al cargar la página, recupera la última selección desde cookie (`sc_prefs`) y rellena los campos. No dispara el cálculo automático.
2. `scCalculate()` — llama al AJAX handler `silversea_calc_shipping` con los campos actuales, muestra el resultado.
3. Al confirmar (`scSaveShipping()`), llama al AJAX handler `silversea_save_shipping` para guardar la sesión y redirige a la página del formulario.
4. Si la URL contiene `?sc_recalc=1` (inyectado por "Actualizar lista") y el método guardado era `delivery`, dispara `scCalculate()` automáticamente y limpia el parámetro con `history.replaceState()`.

### AJAX handlers

| Action | Handler PHP | Descripción |
|--------|-------------|-------------|
| `silversea_calc_shipping` | `silversea_ajax_calc_shipping()` | Calcula y devuelve HTML de resultado |
| `silversea_save_shipping` | `silversea_ajax_save_shipping()` | Guarda datos en WC Session |

---

## Lógica de cálculo

**Archivo:** `includes/shipping-quote-calc.php`

### Constantes y helpers

```php
SILVERSEA_ORIGINS  // ['barcelona', 'madrid', 'madrid2', 'valencia']

silversea_origin_label($origin)
// 'madrid2' → 'Madrid 2', otros → ucfirst()

silversea_get_product_size(WC_Product $product): int
// Prioridad: pa_tamano taxonomy → padre (si es variation) → título del producto → 20 (default)

silversea_raq_to_items_detail($raq_content): array
// Devuelve ['items' => [...], 'total_units' => int]
// Cada item: ['name', 'size', 'quantity', 'units']

silversea_build_truck_list($items_detail): array
// Construye lista de camiones óptima (ver reglas abajo)

silversea_truck_label($truck): string
// Ejemplo: "2×20' + 1×10'"

silversea_build_truck_breakdown($truck_list, $transport, $descarga_modo, ...): array
// Devuelve ['breakdown' => [...], 'total' => float]
```

### Reglas de carga (camiones)

Un camión tiene capacidad de **4 unidades**:
- Contenedor 40' = 4 unidades → ocupa un camión completo solo
- Contenedor 20' = 2 unidades
- Contenedor 10' = 1 unidad

Los 40' siempre van solos. Los 20' y 10' se combinan en orden hasta llenar cada camión.

**Ejemplo:** 3×40' + 4×20' = 3 camiones (uno por cada 40') + 2 camiones (4 unidades de 20' cada uno) = **5 camiones**.

### Reglas de precio (con descarga)

- **Camión 1:** se cobra por contenedor individual (`p_c20` o `p_c40`). Excepción: si el camión está lleno (4u) sin 40', se aplica tarifa equivalente a 40' (`p_c40`).
- **Camiones 2+:** se cobra `p_sin + p_extra_truck` (sin grúa + surcharge).
- Si `descarga_modo === 'camion'`: todos los camiones se cobran al precio sin descarga (`p_sin`), independientemente del modo de transporte seleccionado.

### Estimación de días de entrega

Basada en kilómetros de la fila de tarifas:
```
< 200 km  → 2–3 días hábiles
< 500 km  → 3–5 días hábiles
< 800 km  → 5–7 días hábiles
≥ 800 km  → 7–10 días hábiles
```

### Función principal

```php
silversea_calc_consolidated_shipping($raq_content, $origin, $cp, $transport)
// Devuelve array con: total, trucks, total_units, transport, transp_label,
//                     origin, cp, destino, breakdown, items_detail, days, km, descarga_modo
// O WP_Error si falla validación o no hay tarifa.
```

---

## Sesión y persistencia

**Archivo:** `includes/shipping-session.php`

Los datos de envío elegidos se guardan en la sesión de WooCommerce (`WC()->session`), clave `silversea_shipping_data`:

```php
[
    'method'      => 'delivery' | 'pickup',
    'origin'      => 'barcelona' | 'madrid' | 'madrid2' | 'valencia',
    'postal_code' => '28001',
    'transport'   => 'sin' | 'con',
    'pickup_city' => 'barcelona' | ...,
    'price'       => 786.60,
    'detail'      => 'string HTML del resumen',
    'trucks'      => 2,
    'days'        => 5,
]
```

La sesión se **limpia automáticamente** cuando el usuario hace click en "Actualizar lista" en la página YWRAQ, forzando recalcular el envío con los nuevos productos.

---

## CPT silversea_quote

**Archivo:** `includes/shipping-session.php`

Post type privado `silversea_quote` que guarda cada cotización enviada.

### Meta fields guardados

| Meta key | Contenido |
|----------|-----------|
| `_sq_name` | Nombre del cliente |
| `_sq_email` | Email del cliente |
| `_sq_phone` | Teléfono |
| `_sq_client_type` | `particular` \| `empresa` |
| `_sq_products` | JSON array de productos (`name`, `qty`) |
| `_sq_shipping_method` | `delivery` \| `pickup` |
| `_sq_shipping_origin` | Ciudad de origen |
| `_sq_shipping_cp` | Código postal de destino |
| `_sq_shipping_pickup` | Ciudad de retiro (si método es pickup) |
| `_sq_shipping_price` | Precio total de envío |
| `_sq_breakdown` | JSON del desglose por camión |
| `_sq_email_body_sales` | HTML del email enviado a ventas |
| `_sq_email_body_client` | HTML del email enviado al cliente |

---

## Emails

**Archivo:** `includes/shipping-session.php`

Al enviar el formulario de cotización YWRAQ se disparan (hook `yith_ywraq_after_send_request`):

1. **Email a ventas** → `comercial-eu@silverseacontainers.com` (o `silversea_admin_email` en modo demo). Siempre incluye precios de envío y desglose completo.
2. **Email al cliente** → dirección ingresada en el formulario. Solo se envía si `silversea_email_send_client = 1`. Los precios se incluyen solo si `silversea_email_show_prices = 1`.

Ambos emails se guardan como meta del CPT `silversea_quote` y son visibles en el panel admin con botón para reenviar.

---

## Shortcodes de la página "Mi selección"

**Archivo:** `includes/shipping-quote-pages.php`

### `[silversea_quote_view form_page='slug-del-formulario']`

Widget completo para la página de "Mi selección" (carrito YWRAQ). Muestra:
- Resumen de la cotización de envío calculada (si existe en sesión)
- Opciones de extras WAPO (si hay productos con add-ons)
- Link "Recalcular" que despliega/colapsa el widget calculador inline
- Link "Cancelar" que revierte el widget al estado anterior

Parámetro `form_page`: slug de la página del formulario de cotización (donde está el shortcode `[yith_ywraq_request_quote]`). Se usa para el botón "Continuar".

### `[silversea_quote_form]`

Muestra el resumen de envío dentro de la página del formulario de cotización (antes de enviar). Recupera datos de la sesión.

### `[silversea_quote_thanks]`

Muestra el resumen de envío en la página de confirmación post-envío.

### Helper interno

```php
silversea_get_raq_content(): array
// Obtiene el contenido del carrito YWRAQ desde el objeto YITH_Request_Quote
// o como fallback desde la sesión YITH directamente.
```

---

## Extras (YITH WAPO)

Los productos pueden tener opciones adicionales (seguros, pintura, etc.) definidas en YITH Product Add-Ons.

El widget muestra estas opciones como tarjetas visuales (`sc-extras-grid`) dentro del panel de la página "Mi selección". Las tarjetas se renderizan en **ambos paneles** (retiro y entrega) con estado sincronizado:

- `querySelectorAll('.sc-extras-grid')` — localiza todas las instancias
- `selectedValues = {}` — objeto compartido de estado entre grids
- `syncAllCards(value, isSelected)` — actualiza todas las tarjetas matching en todos los grids simultáneamente
- Al confirmar, los extras seleccionados se incluyen en la sesión y en los emails

---

## Flujo completo del usuario

```
1. Usuario navega el catálogo y agrega contenedores a "Mi solicitud" (YWRAQ)
   ↓
2. Accede a la página "Mi selección" → shortcode [silversea_quote_view]
   ↓
3. Widget cotizador: selecciona origen, CP, tipo de transporte → "Calcular envío"
   ↓
4. JS llama AJAX silversea_calc_shipping → PHP devuelve resultado HTML
   ↓
5. Usuario confirma → JS llama AJAX silversea_save_shipping → guarda en WC Session
   ↓
6. Redirige a página del formulario → [yith_ywraq_request_quote] + [silversea_quote_form]
   ↓
7. Usuario completa datos y envía
   ↓
8. Hook yith_ywraq_after_send_request → crea CPT silversea_quote + envía emails
   ↓
9. Redirige a página de gracias → [silversea_quote_thanks]
```

**Actualizar lista:** Si el usuario modifica cantidades en "Mi selección" y hace click en "Actualizar lista" (formulario YWRAQ `update_raq`), el filter `wp_redirect` detecta el submit, limpia la sesión de envío e inyecta `?sc_recalc=1` en la URL de redirección. Al cargar la página, el JS detecta ese parámetro y dispara `scCalculate()` automáticamente.

---

## Modo demo

Activar en: WooCommerce → Cotizador → ☑ Modo demo

Cuando está activo:
- No consulta la BD de tarifas
- Usa los precios configurados en el panel admin
- El destino se muestra como `"CP XXXXX (DEMO)"`
- Los labels de camiones incluyen `"(DEMO)"`
- `km = 0`, `days = 5`
- Los emails se envían al `silversea_admin_email` en vez del cliente/ventas real

**Desactivar antes de salir a producción.**

---

## FTP / Despliegue

Servidor: FTP Silversea (credenciales en FileZilla)  
Ruta remota: `public_html/wp-content/plugins/silversea/`

### Archivos que se modifican con frecuencia

| Archivo local | Descripción |
|---------------|-------------|
| `includes/shipping-calculator.php` | Widget PHP, AJAX, admin |
| `includes/shipping-quote-calc.php` | Lógica de cálculo |
| `includes/shipping-session.php` | Sesión, CPT, emails |
| `includes/shipping-quote-pages.php` | Shortcodes página selección |
| `assets/js/shipping-calculator.js` | Widget JS frontend |
| `assets/css/shipping-calculator.css` | Estilos widget |
| `assets/css/silversea-raq.css` | Estilos página selección |

> Después de subir cambios en JS o CSS, limpiar cualquier caché de WP (WP Rocket, etc.) para que los visitantes reciban los archivos actualizados.
