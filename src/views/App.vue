<template>
	<NcContent app-name="formvox">
		<NcAppContent :show-details="false">
			<div v-if="loading" class="loading-container">
				<NcLoadingIcon :size="64" />
			</div>

			<div v-else-if="forms.length === 0" class="empty-state">
				<TemplateGallery @select-template="createFormDirectly" />
				<div class="empty-state__content">
					<FormIcon :size="64" />
					<h2>{{ t('No forms yet') }}</h2>
					<p>{{ t('Create your first form to get started.') }}</p>
					<NcButton type="primary" @click="showNewFormModal = true">
						{{ t('Create form') }}
					</NcButton>
				</div>
			</div>

			<div v-else class="forms-container">
				<TemplateGallery @select-template="createFormDirectly" />

				<div class="forms-header">
					<div class="forms-tabs">
						<button
							v-for="tab in tabs"
							:key="tab.id"
							type="button"
							class="forms-tabs__tab"
							:class="{ 'forms-tabs__tab--active': activeTab === tab.id }"
							@click="activeTab = tab.id"
						>
							<component :is="tab.icon" :size="16" />
							{{ tab.label }}
							<span v-if="tab.count !== undefined" class="forms-tabs__count">
								{{ tab.count }}
							</span>
						</button>
					</div>
					<NcButton type="primary" @click="showNewFormModal = true">
						<template #icon>
							<PlusIcon :size="20" />
						</template>
						{{ t('New form') }}
					</NcButton>
				</div>

				<div class="forms-grid">
					<FormCard
						v-for="form in filteredForms"
						:key="form.fileId"
						:form="form"
						@click="openForm(form)"
						@delete="deleteForm(form)"
					/>
				</div>

				<div v-if="filteredForms.length === 0" class="empty-tab">
					<p>{{ emptyTabMessage }}</p>
				</div>
			</div>
		</NcAppContent>

		<NewFormModal
			v-if="showNewFormModal"
			:initial-template="selectedTemplate"
			@close="closeNewFormModal"
			@created="onFormCreated"
		/>

		<NcDialog
			v-if="showDeleteDialog"
			:name="t('Delete form')"
			@closing="cancelDelete"
		>
			<p>{{ t('Are you sure you want to delete this form? This action cannot be undone.') }}</p>
			<template #actions>
				<NcButton @click="cancelDelete">
					{{ t('Cancel') }}
				</NcButton>
				<NcButton type="error" @click="confirmDelete">
					{{ t('Delete') }}
				</NcButton>
			</template>
		</NcDialog>
	</NcContent>
</template>

<script>
import { ref, computed, onMounted } from 'vue'
import {
	NcContent,
	NcAppContent,
	NcButton,
	NcLoadingIcon,
	NcDialog,
} from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@/utils/l10n'
import FormCard from '../components/FormCard.vue'
import NewFormModal from '../components/NewFormModal.vue'
import TemplateGallery from '../components/TemplateGallery.vue'
import FormIcon from '../components/icons/FormIcon.vue'
import PlusIcon from '../components/icons/PlusIcon.vue'

