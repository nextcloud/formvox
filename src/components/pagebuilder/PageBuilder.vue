<template>
  <div class="page-builder">
    <div class="builder-header">
      <h2>{{ t('Page Builder') }}</h2>
      <p class="description">{{ t('Customize the appearance of your public forms.') }}</p>
    </div>

    <div class="builder-tabs">
      <button
        v-for="zone in zones"
        :key="zone.id"
        class="tab-button"
        :class="{ active: activeZone === zone.id }"
        @click="activeZone = zone.id"
      >
        {{ zone.label }}
      </button>
      <button
        class="tab-button styles-tab"
        :class="{ active: activeZone === 'styles' }"
        @click="activeZone = 'styles'"
      >
        <CogIcon :size="16" />
        {{ t('Global Styles') }}
      </button>
    </div>

    <div class="builder-content">
      <!-- Global Styles Panel -->
      <div v-if="activeZone === 'styles'" class="styles-panel">
        <!-- Theme Presets -->
        <div class="style-field">
          <label>{{ t('Theme presets') }}</label>
          <div class="theme-presets">
            <button
              v-for="preset in themePresets"
              :key="preset.id"
              class="theme-preset"
              :class="{ active: isActivePreset(preset) }"
              @click="applyTheme(preset)"
            >
              <div class="preset-colors">
                <span class="preset-primary" :style="{ backgroundColor: preset.primaryColor }"></span>
                <span class="preset-bg" :style="{ backgroundColor: preset.backgroundColor }"></span>
              </div>
              <span class="preset-name">{{ preset.name }}</span>
            </button>
          </div>
        </div>

        <!-- Custom Colors -->
        <div class="style-field">
          <label>{{ t('Primary color') }}</label>
          <div class="color-input">
            <input v-model="globalStyles.primaryColor" type="color" @change="saveStyles" />
            <input v-model="globalStyles.primaryColor" type="text" @change="saveStyles" />
          </div>
        </div>
        <div class="style-field">
          <label>{{ t('Background color') }}</label>
          <div class="color-input">
            <input v-model="globalStyles.backgroundColor" type="color" @change="saveStyles" />
            <input v-model="globalStyles.backgroundColor" type="text" @change="saveStyles" />
          </div>
        </div>
      </div>

      <!-- Zone Builder -->
      <template v-else>
        <div class="builder-main">
          <div class="blocks-list">
            <draggable
              v-model="currentBlocks"
              item-key="id"
              handle=".block-handle"
              @end="onDragEnd"
            >
              <template #item="{ element }">
                <div
                  class="block-item"
                  :class="{ selected: selectedBlockId === element.id }"
                  @click="selectBlock(element.id)"
                >
                  <div class="block-handle">
                    <DragIcon :size="16" />
                  </div>
                  <div class="block-content">
                    <BlockRenderer
                      :block="element"
                      :edit-mode="true"
                      :global-styles="globalStyles"
                      @update="updateBlock"
                      @upload-image="uploadBlockImage"
                    />
                  </div>
                  <div class="block-actions">
                    <NcButton type="tertiary" @click.stop="deleteBlock(element.id)">
                      <template #icon>
                        <DeleteIcon :size="16" />
                      </template>
                    </NcButton>
                  </div>
                </div>
              </template>
            </draggable>

            <div v-if="currentBlocks.length === 0" class="empty-zone">
              <p>{{ t('No blocks yet. Add one below.') }}</p>
            </div>

            <NcActions class="add-block-menu">
              <template #icon>
                <PlusIcon :size="20" />
              </template>
              <NcActionButton
                v-for="blockType in blockTypes"
                :key="blockType.type"
                @click="addBlock(blockType.type)"
              >
                <template #icon>
                  <component :is="blockType.icon" :size="20" />
                </template>
                {{ blockType.label }}
              </NcActionButton>
            </NcActions>
          </div>

          <div v-if="selectedBlock" class="block-editor">
            <h3>{{ t('Block Settings') }}</h3>

            <!-- Alignment -->
            <div class="editor-field">
              <label>{{ t('Alignment') }}</label>
              <div class="alignment-buttons">
                <button
                  :class="{ active: selectedBlock.alignment === 'left' }"
                  @click="updateBlockAlignment('left')"
                >
                  {{ t('Left') }}
                </button>
                <button
                  :class="{ active: selectedBlock.alignment === 'center' }"
                  @click="updateBlockAlignment('center')"
                >
                  {{ t('Center') }}
                </button>
                <button
                  :class="{ active: selectedBlock.alignment === 'right' }"
                  @click="updateBlockAlignment('right')"
                >
                  {{ t('Right') }}
                </button>
              </div>
            </div>

            <!-- Block-specific settings -->
            <template v-if="selectedBlock.type === 'heading'">
              <div class="editor-field">
                <label>{{ t('Level') }}</label>
                <NcSelect
                  v-model="selectedBlock.settings.level"
                  :options="headingLevels"
                  @update:model-value="saveLayout"
                />
              </div>
              <div class="editor-field">
                <label>{{ t('Text') }}</label>
                <NcTextField
                  v-model="selectedBlock.settings.text"
                  @update:model-value="debouncedSave"
                />
              </div>
              <div class="editor-field">
                <label>{{ t('Color') }}</label>
                <div class="color-input">
                  <input
                    v-model="selectedBlock.settings.color"
                    type="color"
                    @change="saveLayout"
                  />
                  <input
                    v-model="selectedBlock.settings.color"
                    type="text"
                    :placeholder="t('Default')"
                    @change="saveLayout"
                  />
                  <button
                    v-if="selectedBlock.settings.color"
                    class="reset-btn"
                    @click="selectedBlock.settings.color = ''; saveLayout()"
                  >
                    ✕
                  </button>
                </div>
              </div>
            </template>

            <template v-else-if="selectedBlock.type === 'text'">
              <div class="editor-field">
                <label>{{ t('Content') }}</label>
                <NcTextArea
                  v-model="selectedBlock.settings.content"
                  :rows="4"
                  @update:model-value="debouncedSave"
                />
              </div>
              <div class="editor-field">
                <label>{{ t('Color') }}</label>
                <div class="color-input">
                  <input
                    v-model="selectedBlock.settings.color"
                    type="color"
                    @change="saveLayout"
                  />
                  <input
                    v-model="selectedBlock.settings.color"
                    type="text"
                    :placeholder="t('Default')"
                    @change="saveLayout"
                  />
                  <button
                    v-if="selectedBlock.settings.color"
                    class="reset-btn"
                    @click="selectedBlock.settings.color = ''; saveLayout()"
                  >
                    ✕
                  </button>
                </div>
              </div>
            </template>

            <template v-else-if="selectedBlock.type === 'spacer'">
              <div class="editor-field">
                <label>{{ t('Size') }}</label>
                <NcSelect
                  v-model="selectedBlock.settings.size"
                  :options="spacerSizes"
                  @update:model-value="saveLayout"
                />
              </div>
            </template>

            <template v-else-if="selectedBlock.type === 'divider'">
              <div class="editor-field">
                <label>{{ t('Color') }}</label>
                <input
                  v-model="selectedBlock.settings.color"
                  type="color"
                  @change="saveLayout"
                />
              </div>
              <div class="editor-field">
                <label>{{ t('Style') }}</label>
                <NcSelect
                  v-model="selectedBlock.settings.style"
                  :options="dividerStyles"
                  @update:model-value="saveLayout"
                />
              </div>
            </template>

            <template v-else-if="selectedBlock.type === 'button'">
              <div class="editor-field">
                <label>{{ t('Button text') }}</label>
                <NcTextField
                  v-model="selectedBlock.settings.text"
                  @update:model-value="debouncedSave"
                />
              </div>
              <div class="editor-field">
                <label>{{ t('URL') }}</label>
                <NcTextField
                  v-model="selectedBlock.settings.url"
                  placeholder="https://..."
                  @update:model-value="debouncedSave"
                />
              </div>
              <div class="editor-field">
                <label>{{ t('Background color') }}</label>
                <div class="color-input">
                  <input
                    v-model="selectedBlock.settings.backgroundColor"
                    type="color"
                    @change="saveLayout"
                  />
                  <input
                    v-model="selectedBlock.settings.backgroundColor"
                    type="text"
                    :placeholder="t('Primary color')"
                    @change="saveLayout"
                  />
                  <button
                    v-if="selectedBlock.settings.backgroundColor"
                    class="reset-btn"
                    @click="selectedBlock.settings.backgroundColor = ''; saveLayout()"
                  >
                    ✕
                  </button>
                </div>
              </div>
              <div class="editor-field">
                <label>{{ t('Text color') }}</label>
                <div class="color-input">
                  <input
                    v-model="selectedBlock.settings.textColor"
                    type="color"
                    @change="saveLayout"
                  />
                  <input
                    v-model="selectedBlock.settings.textColor"
                    type="text"
                    placeholder="#ffffff"
                    @change="saveLayout"
                  />
                  <button
                    v-if="selectedBlock.settings.textColor"
                    class="reset-btn"
                    @click="selectedBlock.settings.textColor = ''; saveLayout()"
                  >
                    ✕
                  </button>
                </div>
              </div>
              <div class="editor-field">
                <NcCheckboxRadioSwitch
                  :checked.sync="selectedBlock.settings.newTab"
                  @update:checked="saveLayout"
                >
                  {{ t('Open in new tab') }}
                </NcCheckboxRadioSwitch>
              </div>
            </template>

            <template v-else-if="selectedBlock.type === 'html'">
              <div class="editor-field">
                <label>{{ t('HTML Content') }}</label>
                <NcTextArea
                  v-model="selectedBlock.settings.content"
                  :rows="6"
                  placeholder="<p>Your HTML here...</p>"
                  @update:model-value="debouncedSave"
                />
              </div>
            </template>

            <template v-else-if="selectedBlock.type === 'image'">
              <div class="editor-field">
                <label>{{ t('Alt text') }}</label>
                <NcTextField
                  v-model="selectedBlock.settings.alt"
                  :placeholder="t('Image description')"
                  @update:model-value="debouncedSave"
                />
              </div>
            </template>

            <template v-else-if="selectedBlock.type === 'socialLinks'">
              <div class="editor-field">
                <label>{{ t('Social Links') }}</label>
                <div
                  v-for="platform in socialPlatforms"
                  :key="platform.id"
                  class="social-link-field"
                >
                  <span class="platform-label">{{ platform.label }}</span>
                  <NcTextField
                    :model-value="getSocialLinkUrl(platform.id)"
                    :placeholder="platform.placeholder"
                    @update:model-value="v => setSocialLinkUrl(platform.id, v)"
                  />
                </div>
              </div>
            </template>

            <template v-else-if="selectedBlock.type === 'progressBar'">
              <div class="editor-field">
                <label>{{ t('Demo progress (%)') }}</label>
                <NcTextField
                  v-model.number="selectedBlock.settings.demoProgress"
                  type="number"
                  min="0"
                  max="100"
                  @update:model-value="debouncedSave"
                />
              </div>
              <div class="editor-field">
                <label>{{ t('Bar color') }}</label>
                <div class="color-input">
                  <input
                    v-model="selectedBlock.settings.color"
                    type="color"
                    @change="saveLayout"
                  />
                  <input
                    v-model="selectedBlock.settings.color"
                    type="text"
                    :placeholder="t('Primary color')"
                    @change="saveLayout"
                  />
                  <button
                    v-if="selectedBlock.settings.color"
                    class="reset-btn"
                    @click="selectedBlock.settings.color = ''; saveLayout()"
                  >
                    ✕
                  </button>
                </div>
              </div>
              <div class="editor-field">
                <NcCheckboxRadioSwitch
                  :checked.sync="selectedBlock.settings.showPercentage"
                  @update:checked="saveLayout"
                >
                  {{ t('Show percentage') }}
                </NcCheckboxRadioSwitch>
              </div>
            </template>
          </div>
        </div>
      </template>
    </div>

    <!-- Preview -->
    <div class="builder-preview">
      <h3>{{ t('Preview') }}</h3>
      <div class="preview-container" :style="previewStyles">
        <div v-for="block in layout.header" :key="block.id" class="preview-block">
          <BlockRenderer :block="block" :edit-mode="true" :global-styles="globalStyles" />
        </div>
        <div class="preview-form-placeholder">
          <div class="form-title">{{ t('Form Title') }}</div>
          <div class="form-question">{{ t('Sample question') }}</div>
          <div class="form-input"></div>
          <div class="form-button" :style="{ backgroundColor: globalStyles.primaryColor }">
            {{ t('Submit') }}
          </div>
        </div>
        <div v-for="block in layout.footer" :key="block.id" class="preview-block">
          <BlockRenderer :block="block" :edit-mode="true" :global-styles="globalStyles" />
        </div>
      </div>
    </div>

    <div v-if="saving" class="saving-indicator">
      {{ t('Saving...') }}
    </div>
  </div>
