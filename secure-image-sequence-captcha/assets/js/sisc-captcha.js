/**
 * Secure Image Sequence CAPTCHA Frontend Script
 */
document.addEventListener('DOMContentLoaded', function () {
    // Selecciona TODOS los contenedores CAPTCHA en la página (puede haber más de uno si hay múltiples formularios)
    const captchaContainers = document.querySelectorAll('.sisc-captcha-container');

    captchaContainers.forEach(container => {
        const imageArea = container.querySelector('.sisc-image-selection-area');
        const hiddenSequenceInput = container.querySelector('input[name="sisc_user_sequence"]');
        let userSequence = []; // Array para almacenar los IDs temporales clicados para este contenedor

        if (!imageArea || !hiddenSequenceInput) {
            console.error('SISC CAPTCHA Error: Could not find image area or hidden input in container:', container);
            return; // Saltar este contenedor si falta algo esencial
        }

        imageArea.addEventListener('click', function (event) {
            // Asegurarse de que se hizo clic en una imagen CAPTCHA
            if (event.target.classList.contains('sisc-captcha-image')) {
                const clickedImage = event.target;
                const tempId = clickedImage.getAttribute('data-sisc-id');

                if (!tempId) {
                    console.error('SISC CAPTCHA Error: Clicked image is missing data-sisc-id attribute.');
                    return;
                }

                // Añadir al array de secuencia
                userSequence.push(tempId);

                // Actualizar el campo oculto (unir con comas)
                hiddenSequenceInput.value = userSequence.join(',');

                // Añadir feedback visual (clase 'sisc-selected')
                // Prevenir doble selección visual si se hace clic rápido (opcional)
                if (!clickedImage.classList.contains('sisc-selected')) {
                     clickedImage.classList.add('sisc-selected');

                     // Opcional: Deshabilitar clic en la misma imagen otra vez?
                     // clickedImage.style.pointerEvents = 'none';
                }

                 // Opcional: ¿Limitar número de clics al tamaño de la secuencia esperada?
                 // const expectedLength = ???; // Necesitaríamos pasar esto desde PHP si queremos limitar
                 // if (userSequence.length >= expectedLength) {
                 //    // Deshabilitar clics en otras imágenes?
                 // }

                 // Opcional: Mostrar secuencia al usuario (para depuración o feedback)
                 // const feedbackEl = container.querySelector('.sisc-feedback-sequence'); // Necesitaría un <p> o <span>
                 // if (feedbackEl) feedbackEl.textContent = 'Selected: ' + userSequence.join(', ');
            }
        });

         // Opcional: Añadir botón de resetear selección?
         // const resetButton = container.querySelector('.sisc-reset-button');
         // if (resetButton) {
         //    resetButton.addEventListener('click', function() {
         //       userSequence = [];
         //       hiddenSequenceInput.value = '';
         //       imageArea.querySelectorAll('.sisc-captcha-image.sisc-selected').forEach(img => {
         //          img.classList.remove('sisc-selected');
         //          // img.style.pointerEvents = 'auto'; // Reactivar si se deshabilitó
         //       });
         //        // Limpiar feedback si existe
         //    });
         // }
    });
});
