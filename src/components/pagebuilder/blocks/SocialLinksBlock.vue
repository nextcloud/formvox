<template>
  <div class="block-social-links" :class="alignmentClass">
    <div class="social-icons">
      <a
        v-for="link in visibleLinks"
        :key="link.platform"
        :href="editMode ? undefined : link.url"
        :title="link.platform"
        class="social-icon"
        :class="{ 'edit-mode': editMode }"
        target="_blank"
        rel="noopener noreferrer"
      >
        <component :is="getSocialIcon(link.platform)" :size="24" />
      </a>
    </div>
    <p v-if="editMode && visibleLinks.length === 0" class="placeholder">
      {{ t('Add social links in settings') }}
    </p>
  </div>
</template>

<script>
import { computed } from 'vue';
import { t } from '@/utils/l10n';
import FacebookIcon from '../../icons/FacebookIcon.vue';
import TwitterIcon from '../../icons/TwitterIcon.vue';
import LinkedInIcon from '../../icons/LinkedInIcon.vue';
import InstagramIcon from '../../icons/InstagramIcon.vue';
import YouTubeIcon from '../../icons/YouTubeIcon.vue';
import GlobeIcon from '../../icons/GlobeIcon.vue';

const SOCIAL_ICONS = {
  facebook: FacebookIcon,
  twitter: TwitterIcon,
  linkedin: LinkedInIcon,
  instagram: InstagramIcon,
  youtube: YouTubeIcon,
  website: GlobeIcon,
};

export default {
  name: 'SocialLinksBlock',
  props: {
    block: { type: Object, required: true },
    editMode: { type: Boolean, default: false },
  },
  setup(props) {
    const alignmentClass = computed(() => `align-${props.block.alignment || 'center'}`);

    const visibleLinks = computed(() => {
      return (props.block.settings.links || []).filter(link => link.url);
    });

    const getSocialIcon = (platform) => {
      return SOCIAL_ICONS[platform] || GlobeIcon;
    };

    return { alignmentClass, visibleLinks, getSocialIcon, t };
  },
};
</script>

<style scoped lang="scss">
.block-social-links {
  padding: 12px 0;

  &.align-left { text-align: left; }
  &.align-center { text-align: center; }
  &.align-right { text-align: right; }

  .social-icons {
    display: inline-flex;
    gap: 16px;
  }

  .social-icon {
    color: var(--color-main-text);
    opacity: 0.7;
    transition: opacity 0.2s;

    &:hover {
      opacity: 1;
    }

    &.edit-mode {
      cursor: default;
      pointer-events: none;
    }
  }

  .placeholder {
    color: var(--color-text-maxcontrast);
    font-style: italic;
    margin: 0;
  }
}
</style>
