import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

/**
 * Vitest config for FormVox frontend unit tests.
 *
 * Components are tested against @vue/test-utils in a happy-dom environment.
 * The heavy @nextcloud/* packages (which import CSS and expect a running
 * Nextcloud) are replaced with lightweight stubs from tests-js/stubs so a
 * component's OWN logic can be tested in isolation — mirroring how the PHP
 * suite mocks the OCP interfaces.
 */
export default defineConfig({
	plugins: [vue()],
	test: {
		environment: 'happy-dom',
		include: ['tests-js/**/*.spec.js'],
		globals: true,
	},
	resolve: {
		alias: {
			'@': fileURLToPath(new URL('./src', import.meta.url)),
			'@nextcloud/vue': fileURLToPath(new URL('./tests-js/stubs/nextcloud-vue.js', import.meta.url)),
			'@nextcloud/l10n': fileURLToPath(new URL('./tests-js/stubs/nextcloud-l10n.js', import.meta.url)),
			'@nextcloud/router': fileURLToPath(new URL('./tests-js/stubs/nextcloud-router.js', import.meta.url)),
			'@nextcloud/axios': fileURLToPath(new URL('./tests-js/stubs/nextcloud-axios.js', import.meta.url)),
			'@nextcloud/dialogs': fileURLToPath(new URL('./tests-js/stubs/nextcloud-dialogs.js', import.meta.url)),
			'@nextcloud/initial-state': fileURLToPath(new URL('./tests-js/stubs/nextcloud-initial-state.js', import.meta.url)),
			'@nextcloud/files': fileURLToPath(new URL('./tests-js/stubs/nextcloud-files.js', import.meta.url)),
		},
	},
})
