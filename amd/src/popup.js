/**
 * Handles the dedicated AI Course Assistant popup.
 *
 * @module local_courseaiassistant/popup
 */

/**
 * Initialise the popup interface.
 */
export const init = () => {
    const assistant = document.querySelector('.course-ai-fullpage-view');

    if (assistant && assistant.parentNode !== document.body) {
        document.body.appendChild(assistant);
    }
};