</template>

<script>
import { ref, reactive, computed, watch } from 'vue';
import {
  NcButton,
  NcActions,
  NcActionButton,
  NcTextField,
  NcTextArea,
  NcSelect,
  NcCheckboxRadioSwitch,
} from '@nextcloud/vue';
import { generateUrl } from '@nextcloud/router';
import axios from '@nextcloud/axios';
import { showError, showSuccess } from '@nextcloud/dialogs';
import { t } from '@/utils/l10n';
import { v4 as uuidv4 } from 'uuid';
import draggable from 'vuedraggable';
import BlockRenderer from './BlockRenderer.vue';
import PlusIcon from '../icons/PlusIcon.vue';
import DeleteIcon from '../icons/DeleteIcon.vue';
import DragIcon from '../icons/DragIcon.vue';
import ImageIcon from '../icons/ImageIcon.vue';
import TextIcon from '../icons/TextIcon.vue';
import CogIcon from '../icons/CogIcon.vue';

export default {
  name: 'PageBuilder',
  components: {
    NcButton,
    NcActions,
    NcActionButton,
    NcTextField,
    NcTextArea,
    NcSelect,
    NcCheckboxRadioSwitch,
    draggable,
    BlockRenderer,
    PlusIcon,
    DeleteIcon,
    DragIcon,
    CogIcon,
  },
  props: {
    initialBranding: {
      type: Object,
      required: true,
    },
  },
  setup(props) {
    const layout = reactive({ ...props.initialBranding.layout });
    const globalStyles = reactive({ ...props.initialBranding.globalStyles });
    const saving = ref(false);
    const activeZone = ref('header');
    const selectedBlockId = ref(null);

    let saveTimeout = null;

    const zones = [
      { id: 'header', label: t('Header') },
      { id: 'footer', label: t('Footer') },
      { id: 'thankYou', label: t('Thank You Page') },
    ];

    const blockTypes = [
      { type: 'logo', label: t('Logo'), icon: ImageIcon },
      { type: 'heading', label: t('Heading'), icon: TextIcon },
      { type: 'text', label: t('Text'), icon: TextIcon },
      { type: 'image', label: t('Image'), icon: ImageIcon },
      { type: 'spacer', label: t('Spacer'), icon: TextIcon },
      { type: 'divider', label: t('Divider'), icon: TextIcon },
      { type: 'button', label: t('Button'), icon: TextIcon },
      { type: 'socialLinks', label: t('Social Links'), icon: TextIcon },
      { type: 'html', label: t('HTML'), icon: TextIcon },
      { type: 'progressBar', label: t('Progress Bar'), icon: TextIcon },
    ];

    const headingLevels = [
      { value: 'h1', label: 'H1' },
      { value: 'h2', label: 'H2' },
      { value: 'h3', label: 'H3' },
      { value: 'h4', label: 'H4' },
    ];

    const spacerSizes = [
      { value: 'small', label: t('Small (16px)') },
      { value: 'medium', label: t('Medium (32px)') },
      { value: 'large', label: t('Large (64px)') },
    ];

    const dividerStyles = [
      { value: 'solid', label: t('Solid') },
      { value: 'dashed', label: t('Dashed') },
      { value: 'dotted', label: t('Dotted') },
    ];

    const socialPlatforms = [
      { id: 'facebook', label: 'Facebook', placeholder: 'https://facebook.com/...' },
      { id: 'twitter', label: 'X (Twitter)', placeholder: 'https://x.com/...' },
      { id: 'linkedin', label: 'LinkedIn', placeholder: 'https://linkedin.com/...' },
      { id: 'instagram', label: 'Instagram', placeholder: 'https://instagram.com/...' },
      { id: 'youtube', label: 'YouTube', placeholder: 'https://youtube.com/...' },
      { id: 'website', label: t('Website'), placeholder: 'https://...' },
    ];

    // Theme presets
    const themePresets = [
      { id: 'nextcloud', name: 'Nextcloud', primaryColor: '#0082c9', backgroundColor: '#ffffff' },
      { id: 'nextcloud-dark', name: 'Nextcloud Dark', primaryColor: '#0082c9', backgroundColor: '#171717' },
      { id: 'ocean', name: 'Ocean', primaryColor: '#006494', backgroundColor: '#e8f4f8' },
      { id: 'forest', name: 'Forest', primaryColor: '#2d6a4f', backgroundColor: '#f1faee' },
      { id: 'sunset', name: 'Sunset', primaryColor: '#e07a5f', backgroundColor: '#fff8f0' },
      { id: 'purple', name: 'Purple', primaryColor: '#7209b7', backgroundColor: '#faf5ff' },
      { id: 'dark', name: 'Dark', primaryColor: '#818cf8', backgroundColor: '#1e1e2e' },
      { id: 'minimal', name: 'Minimal', primaryColor: '#374151', backgroundColor: '#ffffff' },
    ];

    const currentBlocks = computed({
      get: () => layout[activeZone.value] || [],
      set: (value) => {
        layout[activeZone.value] = value;
      },
    });

    const selectedBlock = computed(() => {
      if (!selectedBlockId.value) return null;
      return currentBlocks.value.find(b => b.id === selectedBlockId.value);
    });

    const previewStyles = computed(() => ({
      backgroundColor: globalStyles.backgroundColor,
    }));

    const saveLayout = async () => {
      saving.value = true;
      try {
        await axios.put(generateUrl('/apps/formvox/api/branding/layout'), { layout });
      } catch (error) {
        showError(t('Failed to save layout'));
        console.error(error);
      } finally {
        saving.value = false;
      }
    };

    const saveStyles = async () => {
      saving.value = true;
      try {
        await axios.put(generateUrl('/apps/formvox/api/branding/styles'), { globalStyles });
      } catch (error) {
        showError(t('Failed to save styles'));
        console.error(error);
      } finally {
        saving.value = false;
      }
    };

    const debouncedSave = () => {
      if (saveTimeout) clearTimeout(saveTimeout);
      saveTimeout = setTimeout(saveLayout, 500);
    };

    const addBlock = (type) => {
      const newBlock = {
        id: uuidv4(),
        type,
        alignment: 'center',
        settings: getDefaultSettings(type),
      };
      currentBlocks.value.push(newBlock);
      selectedBlockId.value = newBlock.id;
      saveLayout();
    };

    const getDefaultSettings = (type) => {
      switch (type) {
        case 'heading':
          return { level: 'h1', text: '' };
        case 'text':
          return { content: '' };
        case 'spacer':
          return { size: 'medium' };
        case 'divider':
          return { color: '#cccccc', style: 'solid', thickness: 1 };
        case 'button':
          return { text: 'Click here', url: '', newTab: false };
        case 'socialLinks':
          return { links: [] };
        case 'progressBar':
          return { demoProgress: 50, showPercentage: true };
        default:
          return {};
      }
    };

    const deleteBlock = (blockId) => {
      const index = currentBlocks.value.findIndex(b => b.id === blockId);
      if (index !== -1) {
        currentBlocks.value.splice(index, 1);
        if (selectedBlockId.value === blockId) {
          selectedBlockId.value = null;
        }
        saveLayout();
      }
    };

    const selectBlock = (blockId) => {
      selectedBlockId.value = blockId;
    };

    const updateBlock = (updatedBlock) => {
      const index = currentBlocks.value.findIndex(b => b.id === updatedBlock.id);
      if (index !== -1) {
        currentBlocks.value[index] = updatedBlock;
        saveLayout();
      }
    };

    const updateBlockAlignment = (alignment) => {
      if (selectedBlock.value) {
        selectedBlock.value.alignment = alignment;
        saveLayout();
      }
    };

    const onDragEnd = () => {
      saveLayout();
    };

    const uploadBlockImage = async ({ blockId, file }) => {
      const formData = new FormData();
      formData.append('image', file);

      saving.value = true;
      try {
        const response = await axios.post(
          generateUrl('/apps/formvox/api/branding/image/{blockId}', { blockId }),
          formData,
          { headers: { 'Content-Type': 'multipart/form-data' } }
        );

        // Update block with new image URL
        const block = currentBlocks.value.find(b => b.id === blockId);
        if (block) {
          block.settings.imageId = response.data.imageId;
          block.settings.imageUrl = generateUrl('/apps/formvox/branding/image/{blockId}', { blockId }) + '?t=' + Date.now();
          saveLayout();
        }
        showSuccess(t('Image uploaded'));
      } catch (error) {
        showError(error.response?.data?.error || t('Failed to upload image'));
        console.error(error);
      } finally {
        saving.value = false;
      }
    };

    const getSocialLinkUrl = (platform) => {
      const links = selectedBlock.value?.settings?.links || [];
      const link = links.find(l => l.platform === platform);
      return link?.url || '';
    };

    const setSocialLinkUrl = (platform, url) => {
      if (!selectedBlock.value) return;
      if (!selectedBlock.value.settings.links) {
        selectedBlock.value.settings.links = [];
      }
      const links = selectedBlock.value.settings.links;
      const existing = links.find(l => l.platform === platform);
      if (existing) {
        existing.url = url;
      } else if (url) {
        links.push({ platform, url });
      }
      debouncedSave();
    };

    const applyTheme = (preset) => {
      globalStyles.primaryColor = preset.primaryColor;
      globalStyles.backgroundColor = preset.backgroundColor;
      saveStyles();
    };

    const isActivePreset = (preset) => {
      return globalStyles.primaryColor?.toLowerCase() === preset.primaryColor.toLowerCase() &&
             globalStyles.backgroundColor?.toLowerCase() === preset.backgroundColor.toLowerCase();
    };

    return {
      layout,
      globalStyles,
      saving,
      activeZone,
      selectedBlockId,
      zones,
      blockTypes,
      headingLevels,
      spacerSizes,
      dividerStyles,
      socialPlatforms,
      currentBlocks,
      selectedBlock,
      previewStyles,
      saveLayout,
      saveStyles,
      debouncedSave,
      addBlock,
      deleteBlock,
      selectBlock,
      updateBlock,
      updateBlockAlignment,
      onDragEnd,
      uploadBlockImage,
      getSocialLinkUrl,
      setSocialLinkUrl,
      themePresets,
      applyTheme,
      isActivePreset,
      t,
    };
  },
};
</script>

