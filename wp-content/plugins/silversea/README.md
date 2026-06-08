# Silversea – Plugin de Cotización de Contenedores

Plugin WordPress personalizado para Silversea Containers. Integra un cotizador de transporte de contenedores marítimos con el carrito de YITH Request a Quote (YWRAQ) y envío automático de leads a Salesforce.

---

## Índice

1. [Estructura de archivos](#estructura-de-archivos)
2. [Dependencias](#dependencias)
3. [Menú admin](#menú-admin)
4. [Base de datos](#base-de-datos)
5. [Configuración disponible](#configuración-disponible)
6. [Ciudades / Depósitos](#ciudades--depósitos)
7. [Textos editables](#textos-editables)
8. [Widget del cotizador](#widget-del-cotizador)
9. [Galería de colores](#galería-de-colores)
10. [Lógica de cálculo](#lógica-de-cálculo)
11. [Sesión y persistencia](#sesión-y-persistencia)
12. [CPT silversea_quote](#cpt-silversea_quote)
13. [Emails](#emails)
14. [Integración Salesforce](#integración-salesforce)
15. [Shortcodes de la página "Mi selección"](#shortcodes-de-la-página-mi-selección)
16. [Extras (YITH WAPO)](#extras-yith-wapo)
17. [Herramientas admin de productos](#herramientas-admin-de-productos)
18. [Flujo completo del usuario](#flujo-completo-del-usuario)
19. [Modo demo](#modo-demo)
20. [FTP / Despliegue](#ftp--despliegue)

---

## Estructura de archivos

```
wp-content/plugins/silversea/
├── silversea.php                          # Bootstrap: banderas/países, traducción de menús,
│                                          # form Salesforce de Elementor. Al final: require cotizador.php
├── cotizador.php                          # Núcleo del cotizador: requiere shipping-calculator.php,
│                                          # shortcodes (product_count, badge_usado), menús Precios/Ordenar,
│                                          # columna SKU/Color, color gallery (PHP)
├── includes/
│   ├── shipping-calculator.php            # Constantes, tabla BD, menú admin "Cotizador", página config,
│   │                                      # AJAX calc + autocomplete CP, precios por ciudad, export CSV.
│   │                                      # Al final: require shipping-session.php + shipping-quote-pages.php
│   ├── shipping-quote-calc.php            # Cálculo puro (sin WP output), helpers de ciudades/depósitos,
│   │                                      # normalización de CP, resolución de productos
│   ├── shipping-session.php               # AJAX save-shipping, CPT silversea_quote, emails.
│   │                                      # Al inicio: require shipping-quote-calc.php + salesforce.php
│   ├── shipping-quote-pages.php           # Shortcodes de la página YWRAQ
│   ├── salesforce.php                     # Integración Salesforce Web-to-Lead:
│   │                                      # envío, mapeo de tipos, página de mapeo en lote,
│   │                                      # meta box en cotizaciones, re-envío manual
│   └── texts.php                          # Textos editables al cliente: registro de defaults,
│                                          # helper silversea_text(), página admin "📝 Textos"
└── assets/
    ├── js/
    │   ├── shipping-calculator.js         # Widget cotizador (frontend)
    │   ├── color-gallery.js               # Filtro de galería por color RAL
    │   └── scripts.js                     # Selector de banderas/países
    └── css/
        ├── shipping-calculator.css        # Estilos del widget cotizador
        └── silversea-raq.css              # Estilos de la página "Mi selección"
```

---

## Dependencias

| Plugin | Uso |
|--------|-----|
| WooCommerce | Sesión, productos, taxonomías (`pa_tamano`, `pa_condicion`, `pa_color-ral`) |
| YITH Request a Quote (YWRAQ) | Carrito de cotización, shortcodes, envío de formulario |
| YITH WooCommerce Product Add-Ons (WAPO) | Extras en productos (seguro, pintura, etc.) |

---

## Menú admin

El plugin crea una estructura de menú propia en el panel de WordPress:

```
💰 Cotizador                  → Configuración general + importación de tarifas
   ├── Configuración
   ├── 🏙 Precios Ciudad       → Precio del contenedor por ciudad (en lote)
   ├── € Precios              → Editor masivo de precios
   ├── ↕ Ordenar             → Reordenador drag & drop de productos
   ├── Salesforce            → Mapeo en lote producto → ContainerType
   └── 📝 Textos             → Edición de los textos mostrados al cliente
   
📋 Cotizaciones              → Listado del CPT silversea_quote (leads guardados)
   ├── Todas las cotizaciones
   └── Nueva cotización
```

> En versiones anteriores, "Precios" y "Ordenar" estaban dentro del menú **Productos**. Ahora están bajo **Cotizador**.

---

## Base de datos

**Tabla:** `{prefix}_silversea_tarifas`

| Columna | Tipo | Descripción |
|---------|------|-------------|
| `id` | BIGINT | PK auto-increment |
| `ciudad_origen` | VARCHAR(20) | Clave de ciudad de origen (ej. `barcelona`) |
| `cp_destino` | VARCHAR(10) | Código postal de destino (4–5 dígitos) |
| `municipio_destino` | VARCHAR(120) | Nombre del municipio |
| `km` | SMALLINT | Kilómetros (para estimar días de entrega) |
| `precio_sin_descarga` | DECIMAL(10,2) | Precio por camión, sin grúa |
| `precio_con_desc_20` | DECIMAL(10,2) | Precio por contenedor 20', con grúa |
| `precio_con_desc_40` | DECIMAL(10,2) | Precio por contenedor 40', con grúa |

**Índices:** `UNIQUE (ciudad_origen, cp_destino)`, `KEY (cp_destino)`.

**Importación:** acepta CSV, XLSX o JSON desde el panel admin. La búsqueda primero intenta coincidencia exacta de CP; si falla, busca por los primeros 4 dígitos (`LIKE '1234%'`).

---

## Configuración disponible

**Ruta:** Cotizador → Configuración

| Opción (`wp_options`) | Default | Descripción |
|-----------------------|---------|-------------|
| `silversea_demo_mode` | `0` | Usa precios ficticios sin consultar la BD |
| `silversea_descarga_modo` | `contenedor` | `contenedor` = precio por contenedor; `camion` = precio por camión |
| `silversea_demo_price_sin` | `786.60` | Precio demo sin descarga (por camión) |
| `silversea_demo_price_c20` | `1644.00` | Precio demo con descarga 20' |
| `silversea_demo_price_c40` | `1765.28` | Precio demo con descarga 40' |
| `silversea_extra_truck_cost` | `1350.00` | Surcharge por camión 2+ en modo "con descarga" |
| `silversea_sales_email` | *(vacío)* | Email de ventas al que se envían las cotizaciones. **Si está vacío, no se envían emails** — solo se guardan en el panel |
| `silversea_admin_email` | admin email | Destino de emails en modo demo/debug |
| `silversea_email_send_client` | `0` | Si enviar copia al cliente |
| `silversea_email_show_prices` | `1` | Si mostrar precios en el email al cliente |
| `silversea_show_front` | `0` | Si mostrar el precio calculado al cliente en el front |
| `silversea_require_quote` | `0` | Si requerir cotización antes de poder agregar al carrito |
| `silversea_show_consolidated` | `1` | Si mostrar el widget consolidado en "Mi selección" |

> **Importante:** configurar `silversea_sales_email` antes de salir a producción, de lo contrario las cotizaciones se guardan pero no se envían por email.

---

## Ciudades / Depósitos

**Ruta:** Cotizador → Configuración → sección "🏙️ Ciudades / Depósitos"

Cada depósito puede habilitarse para uno o dos modos:

- **Entrega:** el cliente recibe el contenedor a domicilio (requiere tarifas importadas en la BD).
- **Retiro:** el cliente retira en el depósito (sin costo de transporte).

La configuración se guarda en la opción `silversea_cities_config`. Si la opción no existe, se usan los valores por defecto definidos en `silversea_default_cities()`.

### Helpers disponibles

```php
silversea_get_cities()                // Todas las ciudades con sus modos configurados
silversea_get_cities_for_mode($mode)  // Solo ciudades habilitadas para 'delivery' o 'pickup'
silversea_get_city_keys($mode)        // Solo los slugs (para validación)
silversea_origin_label($key)          // 'barcelona' → 'Barcelona'
```

---

## Textos editables

**Archivo:** `includes/texts.php`
**Ruta admin:** Cotizador → 📝 Textos

Todos los textos mostrados al cliente (botones, labels, tooltips, avisos, validaciones, mensajes de error, página "Mi selección" y "Gracias") se editan desde el admin **sin tocar código**. Se permite HTML básico (negritas, enlaces, saltos de línea). Un campo vacío vuelve a su valor por defecto.

### Cómo funciona

- **Registro de defaults:** `silversea_text_defaults()` define cada texto como `clave => [grupo, etiqueta, tipo, default]`.
- **Almacenamiento:** opción `silversea_texts` (array `clave => valor`), saneada con `wp_kses_post`.
- **Lectura en PHP:** `silversea_text('clave')` devuelve el valor guardado o el default.
- **Lectura en JS:** los textos viajan en `silvSea.texts` (vía `wp_localize_script`). El helper `scT('clave', fallback)` los lee con respaldo al texto por defecto, de modo que el frontend nunca queda vacío.
- Algunos textos usan placeholders `sprintf`, p. ej. `msg_no_tarifa` → `%1$s` = CP, `%2$s` = ciudad.

### Agregar nuevos textos (p. ej. emails)

1. Sumar una entrada a `silversea_text_defaults()` con su grupo/etiqueta/default.
2. Reemplazar el literal en el código por `silversea_text('clave')` (PHP) o `scT('clave', '…')` (JS).

Los emails al cliente **todavía no** están integrados al editor (su plantilla es HTML completo); se pueden sumar con el mismo mecanismo cuando se decida.

---

## Widget del cotizador

**Shortcode:** `[silversea_shipping mode="single"]` o `[silversea_shipping mode="consolidated"]`

**Modos:**
- `single` — en páginas de producto individual. Muestra cantidad, selector de color RAL (si aplica), y cotizador.
- `consolidated` — en la página "Mi selección". Calcula el envío para todos los productos del carrito.

### Paneles

| Panel | Método | Descripción |
|-------|--------|-------------|
| Recogida | `pickup` | Ciudad de recogida (depósitos con modo pickup). Sin coste. |
| Entrega | `delivery` | Ciudad de salida + CP destino + tipo transporte (sin/con descarga). |

> Todos los textos de los paneles son editables desde **Cotizador → 📝 Textos**.

### Aviso de volumen

En modo `single`, se muestra un banner azul: _"¿Necesita más de 7 contenedores? → sales@silverseacontainers.com"_ (editable como `bulk_notice`).

### AJAX handlers

| Action | Handler | Descripción |
|--------|---------|-------------|
| `silversea_calc_shipping` | `silversea_shipping_ajax_calc()` | Cálculo single |
| `silversea_calc_consolidated` | `silversea_shipping_ajax_calc_consolidated()` | Cálculo consolidado |
| `silversea_save_shipping` | `silversea_ajax_save_shipping()` | Guarda en WC Session |

### Validación de color (productos variables)

Si el producto es variable con atributo `pa_color-ral`, el widget muestra un selector de color dentro del cotizador (clon del select nativo de WC). Si `silversea_require_quote = 1`, el botón "Añadir a selección" queda bloqueado hasta calcular el envío. El botón también queda bloqueado si el usuario no seleccionó color.

---

## Galería de colores

**Archivo:** `assets/js/color-gallery.js`  
**Activado en:** páginas de producto variable con atributo `pa_color-ral`

Al seleccionar un color en el dropdown de variaciones, el gallery de WooCommerce filtra las imágenes:
- **Sin color seleccionado:** se muestran todas las imágenes.
- **Con color seleccionado:** solo se muestran las imágenes de ese color; las del resto de variaciones y las genéricas del producto se ocultan.

### Cómo funciona

El PHP inyecta `data-sc-image-id` en cada slide/thumbnail del gallery. Al cargar la página, el JS construye un mapa `colorSlug → [imageIds]` y lo usa para mostrar/ocultar slides y thumbnails.

### Agregar más imágenes por color

Por defecto cada variación tiene **una imagen** (la de la variación). Para agregar más imágenes a un color:

1. Subir las imágenes adicionales a la **galería del producto** (no a la imagen principal).
2. Editar cada imagen en la **Biblioteca de medios → campo "Texto alternativo"**.
3. Escribir el **slug exacto del color** (ej. `ral-9016-blanco`). El slug es el que aparece en la URL del atributo de WooCommerce.

---

## Lógica de cálculo

**Archivo:** `includes/shipping-quote-calc.php`

### Reglas de carga (camiones)

Capacidad por camión: **4 unidades**
- Contenedor 40' = 4 unidades (camión completo solo)
- Contenedor 20' = 2 unidades
- Contenedor 10' = 1 unidad

Los 40' siempre van solos. Los 20' y 10' se combinan hasta llenar cada camión.

### Reglas de precio (con descarga)

- **Camión 1:** se cobra por contenedor individual (`p_c20` o `p_c40`). Si el camión está lleno (4u) sin 40', se aplica tarifa equivalente a 40'.
- **Camiones 2+:** se cobra `p_sin + p_extra_truck`.
- Si `descarga_modo = camion`: todos los camiones al precio sin descarga.

### Estimación de días

| Kilómetros | Plazo |
|-----------|-------|
| ≤ 100 km | 2 días hábiles |
| ≤ 300 km | 3 días hábiles |
| ≤ 600 km | 4 días hábiles |
| > 600 km | 5 días hábiles |

---

## Sesión y persistencia

Los datos de envío elegidos se guardan en `WC()->session` con clave `silversea_shipping_data`:

```php
[
    'method'      => 'delivery' | 'pickup',
    'origin'      => 'barcelona' | 'madrid' | ...,
    'postal_code' => '28001',
    'transport'   => 'sin' | 'con',
    'pickup_city' => 'bilbao' | ...,
    'price'       => 786.60,
    'detail'      => 'string de resumen',
    'trucks'      => 2,
    'days'        => 5,
]
```

Las preferencias de UI (ciudad, CP, transporte) también se guardan en cookie `sc_prefs` (365 días) para pre-rellenar el widget en visitas futuras, **sin** disparar el cálculo automático.

---

## CPT silversea_quote

Post type privado `silversea_quote`. Cada cotización enviada genera un registro.

### Meta fields

| Meta key | Contenido |
|----------|-----------|
| `_sq_name` | Nombre del cliente |
| `_sq_email` | Email |
| `_sq_phone` | Teléfono (prefijo + número) |
| `_sq_client_type` | `particular` \| `empresa` |
| `_sq_city` | Ciudad del cliente |
| `_sq_postal` | CP del cliente |
| `_sq_message` | Mensaje libre |
| `_sq_products` | JSON: `[{name, product_id, condition, qty, addons, color, price, city}]` |
| `_sq_shipping_method` | `delivery` \| `pickup` |
| `_sq_shipping_origin` | Ciudad de origen del envío |
| `_sq_shipping_cp` | CP de destino |
| `_sq_shipping_transport` | `sin` \| `con` |
| `_sq_shipping_pickup` | Ciudad de retiro |
| `_sq_shipping_price` | Precio total de envío |
| `_sq_shipping_trucks` | Número de camiones |
| `_sq_shipping_days` | Días de entrega estimados |
| `_sq_shipping_breakdown` | JSON del desglose por camión |
| `_sq_email_body_sales` | HTML del email enviado a ventas |
| `_sq_email_body_client` | HTML del email enviado al cliente |
| `_sq_sf_payload` | Array del payload enviado a Salesforce |
| `_sq_sf_status` | `success` \| `error` |
| `_sq_sf_response_code` | Código HTTP de la respuesta (200 / 302) |
| `_sq_sf_sent_at` | Timestamp del envío |
| `_sq_sf_error` | Mensaje de error (si falló) |

---

## Emails

**Hook:** `ywraq_process`  
**Función:** `silversea_process_and_save()`

Al enviar el formulario de cotización se disparan:

1. **Email a ventas** → dirección configurada en `silversea_sales_email`. Si el campo está vacío, **no se envía** — la cotización queda guardada en el panel para enviarla manualmente con el botón "Reenviar". En modo demo, se redirige al email de debug.
2. **Email al cliente** → dirección ingresada en el formulario. Solo si `silversea_email_send_client = 1` **y** hay email de ventas configurado.

Ambos emails se guardan como meta del CPT y se pueden **editar y reenviar** desde el panel de cada cotización.

---

## Integración Salesforce

**Archivo:** `includes/salesforce.php`  
**Endpoint:** `https://webto.salesforce.com/servlet/servlet.WebToLead`  
**OID:** `00D8a000002A8Hp`

### Activación

Se ejecuta automáticamente al procesar cada cotización (`ywraq_process`), después de guardar el CPT y enviar los emails.

### Campos enviados (Web-to-Lead)

| Campo Salesforce | Valor |
|-----------------|-------|
| `lead_source` | `Web` (fijo) |
| `00N8a00000FXdRj` — Market | `Europe` (fijo) |
| `00N8a00000FXdRt` — Modality | `Buy` (fijo) |
| `00N8a00000FXdRZ` — ContainerType | Ver lógica abajo |
| `00N8a00000FXdRo` — Quantity | Ver lógica abajo |
| `first_name` / `last_name` | Del formulario. Para empresas: `last_name = nombre de empresa` |
| `company` | Solo si el cliente es empresa |
| `email`, `phone`, `city`, `zip` | Del formulario |
| `description` | Detalle completo del carrito (ver formato abajo) |

### Lógica ContainerType / Quantity

```
Si el carrito tiene 1 solo tipo de contenedor:
    ContainerType = tipo del item (del mapeo)
    Quantity      = cantidad del item

Si el carrito tiene 2 o más tipos diferentes:
    ContainerType = vacío
    Quantity      = suma total de unidades

Description = SIEMPRE:
    "Pedido del cotizador:
     - 40' High Cube x3
     - 20' Dry Van x2

     Mensaje del cliente: [texto]"
```

### Mapeo Producto → ContainerType

Se configura desde **Cotizador → Salesforce** (página de mapeo en lote).

| Producto en la web | ContainerType en Salesforce |
|--------------------|----------------------------|
| 20' Pies Estándar (nuevo, usado, RAL 9003, RAL 9010) | `20' Dry Van` |
| 40' Pies Estándar (nuevo, usado) | `40' Dry Van` |
| 10' Pies | `10' Dry Van` |
| 20' Pies High Cube (nuevo, usado) | `20' High Cube` |
| 40' Pies High Cube (nuevo, usado, todos los RAL) | `40' High Cube` |
| 40' Pies High Cube NOR | `40' High Cube NOR` |
| 40' Pies NOR | `40' NOR` |
| 20' Pies Refrigerado (nuevo, usado) | `20' Reefer` |
| 40' Pies Refrigerado (nuevo, usado) | `40' Reefer` |
| 20' Pies Open Top (nuevo, usado) | `20' Open Top` |
| 40' Pies Open Top (nuevo, usado, RAL 5010, RAL 5013) | `40' Open Top` |
| 20' Pies Doble Puerta (todos los RAL, EOS-1015) | `20' Double Door` |
| 40' Pies High Cube Doble Puerta (todos los RAL) | `40' HC Double Door` |
| 40' HC Full Open Side | `40' HC Open Side` |
| 40' HC Open Side 4 Doors | `40' HC Open Side 4 Doors` |

> El mapeo se guarda como post meta `silversea_sf_container_type` en el producto padre. Las variaciones (colores, condición) heredan el tipo del padre automáticamente.

### Panel Salesforce en cada cotización

Cada registro del CPT `silversea_quote` muestra un panel lateral "☁️ Salesforce" con:
- Estado: ✓ Enviado (verde) o ✗ Error (rojo) con el código HTTP
- Fecha y hora del envío
- Datos enviados (desplegable)
- Botón **Re-enviar** (o **Enviar** para cotizaciones anteriores a la integración)

Para cotizaciones anteriores a la integración, el payload se reconstruye automáticamente desde los meta guardados (`_sq_name`, `_sq_email`, `_sq_products`, etc.).

---

## Shortcodes de la página "Mi selección"

**Archivo:** `includes/shipping-quote-pages.php`

### `[silversea_quote_view form_page='slug']`
Widget completo para la página de "Mi selección". Muestra resumen de envío, extras WAPO y widget cotizador inline.

### `[silversea_quote_form]`
Resumen de envío dentro de la página del formulario.

### `[silversea_quote_thanks]`
Resumen en la página de confirmación post-envío.

---

## Extras (YITH WAPO)

Los productos pueden tener opciones adicionales definidas en YITH Product Add-Ons. El widget los muestra como tarjetas visuales sincronizadas con los inputs nativos de WAPO.

---

## Herramientas admin de productos

### € Precios (`Cotizador → € Precios`)

Editor masivo de precios para todos los productos y sus variaciones. Los productos variables se expanden/colapsan para editar cada variación individualmente.

### ↕ Ordenar (`Cotizador → ↕ Ordenar`)

Reordenador drag & drop por categoría o búsqueda. Modifica el campo `menu_order`, que Elementor usa cuando el orden está configurado como "Orden del menú".

### Salesforce (`Cotizador → Salesforce`)

Tabla de todos los productos con un dropdown de ContainerType por fila. Las filas modificadas se resaltan en amarillo. El botón **Guardar mapeo** actualiza todos los productos a la vez vía AJAX.

### ⬇ Exportar contenedores

Botón en la cabecera de **Cotizador → Configuración**. Descarga un CSV con los siguientes campos para todos los productos (publicados, borradores y privados):

| Columna | Fuente |
|---------|--------|
| Contenedor | Título del producto |
| SKU | SKU |
| Colores RAL | Términos de `pa_color-ral` (todos los asignados) |
| Precio | Regular price (simple) o rango min–max (variable) |
| Categoría Salesforce | Meta `silversea_sf_container_type` |
| Tamaño | Términos de `pa_tamano` |
| Condición | Términos de `pa_condicion` |
| Tipo | Categorías `para-transporte` y/o `para-almacenaje` |
| Estado | Publicado / Borrador / Privado / Pendiente |
| Orden | `menu_order` |

> El archivo usa separador `;` y BOM UTF-8 para apertura directa en Excel (España).

---

## Flujo completo del usuario

```
1. Usuario navega el catálogo
   ↓
2. En la ficha de producto:
   - Selecciona color RAL (dropdown)
   - Ajusta cantidad
   - Cotiza el envío (opcional si silversea_require_quote=0)
   - Hace clic en "Añadir a selección" (YITH RAQ)
   ↓
3. Accede a "Mi selección" → [silversea_quote_view]
   ↓
4. Cotiza el envío consolidado o por producto
   ↓
5. Pasa al formulario de cotización → [yith_ywraq_request_quote]
   ↓
6. Completa datos y envía
   ↓
7. hook ywraq_process → silversea_process_and_save():
   - Crea CPT silversea_quote
   - Envía email a ventas (si silversea_sales_email configurado)
   - Envía email al cliente (si silversea_email_send_client=1)
   - Envía lead a Salesforce Web-to-Lead (salvo en modo demo)
   ↓
8. Redirige a página de gracias → [silversea_quote_thanks]
```

---

## Modo demo

Activar en: Cotizador → Configuración → ☑ Modo demo

En modo demo:
- No consulta la BD de tarifas, usa los precios configurados
- El destino se muestra como `"CP XXXXX (DEMO)"`
- Todos los emails se envían a `silversea_admin_email`
- **No se envían leads a Salesforce** automáticamente (el panel marca la cotización como "No enviado (demo)"). El botón "Enviar a Salesforce" del panel sí funciona manualmente.

**Desactivar antes de salir a producción.**

---

## FTP / Despliegue

Servidor: FTP Silversea (credenciales en FileZilla)  
Ruta remota: `public_html/wp-content/plugins/silversea/`

### Versionado de assets (cache-busting)

Todos los CSS/JS se encolan con la constante **`SILVERSEA_VERSION`** (definida en `includes/shipping-calculator.php`). Al modificar cualquier archivo de `assets/`, **subí ese número** y los visitantes reciben la versión nueva sin depender de la caché. No hace falta tocar cada `wp_enqueue_*` por separado.

### Archivos que se modifican con frecuencia

| Archivo | Descripción |
|---------|-------------|
| `silversea.php` | Bootstrap, banderas, form Salesforce de Elementor |
| `cotizador.php` | Shortcodes, menús Precios/Ordenar, columna SKU, color gallery (PHP) |
| `includes/shipping-calculator.php` | Widget PHP, AJAX, menú admin, exportación, `SILVERSEA_VERSION` |
| `includes/shipping-quote-calc.php` | Lógica de cálculo, helpers de ciudades |
| `includes/shipping-session.php` | Sesión, CPT, emails |
| `includes/shipping-quote-pages.php` | Shortcodes página selección |
| `includes/salesforce.php` | Integración Salesforce completa |
| `includes/texts.php` | Textos editables al cliente + página admin "📝 Textos" |
| `assets/js/shipping-calculator.js` | Widget JS frontend |
| `assets/js/color-gallery.js` | Filtro de galería por color |
| `assets/css/shipping-calculator.css` | Estilos widget |
| `assets/css/silversea-raq.css` | Estilos página selección |

> Después de subir cambios en JS o CSS, limpiar caché de WP (WP Rocket, etc.).

### Checklist antes de poner en producción

- [ ] `silversea_demo_mode` = **desactivado**
- [ ] `silversea_sales_email` = email de ventas real configurado
- [ ] Cotizador → Salesforce: todos los productos mapeados
- [ ] Cotizador → Ciudades: modos delivery/pickup configurados correctamente
- [ ] Tarifas importadas para todas las ciudades de entrega
