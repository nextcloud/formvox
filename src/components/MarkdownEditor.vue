<template>
  <div ref="wrapperEl" class="md-editor">
    <div class="md-editor__toolbar" role="toolbar" :aria-label="t('Formatting')">
      <button v-for="a in actions" :key="a.id" type="button" class="md-editor__btn"
              :class="{ active: activeStates[a.id] }"
              :title="a.title" :aria-label="a.title" @click="run(a)">
        <component :is="a.icon" :size="18" />
      </button>
    </div>
    <textarea ref="textareaEl" />
    <div class="md-editor__resize" :title="t('Drag to resize')" @mousedown="startResize" />
  </div>
</template>

<script>
import { ref, onMounted, onBeforeUnmount, watch } from 'vue';
import EasyMDE from 'easymde';
import 'easymde/dist/easymde.min.css';
import BoldIcon from 'vue-material-design-icons/FormatBold.vue';
import ItalicIcon from 'vue-material-design-icons/FormatItalic.vue';
import HeadingIcon from 'vue-material-design-icons/FormatHeader1.vue';
import ListIcon from 'vue-material-design-icons/FormatListBulleted.vue';
import OrderedIcon from 'vue-material-design-icons/FormatListNumbered.vue';
import LinkIcon from 'vue-material-design-icons/Link.vue';
import ImageIcon from 'vue-material-design-icons/Image.vue';
import EyeIcon from 'vue-material-design-icons/Eye.vue';
import { t } from '@/utils/l10n';

export default {
  name: 'MarkdownEditor',
  props: {
    modelValue: {
      type: String,
      default: '',
    },
    placeholder: {
      type: String,
      default: '',
    },
    disabled: {
      type: Boolean,
      default: false,
    },
    minHeight: {
      type: String,
      default: '90px',
    },
    maxHeight: {
      type: String,
      default: '90px',
    },
  },
  emits: ['update:model-value'],
  setup(props, { emit }) {
    const textareaEl = ref(null);
    const wrapperEl = ref(null);
    const activeStates = ref({});
    let mde = null;
    let suppressNext = false;
    let resizeObserver = null;

    // Map EasyMDE state-names → our action ids
    const stateToActionId = {
      bold: 'bold',
      italic: 'italic',
      'header-1': 'heading',
      'header-2': 'heading',
      'header-3': 'heading',
      'header-4': 'heading',
      'header-5': 'heading',
      'header-6': 'heading',
      'unordered-list': 'ul',
      'ordered-list': 'ol',
      link: 'link',
      image: 'image',
    };

    const refreshActiveStates = () => {
      if (!mde) return;
      const state = mde.getState() || {};
      const next = {};
      // EasyMDE.getState() returns an object whose truthy keys are the active states
      Object.keys(state).forEach(k => {
        if (!state[k]) return;
        const actionId = stateToActionId[k];
        if (actionId) next[actionId] = true;
      });
      // Preview is tracked via .editor-preview-active on the wrapper
      const wrapper = mde.codemirror.getWrapperElement().closest('.EasyMDEContainer');
      if (wrapper && wrapper.classList.contains('sided--no-fullscreen') === false) {
        // not really useful, leave preview indicator on demand
      }
      activeStates.value = next;
    };

    onMounted(() => {
      mde = new EasyMDE({
        element: textareaEl.value,
        initialValue: props.modelValue || '',
        placeholder: props.placeholder,
        spellChecker: false,
        nativeSpellcheck: true,
        status: false,
        autoDownloadFontAwesome: false,
        minHeight: props.minHeight,
        maxHeight: props.maxHeight,
        toolbar: false, // we render our own toolbar with NC-style icons above
      });

      mde.codemirror.on('change', () => {
        if (suppressNext) {
          suppressNext = false;
          return;
        }
        emit('update:model-value', mde.value());
        refreshActiveStates();
      });
      mde.codemirror.on('cursorActivity', refreshActiveStates);
      refreshActiveStates();

      if (wrapperEl.value && typeof ResizeObserver !== 'undefined') {
        resizeObserver = new ResizeObserver(() => {
          if (mde) mde.codemirror.refresh();
        });
        resizeObserver.observe(wrapperEl.value);
      }
    });

    watch(() => props.modelValue, (newVal) => {
      if (mde && mde.value() !== (newVal || '')) {
        suppressNext = true;
        mde.value(newVal || '');
      }
    });

    watch(() => props.disabled, (val) => {
      if (mde) mde.codemirror.setOption('readOnly', val ? 'nocursor' : false);
    });

    onBeforeUnmount(() => {
      if (resizeObserver) {
        resizeObserver.disconnect();
        resizeObserver = null;
      }
      if (mde) {
        mde.toTextArea();
        mde = null;
      }
    });

    const actions = [
      { id: 'bold', title: t('Bold'), icon: BoldIcon, fn: 'toggleBold' },
      { id: 'italic', title: t('Italic'), icon: ItalicIcon, fn: 'toggleItalic' },
      { id: 'heading', title: t('Heading'), icon: HeadingIcon, fn: 'toggleHeadingSmaller' },
      { id: 'ul', title: t('Bulleted list'), icon: ListIcon, fn: 'toggleUnorderedList' },
      { id: 'ol', title: t('Numbered list'), icon: OrderedIcon, fn: 'toggleOrderedList' },
      { id: 'link', title: t('Link'), icon: LinkIcon, fn: 'drawLink' },
      { id: 'image', title: t('Image'), icon: ImageIcon, fn: 'drawImage' },
      { id: 'preview', title: t('Toggle preview'), icon: EyeIcon, fn: 'togglePreview' },
    ];

    const run = (a) => {
      if (!mde) return;
      const fn = EasyMDE[a.fn];
      if (typeof fn === 'function') fn(mde);
      refreshActiveStates();
    };

    const startResize = (e) => {
      if (!mde || !wrapperEl.value) return;
      e.preventDefault();
      const wrap = wrapperEl.value;
      const scroller = mde.codemirror.getScrollerElement();
      const cmWrapper = mde.codemirror.getWrapperElement();
      const toolbarH = wrap.querySelector('.md-editor__toolbar')?.offsetHeight || 0;
      const startY = e.clientY;
      const startH = wrap.getBoundingClientRect().height;

      const onMove = (ev) => {
        const newH = Math.max(120, startH + (ev.clientY - startY));
        wrap.style.height = newH + 'px';
        // Use the wrapper's actual content height after layout, so the inner
        // CodeMirror never overflows the wrapper's border or its bottom-rounded corner.
        const innerPx = Math.max(40, wrap.clientHeight - toolbarH) + 'px';
        scroller.style.minHeight = innerPx;
        scroller.style.height = innerPx;
        cmWrapper.style.height = innerPx;
        mde.codemirror.refresh();
      };
      const onUp = () => {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
      };
      document.addEventListener('mousemove', onMove);
      document.addEventListener('mouseup', onUp);
    };

    return { textareaEl, wrapperEl, activeStates, actions, run, startResize, t };
  },
};
</script>

