<template>
	<div class="support-settings">
		<!-- Section 1: About FormVox + CTA -->
		<div class="settings-section">
			<!-- TRANSLATORS: Heading; FormVox is the product name -->
			<h2>{{ t('formvox', 'Support FormVox') }}</h2>
			<p class="settings-section-desc">
				{{ t('formvox', 'FormVox is free and open source (AGPL-3.0). All features work without a subscription. If FormVox is valuable to your organization, a subscription supports active development and gives you guaranteed Nextcloud compatibility and email support.') }}
			</p>
			<p class="settings-section-desc subscription-includes">
				{{ t('formvox', 'A subscription includes: guaranteed Nextcloud compatibility, email support, priority bug fixes, and active development.') }}
			</p>
			<div class="cta-block">
				<NcButton type="primary"
					:href="pricingUrl"
					target="_blank"
					rel="noopener noreferrer">
					<!-- TRANSLATORS: Link to the price list -->
					{{ t('formvox', 'Pricing details') }}
				</NcButton>
				<p class="cta-contact">
					<!-- TRANSLATORS: Link to contact information -->
					{{ t('formvox', 'Questions?') }}
					<a href="mailto:info@voxcloud.nl">info@voxcloud.nl</a>
				</p>
			</div>
		</div>

		<!-- Section 4: Your installation -->
		<div class="settings-section">
			<!-- TRANSLATORS: Heading above statistics of this Nextcloud server -->
			<h2>{{ t('formvox', 'Your installation') }}</h2>

			<div v-if="licenseStats" class="stats-overview">
				<div class="stat-row">
					<div class="stat-info">
						<span class="stat-icon">📋</span>
						<!-- TRANSLATORS: Statistic label -->
						<span class="stat-label">{{ t('formvox', 'Total forms') }}</span>
					</div>
					<span class="stat-value">{{ licenseStats.totalForms }}</span>
				</div>
				<div class="stat-row">
					<div class="stat-info">
						<span class="stat-icon">📝</span>
						<!-- TRANSLATORS: Statistic label -->
						<span class="stat-label">{{ t('formvox', 'Total responses') }}</span>
					</div>
					<span class="stat-value">{{ licenseStats.totalResponses }}</span>
				</div>
				<div class="stat-row">
					<div class="stat-info">
						<span class="stat-icon">👥</span>
						<!-- TRANSLATORS: Statistic label -->
						<span class="stat-label">{{ t('formvox', 'Total accounts') }}</span>
					</div>
					<span class="stat-value">{{ licenseStats.totalUsers }}</span>
				</div>
			</div>

			<NcNoteCard v-if="licenseStats && licenseStats.hasLicense && licenseStats.licenseValid" type="success">
				{{ t('formvox', 'Subscription active — thank you for supporting FormVox!') }}
			</NcNoteCard>

			<NcNoteCard v-if="licenseProblem" type="warning">
				{{ licenseProblem }}
			</NcNoteCard>
		</div>

		<!-- Section 6: Subscription key -->
		<div class="settings-section">
			<!-- TRANSLATORS: Heading and label: the key of a paid support subscription -->
			<h2>{{ t('formvox', 'Subscription key') }}</h2>

			<div class="field-row">
				<input id="license-key"
					v-model="licenseKey"
					type="text"
					:placeholder="t('formvox', 'e.g. {example}', { example: 'FVOX-XXXX-XXXX-XXXX-XXXX' })"
					class="contact-input"
					@input="_userEditedLicenseKey = true">
			</div>
			<div class="license-key-actions">
				<NcButton type="primary"
					:disabled="savingLicense"
					@click="saveLicenseKey">
					{{ savingLicense ? t('formvox', 'Saving …') : t('formvox', 'Save & activate') }}
				</NcButton>
				<NcButton v-if="licenseStats && licenseStats.hasLicense"
					type="tertiary"
					:disabled="savingLicense"
					@click="removeLicenseKey">
					{{ t('formvox', 'Remove subscription key') }}
				</NcButton>
			</div>
		</div>

		<!-- Section 8: Anonymous Usage Statistics -->
		<div class="settings-section">
			<h2>{{ t('formvox', 'Anonymous usage statistics') }}</h2>
			<p class="settings-section-desc">
				{{ t('formvox', 'Help improve FormVox by sharing anonymous usage statistics.') }}
			</p>

			<div class="telemetry-settings">
				<div class="engagement-option">
					<NcCheckboxRadioSwitch
						type="switch"
						:model-value="telemetryEnabled"
						@update:model-value="toggleTelemetry($event)">
						<div class="option-info">
							<span class="option-label">{{ t('formvox', 'Share anonymous usage statistics') }}</span>
							<span class="option-desc">{{ t('formvox', 'We collect: form counts, response counts, account counts, version info (FormVox, Nextcloud, PHP), and basic server configuration. No personal data or form content is shared.') }}</span>
						</div>
					</NcCheckboxRadioSwitch>
				</div>

				<div v-if="telemetryEnabled" class="telemetry-info">
					<NcNoteCard type="success">
						<p>{{ t('formvox', 'Thank you for helping improve FormVox!') }}</p>
						<p v-if="telemetryLastReport">
							<!-- TRANSLATORS: {date} is a date and time -->
							{{ t('formvox', 'Last report sent: {date}', { date: formatDate(telemetryLastReport) }) }}
						</p>
						<NcButton type="secondary"
							:disabled="sendingTelemetry"
							@click="sendTelemetryNow">
							<!-- TRANSLATORS: Button text while sending the report -->
							{{ sendingTelemetry ? t('formvox', 'Sending …') : t('formvox', 'Send report now') }}
						</NcButton>
					</NcNoteCard>
					<NcNoteCard v-if="telemetryMessage" :type="telemetryMessageType" class="telemetry-result">
						{{ telemetryMessage }}
					</NcNoteCard>
				</div>

				<div class="telemetry-details">
					<h4>{{ t('formvox', 'What we collect:') }}</h4>
					<ul>
						<li>{{ t('formvox', 'Total form and response counts') }}</li>
						<li>{{ t('formvox', 'Total account count and active accounts') }}</li>
						<li>{{ t('formvox', 'FormVox, Nextcloud, and PHP version numbers') }}</li>
						<li>{{ t('formvox', 'A unique hash of your instance URL (privacy-friendly identifier)') }}</li>
						<li>{{ t('formvox', 'Basic server configuration (database, OS, web server, language, timezone, country)') }}</li>
						<li>{{ t('formvox', 'Whether your Nextcloud has an Extended Support / Enterprise subscription (a single yes/no, sourced from Nextcloud\'s public API)') }}</li>
					</ul>
					<h4>{{ t('formvox', 'What we never collect:') }}</h4>
					<ul class="not-collected">
						<li>{{ t('formvox', 'Form content or titles') }}</li>
						<li>{{ t('formvox', 'Response data or answers') }}</li>
						<li>{{ t('formvox', 'Account names or email addresses') }}</li>
						<li>{{ t('formvox', 'Your actual server URL') }}</li>
						<li>{{ t('formvox', 'Any personal or sensitive data') }}</li>
					</ul>
				</div>
			</div>
		</div>

		<div v-if="message" :class="['message', messageType]">
			{{ message }}
		</div>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcNoteCard } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'SupportSettings',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
	},

	props: {
		initialTelemetryEnabled: {
			type: Boolean,
			default: false,
		},
		initialTelemetryLastReport: {
			type: [Number, null],
			default: null,
		},
	},

	emits: ['license-changed'],

	data() {
		return {
			licenseStats: null,
			licenseKey: '',
			savingLicense: false,
			_userEditedLicenseKey: false,
			telemetryEnabled: this.initialTelemetryEnabled,
			telemetryLastReport: this.initialTelemetryLastReport,
			sendingTelemetry: false,
			telemetryMessage: '',
			telemetryMessageType: 'success',
			message: '',
			messageType: 'success',
		}
	},

	computed: {
		/**
		 * Where the price actually lives.
		 *
		 * Deliberately not the App Store: it has no price field at all — the API
		 * exposes versions, licences and ratings, nothing commercial — so linking
		 * there for pricing would move the reader further from the answer.
		 * Collabora and OnlyOffice point at their own sites for the same reason.
		 *
		 * This is the information page, not the purchase route. Subscriptions are
		 * sold through Nextcloud, which is what the subscription notice says; the
		 * label says "details" so the two do not read as competing offers. There
		 * is deliberately no second button to the App Store listing either: it
		 * tells an administrator nothing they cannot see from inside their own
		 * server.
		 *
		 * Intended to move to a Nextcloud-hosted page once one exists. Not linked
		 * speculatively: a URL that 404s would ship in a release that sits on the
		 * App Store for months.
		 */
		pricingUrl() {
			const lang = (window.document?.documentElement?.lang || '').split('-')[0]
			return lang === 'nl' ? 'https://voxcloud.nl/pricing/#formvox' : 'https://voxcloud.nl/en/pricing/#formvox'
		},


		/**
		 * The licence server tells us *why* a key was refused -- expired,
		 * unknown, already bound elsewhere, deactivated. Each needs a different
		 * response from the admin, so map them to distinct messages instead of
		 * one "invalid or expired" that fits all four and helps with none.
		 */
		licenseProblem() {
			const s = this.licenseStats
			if (!s || !s.hasLicense || s.licenseValid) return null

			const reason = s.licenseReason || ''

			if (reason === 'License has expired') {
				const until = this.formatIsoDate(s.licenseValidUntil)
				return until
					// TRANSLATORS: {date} is a date
					? this.t('formvox', 'Your subscription expired on {date}. Renew it to keep receiving support.', { date: until })
					: this.t('formvox', 'Your subscription has expired. Renew it to keep receiving support.')
			}
			if (reason === 'License not found') {
				return this.t('formvox', 'This subscription key is not known to the license server. Check it for typos.')
			}
			if (reason.startsWith('License already in use')) {
				return this.t('formvox', 'This subscription key is already registered to another Nextcloud instance. Contact us if this server replaces that one.')
			}
			if (reason === 'License is inactive') {
				return this.t('formvox', 'This subscription key has been deactivated. Contact us to reactivate it.')
			}
			if (reason === 'License not yet valid') {
				return this.t('formvox', 'This subscription key is not valid yet.')
			}
			if (reason === 'Could not connect to license server') {
				return this.t('formvox', 'Could not reach the license server, so the subscription status could not be confirmed.')
			}

			// An unrecognised reason still beats saying nothing specific.
			return reason
				// TRANSLATORS: {reason} comes from the license server, in English
				? this.t('formvox', 'Subscription key was refused: {reason}', { reason })
				: this.t('formvox', 'Subscription key is invalid or expired.')
		},
	},

	mounted() {
		this.loadLicenseStats()
	},

	methods: {
		async loadLicenseStats() {
			try {
				const response = await axios.get(generateUrl('/apps/formvox/api/license/stats'))
				if (response.data.success) {
					this.licenseStats = response.data
					if (this.licenseStats.hasLicense && !this._userEditedLicenseKey) {
						this.licenseKey = this.licenseStats.licenseKeyMasked || ''
					}
				}
			} catch (error) {
				console.error('Failed to load license stats:', error)
			}
		},

		async saveLicenseKey() {
			const key = this.licenseKey.trim()
			if (!key) {
				this.showMessage(this.t('formvox', 'Please enter a subscription key'), 'error')
				return
			}
			this.savingLicense = true
			try {
				const saveRes = await axios.post(generateUrl('/apps/formvox/api/settings/license'), {
					licenseKey: key,
				})
				if (!saveRes.data.success) {
					this.showMessage(this.t('formvox', 'Failed to save subscription key'), 'error')
					return
				}

				const valRes = await axios.post(generateUrl('/apps/formvox/api/license/validate'))
				if (valRes.data.success && valRes.data.validation?.valid) {
					await axios.post(generateUrl('/apps/formvox/api/license/update-usage'))
					// TRANSLATORS: Confirmation message
					this.showMessage(this.t('formvox', 'Subscription activated!'), 'success')
				} else {
					// TRANSLATORS: {reason} comes from the license server, in English
					this.showMessage(this.t('formvox', 'Subscription key saved but validation failed: {reason}', { reason: valRes.data.validation?.reason || 'unknown' }), 'error')
				}

				await this.loadLicenseStats()
				this.$emit('license-changed')
			} catch (error) {
				console.error('Failed to save/validate license key:', error)
				this.showMessage(this.t('formvox', 'Failed to save subscription key'), 'error')
			} finally {
				this.savingLicense = false
			}
		},

		async removeLicenseKey() {
			this.savingLicense = true
			try {
				await axios.post(generateUrl('/apps/formvox/api/settings/license'), {
					licenseKey: '',
				})
				this.licenseKey = ''
				this._userEditedLicenseKey = false
				await this.loadLicenseStats()
				this.$emit('license-changed')
				this.showMessage(this.t('formvox', 'Subscription key removed.'), 'success')
			} catch (error) {
				this.showMessage(this.t('formvox', 'Failed to remove subscription key'), 'error')
			} finally {
				this.savingLicense = false
			}
		},

		async sendTelemetryNow() {
			this.sendingTelemetry = true
			this.telemetryMessage = ''
			try {
				const response = await axios.post(generateUrl('/apps/formvox/api/statistics/telemetry/send'))
				const data = response.data
				if (data.success) {
					this.telemetryLastReport = Math.floor(Date.now() / 1000)
					this.telemetryMessage = this.t('formvox', 'Report sent successfully')
					this.telemetryMessageType = 'success'
				} else if (data.reason === 'server_error' || data.reason === 'error') {
					const serverMsg = data.message || ''
					if (serverMsg.startsWith('HTTP ') || serverMsg.includes('error')) {
						// Shown as text, so the server's message is not HTML-escaped.
						// TRANSLATORS: {error} is a technical message, in English
						this.telemetryMessage = this.t('formvox', 'The telemetry server returned an error: {error}', { error: serverMsg }, undefined, { escape: false, sanitize: false })
					} else if (serverMsg.includes('cURL') || serverMsg.includes('connect') || serverMsg.includes('timeout') || serverMsg.includes('resolve')) {
						this.telemetryMessage = this.t('formvox', 'Could not reach the telemetry server. Please try again later.')
					} else {
						this.telemetryMessage = serverMsg
							// TRANSLATORS: {error} is a technical message, in English
							? this.t('formvox', 'Failed to send report: {error}', { error: serverMsg }, undefined, { escape: false, sanitize: false })
							: this.t('formvox', 'Failed to send report')
					}
					this.telemetryMessageType = 'error'
				} else {
					this.telemetryMessage = this.t('formvox', 'Failed to send report')
					this.telemetryMessageType = 'error'
				}
			} catch (error) {
				console.error('Failed to send telemetry:', error)
				this.telemetryMessage = this.t('formvox', 'Could not reach the telemetry server. Please try again later.')
				this.telemetryMessageType = 'error'
			} finally {
				this.sendingTelemetry = false
			}
		},

		async toggleTelemetry(enabled) {
			try {
				await axios.post(
					generateUrl('/apps/formvox/api/statistics/telemetry'),
					{ enabled },
				)
				this.telemetryEnabled = enabled
			} catch (error) {
				console.error('Failed to toggle telemetry:', error)
				showError(this.t('formvox', 'Failed to update telemetry settings'))
			}
		},

		showMessage(text, type) {
			this.message = text
			this.messageType = type
			setTimeout(() => {
				this.message = ''
			}, 5000)
		},

		/** validUntil arrives as an ISO string, not the unix stamp formatDate takes. */
		formatIsoDate(iso) {
			if (!iso) return ''
			const date = new Date(iso)
			if (Number.isNaN(date.getTime())) return ''
			return date.toLocaleDateString(undefined, {
				year: 'numeric',
				month: 'long',
				day: 'numeric',
			})
		},

		formatDate(timestamp) {
			// TRANSLATORS: Shown instead of a date when no report was sent yet
			if (!timestamp) return this.t('formvox', 'Never')
			const date = new Date(timestamp * 1000)
			return date.toLocaleString(undefined, {
				year: 'numeric',
				month: 'long',
				day: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			})
		},

		// @nextcloud/l10n's translate, called with the app id first,
		// the only form the Nextcloud Transifex extractor can see.
		t,
	},
}
</script>

