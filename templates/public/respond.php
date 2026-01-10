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
    min-height: 100vh !important;
    overflow: visible !important;
    overflow-y: auto !important;
    position: static !important;
}
#body-public {
    background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
}
</style>

<div id="formvox-public"></div>
