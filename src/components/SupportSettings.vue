<template>
	<div class="support-settings">
		<!-- Section 1: About FormVox -->
		<div class="settings-section">
			<h2>{{ t('Support FormVox') }}</h2>
			<p class="settings-section-desc">
				{{ t('FormVox is free and open source (AGPL-3.0). You can use all features without a subscription — no limits on functionality, no restrictions, no catch.') }}
			</p>
			<p class="settings-section-desc">
				{{ t('If FormVox is valuable to your organization, consider subscribing. Your subscription funds active development, guaranteed Nextcloud compatibility, and email support.') }}
			</p>
		</div>

		<!-- Section 2: What's included -->
		<div class="settings-section">
			<h2>{{ t('What a subscription includes') }}</h2>

			<div class="includes-list">
				<div class="includes-item">
					<span class="includes-check">&#x2705;</span>
					<div class="includes-text">
						<span class="includes-label">{{ t('Guaranteed compatibility') }}</span>
						<span class="includes-desc">{{ t('Tested with every new Nextcloud release') }}</span>
					</div>
				</div>
				<div class="includes-item">
					<span class="includes-check">&#x2705;</span>
					<div class="includes-text">
						<span class="includes-label">{{ t('Email support') }}</span>
						<span class="includes-desc">{{ t('Direct support from the developers') }}</span>
					</div>
				</div>
				<div class="includes-item">
					<span class="includes-check">&#x2705;</span>
					<div class="includes-text">
						<span class="includes-label">{{ t('Priority bug fixes') }}</span>
						<span class="includes-desc">{{ t('Your issues get priority attention') }}</span>
					</div>
				</div>
				<div class="includes-item">
					<span class="includes-check">&#x2705;</span>
					<div class="includes-text">
						<span class="includes-label">{{ t('Active development') }}</span>
						<span class="includes-desc">{{ t('New features and improvements') }}</span>
					</div>
				</div>
			</div>
		</div>

		<!-- Section 3: Pricing -->
		<div class="settings-section">
			<h2>{{ t('Pricing') }}</h2>

			<div class="pricing-table">
				<div class="pricing-row pricing-row--free">
					<span class="pricing-tier">{{ t('Free') }}</span>
					<span class="pricing-price pricing-price--free">{{ t('Free — 25 forms, 500 responses per form') }}</span>
				</div>
				<div class="pricing-row">
					<span class="pricing-tier">{{ t('1–50 users') }}</span>
					<span class="pricing-price">{{ t('€19/year') }}</span>
				</div>
				<div class="pricing-row">
					<span class="pricing-tier">{{ t('51–250 users') }}</span>
					<span class="pricing-price">{{ t('€59/year') }}</span>
				</div>
				<div class="pricing-row">
					<span class="pricing-tier">{{ t('251–1000 users') }}</span>
					<span class="pricing-price">{{ t('€139/year') }}</span>
				</div>
				<div class="pricing-row">
					<span class="pricing-tier">{{ t('1000+ users') }}</span>
					<span class="pricing-price">{{ t('Contact us') }}</span>
				</div>
			</div>

			<p class="pricing-note">
				{{ t('Excl. VAT, per instance, per year. All paid tiers include unlimited forms and responses.') }}
			</p>

			<NcButton type="primary"
				:href="pricingUrl"
				target="_blank"
				rel="noopener noreferrer">
				{{ t('View pricing & subscribe') }}
			</NcButton>
		</div>

		<!-- Section 4: Your installation -->
		<div class="settings-section">
			<h2>{{ t('Your installation') }}</h2>

			<div v-if="licenseStats" class="stats-overview">
				<div class="stat-row">
					<div class="stat-info">
						<span class="stat-icon">📋</span>
						<span class="stat-label">{{ t('Total Forms') }}</span>
					</div>
					<span class="stat-value">{{ licenseStats.totalForms }}</span>
				</div>
				<div class="stat-row">
					<div class="stat-info">
						<span class="stat-icon">📝</span>
						<span class="stat-label">{{ t('Total Responses') }}</span>
					</div>
					<span class="stat-value">{{ licenseStats.totalResponses }}</span>
				</div>
				<div class="stat-row">
					<div class="stat-info">
						<span class="stat-icon">👥</span>
						<span class="stat-label">{{ t('Total Users') }}</span>
					</div>
					<span class="stat-value">{{ licenseStats.totalUsers }}</span>
				</div>
			</div>

			<NcNoteCard v-if="licenseStats && licenseStats.hasLicense && licenseStats.licenseValid" type="success">
				{{ t('Subscription active — thank you for supporting FormVox!') }}
			</NcNoteCard>

			<NcNoteCard v-if="licenseStats && licenseStats.hasLicense && !licenseStats.licenseValid" type="warning">
				{{ t('Subscription key is invalid or expired.') }}
			</NcNoteCard>
		</div>

		<!-- Section 5: Your organization -->
		<div class="settings-section">
			<div class="contact-fields">
				<h2>{{ t('Your organization (optional)') }}</h2>
				<p class="field-desc">{{ t('These details help us reach you if needed. They are never shared.') }}</p>

				<div class="field-row">
					<label for="organization-name">{{ t('Organization name') }}</label>
					<input id="organization-name"
						v-model="organizationName"
						type="text"
						:placeholder="t('e.g. Acme Corporation')"
						class="contact-input">
				</div>

				<div class="field-row">
					<label for="contact-email">{{ t('Contact email') }}</label>
					<input id="contact-email"
						v-model="contactEmail"
						type="email"
						:placeholder="t('e.g. admin@example.com')"
						class="contact-input">
				</div>

				<NcButton type="primary"
					:disabled="savingContact"
					@click="saveContactInfo">
					{{ savingContact ? t('Saving...') : t('Save') }}
				</NcButton>
			</div>
		</div>

		<!-- Section 6: Subscription key -->
		<div class="settings-section">
			<h2>{{ t('Subscription key') }}</h2>

			<div class="field-row">
				<input id="license-key"
					v-model="licenseKey"
					type="text"
					:placeholder="t('e.g. FVOX-XXXX-XXXX-XXXX-XXXX')"
					class="contact-input"
					@input="_userEditedLicenseKey = true">
			</div>
			<div class="license-key-actions">
				<NcButton type="primary"
					:disabled="savingLicense"
					@click="saveLicenseKey">
					{{ savingLicense ? t('Saving...') : t('Save & activate') }}
				</NcButton>
				<NcButton v-if="licenseStats && licenseStats.hasLicense"
					type="tertiary"
					:disabled="savingLicense"
					@click="removeLicenseKey">
					{{ t('Remove subscription key') }}
				</NcButton>
			</div>
		</div>

		<!-- Section 7: Contact -->
		<div class="settings-section">
			<div class="contact-info-block">
				<p>
					{{ t('Learn more about FormVox') }}:
					<a href="https://voxcloud.nl" target="_blank" rel="noopener noreferrer">voxcloud.nl</a>
				</p>
				<p>
					{{ t('Questions or feedback?') }}
					<a href="mailto:info@voxcloud.nl">info@voxcloud.nl</a>
				</p>
			</div>
		</div>

		<!-- Section 8: Anonymous Usage Statistics -->
		<div class="settings-section">
			<h2>{{ t('Anonymous Usage Statistics') }}</h2>
			<p class="settings-section-desc">
				{{ t('Help improve FormVox by sharing anonymous usage statistics.') }}
			</p>

			<div class="telemetry-settings">
				<div class="engagement-option">
					<NcCheckboxRadioSwitch
						type="switch"
						:model-value="telemetryEnabled"
						@update:model-value="toggleTelemetry($event)">
						<div class="option-info">
							<span class="option-label">{{ t('Share anonymous usage statistics') }}</span>
							<span class="option-desc">{{ t('We collect: form counts, response counts, user counts, and version info (FormVox, Nextcloud, PHP). No personal data or form content is shared.') }}</span>
						</div>
					</NcCheckboxRadioSwitch>
				</div>

				<div v-if="telemetryEnabled" class="telemetry-info">
					<NcNoteCard type="success">
						<p>{{ t('Thank you for helping improve FormVox!') }}</p>
						<p v-if="telemetryLastReport">
							{{ t('Last report sent') }}: {{ formatDate(telemetryLastReport) }}
						</p>
						<NcButton type="secondary"
							:disabled="sendingTelemetry"
							@click="sendTelemetryNow">
							{{ sendingTelemetry ? t('Sending...') : t('Send report now') }}
						</NcButton>
					</NcNoteCard>
				</div>

				<div class="telemetry-details">
					<h4>{{ t('What we collect') }}:</h4>
					<ul>
						<li>{{ t('Number of forms and responses') }}</li>
						<li>{{ t('Number of active users') }}</li>
						<li>{{ t('FormVox, Nextcloud, and PHP version numbers') }}</li>
						<li>{{ t('A unique hash of your instance URL (privacy-friendly identifier)') }}</li>
					</ul>
					<h4>{{ t('What we never collect') }}:</h4>
					<ul class="not-collected">
						<li>{{ t('Form content or titles') }}</li>
						<li>{{ t('Response data or answers') }}</li>
						<li>{{ t('User names or email addresses') }}</li>
						<li>{{ t('Your actual server URL') }}</li>
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
			organizationName: '',
			contactEmail: '',
			savingContact: false,
			telemetryEnabled: this.initialTelemetryEnabled,
			telemetryLastReport: this.initialTelemetryLastReport,
			sendingTelemetry: false,
			message: '',
			messageType: 'success',
		}
	},

	computed: {
		pricingUrl() {
			const lang = (window.document?.documentElement?.lang || '').split('-')[0]
			return lang === 'nl' ? 'https://voxcloud.nl/pricing/#formvox' : 'https://voxcloud.nl/en/pricing/#formvox'
		},
	},

	mounted() {
		this.loadSettings()
		this.loadLicenseStats()
	},

	methods: {
		async loadSettings() {
			try {
				const res = await axios.get(generateUrl('/apps/formvox/api/settings'))
				if (res.data.success) {
					this.organizationName = res.data.settings.organization_name || ''
					this.contactEmail = res.data.settings.contact_email || ''
				}
			} catch (error) {
				console.error('Failed to load settings:', error)
			}
		},

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
				this.showMessage(this.t('Please enter a subscription key'), 'error')
				return
			}
			this.savingLicense = true
			try {
				const saveRes = await axios.post(generateUrl('/apps/formvox/api/settings/license'), {
					licenseKey: key,
				})
				if (!saveRes.data.success) {
					this.showMessage(this.t('Failed to save subscription key'), 'error')
					return
				}

				const valRes = await axios.post(generateUrl('/apps/formvox/api/license/validate'))
				if (valRes.data.success && valRes.data.validation?.valid) {
					await axios.post(generateUrl('/apps/formvox/api/license/update-usage'))
					this.showMessage(this.t('Subscription activated!'), 'success')
				} else {
					this.showMessage(this.t('Subscription key saved but validation failed: {reason}', { reason: valRes.data.validation?.reason || 'unknown' }), 'error')
				}

				await this.loadLicenseStats()
				this.$emit('license-changed')
			} catch (error) {
				console.error('Failed to save/validate license key:', error)
				this.showMessage(this.t('Failed to save subscription key'), 'error')
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
				this.showMessage(this.t('Subscription key removed.'), 'success')
			} catch (error) {
				this.showMessage(this.t('Failed to remove subscription key'), 'error')
			} finally {
				this.savingLicense = false
			}
		},

		async saveContactInfo() {
			this.savingContact = true
			try {
				await axios.post(generateUrl('/apps/formvox/api/settings'), {
					organizationName: this.organizationName,
					contactEmail: this.contactEmail,
				})
				this.showMessage(this.t('Contact information saved.'), 'success')
			} catch (error) {
				console.error('Failed to save contact info:', error)
				this.showMessage(this.t('Failed to save contact information'), 'error')
			} finally {
				this.savingContact = false
			}
		},

		async sendTelemetryNow() {
			this.sendingTelemetry = true
			try {
				await axios.post(generateUrl('/apps/formvox/api/statistics/telemetry/send'))
				this.telemetryLastReport = Math.floor(Date.now() / 1000)
			} catch (error) {
				console.error('Failed to send telemetry:', error)
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
				showError(this.t('Failed to update telemetry settings'))
			}
		},

		showMessage(text, type) {
			this.message = text
			this.messageType = type
			setTimeout(() => {
				this.message = ''
			}, 5000)
		},

		formatDate(timestamp) {
			if (!timestamp) return this.t('Never')
			const date = new Date(timestamp * 1000)
			return date.toLocaleString(undefined, {
				year: 'numeric',
				month: 'long',
				day: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			})
		},

		t(text, vars) {
			if (typeof OC !== 'undefined' && OC.L10N) {
				return OC.L10N.translate('formvox', text, vars)
			}
			if (vars) {
				return Object.keys(vars).reduce((result, key) => {
					return result.replace(`{${key}}`, vars[key])
				}, text)
			}
			return text
		},
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

/* What's included list */
.includes-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-bottom: 24px;
}

.includes-item {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	padding: 12px 20px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
}

.includes-check {
	font-size: 1.2em;
	flex-shrink: 0;
}

.includes-text {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.includes-label {
	font-weight: 600;
	color: var(--color-main-text);
}

.includes-desc {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

/* Pricing table */
.pricing-table {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 16px;
}

.pricing-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 20px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
}

.pricing-row--free {
	border: 2px solid var(--color-primary-element-light);
}

.pricing-tier {
	font-weight: 500;
	color: var(--color-main-text);
}

.pricing-price {
	font-size: 16px;
	font-weight: 700;
	color: var(--color-primary);
}

.pricing-price--free {
	font-weight: 500;
	font-size: 14px;
}

.pricing-note {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
	font-size: 14px;
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

/* Contact info block */
.contact-info-block {
	margin-bottom: 20px;
	padding: 16px 20px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
}

.contact-info-block p {
	margin: 0 0 8px 0;
	line-height: 1.5;
}

.contact-info-block p:last-child {
	margin-bottom: 0;
}

.contact-info-block a {
	color: var(--color-primary-element);
	font-weight: 500;
	text-decoration: none;
}

.contact-info-block a:hover {
	text-decoration: underline;
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
