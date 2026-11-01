/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

import Modal from '@typo3/backend/modal.js';

/**
 * The new-job form:
 *
 * - Shows the fields of the selected source type (`data-source-types`).
 * - Lets the editor pick a page or a file in TYPO3's element browser, shown in
 *   the backend modal. A pick is reported as an event on the modal's iframe
 *   (`useEvents` in the browser URL), which is the way FormEngine listens too;
 *   this form has no FormEngine.
 * - Immediate click feedback: disable the submit button and show a busy label
 *   while the form posts, so the editor sees the click registered even when the
 *   round-trip takes a moment, and a second click cannot queue the job twice.
 *
 * Loaded by JobController::newAction() as an ES module, not inline: the
 * backend Content Security Policy blocks inline scripts without a nonce.
 */

const ELEMENT_ADDED = 'typo3:elementBrowser:elementAdded';

/** The uid in a pick's value: "pages_12" from the page browser, "12" from the file browser. */
function uidOf(value) {
    const uid = String(value).split('_').pop();
    return /^\d+$/.test(uid) ? uid : '';
}

/** One picker block: the hidden input, the shown item, browse and remove buttons, the error line. */
function initPicker(block) {
    const input = block.querySelector('input[type="hidden"]');
    const item = block.querySelector('output');
    const browse = block.querySelector('[data-picker-browse]');
    const remove = block.querySelector('[data-picker-remove]');
    const error = block.querySelector('[data-picker-error]');
    if (!input || !item || !browse || !remove || !error) {
        return null;
    }

    const showError = (message) => {
        // textContent paths (not innerHTML): titles and XLF strings are no markup.
        error.textContent = message;
        error.classList.toggle('d-block', message !== '');
    };

    const show = (label) => {
        item.textContent = label || item.dataset.emptyLabel;
        item.classList.toggle('text-body-secondary', !label);
        remove.hidden = !label;
    };

    const pick = (uid, title) => {
        input.value = uid;
        show(`${title} [${uid}]`);
        showError('');
    };

    show(item.textContent.trim());

    browse.addEventListener('click', () => {
        const modal = Modal.advanced({
            type: Modal.types.iframe,
            content: browse.dataset.browserUrl,
            size: Modal.sizes.large,
        });
        modal.addEventListener('typo3:element-browser:message', (event) => {
            const { actionName, value, label } = event.detail;
            if (actionName !== ELEMENT_ADDED) {
                return;
            }
            const uid = uidOf(value);
            if (uid !== '') {
                pick(uid, label ?? '');
            }
            // One source per job: any pick ends the browsing.
            modal.hideModal();
            browse.focus();
        });
    });

    remove.addEventListener('click', () => {
        input.value = '';
        show('');
        browse.focus();
    });

    return {
        block,
        /** False when this picker's source type is selected and nothing is picked. */
        validate() {
            if (block.hidden || input.value !== '') {
                return true;
            }
            showError(block.dataset.requiredMessage ?? '');
            browse.focus();
            return false;
        },
    };
}

function initSourceFields(form) {
    const select = document.getElementById('nrrepurpose-sourceType');
    if (!select) {
        return [];
    }
    const blocks = form.querySelectorAll('[data-source-types]');
    const update = () => {
        blocks.forEach((block) => {
            block.hidden = !block.dataset.sourceTypes.split(' ').includes(select.value);
        });
    };
    select.addEventListener('change', update);
    update();

    return [...form.querySelectorAll('[data-source-picker]')].map(initPicker).filter(Boolean);
}

function initSubmitFeedback(form, button, status) {
    form.addEventListener('submit', (event) => {
        if (event.defaultPrevented) {
            return;
        }
        // Defer past the submit dispatch: disabling the triggering button
        // synchronously can cancel the submission in some browsers (Safari).
        setTimeout(() => {
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            // The spinner is decoration; the status region below announces the state.
            const spinner = document.createElement('span');
            spinner.className = 'spinner-border spinner-border-sm';
            spinner.setAttribute('aria-hidden', 'true');
            // textContent path (not innerHTML): the label is an overridable
            // XLF string and must never become an HTML injection point.
            button.replaceChildren(spinner, document.createTextNode(' ' + button.dataset.submittingLabel));
            if (status) {
                status.textContent = button.dataset.submittingLabel ?? '';
            }
        }, 0);
    });
}

function init() {
    const form = document.querySelector('form[name="newJob"]');
    const button = document.getElementById('nrrepurpose-submit');
    const status = document.getElementById('nrrepurpose-submit-status');
    if (!form || !button) {
        return;
    }
    const pickers = initSourceFields(form);
    // Registered before the feedback listener, which skips a submit stopped here.
    form.addEventListener('submit', (event) => {
        if (!pickers.every((picker) => picker.validate())) {
            event.preventDefault();
        }
    });
    initSubmitFeedback(form, button, status);
}

// The module may be evaluated before the form is parsed.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
