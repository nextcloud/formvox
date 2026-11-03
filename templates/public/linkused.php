<?php
/**
 * FormVox - "Link already used" template
 * Shown when a unique response link has no responses left.
 */

declare(strict_types=1);

$appId = $_['appId'];
$title = $_['title'] ?? $l->t('Form');
?>

<style>
/* Override Nextcloud's public template scroll restrictions */
html, body, #body-public, #content, #content-wrapper, .content {
    height: auto !important;
    min-height: 100vh !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
    position: static !important;
}
#body-public {
    background: var(--formvox-page-bg);
}
.linkused-container {
    /* Same width as the form itself (.respond-container) */
    max-width: 700px;
    width: calc(100% - 32px);
    box-sizing: border-box;
    margin: 100px auto;
    padding: 40px;
    background: var(--formvox-bg-primary);
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
    text-align: center;
}
.linkused-container h2 {
    margin: 0 0 8px;
    font-size: 24px;
    font-weight: 600;
    color: var(--formvox-text-primary);
}
.linkused-container .form-title {
    margin: 0 0 16px;
    font-size: 15px;
    color: var(--formvox-text-primary);
}
.linkused-container .subtitle {
    color: var(--formvox-text-muted);
    margin: 0;
    font-size: 14px;
    line-height: 1.5;
}
.linkused-container .icon-check {
    width: 64px;
    height: 64px;
    margin: 0 auto 24px;
    background: #e0f2fe;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}
.linkused-container .icon-check svg {
    width: 32px;
    height: 32px;
    color: #0369a1;
}
</style>

<div class="linkused-container">
    <div class="icon-check">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
            <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
        </svg>
    </div>

    <?php // TRANSLATORS: Heading when a unique (e.g. single-use) response link was already used?>
    <h2><?php p($l->t('This link has already been used')); ?></h2>
    <p class="form-title"><?php p($title); ?></p>
    <p class="subtitle">
        <?php p($l->t('A response has already been submitted with this link, so it cannot be used again.')); ?><br>
        <?php p($l->t('Contact the form owner if you believe this is an error.')); ?>
    </p>
</div>
