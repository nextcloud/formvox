<?php
/**
 * FormVox - Password protected form template
 * Shows a password form before accessing the protected form
 */

declare(strict_types=1);

use OCP\Util;

$appId = $_['appId'];
$token = $_['token'];
$error = $_['error'] ?? null;
$title = $_['title'] ?? 'Protected Form';
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
.password-container {
    max-width: 400px;
    margin: 100px auto;
    padding: 40px;
    background: white;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
    text-align: center;
}
.password-container h2 {
    margin: 0 0 8px;
    font-size: 24px;
    font-weight: 600;
    color: #1a1a1a;
}
.password-container .subtitle {
    color: #666;
    margin: 0 0 24px;
    font-size: 14px;
}
.password-container .icon-lock {
    width: 64px;
    height: 64px;
    margin: 0 auto 24px;
    background: #f5f5f5;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}
.password-container .icon-lock svg {
    width: 32px;
    height: 32px;
    color: #666;
}
.password-container form {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.password-container input[type="password"] {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 16px;
    box-sizing: border-box;
}
.password-container input[type="password"]:focus {
    outline: none;
    border-color: var(--color-primary, #0082c9);
    box-shadow: 0 0 0 2px rgba(0, 130, 201, 0.1);
}
.password-container button {
    width: 100%;
    padding: 12px 16px;
    background: var(--color-primary, #0082c9);
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
}
.password-container button:hover {
    background: var(--color-primary-hover, #0070ad);
}
.password-container .error {
    color: #c00;
    background: #fee;
    padding: 12px;
    border-radius: 8px;
    font-size: 14px;
    margin-bottom: 16px;
}
</style>

<div class="password-container">
    <div class="icon-lock">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
        </svg>
    </div>

    <h2><?php p($title); ?></h2>
    <p class="subtitle"><?php p($l->t('This form is password protected')); ?></p>

    <?php if ($error): ?>
        <div class="error"><?php p($error); ?></div>
    <?php endif; ?>

    <form method="post" action="">
        <input
            type="password"
            name="password"
            placeholder="<?php p($l->t('Enter password')); ?>"
            required
            autofocus
        >
        <button type="submit"><?php p($l->t('Submit')); ?></button>
    </form>
</div>
