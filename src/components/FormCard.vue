<template>
	<div
		class="form-card"
		:style="{ '--card-color': cardColor }"
		@click="$emit('click')"
	>
		<div class="form-card__header">
			<span v-if="formType" class="form-card__badge">{{ formType }}</span>
		</div>
		<div class="form-card__body">
			<div class="form-card__title-row">
				<h3 class="form-card__title">{{ form.title }}</h3>
				<button
					type="button"
					class="form-card__favorite"
					:class="{ 'form-card__favorite--active': form.favorite }"
					:aria-label="form.favorite ? t('Remove from favorites') : t('Add to favorites')"
					:title="form.favorite ? t('Remove from favorites') : t('Add to favorites')"
					@click.stop="$emit('toggle-favorite')"
				>
					<StarIcon :size="18" />
				</button>
			</div>
			<p v-if="form.description" class="form-card__description">
				{{ truncate(form.description, 60) }}
			</p>
			<div class="form-card__meta">
				<span class="form-card__responses">
					<ChatIcon :size="14" />
					{{ form.responseCount }}
				</span>
				<span class="form-card__date">
					{{ formatDate(form.modifiedAt) }}
				</span>
			</div>
		</div>
		<div class="form-card__actions">
			<NcActions>
				<NcActionButton @click.stop="$emit('delete')">
					<template #icon>
						<DeleteIcon :size="20" />
					</template>
					{{ t('Delete') }}
				</NcActionButton>
			</NcActions>
		</div>
	</div>
</template>

<script>
import { computed } from 'vue'
import { NcActions, NcActionButton } from '@nextcloud/vue'
import { t } from '@/utils/l10n'
import StarIcon from './icons/StarIcon.vue'
import DeleteIcon from './icons/DeleteIcon.vue'
import ChatIcon from './icons/ChatIcon.vue'

// Template colors matching TemplateGallery
const TEMPLATE_COLORS = {
	survey: '#9C27B0',
	poll: '#FF9800',
	registration: '#4CAF50',
	demo: '#E91E63',
	blank: '#2196F3',
	default: '#0082c9',
}

const TEMPLATE_LABELS = {
	survey: 'Survey',
	poll: 'Poll',
	registration: 'Registration',
	demo: 'Demo',
}

export default {
	name: 'FormCard',
	components: {
		NcActions,
		NcActionButton,
		StarIcon,
		DeleteIcon,
		ChatIcon,
	},
	props: {
		form: {
			type: Object,
			required: true,
		},
	},
	emits: ['click', 'delete', 'toggle-favorite'],
	setup(props) {
		const cardColor = computed(() => {
			const template = props.form.template || 'default'
			return TEMPLATE_COLORS[template] || TEMPLATE_COLORS.default
		})

		const formType = computed(() => {
			const template = props.form.template
			if (template && TEMPLATE_LABELS[template]) {
				return t(TEMPLATE_LABELS[template])
			}
			return null
		})

		const truncate = (text, length) => {
			if (!text) return ''
			if (text.length <= length) return text
			return text.substring(0, length) + '...'
		}

		const formatDate = (dateString) => {
			if (!dateString) return ''
			const date = new Date(dateString)
			return date.toLocaleDateString()
		}

		return {
			cardColor,
			formType,
			truncate,
			formatDate,
			t,
		}
	},
}
</script>

<style scoped lang="scss">
.form-card {
	display: flex;
	flex-direction: column;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 10px);
	cursor: pointer;
	overflow: hidden;
	transition: box-shadow 0.2s ease-in-out, border-color 0.2s ease-in-out, transform 0.2s ease-in-out;

	&:hover {
		border-color: var(--card-color);
		box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
		transform: translateY(-2px);
	}

	&__header {
		height: 60px;
		background: var(--card-color);
		display: flex;
		align-items: flex-start;
		justify-content: flex-end;
		padding: 8px;
	}

	&__badge {
		display: inline-block;
		padding: 4px 10px;
		border-radius: var(--border-radius-pill, 100px);
		background: rgba(255, 255, 255, 0.9);
		color: var(--card-color);
		font-size: 11px;
		font-weight: 600;
		text-transform: uppercase;
		letter-spacing: 0.5px;
	}

	&__body {
		flex: 1;
		padding: 16px;
	}

	&__title-row {
		display: flex;
		align-items: flex-start;
		gap: 8px;
		margin-bottom: 8px;
	}

	&__title {
		flex: 1;
		margin: 0;
		font-size: 16px;
		font-weight: 600;
		color: var(--color-main-text);
		overflow: hidden;
		text-overflow: ellipsis;
		display: -webkit-box;
		-webkit-line-clamp: 2;
		-webkit-box-orient: vertical;
	}

	&__favorite {
		flex-shrink: 0;
		display: flex;
		align-items: center;
		justify-content: center;
		width: 32px;
		height: 32px;
		padding: 0;
		border: none;
		border-radius: 50%;
		background: transparent;
		color: var(--color-text-maxcontrast);
		cursor: pointer;
		transition: color 0.2s ease-in-out, background-color 0.2s ease-in-out;

		&:hover {
			background: var(--color-background-hover);
			color: #f39c12;
		}

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 2px;
		}

		&--active {
			color: #f39c12;

			svg {
				fill: #f39c12;
			}
		}
	}

	&__description {
		margin: 0 0 12px;
		font-size: 13px;
		color: var(--color-text-maxcontrast);
		line-height: 1.4;
		overflow: hidden;
		text-overflow: ellipsis;
		display: -webkit-box;
		-webkit-line-clamp: 2;
		-webkit-box-orient: vertical;
	}

	&__meta {
		display: flex;
		align-items: center;
		gap: 16px;
		font-size: 12px;
		color: var(--color-text-maxcontrast);
	}

	&__responses {
		display: flex;
		align-items: center;
		gap: 4px;
		font-weight: 500;
	}

	&__date {
		margin-left: auto;
	}

	&__actions {
		position: absolute;
		top: 68px;
		right: 8px;
		opacity: 0;
		transition: opacity 0.2s ease-in-out;
	}

	&:hover &__actions {
		opacity: 1;
	}

	// Make the card position relative for absolute positioning of actions
	position: relative;
}

@media (prefers-reduced-motion: reduce) {
	.form-card {
		transition: none;

		&:hover {
			transform: none;
		}

		&__favorite,
		&__actions {
			transition: none;
		}
	}
}
</style>
