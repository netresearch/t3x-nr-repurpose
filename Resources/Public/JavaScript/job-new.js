/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Immediate click feedback on the new-job form: disable the submit button and
 * show a busy label while the form posts, so the editor sees the click
 * registered even when the round-trip takes a moment, and a second click
 * cannot queue the job twice. No dependencies.
 *
 * Loaded by JobController::newAction() as an ES module, not inline: the
 * backend Content Security Policy blocks inline scripts without a nonce.
 */
function init() {
    const form = document.querySelector('form[name="newJob"]');
    const button = document.getElementById('nrrepurpose-submit');
    if (!form || !button) {
        return;
    }
    form.addEventListener('submit', () => {
        // Defer past the submit dispatch: disabling the triggering button
        // synchronously can cancel the submission in some browsers (Safari).
        setTimeout(() => {
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            const spinner = document.createElement('span');
            spinner.className = 'spinner-border spinner-border-sm';
            spinner.setAttribute('role', 'status');
            spinner.setAttribute('aria-hidden', 'true');
            // textContent path (not innerHTML): the label is an overridable
            // XLF string and must never become an HTML injection point.
            button.replaceChildren(spinner, document.createTextNode(' ' + button.dataset.submittingLabel));
        }, 0);
    });
}

// The module may be evaluated before the form is parsed.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
