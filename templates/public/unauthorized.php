<?php
/**
 * FormVox - Unauthorized access template
 * Shows when user is logged in but not authorized for this form
 */

declare(strict_types=1);

$appId = $_['appId'];
$title = $_['title'] ?? 'Form';
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
@media (prefers-color-scheme: dark) {
    #body-public {
        background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
    }
    .unauthorized-container {
        background: #2c2c2c !important;
    }
    .unauthorized-container h2 {
        color: #e0e0e0 !important;
    }
    .unauthorized-container .subtitle {
        color: #999 !important;
    }
    .unauthorized-container .icon-lock {
        background: #3a3a3a !important;
    }
    .unauthorized-container .icon-lock svg {
        color: #999 !important;
    }
}
.unauthorized-container {
    max-width: 400px;
    margin: 100px auto;
    padding: 40px;
    background: white;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
    text-align: center;
}
.unauthorized-container h2 {
    margin: 0 0 8px;
    font-size: 24px;
    font-weight: 600;
    color: #1a1a1a;
}
.unauthorized-container .subtitle {
    color: #666;
    margin: 0 0 24px;
    font-size: 14px;
    line-height: 1.5;
}
.unauthorized-container .icon-lock {
    width: 64px;
    height: 64px;
    margin: 0 auto 24px;
    background: #fff3cd;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}
.unauthorized-container .icon-lock svg {
    width: 32px;
    height: 32px;
    color: #856404;
}
</style>

<div class="unauthorized-container">
    <div class="icon-lock">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line>
        </svg>
    </div>

    <h2><?php p($l->t('Access Restricted')); ?></h2>
    <p class="subtitle">
        <?php p($l->t('You do not have permission to access this form.')); ?><br>
        <?php p($l->t('Contact the form owner if you believe this is an error.')); ?>
    </p>
</div>
