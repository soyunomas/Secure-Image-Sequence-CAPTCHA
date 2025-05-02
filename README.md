# Secure Image Sequence CAPTCHA

[![License: GPL v2 or later](https://img.shields.io/badge/License-GPL%20v2%20or%20later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Stable tag](https://img.shields.io/badge/Stable%20tag-1.3.1-brightgreen.svg)](https://github.com/Soyunomas/Secure-Image-Sequence-CAPTCHA/releases/tag/1.3.1)
[![Requires WordPress](https://img.shields.io/badge/Requires%20WordPress-5.8+-blue.svg)](https://wordpress.org/download/)
[![Tested up to WordPress](https://img.shields.io/badge/Tested%20up%20to%20WordPress-6.8-blue.svg)](https://wordpress.org/download/)
[![Requires PHP](https://img.shields.io/badge/Requires%20PHP-7.4+-blue.svg)](https://www.php.net/releases/)

Protege tus formularios de comentarios, inicio de sesión y registro de WordPress contra bots con un CAPTCHA de secuencia de imágenes seguro y fácil de usar.

---

## Descripción

Secure Image Sequence CAPTCHA mejora la seguridad de tu sitio web añadiendo un desafío CAPTCHA intuitivo a tus formularios. En lugar de descifrar texto difícil de leer, los usuarios simplemente hacen clic en una serie de imágenes en el orden correcto según las instrucciones. Este plugin se centra en una seguridad robusta y la facilidad de uso.

**Características Clave de Seguridad:**

*   🔐 **IDs Temporales:** Cada imagen mostrada utiliza un ID temporal único y criptográficamente seguro para ese desafío específico.
*   🛡️ **Nonces:** Cada envío de CAPTCHA está protegido por un nonce de WordPress vinculado a la instancia específica del desafío, previniendo ataques CSRF y de repetición.
*   ⏱️ **Transitorios:** Los datos del desafío (como la secuencia correcta) se almacenan de forma segura en transitorios de WordPress de corta duración y se eliminan inmediatamente después de la validación.
*   ✔️ **Validación Segura:** La validación ocurre en el lado del servidor, comparando la secuencia de IDs temporales enviada con la secuencia correcta almacenada.
*   🚫 **Sin Texto Plano:** Las respuestas correctas nunca se exponen en el código fuente HTML del frontend.

**Funcionalidades Clave:**

*   📝 **Protección Múltiple de Formularios:** Habilita el CAPTCHA en Comentarios, Formulario de Inicio de Sesión (`wp-login.php`) y Formulario de Registro.
*   🖼️ **Fuentes de Imágenes Flexibles:**
    *   **Imágenes Personalizadas:** Sube tus propias imágenes a la Biblioteca de Medios y organízalas usando la taxonomía dedicada "Medios -> Categorías CAPTCHA".
    *   **Conjuntos Predefinidos:** Utiliza conjuntos de imágenes incorporados (como frutas, animales) incluidos directamente en la carpeta `/images` del plugin para una configuración rápida (debes proporcionar estas imágenes).
*   ⚙️ **Página de Ajustes de Admin:** Configura fácilmente dónde aparece el CAPTCHA y selecciona la fuente de imágenes en "Ajustes -> Image Sequence CAPTCHA".
*   📊 **Contador de Admin Corregido:** Corrige el error visual donde la columna "Cantidad" para la taxonomía Categorías CAPTCHA mostraba incorrectamente '0' para los adjuntos, mostrando el recuento correcto en su lugar.
*   🌍 **Listo para Internacionalización:** Las cadenas de texto del plugin son traducibles (requiere generación de archivo `.pot` y archivos `.po`/`.mo`).

Este plugin proporciona una alternativa sólida a los CAPTCHAs tradicionales basados en texto, ofreciendo un equilibrio entre seguridad y experiencia de usuario.

---

## Instalación

1.  **Descarga:** Descarga el archivo `.zip` de la última versión desde la [página de Releases](https://github.com/Soyunomas/Secure-Image-Sequence-CAPTCHA/releases).
2.  **Admin de WordPress:**
    *   En tu panel de administración de WordPress, ve a `Plugins` > `Añadir nuevo`.
    *   Haz clic en `Subir plugin`.
    *   Selecciona el archivo ZIP descargado (`secure-image-sequence-captcha.zip`) y haz clic en `Instalar ahora`.
    *   Activa el plugin a través del menú `Plugins`.
3.  **FTP:**
    *   Descomprime el archivo descargado.
    *   Sube la carpeta completa `secure-image-sequence-captcha` al directorio `/wp-content/plugins/` en tu servidor.
    *   Activa el plugin a través del menú `Plugins` en WordPress.

---

## Configuración y Uso

1.  Ve a `Ajustes` > `Image Sequence CAPTCHA` en tu panel de administración de WordPress.
2.  **Habilitar CAPTCHA en Formularios:** Marca las casillas para `Formulario de comentarios`, `Formulario de inicio de sesión` y/o `Formulario de registro` donde quieras que aparezca el CAPTCHA.
3.  **Seleccionar Fuente de Imágenes:**
    *   **Imágenes Personalizadas (Biblioteca de Medios y Categorías CAPTCHA):**
        *   Elige esta opción si quieres usar tus propias imágenes subidas.
        *   **Debes** crear categorías en `Medios` > `Categorías CAPTCHA`.
        *   **Debes** subir imágenes a la `Biblioteca de Medios` y asignarlas a tus Categorías CAPTCHA creadas.
        *   Cada categoría necesita **al menos 6 imágenes** asignadas. Los títulos de las imágenes se usarán para la pregunta de la secuencia.
    *   **Conjuntos de Imágenes Predefinidos (Incluidos con el Plugin):**
        *   Elige esta opción para un inicio rápido usando conjuntos de imágenes incluidos con el plugin.
        *   Esto requiere que tú (el desarrollador/mantenedor del plugin) coloques conjuntos de imágenes dentro de la carpeta `images/` del plugin.
        *   Estructura: `secure-image-sequence-captcha/images/nombre_set/imagen.jpg` (ej., `images/fruits/apple.png`, `images/animals/cat.jpg`).
        *   Cada subcarpeta `nombre_set` necesita **al menos 6 imágenes** (`.jpg`, `.jpeg`, `.png`, `.gif`, `.webp`).
        *   El nombre del archivo de imagen (sin extensión, con espacios en lugar de guiones/barras bajas) se usará para la pregunta de la secuencia.
4.  Haz clic en `Guardar cambios`.

El CAPTCHA aparecerá ahora en los formularios seleccionados usando la fuente de imágenes elegida.

---

## Preguntas Frecuentes (FAQ)

### ¿Cómo uso mis propias imágenes (Imágenes Personalizadas)?

1.  Ve a `Medios` > `Biblioteca` y sube tus imágenes. Usa títulos descriptivos (ej., "Manzana", "Plátano", "Coche").
2.  Ve a `Medios` > `Categorías CAPTCHA` y crea una o más categorías (ej., "Frutas", "Vehículos").
3.  Vuelve a `Medios` > `Biblioteca`, cambia a vista de Lista si es necesario, y asigna tus imágenes subidas a la Categoría CAPTCHA apropiada.
4.  Asegúrate de que cada categoría que quieras usar tenga **al menos 6 imágenes** asignadas.
5.  Ve a `Ajustes` > `Image Sequence CAPTCHA` y selecciona `Imágenes Personalizadas` como `Fuente de Imágenes`.

### ¿Cómo uso los Conjuntos de Imágenes Predefinidos?

1.  Esto requiere que el paquete del plugin contenga las imágenes. Crea una carpeta `images` en la raíz del plugin (`wp-content/plugins/secure-image-sequence-captcha/images/`).
2.  Dentro de `images`, crea subcarpetas para cada conjunto (ej., `images/fruits/`, `images/animals/`).
3.  Coloca los archivos de imagen (`.jpg`, `.png`, `.gif`, `.webp`) dentro de estas subcarpetas.
4.  Asegúrate de que cada subcarpeta tenga **al menos 6 imágenes**.
5.  Ve a `Ajustes` > `Image Sequence CAPTCHA` y selecciona `Conjuntos de Imágenes Predefinidos` como `Fuente de Imágenes`.

### ¿Cuántas imágenes necesito por categoría o conjunto?

Se requiere un mínimo de **6 imágenes** por categoría personalizada o carpeta de conjunto predefinido.

### ¿Funciona el CAPTCHA si JavaScript está deshabilitado?

No. Se mostrará un mensaje indicando que JavaScript es necesario. El CAPTCHA no se puede resolver ni enviar correctamente sin JavaScript habilitado en el navegador del usuario.

### ¿Por qué la columna 'Cantidad' mostraba 0 en Medios > Categorías CAPTCHA?

Era un error visual en el núcleo de WordPress al contar adjuntos en taxonomías. Este plugin incluye una corrección, reemplazando la columna por defecto con una columna personalizada "Image Count" que muestra el número correcto de imágenes asociadas.

### Los mensajes de error de inicio de sesión revelan si un nombre de usuario existe. ¿Soluciona esto el plugin?

No. Los mensajes de error predeterminados de WordPress ("Contraseña incorrecta" vs. "Nombre de usuario desconocido") pueden usarse para la enumeración de usuarios. Este plugin protege contra ataques *automatizados* pero no modifica esos mensajes del núcleo. Se recomienda abordar este comportamiento del núcleo de WordPress por separado para mejorar la seguridad, por ejemplo, usando un filtro para mostrar siempre un mensaje de error de inicio de sesión genérico, sin importar si falló el usuario o la contraseña.

---

## Capturas de Pantalla

<div align="center">
<table>
  <tr>
    <td align="center">
      <b>Formulario de Comentarios</b><br>
      <img src="images/screenshot-1.png" alt="CAPTCHA en Comentarios" width="350">
    </td>
    <td align="center">
      <b>Formulario de Inicio de Sesión</b><br>
      <img src="images/screenshot-2.png" alt="CAPTCHA en Inicio de Sesión" width="350">
    </td>
  </tr>
  <tr>
    <td align="center">
      <b>Formulario de Registro</b><br>
      <img src="images/screenshot-3.png" alt="CAPTCHA en Registro" width="350">
    </td>
    <td align="center">
      <b>Página de Ajustes</b><br>
      <img src="images/screenshot-4.png" alt="Página de Ajustes del Plugin" width="350">
    </td>
  </tr>
</table>
</div>

---

## Contribuciones

¡Las contribuciones son bienvenidas! Si encuentras un error o tienes una solicitud de función, por favor abre un [issue](https://github.com/Soyunomas/Secure-Image-Sequence-CAPTCHA/issues). Si quieres contribuir con código, por favor abre un [pull request](https://github.com/Soyunomas/Secure-Image-Sequence-CAPTCHA/pulls).

---

## Changelog (Historial de Cambios)

### 1.3.1 (Actual)
*   Corrección: Mostrar correctamente el número de imágenes asociadas en la columna "Image Count" en la pantalla de admin de Categorías CAPTCHA.
*   Ajuste: Refinamientos menores de código.

### 1.3.0
*   Característica: Añadido soporte CAPTCHA para el formulario de Inicio de Sesión de WordPress.
*   Característica: Añadido soporte CAPTCHA para el formulario de Registro de WordPress.
*   Corrección: Implementada solución alternativa de redirección/transitorio para posible error fatal en fallo de envío de comentarios.
*   Mejora: Lógica de carga de assets mejorada para frontend y pantallas de login/registro.
*   Mejora: Añadidas clases CSS de contexto (`.sisc-context-*`) al contenedor CAPTCHA.

### 1.2.1
*   Corrección: Ajustado manejo de CSS usando `wp_add_inline_style` para dimensiones de imagen flexibles.
*   Actualización: Cambiada dimensión máxima de imagen por defecto a 75px mediante constante.

### 1.2.0
*   Característica: Añadida opción para usar Conjuntos de Imágenes Predefinidos incluidos con el plugin (estructura `images/setname/`).
*   Característica: Añadido ajuste "Fuente de Imágenes" (Personalizada vs. Predefinida).
*   Mejora: Lógica mejorada para encontrar conjuntos de imágenes predefinidos.
*   Mejora: Añadido soporte para imágenes `.webp` en conjuntos predefinidos.
*   Corrección: Movido CSS inline a archivo separado y encolado correctamente.

### 1.1.0
*   Versión inicial estable candidata con protección para formulario de Comentarios.
*   Implementada generación segura de CAPTCHA (IDs temporales, nonces, transitorios).
*   Añadida página de Ajustes y Taxonomía Personalizada.

---

## Licencia

Secure Image Sequence CAPTCHA está licenciado bajo [GPLv2 o posterior](https://www.gnu.org/licenses/gpl-2.0.html).
Consulta el archivo `license.txt` incluido con el plugin para ver el texto completo.