<style lang="scss">
/* Unscoped — EasyMDE injects DOM that we need to style. */

.md-editor {
  position: relative;
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius);
  background: var(--color-main-background);
  display: flex;
  flex-direction: column;
  min-height: 80px;

  &__toolbar {
    display: flex;
    gap: 2px;
    padding: 4px 6px;
    border-bottom: 1px solid var(--color-border);
    background: var(--color-background-hover);
    flex-wrap: wrap;
  }

  &__btn {
    width: 30px;
    height: 30px;
    border: none;
    background: transparent;
    border-radius: var(--border-radius);
    color: var(--color-main-text);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;

    &:hover { background: var(--color-background-dark); }

    &.active {
      background: var(--color-primary-element-light);
      color: var(--color-primary-element);
    }
  }

  &__resize {
    position: absolute;
    right: 0;
    bottom: 0;
    width: 14px;
    height: 14px;
    cursor: ns-resize;
    opacity: 0.4;
    background:
      linear-gradient(135deg, transparent 0 50%, var(--color-main-text) 50% 60%, transparent 60% 70%, var(--color-main-text) 70% 80%, transparent 80%);

    &:hover { opacity: 0.8; }
  }

  .EasyMDEContainer {
    background: var(--color-main-background);

    .editor-toolbar { display: none !important; }
    .editor-statusbar { display: none !important; }

    .CodeMirror {
      border: none;
      border-radius: 0;
      background: var(--color-main-background);
      color: var(--color-main-text);
      font-family: inherit;
      font-size: 14px;
      min-height: 60px !important;
    }

    .CodeMirror-scroll {
      min-height: 60px !important;
    }

    .CodeMirror-cursor { border-left-color: var(--color-main-text); }
    .CodeMirror-selected { background: var(--color-primary-element-light) !important; }

    .editor-preview, .editor-preview-side {
      background: var(--color-main-background);
      color: var(--color-main-text);
    }
  }
}
</style>
