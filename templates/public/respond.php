<?php
/**
 * FormVox - Public respond template (anonymous users)
 * Initial state is provided by PublicController via IInitialState
 */

declare(strict_types=1);
?>

<style>
/* Override Nextcloud's public template scroll restrictions */
/* Using multiple specificity levels to override any conflicting styles */
/* Also handles password manager extensions (LastPass, Bitwarden) that inject elements */
html, body, #body-public, #content, #content-wrapper, .content,
html body, html #body-public, body#body-public,
html body#body-public, html body #content {
    height: auto !important;
    max-height: none !important;
    min-height: 100vh !important;
    overflow: visible !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
    position: static !important;
    -webkit-overflow-scrolling: touch !important;
}

/* Ensure the main content wrapper doesn't trap scrolling */
#body-public > *:not(#header):not(script):not(style) {
    overflow: visible !important;
    height: auto !important;
}

/* Reset any transform/position that could create stacking context issues */
#body-public {
    background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
    transform: none !important;
}

/* Fix for Nextcloud 28+ public page layout */
.public-layout, .public-layout__main, .public-layout__content {
    height: auto !important;
    overflow: visible !important;
    overflow-y: auto !important;
}

/* Password manager extension fixes (LastPass, Bitwarden, 1Password, etc.) */
/* These extensions inject elements that can break scroll behavior */
[data-lastpass-root],
[data-lastpass-icon-root],
com-1password-button,
[data-bitwarden-watching] {
    position: absolute !important;
    z-index: 999999 !important;
}

/* Prevent extension overlays from capturing scroll events */
#body-public [style*="position: fixed"],
#body-public [style*="position:fixed"] {
    pointer-events: none;
}
#body-public [style*="position: fixed"] input,
#body-public [style*="position: fixed"] button,
#body-public [style*="position:fixed"] input,
#body-public [style*="position:fixed"] button {
    pointer-events: auto;
}
</style>

<div id="formvox-public"></div>