<style scoped>
.support-settings {
	max-width: 800px;
}

/* Settings sections */
.settings-section {
	margin-bottom: 32px;
}

.settings-section h2 {
	font-size: 20px;
	font-weight: bold;
	margin-bottom: 8px;
}

.settings-section-desc {
	color: var(--color-text-maxcontrast);
	margin-bottom: 20px;
}

.subscription-includes {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

/* CTA block */
.cta-block {
	display: flex;
	align-items: center;
	gap: 16px;
	flex-wrap: wrap;
	margin-top: 16px;
}

.cta-contact {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.cta-contact a {
	color: var(--color-primary-element);
	font-weight: 500;
	text-decoration: none;
}

.cta-contact a:hover {
	text-decoration: underline;
}

/* Stats overview */
.stats-overview {
	margin-bottom: 24px;
}

.stat-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 20px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
	margin-bottom: 8px;
}

.stat-info {
	display: flex;
	align-items: center;
	gap: 10px;
}

.stat-icon {
	font-size: 1.2em;
}

.stat-label {
	font-weight: 500;
}

.stat-value {
	font-size: 16px;
	font-weight: 700;
	color: var(--color-primary);
}

.contact-fields h2 {
	margin: 0 0 8px 0;
	font-size: 20px;
	font-weight: bold;
}

.contact-fields .field-desc {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.field-row {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 12px;
}

.field-row label {
	font-weight: 500;
	font-size: 14px;
}

.contact-input {
	width: 100%;
	max-width: 400px;
	padding: 8px 12px;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 14px;
}

.contact-input:focus {
	border-color: var(--color-primary-element);
	outline: none;
}

/* License key section */
.license-key-actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}

/* Telemetry */
.telemetry-settings {
	margin-top: 16px;
}

.telemetry-info {
	margin-top: 16px;
}

.telemetry-details {
	margin-top: 16px;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.telemetry-details h4 {
	color: var(--color-main-text);
	font-size: 14px;
	margin-bottom: 8px;
}

.telemetry-details h4:not(:first-child) {
	margin-top: 16px;
}

.telemetry-details ul {
	list-style: none;
	padding: 0;
	margin: 0 0 8px 0;
}

.telemetry-details ul li {
	padding: 4px 0;
	padding-left: 20px;
	position: relative;
}

.telemetry-details ul li::before {
	content: '•';
	position: absolute;
	left: 6px;
	color: var(--color-primary);
}

.telemetry-details ul.not-collected li::before {
	content: '✕';
	color: var(--color-error);
}

.engagement-option {
	margin-bottom: 12px;
}

.option-info {
	display: flex;
	flex-direction: column;
}

.option-label {
	font-weight: 500;
}

.option-desc {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.message {
	margin-top: 15px;
	padding: 10px 15px;
	border-radius: var(--border-radius);
	font-size: 14px;
}

.message.success {
	background: #d4edda;
	color: #155724;
	border: 1px solid #c3e6cb;
}

.message.error {
	background: #f8d7da;
	color: #721c24;
	border: 1px solid var(--color-error, #f5c6cb);
}
</style>