<style scoped lang="scss">
.page-builder {
  max-width: 1200px;
  padding: 20px;
}

.builder-header {
  margin-bottom: 24px;

  h2 { margin: 0 0 8px; }
  .description { color: var(--color-text-maxcontrast); margin: 0; }
}

.builder-tabs {
  display: flex;
  gap: 4px;
  margin-bottom: 20px;
  padding: 4px;
  background: var(--color-background-hover);
  border-radius: var(--border-radius-large);

  .tab-button {
    padding: 8px 16px;
    border: none;
    background: transparent;
    border-radius: var(--border-radius);
    cursor: pointer;
    font-weight: 500;

    &:hover { background: var(--color-background-dark); }
    &.active { background: var(--color-main-background); box-shadow: 0 1px 3px rgba(0,0,0,0.1); }

    &.styles-tab {
      display: flex;
      align-items: center;
      gap: 6px;
      background: var(--color-primary-element-light);
      color: var(--color-primary);

      &:hover {
        background: var(--color-primary-element);
        color: white;
      }

      &.active {
        background: var(--color-primary);
        color: white;
      }
    }
  }
}

.builder-content {
  margin-bottom: 24px;
}

.styles-panel {
  padding: 20px;
  background: var(--color-background-hover);
  border-radius: var(--border-radius-large);

  .style-field {
    margin-bottom: 16px;

    label { display: block; font-weight: 500; margin-bottom: 8px; }

    .color-input {
      display: flex;
      gap: 8px;
      align-items: center;

      input[type="color"] { width: 40px; height: 40px; padding: 2px; border: 1px solid var(--color-border); border-radius: var(--border-radius); cursor: pointer; }
      input[type="text"] { width: 100px; padding: 8px; border: 1px solid var(--color-border); border-radius: var(--border-radius); font-family: monospace; }
    }

    .theme-presets {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 12px;

      .theme-preset {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 8px;
        padding: 12px;
        border: 2px solid var(--color-border);
        border-radius: var(--border-radius-large);
        background: var(--color-main-background);
        cursor: pointer;
        transition: all 0.2s;

        &:hover {
          border-color: var(--color-border-dark);
          transform: translateY(-2px);
        }

        &.active {
          border-color: var(--color-primary);
          box-shadow: 0 0 0 2px var(--color-primary-element-light);
        }

        .preset-colors {
          display: flex;
          gap: 4px;

          span {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            border: 1px solid var(--color-border);
          }
        }

        .preset-name {
          font-size: 12px;
          font-weight: 500;
          text-align: center;
        }
      }
    }
  }
}