const TAB_STORAGE_KEY = 'formvox-active-tab'

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppContent,
		NcButton,
		NcLoadingIcon,
		NcDialog,
		FormCard,
		NewFormModal,
		TemplateGallery,
		FormIcon,
		PlusIcon,
	},
	setup() {
		const forms = ref([])
		const loading = ref(true)
		const showNewFormModal = ref(false)
		const selectedTemplate = ref(null)
		const activeTab = ref('recent')
		const showDeleteDialog = ref(false)
		const formToDelete = ref(null)

		// Tab definitions
		const tabs = computed(() => [
			{
				id: 'recent',
				label: t('Recent'),
				icon: FormIcon,
				count: recentForms.value.length,
			},
			{
				id: 'myforms',
				label: t('My forms'),
				icon: FormIcon,
				count: forms.value.length,
			},
		])

		// Filtered form lists
		const recentForms = computed(() => {
			return [...forms.value]
				.sort((a, b) => new Date(b.modifiedAt) - new Date(a.modifiedAt))
				.slice(0, 10)
		})

		const filteredForms = computed(() => {
			switch (activeTab.value) {
			case 'recent':
				return recentForms.value
			case 'myforms':
				return forms.value
			default:
				return forms.value
			}
		})

		const emptyTabMessage = computed(() => {
			return t('No forms found.')
		})

		const loadForms = async () => {
			loading.value = true
			try {
				const response = await axios.get(generateUrl('/apps/formvox/api/forms'))
				forms.value = response.data
			} catch (error) {
				showError(t('Failed to load forms'))
				console.error(error)
			} finally {
				loading.value = false
			}
		}

		const getFormUrl = (form) => {
			return generateUrl('/apps/formvox/edit/{fileId}', { fileId: form.fileId })
		}

		const openForm = (form) => {
			window.location.href = getFormUrl(form)
		}

		const deleteForm = (form) => {
			formToDelete.value = form
			showDeleteDialog.value = true
		}

		const cancelDelete = () => {
			showDeleteDialog.value = false
			formToDelete.value = null
		}

		const confirmDelete = async () => {
			if (!formToDelete.value) return

			try {
				await axios.delete(generateUrl('/apps/formvox/api/form/{fileId}', { fileId: formToDelete.value.fileId }))
				forms.value = forms.value.filter(f => f.fileId !== formToDelete.value.fileId)
				showSuccess(t('Form deleted'))
			} catch (error) {
				showError(t('Failed to delete form'))
				console.error(error)
			} finally {
				showDeleteDialog.value = false
				formToDelete.value = null
			}
		}

		const createFormDirectly = async (templateId) => {
			const templateNames = {
				survey: 'New Survey',
				poll: 'New Poll',
				registration: 'New Registration',
				demo: 'Demo Form',
				blank: 'Untitled Form',
			}

			try {
				const response = await axios.post(generateUrl('/apps/formvox/api/forms'), {
					title: t(templateNames[templateId] || 'Untitled Form'),
					path: '',
					template: templateId === 'blank' ? null : templateId,
				})

				window.location.href = generateUrl(`/apps/formvox/edit/${response.data.fileId}`)
			} catch (error) {
				showError(t('Failed to create form'))
				console.error(error)
			}
		}

		const closeNewFormModal = () => {
			showNewFormModal.value = false
			selectedTemplate.value = null
		}

		const onFormCreated = (newForm) => {
			forms.value.unshift(newForm)
			closeNewFormModal()
			openForm(newForm)
		}

		onMounted(() => {
			loadForms()

			// Restore active tab from localStorage
			try {
				const storedTab = localStorage.getItem(TAB_STORAGE_KEY)
				if (storedTab && ['recent', 'myforms'].includes(storedTab)) {
					activeTab.value = storedTab
				}
			} catch (e) {
				// localStorage not available
			}
		})

		// Watch activeTab to save to localStorage
		const saveActiveTab = () => {
			try {
				localStorage.setItem(TAB_STORAGE_KEY, activeTab.value)
			} catch (e) {
				// localStorage not available
			}
		}

		return {
			forms,
			loading,
			showNewFormModal,
			selectedTemplate,
			activeTab,
			tabs,
			filteredForms,
			emptyTabMessage,
			getFormUrl,
			openForm,
			deleteForm,
			createFormDirectly,
			closeNewFormModal,
			onFormCreated,
			saveActiveTab,
			showDeleteDialog,
			cancelDelete,
			confirmDelete,
			t,
		}
	},
	watch: {
		activeTab() {
			this.saveActiveTab()
		},
	},
}
</script>

<style scoped lang="scss">
.loading-container {
	display: flex;
	justify-content: center;
	align-items: center;
	height: 100%;
}

.empty-state {
	display: flex;
	flex-direction: column;
	height: 100%;
	padding-top: 24px;

	&__content {
		display: flex;
		flex-direction: column;
		justify-content: center;
		align-items: center;
		flex: 1;
		text-align: center;
		color: var(--color-text-maxcontrast);

		h2 {
			margin-top: 20px;
			margin-bottom: 10px;
		}

		p {
			margin-bottom: 20px;
		}
	}
}

.forms-container {
	height: 100%;
	padding-top: 24px;
	overflow-y: auto;
}

.forms-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	margin: 0 20px 16px 44px;
	padding-bottom: 12px;
	border-bottom: 1px solid var(--color-border);
}

.forms-tabs {
	display: flex;
	gap: 4px;

	&__tab {
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 8px 16px;
		border: none;
		border-radius: var(--border-radius-pill, 100px);
		background: transparent;
		color: var(--color-text-maxcontrast);
		font-size: 14px;
		font-weight: 500;
		cursor: pointer;
		transition: background-color 0.2s ease-in-out, color 0.2s ease-in-out;

		&:hover {
			background: var(--color-background-hover);
			color: var(--color-main-text);
		}

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 2px;
		}

		&--active {
			background: var(--color-primary-element-light);
			color: var(--color-primary-element);

			&:hover {
				background: var(--color-primary-element-light);
			}
		}
	}

	&__count {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		min-width: 20px;
		height: 20px;
		padding: 0 6px;
		border-radius: 10px;
		background: var(--color-background-dark);
		font-size: 12px;
		font-weight: 600;
	}
}

.forms-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
	gap: 20px;
	padding: 0 20px 20px 44px;
}

.empty-tab {
	display: flex;
	justify-content: center;
	align-items: center;
	padding: 40px 20px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

@media (prefers-reduced-motion: reduce) {
	.forms-tabs__tab {
		transition: none;
	}
}

@media (max-width: 768px) {
	.forms-header {
		flex-direction: column;
		align-items: stretch;
		margin: 0 16px 16px;
		gap: 12px;
	}

	.forms-tabs {
		overflow-x: auto;
		scrollbar-width: none;

		&::-webkit-scrollbar {
			display: none;
		}

		&__tab {
			white-space: nowrap;
		}
	}

	.forms-grid {
		grid-template-columns: 1fr;
		padding: 0 16px 16px;
	}
}
</style>
