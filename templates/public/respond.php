<?php
/**
 * FormVox - Public respond template (anonymous users)
 * Initial state is provided by PublicController via IInitialState
 */

declare(strict_types=1);
?>

<style>
/* Override Nextcloud's public template scroll restrictions */
html, body, #body-public, #content, #content-wrapper, .content {
    height: auto !important;
    max-height: none !important;
    min-height: 100vh !important;
    overflow: visible !important;
    position: static !important;
    overscroll-behavior: auto !important;
}

/* Body background and scroll */
#body-public {
    background: var(--formvox-page-bg);
    transform: none !important;
    overflow-x: hidden !important;
    overflow-y: scroll !important;
}

/* Fix for Nextcloud 28+ public page layout */
.public-layout, .public-layout__main, .public-layout__content {
    height: auto !important;
    overflow: visible !important;
}
</style>

<div id="formvox-public"></div>
