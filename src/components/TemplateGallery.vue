<template>
	<div class="template-gallery" :class="{ 'template-gallery--collapsed': isCollapsed }">
		<div class="template-gallery__header">
			<h3 class="template-gallery__title">{{ t('Explore templates') }}</h3>
			<NcButton
				type="tertiary"
				:aria-label="isCollapsed ? t('Show templates') : t('Hide templates')"
				@click="toggleCollapsed"
			>
				<template #icon>
					<ChevronIcon :size="20" :class="{ 'chevron--up': !isCollapsed }" />
				</template>
				{{ isCollapsed ? t('Show templates') : t('Hide templates') }}
			</NcButton>
		</div>

		<Transition name="slide">
			<div v-if="!isCollapsed" class="template-gallery__content">
				<div class="template-gallery__scroll">
					<TemplateCard
						v-for="template in templates"
						:key="template.id"
						:name="template.name"
						:description="template.description"
						:icon="template.icon"
						:color="template.color"
						@select="$emit('select-template', template.id)"
					/>
				</div>
			</div>
		</Transition>
	</div>
</template>

<script>
import { ref, onMounted } from 'vue'
import { NcButton } from '@nextcloud/vue'
import { t } from '@/utils/l10n'
import TemplateCard from './TemplateCard.vue'
import ChevronIcon from './icons/ChevronIcon.vue'
import FormIcon from './icons/FormIcon.vue'
import PollIcon from './icons/PollIcon.vue'
import SurveyIcon from './icons/SurveyIcon.vue'
import RegistrationIcon from './icons/RegistrationIcon.vue'
import DemoIcon from './icons/DemoIcon.vue'

const STORAGE_KEY = 'formvox-templates-collapsed'

export default {
	name: 'TemplateGallery',
	components: {
		NcButton,
		TemplateCard,
		ChevronIcon,
	},
	emits: ['select-template'],
	setup() {
		const isCollapsed = ref(false)

		const templates = [
			{
				id: 'survey',
				name: t('Survey'),
				description: t('Feedback and opinions'),
				icon: SurveyIcon,
				color: '#9C27B0',
			},
			{
				id: 'poll',
				name: t('Poll'),
				description: t('Quick voting form'),
				icon: PollIcon,
				color: '#FF9800',
			},
			{
				id: 'registration',
				name: t('Registration'),
				description: t('Collect contact info'),
				icon: RegistrationIcon,
				color: '#4CAF50',
			},
			{
				id: 'demo',
				name: t('Demo Form'),
				description: t('All features showcase'),
				icon: DemoIcon,
				color: '#E91E63',
			},
			{
				id: 'blank',
				name: t('Blank form'),
				description: t('Start from scratch'),
				icon: FormIcon,
				color: '#2196F3',
			},
		]

		const toggleCollapsed = () => {
			isCollapsed.value = !isCollapsed.value
			try {
				localStorage.setItem(STORAGE_KEY, isCollapsed.value ? 'true' : 'false')
			} catch (e) {
				// localStorage not available
			}
		}

		onMounted(() => {
			try {
				const stored = localStorage.getItem(STORAGE_KEY)
				if (stored === 'true') {
					isCollapsed.value = true
				}
			} catch (e) {
				// localStorage not available
			}
		})

		return {
			isCollapsed,
			templates,
			toggleCollapsed,
			t,
		}
	},
}
</script>

<style scoped lang="scss">
.template-gallery {
	margin-bottom: 24px;
	padding: 0 20px;

	&__header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		margin-bottom: 16px;
	}

	&__title {
		margin: 0;
		font-size: 18px;
		font-weight: 600;
		color: var(--color-main-text);
	}

	&__content {
		overflow: hidden;
	}

	&__scroll {
		display: flex;
		gap: 16px;
		overflow-x: auto;
		padding-bottom: 8px;
		scrollbar-width: thin;
		scrollbar-color: var(--color-border) transparent;

		&::-webkit-scrollbar {
			height: 6px;
		}

		&::-webkit-scrollbar-track {
			background: transparent;
		}

		&::-webkit-scrollbar-thumb {
			background: var(--color-border);
			border-radius: 3px;
		}
	}

	.chevron--up {
		transform: rotate(180deg);
	}
}

.slide-enter-active,
.slide-leave-active {
	transition: max-height 0.3s ease-in-out, opacity 0.3s ease-in-out;
	max-height: 200px;
	opacity: 1;
}

.slide-enter-from,
.slide-leave-to {
	max-height: 0;
	opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
	.slide-enter-active,
	.slide-leave-active {
		transition: none;
	}

	.template-gallery .chevron--up {
		transition: none;
	}
}

@media (max-width: 768px) {
	.template-gallery {
		padding: 0 16px;

		&__scroll {
			gap: 12px;
		}
	}
}
</style>
