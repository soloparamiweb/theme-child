# Propuesta de fixes para Telegram & AI Content Curator Pro

## 1) Evitar enlaces repetidos en el mismo texto
En `includes/auto-linker.php`, en `TTW_Auto_Linker::add_links()`, guarda los términos ya enlazados y aplica `preg_replace(..., 1)` para enlazar solo la primera ocurrencia global por término.

## 2) Color azul en enlaces
En `telegram-to-wp-pro.php`, dentro de `ttw_read_more_css()`, añade:

```css
.ttw-content a,
.activity-inner a,
.entry-content a {
  color: #0b57d0 !important;
  text-decoration: underline;
}
```

## 3) No usar imagen de autor cuando no hay carrusel
En `includes/class-telegram-handler.php`, dentro de `scrape_linkedin_images()`:

- Filtra estrictamente imágenes de post (por ejemplo `feedshare`, `carousel`, `article`), y descarta cualquier URL de avatar/profile.
- Si no hay imágenes reales de post, deja:
  - `main = ''`
  - `carousel = []`

Con eso nunca se sube avatar cuando no hay carrusel.

## 4) Fallos al descargar imágenes del carrusel
En `includes/class-wp-integrator.php`, dentro de `attach_carousel_images()`:

- Incrementa timeout de `download_url($url, 15)` a `download_url($url, 30)`.
- Añade logging cuando falle `download_url` o `media_handle_sideload` para diagnosticar CDN hotlink/referrer.

## 5) Carrusel horizontal real (no imágenes apiladas)
`wp_kses` en BuddyPress elimina `<figure>`, por eso se rompe el layout.

- Cambia el HTML del carrusel para usar solo etiquetas permitidas (`div` + `img`).
- En CSS usa flex horizontal con scroll:

```css
.ttw-gallery-row {
  display: flex;
  gap: 12px;
  overflow-x: auto;
  padding-bottom: 6px;
}
.ttw-gallery-row .ttw-gallery-item {
  flex: 0 0 auto;
  width: min(320px, 80vw);
}
.ttw-gallery-row img {
  width: 100%;
  height: auto;
  border-radius: 8px;
  display: block;
}
```

## 6) Añadir enlace adicional de “texto completo”
En `includes/class-ai-processor.php`, ya se extraen `extra_links`; mantenlo y añade etiqueta semántica al pie:

- “Fuente original (LinkedIn)”
- “Lectura completa / enlace adicional” (si existe)

## 7) Mostrar vídeo embebido en vez de captura
En `includes/class-telegram-handler.php` extrae URLs de vídeo (YouTube, Vimeo, mp4).
Luego en `includes/class-wp-integrator.php`:

- Blog: `wp_oembed_get($video_url)` o `<video controls>` si mp4.
- BuddyPress: incluir embed permitido por `wp_kses` (si no, añadir `iframe` a `BP_ALLOWED` de forma controlada).

## Notas extra detectadas
- El option name `ttw_openai_key` realmente guarda la clave de Anthropic; conviene renombrar a `ttw_anthropic_key` para evitar confusión futura.
- Hay múltiples definiciones de `TTW_Group_Mapper` por compatibilidad; mejor centralizar en un único archivo y mantener guards `class_exists`.