.builder-main {
  display: grid;
  grid-template-columns: 1fr 300px;
  gap: 20px;
}

.blocks-list {
  padding: 20px;
  background: var(--color-background-hover);
  border-radius: var(--border-radius-large);
  min-height: 200px;
}

.block-item {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  padding: 12px;
  margin-bottom: 8px;
  background: var(--color-main-background);
  border: 2px solid transparent;
  border-radius: var(--border-radius);
  cursor: pointer;

  &:hover { border-color: var(--color-border-dark); }
  &.selected { border-color: var(--color-primary); }

  .block-handle { cursor: grab; padding: 4px; color: var(--color-text-maxcontrast); }
  .block-content { flex: 1; min-width: 0; }
  .block-actions { opacity: 0; transition: opacity 0.2s; }
  &:hover .block-actions { opacity: 1; }
}

.empty-zone {
  text-align: center;
  padding: 40px;
  color: var(--color-text-maxcontrast);
}

.add-block-menu {
  margin-top: 12px;
}

.block-editor {
  padding: 20px;
  background: var(--color-background-hover);
  border-radius: var(--border-radius-large);

  h3 { margin: 0 0 16px; font-size: 16px; }

  .editor-field {
    margin-bottom: 16px;

    label { display: block; font-weight: 500; margin-bottom: 8px; }
  }

  .color-input {
    display: flex;
    gap: 8px;
    align-items: center;

    input[type="color"] {
      width: 40px;
      height: 40px;
      padding: 2px;
      border: 1px solid var(--color-border);
      border-radius: var(--border-radius);
      cursor: pointer;
    }

    input[type="text"] {
      width: 100px;
      padding: 8px;
      border: 1px solid var(--color-border);
      border-radius: var(--border-radius);
      font-family: monospace;
    }

    .reset-btn {
      padding: 4px 8px;
      border: 1px solid var(--color-border);
      background: var(--color-main-background);
      border-radius: var(--border-radius);
      cursor: pointer;
      font-size: 12px;

      &:hover {
        background: var(--color-error);
        color: white;
        border-color: var(--color-error);
      }
    }
  }

  .alignment-buttons {
    display: flex;
    gap: 4px;

    button {
      flex: 1;
      padding: 8px;
      border: 1px solid var(--color-border);
      background: var(--color-main-background);
      border-radius: var(--border-radius);
      cursor: pointer;

      &:hover { background: var(--color-background-hover); }
      &.active { background: var(--color-primary); color: white; border-color: var(--color-primary); }
    }
  }

  .social-link-field {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;

    .platform-label { width: 80px; font-size: 13px; }
  }
}

.builder-preview {
  h3 { margin: 0 0 12px; font-size: 16px; }

  .preview-container {
    padding: 24px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
  }

  .preview-form-placeholder {
    padding: 20px 0;
    border-top: 1px dashed var(--color-border);
    border-bottom: 1px dashed var(--color-border);
    margin: 16px 0;

    .form-title { font-size: 20px; font-weight: 600; margin-bottom: 16px; }
    .form-question { font-weight: 500; margin-bottom: 8px; }
    .form-input { height: 40px; border: 1px solid var(--color-border); border-radius: var(--border-radius); margin-bottom: 16px; }
    .form-button { display: inline-block; padding: 10px 24px; color: white; border-radius: var(--border-radius-large); font-weight: 500; }
  }
}

.saving-indicator {
  position: fixed;
  bottom: 20px;
  right: 20px;
  padding: 12px 20px;
  background: var(--color-primary);
  color: white;
  border-radius: var(--border-radius-large);
  font-weight: 500;
}
</style>
