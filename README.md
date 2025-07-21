
# Secure Image Sequence CAPTCHA

[![License: GPL v2 or later](https://img.shields.io/badge/License-GPL%20v2%20or%20later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Stable tag](https://img.shields.io/badge/Stable%20tag-1.5.0-brightgreen.svg)](https://github.com/Soyunomas/Secure-Image-Sequence-CAPTCHA/releases/tag/1.5.0)
[![Requires WordPress](https://img.shields.io/badge/Requires%20WordPress-5.8+-blue.svg)](https://wordpress.org/download/)
[![Tested up to WordPress](https://img.shields.io/badge/Tested%20up%20to%20WordPress-6.8-blue.svg)](https://wordpress.org/download/)
[![Requires PHP](https://img.shields.io/badge/Requires%20PHP-7.4+-blue.svg)](https://www.php.net/releases/)

Protege tus formularios de comentarios, inicio de sesión y registro de WordPress contra bots con un CAPTCHA de secuencia de imágenes seguro, intuitivo y reforzado contra ataques de fuerza bruta.

---

## Descripción

Secure Image Sequence CAPTCHA mejora la seguridad de tu sitio web añadiendo una defensa de varias capas. La primera línea es un desafío CAPTCHA intuitivo donde los usuarios hacen clic en imágenes en el orden correcto. La segunda, y más importante, es una robusta **protección contra ataques de fuerza bruta** que bloquea las IPs de los atacantes.

Este plugin ha sido diseñado con una mentalidad de **seguridad primero**, priorizando la protección del acceso a tu sitio sobre todo lo demás.

### Funcionalidades Clave de Seguridad

*   🛡️ **Protección contra Fuerza Bruta (Login Lockdown):** Bloquea temporalmente las direcciones IP que intentan repetidamente iniciar sesión sin éxito, neutralizando los ataques de diccionario y de adivinación de contraseñas.
*   🕵️ **Detección de IP Segura:** La lógica de bloqueo de IP ha sido reforzada para prevenir que los atacantes la eludan falsificando cabeceras HTTP (IP Spoofing), asegurando que la protección funcione incluso detrás de un proxy o CDN como Cloudflare.
*   🔒 **Soluciones Hasheadas:** Las secuencias correctas del CAPTCHA se almacenan como hashes criptográficos en la base de datos, no en texto plano. Incluso si la base de datos se viera comprometida por otra vía, las soluciones del CAPTCHA permanecerían seguras.
*   🔐 **IDs Temporales:** Cada imagen del CAPTCHA utiliza un ID temporal único y criptográficamente seguro para cada desafío, haciendo imposible predecir o reutilizar soluciones.
*   🚫 **Defensa CSRF y Anti-Repetición:** Cada envío de CAPTCHA está protegido por un nonce de WordPress de un solo uso, vinculado a la instancia específica del desafío.

### Funcionalidades Generales

*   📝 **Protección Múltiple de Formularios:** Habilita el CAPTCHA y la protección contra fuerza bruta en los formularios de Comentarios, Inicio de Sesión (`wp-login.php`) y Registro.
*   🖼️ **Fuentes de Imágenes Flexibles:**
    *   **Imágenes Personalizadas:** Sube tus propias imágenes a la Biblioteca de Medios y organízalas usando la taxonomía dedicada "Medios -> Categorías CAPTCHA".
    *   **Conjuntos Predefinidos:** Utiliza conjuntos de imágenes incorporados (como frutas, animales) para una configuración rápida y sencilla.
*   ⚙️ **Panel de Ajustes Completo:** Configura fácilmente dónde aparece el CAPTCHA, la fuente de las imágenes y los umbrales de la protección contra fuerza bruta (intentos fallidos y duración del bloqueo).
*   🌍 **Listo para Internacionalización:** Todas las cadenas de texto del plugin son traducibles.

---

## Instalación

1.  **Descarga:** Descarga el archivo `.zip` de la última versión (`1.5.0` o superior) desde la [página de Releases](https://github.com/Soyunomas/Secure-Image-Sequence-CAPTCHA/releases).
2.  **Admin de WordPress:**
    *   En tu panel de administración, ve a `Plugins` > `Añadir nuevo`.
    *   Haz clic en `Subir plugin`.
    *   Selecciona el archivo ZIP descargado y haz clic en `Instalar ahora`.
    *   Activa el plugin.
3.  **FTP:**
    *   Descomprime el archivo `.zip`.
    *   Sube la carpeta `secure-image-sequence-captcha` al directorio `/wp-content/plugins/` en tu servidor.
    *   Activa el plugin a través del menú `Plugins` en WordPress.

### Mejores Prácticas de Seguridad
Para una seguridad óptima, recomendamos establecer los permisos de archivos de tu instalación de WordPress según la [guía oficial de Hardening WordPress](https://wordpress.org/support/article/hardening-wordpress/). Los directorios deben ser `755` y los archivos `.php` `644`.

---

## Configuración y Uso

1.  Ve a `Ajustes` > `Image Sequence CAPTCHA` en tu panel de administración.
2.  **Habilitar CAPTCHA:** Marca las casillas para los formularios que deseas proteger.
3.  **Configurar Login Lockdown:**
    *   Marca "Enable Lockdown" para activar la protección contra fuerza bruta (¡altamente recomendado!).
    *   Define el "Umbral de Intentos Fallidos" (cuántos intentos antes de bloquear una IP).
    *   Establece la "Duración del Bloqueo" en minutos.
    *   Selecciona la "Fuente de IP del Cliente". Utiliza la herramienta de diagnóstico para elegir la opción correcta para tu servidor, especialmente si usas Cloudflare u otro proxy.
4.  **Seleccionar Fuente de Imágenes:** Elige entre usar tus propias imágenes personalizadas o los conjuntos predefinidos.
5.  Haz clic en `Guardar cambios`.

---

## Preguntas Frecuentes (FAQ)

### ¿Cómo uso mis propias imágenes (Imágenes Personalizadas)?

1.  Ve a `Medios` > `Biblioteca` y sube tus imágenes. Usa títulos descriptivos (ej., "Manzana", "Plátano", "Coche").
2.  Ve a `Medios` > `Categorías CAPTCHA` y crea una o más categorías (ej., "Frutas", "Vehículos").
3.  Vuelve a `Medios` > `Biblioteca` (se recomienda la vista de lista) y asigna tus imágenes a la Categoría CAPTCHA apropiada.
4.  Asegúrate de que cada categoría que quieras usar tenga **al menos 6 imágenes**.
5.  Ve a `Ajustes` > `Image Sequence CAPTCHA` y selecciona `Imágenes Personalizadas`.

### ¿Cuántas imágenes necesito por categoría o conjunto?

Se requiere un mínimo de **6 imágenes** por categoría o conjunto. Esto permite al plugin seleccionar 3 imágenes para la secuencia correcta y 3 imágenes distractoras para cada desafío.

---

## Capturas de Pantalla

<div align="center">
<table>
  <tr>
    <td align="center">
      <b>CAPTCHA y Protección contra Fuerza Bruta</b><br>
      <img src="images/screenshot-1.png" alt="Página de ajustes mostrando las opciones de CAPTCHA y Lockdown" width="400">
    </td>
    <td align="center">
      <b>Diagnóstico de IP y Fuentes de Imágenes</b><br>
      <img src="images/screenshot-2.png" alt="Ajustes de fuente de imágenes y herramienta de diagnóstico de IP" width="400">
    </td>
  </tr>
  <tr>
    <td align="center">
      <b>Ejemplo en el Formulario de Inicio de Sesión</b><br>
      <img src="images/screenshot-3.png" alt="El CAPTCHA funcionando en el formulario wp-login.php" width="400">
    </td>
    <td align="center">
      <b>Gestión de Categorías CAPTCHA</b><br>
      <img src="images/screenshot-4.png" alt="Pantalla de administración para las categorías de imágenes del CAPTCHA" width="400">
    </td>
  </tr>
</table>
</div>

---

## Contribuciones y Seguridad

¡Las contribuciones son bienvenidas! Si tienes una solicitud de función, por favor abre un [issue](https://github.com/Soyunomas/Secure-Image-Sequence-CAPTCHA/issues). Para contribuir con código, por favor abre un [pull request](https://github.com/Soyunomas/Secure-Image-Sequence-CAPTCHA/pulls).

### Política de Seguridad
Nos tomamos la seguridad muy en serio. Si descubres una vulnerabilidad de seguridad, por favor **divúlgala de forma responsable** enviando un correo electrónico a `tu-email-privado-aqui@example.com`. Todas las vulnerabilidades de seguridad serán abordadas con prontitud. Te pedimos amablemente que no reveles el problema públicamente hasta que hayamos tenido la oportunidad de lanzar un parche.

---

## Changelog (Historial de Cambios)

### 1.5.0 (Lanzamiento de Seguridad Mayor - Actual y Recomendada)
*   **CARACTERÍSTICA DE SEGURIDAD MAYOR:** Reintroducida y mejorada la funcionalidad de **Login Lockdown** para proporcionar una protección robusta contra ataques de fuerza bruta.
*   **FORTALECIMIENTO DE SEGURIDAD:** La lógica de detección de IP ha sido reescrita para prevenir el bypass del bloqueo mediante la falsificación de cabeceras HTTP (IP Spoofing), garantizando la protección detrás de proxies y CDNs.
*   **FORTALECIMIENTO DE SEGURIDAD:** Las soluciones del CAPTCHA ahora se almacenan como hashes en la base de datos (`wp_hash`), eliminando el almacenamiento de la respuesta correcta en texto plano.
*   **CORRECCIÓN DE SEGURIDAD:** Corregida una vulnerabilidad de fuga de información (Information Disclosure) que exponía públicamente los nombres de las categorías del CAPTCHA a través de la API REST. El endpoint ha sido deshabilitado por defecto.
*   **MEJORA DE UX:** Añadida una herramienta de diagnóstico de IP en la página de ajustes para ayudar a los administradores a seleccionar la fuente de IP correcta para su entorno.
*   **MEJORA:** Añadida una rutina de limpieza completa en la desinstalación para eliminar los términos de la taxonomía y no dejar datos huérfanos.
*   **MEJORA:** Añadidos archivos `index.php` en todos los directorios para prevenir el listado de directorios en servidores mal configurados.

*(El historial de versiones anteriores 1.4.0 a 1.1.0 se mantiene igual que antes)*

### 1.4.0
*   **Corrección de Estabilidad:** Solucionado un fallo que podía bloquear a los administradores fuera de su propio sitio si una categoría de imágenes personalizada no tenía el número mínimo de imágenes requerido.
*   ... (resto del changelog de la 1.4.0)

...

---

## Licencia

GPL v2 or later

---
