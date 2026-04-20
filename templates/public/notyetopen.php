<?php
/**
 * FormVox - "Not yet open" template
 * Shown when a form has a share_starts_at in the future.
 */

declare(strict_types=1);

$appId = $_['appId'];
$title = $_['title'] ?? 'Form';
$opensAt = $_['opensAt'] ?? '';
?>

<style>
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
.notyet-container {
    max-width: 460px;
    margin: 100px auto;
    padding: 40px;
    background: var(--formvox-bg-primary);
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
    text-align: center;
}
.notyet-container h2 {
    margin: 0 0 8px;
    font-size: 24px;
    font-weight: 600;
    color: var(--formvox-text-primary);
}
.notyet-container .subtitle {
    color: var(--formvox-text-muted);
    margin: 0 0 20px;
    font-size: 14px;
    line-height: 1.5;
}
.notyet-container .opens-at {
    background: var(--formvox-bg-secondary, #f5f5f5);
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 15px;
    color: var(--formvox-text-primary);
    display: inline-block;
    margin-top: 4px;
}
.notyet-container .icon-clock {
    width: 64px;
    height: 64px;
    margin: 0 auto 24px;
    background: #e0f2fe;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}
.notyet-container .icon-clock svg {
    width: 32px;
    height: 32px;
    color: #0369a1;
}
</style>

<div class="notyet-container">
    <div class="icon-clock">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"></circle>
            <polyline points="12 6 12 12 16 14"></polyline>
        </svg>
    </div>

    <h2><?php p($l->t('This form is not yet open')); ?></h2>
    <p class="subtitle">
        <?php p($l->t('%1$s is scheduled to open at:', [$title])); ?>
    </p>
    <div class="opens-at"><?php p($opensAt); ?></div>
</div>
