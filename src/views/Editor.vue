<template>
  <NcContent app-name="formvox">
    <NcAppContent>
      <div class="editor-container">
        <div class="editor-topbar">
          <NcButton type="tertiary" @click="goBack">
            <template #icon>
              <ArrowLeftIcon :size="20" />
            </template>
            {{ t('All forms') }}
          </NcButton>

          <div class="editor-topbar__tabs" role="tablist">
            <NcButton
              role="tab"
              :type="!showPreview ? 'primary' : 'tertiary'"
              :aria-selected="!showPreview"
              @click="showPreview = false"
            >
              <template #icon>
                <PencilIcon :size="20" />
              </template>
              {{ t('Edit') }}
            </NcButton>
            <NcButton
              role="tab"
              :type="showPreview ? 'primary' : 'tertiary'"
              :aria-selected="showPreview"
              @click="showPreview = true"
            >
              <template #icon>
                <EyeIcon :size="20" />
              </template>
              {{ t('Preview') }}
            </NcButton>
          </div>

          <div class="editor-topbar__actions">
            <NcButton
              type="secondary"
              :disabled="!canShare"
              :title="t('Share')"
              :aria-label="t('Share')"
              @click="showShare = true"
            >
              <template #icon>
                <ShareIcon :size="20" />
              </template>
              {{ t('Share') }}
            </NcButton>

            <NcButton
              v-if="canViewResponses"
              type="primary"
              :title="t('Results')"
              :aria-label="t('Results')"
              @click="viewResults"
            >
              <template #icon>
                <ChartIcon :size="20" />
              </template>
              {{ t('Results') }}
            </NcButton>

            <NcActions>
              <NcActionButton v-if="hasPages" :disabled="!canEdit" @click="addPage">
                <template #icon>
                  <PagesIcon :size="20" />
                </template>
                {{ t('Add page') }}
              </NcActionButton>
              <NcActionButton :disabled="!canEdit" @click="togglePages">
                <template #icon>
                  <PagesOffIcon v-if="hasPages" :size="20" />
                  <PagesOnIcon v-else :size="20" />
                </template>
                {{ hasPages ? t('Disable pages') : t('Enable pages') }}
              </NcActionButton>
              <NcActionSeparator />
              <NcActionButton :disabled="!canEditSettings" @click="showBranding = true">
                <template #icon>
                  <PaletteIcon :size="20" />
                </template>
                {{ t('Branding') }}
              </NcActionButton>
              <NcActionButton :disabled="!canEditSettings" @click="showSettings = true">
                <template #icon>
                  <CogIcon :size="20" />
                </template>
                {{ t('Work together') }}
              </NcActionButton>
            </NcActions>
          </div>
        </div>

        <!-- Lock banner when another editor is active -->
        <div v-if="lockedByOther" class="permission-banner permission-banner--lock">
          <div class="lock-editor-info">
            <NcAvatar :user="lockingEditor?.userId" :display-name="lockingEditor?.displayName" :size="32" />
            <div class="lock-editor-text">
              <strong>{{ lockingEditor?.displayName || t('Someone') }}</strong>
              {{ t('is currently editing this form') }}
            </div>
          </div>
          <span class="lock-subtitle">{{ t('You can view the form but not make changes right now.') }}</span>
        </div>

        <!-- Permission banner for read-only users -->
        <div v-else-if="!hasEditPermission" class="permission-banner">
          <span class="permission-icon">🔒</span>
          <span>{{ t('You have read-only access to this form. Contact the owner to request edit permissions.') }}</span>
        </div>

        <div class="editor-header">
          <div class="form-field">
            <label class="form-label">{{ t('Form title') }}</label>
            <NcTextField
              v-model="form.title"
              :disabled="!canEdit"
              :placeholder="t('Enter form title')"
              class="title-input"
              @update:model-value="debouncedSave"
            />
          </div>
          <div class="form-field">
            <label class="form-label">{{ t('Description') }}</label>
            <MarkdownEditor
              v-model="form.description"
              :disabled="!canEdit"
              :placeholder="t('Enter description (optional)')"
              class="description-input"
              @update:model-value="debouncedSave"
            />
          </div>
        </div>

        <!-- Page tabs when pages are enabled -->
        <div v-if="hasPages && !showPreview" class="page-tabs">
          <div
            v-for="(page, pageIndex) in form.pages"
            :key="page.id"
            class="page-tab"
            :class="{ active: currentPageIndex === pageIndex }"
            @click="currentPageIndex = pageIndex"
          >
            <span class="page-title">{{ page.title || t('Page {n}', { n: pageIndex + 1 }) }}</span>
            <span class="page-question-count">({{ page.questions.length }})</span>
            <NcActions v-if="form.pages.length > 1" class="page-actions">
              <NcActionButton @click.stop="renamePage(pageIndex)">
                {{ t('Rename') }}
              </NcActionButton>
              <NcActionButton @click.stop="openRouting(pageIndex)">
                <template #icon>
                  <RouteIcon :size="20" />
                </template>
                {{ t('Routing') }}
              </NcActionButton>
              <NcActionButton @click.stop="deletePage(pageIndex)">
                {{ t('Delete') }}
              </NcActionButton>
            </NcActions>
          </div>
        </div>

        <div v-if="showPreview" class="preview-container">
          <div v-if="publicPreviewUrl" class="preview-iframe-wrapper">
            <iframe
              :src="publicPreviewUrl"
              class="preview-iframe"
              frameborder="0"
            />
          </div>
          <div v-else class="preview-no-link">
            <p>{{ t('Create a public link first to preview the form.') }}</p>
            <NcButton type="primary" @click="showShare = true">
              {{ t('Create public link') }}
            </NcButton>
          </div>
        </div>

        <div v-else class="questions-container">
          <!-- Page-based view -->
          <template v-if="hasPages">
            <draggable
              v-model="currentPageQuestions"
              item-key="id"
              handle=".drag-handle"
              group="questions"
              :disabled="!canEdit"
              @end="onQuestionDragEnd"
            >
              <template #item="{ element }">
                <QuestionEditor
                  :question="element"
                  :index="getQuestionIndex(element.id)"
                  :questions="form.questions"
                  :pages="form.pages"
                  :current-page-index="currentPageIndex"
                  :readonly="!canEdit"
                  @update="updateQuestionById(element.id, $event)"
                  @delete="deleteQuestionById(element.id)"
                  @duplicate="duplicateQuestionById(element.id)"
                  @move="moveQuestionToPage(element.id, $event)"
                />
              </template>
            </draggable>

            <div v-if="currentPageQuestions.length === 0" class="empty-questions">
              <p>{{ t('No questions on this page. Click "Add question" to add one.') }}</p>
            </div>
          </template>

          <!-- Single page view (no pages) -->
          <template v-else>
            <draggable
              v-model="form.questions"
              item-key="id"
              handle=".drag-handle"
              :disabled="!canEdit"
              @end="onDragEnd"
            >
              <template #item="{ element, index }">
                <div :class="{ 'section-header-wrapper': element.type === 'section', 'in-section-wrapper': element.sectionId && element.type !== 'section' }">
                  <QuestionEditor
                    :question="element"
                    :index="index"
                    :questions="form.questions"
                    :readonly="!canEdit"
                    @update="updateQuestion(index, $event)"
                    @delete="element.type === 'section' ? deleteSectionById(element.id) : deleteQuestion(index)"
                    @duplicate="duplicateQuestion(index)"
                    @move-to-section="moveQuestionToSection(index, $event)"
                  />
                </div>
              </template>
            </draggable>

            <div v-if="form.questions.length === 0" class="empty-questions">
              <p>{{ t('No questions yet. Click "Add question" to get started.') }}</p>
            </div>
          </template>

          <div v-if="canEdit && !showPreview" class="add-question-rail">
            <NcButton type="primary" @click="addQuestion">
              <template #icon>
                <PlusIcon :size="20" />
              </template>
              {{ t('Add question') }}
            </NcButton>
            <NcButton type="tertiary" @click="addSection">
              <template #icon>
                <SectionIcon :size="20" />
              </template>
              {{ t('Add section') }}
            </NcButton>
          </div>
        </div>

      </div>
    </NcAppContent>

    <SettingsPanel
      v-if="showSettings"
      :file-id="fileId"
      :settings="form.settings"
      :permissions="form.permissions"
      :can-edit-settings="permissions.editSettings"
      @update:settings="updateSettings"
      @update:permissions="updatePermissions"
      @close="showSettings = false"
    />

    <ShareDialog
      v-if="showShare"
      :file-id="fileId"
      :form="form"
      :can-share="canShare"
      @close="showShare = false"
    />

    <FormBrandingEditor
      v-if="showBranding"
      :branding="form.branding"
      :file-id="fileId"
      @update:branding="updateBranding"
      @close="showBranding = false"
    />

    <PageRoutingEditor
      v-if="showRouting !== null"
      :page="form.pages[showRouting]"
      :pages="form.pages"
      :questions="form.questions"
      @update:routing="updatePageRouting"
      @close="showRouting = null"
    />
  </NcContent>
</template>

<script>
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import {
  NcContent,
  NcAppContent,
  NcButton,
  NcActions,
  NcActionButton,
  NcActionSeparator,
  NcTextField,
  NcTextArea,
  NcAvatar,
} from '@nextcloud/vue';
import { generateUrl } from '@nextcloud/router';
import axios from '@nextcloud/axios';
import { showError, showSuccess } from '@nextcloud/dialogs';
import { t } from '@/utils/l10n';
import { v4 as uuidv4 } from 'uuid';
import draggable from 'vuedraggable';
import QuestionEditor from '../components/QuestionEditor.vue';
import MarkdownEditor from '../components/MarkdownEditor.vue';
import SettingsPanel from '../components/SettingsPanel.vue';
import ShareDialog from '../components/ShareDialog.vue';
import FormBrandingEditor from '../components/FormBrandingEditor.vue';
import PageRoutingEditor from '../components/PageRoutingEditor.vue';
import PlusIcon from '../components/icons/PlusIcon.vue';
import EyeIcon from '../components/icons/EyeIcon.vue';
import CogIcon from '../components/icons/CogIcon.vue';
import PencilIcon from 'vue-material-design-icons/Pencil.vue';
import ShareIcon from '../components/icons/ShareIcon.vue';
import ChartIcon from '../components/icons/ChartIcon.vue';
import PagesIcon from '../components/icons/PagesIcon.vue';
import PaletteIcon from '../components/icons/PaletteIcon.vue';
import SectionIcon from 'vue-material-design-icons/CardText.vue';
import PagesOnIcon from 'vue-material-design-icons/Grid.vue';
import PagesOffIcon from 'vue-material-design-icons/GridOff.vue';
import ArrowLeftIcon from '../components/icons/ArrowLeftIcon.vue';
import RouteIcon from 'vue-material-design-icons/DirectionsFork.vue';

export default {
  name: 'Editor',
  components: {
    NcContent,
    NcAppContent,
    NcButton,
    NcActions,
    NcActionButton,
    NcActionSeparator,
    NcTextField,
    NcTextArea,
    draggable,
    QuestionEditor,
    MarkdownEditor,
    SettingsPanel,
    ShareDialog,
    FormBrandingEditor,
    PageRoutingEditor,
    NcAvatar,
    PlusIcon,
    EyeIcon,
    PencilIcon,
    CogIcon,
    ShareIcon,
    ChartIcon,
    PagesIcon,
    PagesOnIcon,
    PagesOffIcon,
    PaletteIcon,
    ArrowLeftIcon,
    RouteIcon,
    SectionIcon,
  },
  props: {
    fileId: {
      type: Number,
      required: true,
    },
    initialForm: {
      type: Object,
      required: true,
    },
    role: {
      type: String,
      required: true,
    },
    permissions: {
      type: Object,
      required: true,
    },
    adminBranding: {
      type: Object,
      default: null,
    },
  },
  setup(props) {
    // Deep copy to preserve nested objects like question.validation
    const form = reactive(JSON.parse(JSON.stringify(props.initialForm)));
    const saving = ref(false);
    const showSettings = ref(false);
    const showShare = ref(false);
    const showBranding = ref(false);
    const showPreview = ref(false);
    const showRouting = ref(null);
    const currentPageIndex = ref(0);

    // Presence
    const activeEditors = ref([]);
    const myFirstSeen = ref(null);
    let presenceInterval = null;

    let saveTimeout = null;

    // Permission checks
    const hasEditPermission = computed(() => props.permissions?.editQuestions ?? false);
    // The editor who arrived first (has smallest firstSeen) gets priority
    const lockingEditor = computed(() => {
      if (activeEditors.value.length === 0) return null;
      // Find the editor who was there first
      return activeEditors.value.reduce((earliest, e) =>
        !earliest || e.firstSeen < earliest.firstSeen ? e : earliest, null);
    });

    // Only locked if another editor was there before us
    const lockedByOther = computed(() => {
      if (activeEditors.value.length === 0) return false;
      if (!myFirstSeen.value) return true; // Be safe: if we don't know our own firstSeen, assume locked
      // Check if any other editor arrived before us
      return activeEditors.value.some(e => e.firstSeen <= myFirstSeen.value);
    });
    const canEdit = computed(() => hasEditPermission.value && !lockedByOther.value);
    const canEditSettings = computed(() => (props.permissions?.editSettings ?? false) && !lockedByOther.value);
    const canViewResponses = computed(() => props.permissions?.viewResponses ?? false);
    const canShare = computed(() => props.permissions?.canShare ?? false);

    // Computed: get public preview URL (if public token exists)
    const publicPreviewUrl = computed(() => {
      const token = form.settings?.public_token;
      if (!token) return null;
      return generateUrl('/apps/formvox/public/{fileId}/{token}', {
        fileId: props.fileId,
        token: token
      });
    });

    // Computed: check if pages are enabled
    const hasPages = computed(() => {
      return Array.isArray(form.pages) && form.pages.length > 0;
    });

    // Computed: get questions for the current page
    const currentPageQuestions = computed({
      get() {
        if (!hasPages.value) return [];
        const page = form.pages[currentPageIndex.value];
        if (!page) return [];
        // Return the actual question objects for this page
        return page.questions.map(qId => form.questions.find(q => q.id === qId)).filter(Boolean);
      },
      set(newQuestions) {
        if (!hasPages.value) return;
        // Update the page's question IDs based on the new order
        form.pages[currentPageIndex.value].questions = newQuestions.map(q => q.id);
        debouncedSave();
      }
    });

    const save = async () => {
      if (!canEdit.value) {
        showError(t('You do not have permission to edit this form'));
        return;
      }
      saving.value = true;
      try {
        await axios.put(
          generateUrl('/apps/formvox/api/form/{fileId}', { fileId: props.fileId }),
          {
            title: form.title,
            description: form.description,
            questions: form.questions,
            settings: form.settings,
            pages: form.pages,
            branding: form.branding,
          }
        );
        showSuccess(t('All changes saved'));
      } catch (error) {
        if (error.response?.status === 403) {
          showError(t('You do not have permission to edit this form'));
        } else {
          showError(t('Failed to save form'));
        }
        console.error(error);
      } finally {
        saving.value = false;
      }
    };

    const debouncedSave = () => {
      if (saveTimeout) {
        clearTimeout(saveTimeout);
      }
      saveTimeout = setTimeout(save, 500);
    };

    // Group questions into display items for the editor
    const editorDisplayItems = computed(() => {
      const items = [];
      const sectionChildren = {};

      // Collect children per section
      for (const q of form.questions) {
        if (q.sectionId) {
          if (!sectionChildren[q.sectionId]) sectionChildren[q.sectionId] = [];
          sectionChildren[q.sectionId].push(q);
        }
      }

      // Build display items in order (top-level only)
      for (const q of form.questions) {
        if (q.sectionId) continue; // Skip, will be rendered inside section
        if (q.type === 'section') {
          items.push({
            type: 'section-group',
            id: 'sg-' + q.id,
            section: q,
            questions: sectionChildren[q.id] || [],
          });
        } else {
          items.push({ type: 'question', id: q.id, question: q });
        }
      }
      return items;
    });

    const onDragEnd = (evt) => {
      const newIndex = evt.newIndex;
      if (newIndex === undefined) {
        debouncedSave();
        return;
      }

      const movedItem = form.questions[newIndex];
      if (!movedItem) {
        debouncedSave();
        return;
      }

      // If a section was moved, bring its children along
      if (movedItem.type === 'section') {
        const sectionId = movedItem.id;
        // Collect all children (they may now be scattered)
        const children = form.questions.filter(q => q.sectionId === sectionId && q.id !== sectionId);
        // Remove children from current positions
        for (const child of children) {
          const idx = form.questions.indexOf(child);
          if (idx !== -1) form.questions.splice(idx, 1);
        }
        // Re-insert children right after the section header
        const sectionPos = form.questions.indexOf(movedItem);
        form.questions.splice(sectionPos + 1, 0, ...children);
        debouncedSave();
        return;
      }

      // For regular questions: auto-assign sectionId based on position
      let sectionId = null;
      for (let i = newIndex - 1; i >= 0; i--) {
        const above = form.questions[i];
        if (above.type === 'section') {
          sectionId = above.id;
          break;
        }
        if (above.sectionId) {
          sectionId = above.sectionId;
          break;
        }
        // Hit a standalone question — not in any section
        break;
      }

      if (sectionId) {
        const sectionIndex = form.questions.findIndex(q => q.id === sectionId);
        if (sectionIndex < newIndex) {
          movedItem.sectionId = sectionId;
        } else {
          delete movedItem.sectionId;
        }
      } else {
        delete movedItem.sectionId;
      }

      debouncedSave();
    };

    const moveQuestionToSection = (currentIndex, sectionId) => {
      // Remove the question from its current position
      const [question] = form.questions.splice(currentIndex, 1);

      // Set the sectionId
      question.sectionId = sectionId;

      // Find the last question in this section, or the section header itself
      let insertAfter = form.questions.findIndex(q => q.id === sectionId);
      for (let i = insertAfter + 1; i < form.questions.length; i++) {
        if (form.questions[i].sectionId === sectionId) {
          insertAfter = i;
        } else {
          break;
        }
      }

      // Insert after the last section member
      form.questions.splice(insertAfter + 1, 0, question);
      debouncedSave();
    };

    const deleteSectionById = (sectionId) => {
      // Remove section and unlink its questions
      form.questions.forEach(q => {
        if (q.sectionId === sectionId) {
          delete q.sectionId;
        }
      });
      const index = form.questions.findIndex(q => q.id === sectionId);
      if (index !== -1) {
        form.questions.splice(index, 1);
      }
      if (hasPages.value) {
        form.pages.forEach(page => {
          page.questions = page.questions.filter(qId => qId !== sectionId);
        });
      }
      debouncedSave();
    };

    const addSection = () => {
      const newSection = {
        id: `s${uuidv4().split('-')[0]}`,
        type: 'section',
        question: '',
        description: '',
        required: false,
        options: [],
        showIf: null,
      };
      form.questions.push(newSection);
      if (hasPages.value && form.pages[currentPageIndex.value]) {
        form.pages[currentPageIndex.value].questions.push(newSection.id);
      }
      debouncedSave();
    };

    const addQuestion = () => {
      const newQuestion = {
        id: `q${uuidv4().split('-')[0]}`,
        type: 'text',
        question: '',
        description: '',
        required: false,
        options: [],
        showIf: null,
      };
      form.questions.push(newQuestion);
      // If pages are enabled, add to current page
      if (hasPages.value && form.pages[currentPageIndex.value]) {
        form.pages[currentPageIndex.value].questions.push(newQuestion.id);
      }
      debouncedSave();
    };

    const updateQuestion = (index, question) => {
      form.questions[index] = question;
      debouncedSave();
    };

    const deleteQuestion = (index) => {
      form.questions.splice(index, 1);
      debouncedSave();
    };

    const duplicateQuestion = (index) => {
      const original = form.questions[index];
      const duplicate = {
        ...JSON.parse(JSON.stringify(original)),
        id: `q${uuidv4().split('-')[0]}`,
      };
      form.questions.splice(index + 1, 0, duplicate);
      debouncedSave();
    };

    // Page management functions
    const togglePages = () => {
      if (hasPages.value) {
        // Disable pages: flatten all questions back to simple list
        form.pages = [];
        currentPageIndex.value = 0;
      } else {
        // Enable pages: create first page with all existing questions
        form.pages = [{
          id: `p${uuidv4().split('-')[0]}`,
          title: '',
          questions: form.questions.map(q => q.id),
        }];
        currentPageIndex.value = 0;
      }
      debouncedSave();
    };

    const addPage = () => {
      if (!hasPages.value) return;
      const newPage = {
        id: `p${uuidv4().split('-')[0]}`,
        title: '',
        questions: [],
      };
      form.pages.push(newPage);
      currentPageIndex.value = form.pages.length - 1;
      debouncedSave();
    };

    const deletePage = (pageIndex) => {
      if (!hasPages.value || form.pages.length <= 1) return;
      // Remove the page (questions remain in form.questions but are unassigned)
      form.pages.splice(pageIndex, 1);
      // Adjust current page index if needed
      if (currentPageIndex.value >= form.pages.length) {
        currentPageIndex.value = form.pages.length - 1;
      }
      debouncedSave();
    };

    const renamePage = (pageIndex) => {
      const page = form.pages[pageIndex];
      const newTitle = prompt(t('Enter page title:'), page.title || '');
      if (newTitle !== null) {
        page.title = newTitle;
        debouncedSave();
      }
    };

    // Page-aware question management
    const getQuestionIndex = (questionId) => {
      return form.questions.findIndex(q => q.id === questionId);
    };

    const updateQuestionById = (questionId, updatedQuestion) => {
      const index = getQuestionIndex(questionId);
      if (index !== -1) {
        form.questions[index] = updatedQuestion;
        debouncedSave();
      }
    };

    const deleteQuestionById = (questionId) => {
      const index = getQuestionIndex(questionId);
      if (index !== -1) {
        form.questions.splice(index, 1);
        // Also remove from current page
        if (hasPages.value) {
          const page = form.pages[currentPageIndex.value];
          const qIndex = page.questions.indexOf(questionId);
          if (qIndex !== -1) {
            page.questions.splice(qIndex, 1);
          }
        }
        debouncedSave();
      }
    };

    const duplicateQuestionById = (questionId) => {
      const index = getQuestionIndex(questionId);
      if (index !== -1) {
        const original = form.questions[index];
        const duplicate = {
          ...JSON.parse(JSON.stringify(original)),
          id: `q${uuidv4().split('-')[0]}`,
        };
        form.questions.splice(index + 1, 0, duplicate);
        // Also add to current page after the original
        if (hasPages.value) {
          const page = form.pages[currentPageIndex.value];
          const qIndex = page.questions.indexOf(questionId);
          if (qIndex !== -1) {
            page.questions.splice(qIndex + 1, 0, duplicate.id);
          }
        }
        debouncedSave();
      }
    };

    const onQuestionDragEnd = () => {
      // The v-model on currentPageQuestions already handles updating page.questions
      debouncedSave();
    };

    const moveQuestionToPage = (questionId, targetPageIndex) => {
      if (!hasPages.value) return;

      // Remove from current page
      const currentPage = form.pages[currentPageIndex.value];
      const qIndex = currentPage.questions.indexOf(questionId);
      if (qIndex !== -1) {
        currentPage.questions.splice(qIndex, 1);
      }

      // Add to target page
      const targetPage = form.pages[targetPageIndex];
      if (targetPage) {
        targetPage.questions.push(questionId);
      }

      debouncedSave();
    };

    const updateSettings = (newSettings) => {
      Object.assign(form.settings, newSettings);
      debouncedSave();
    };

    const updatePermissions = (newPermissions) => {
      Object.assign(form.permissions, newPermissions);
      debouncedSave();
    };

    const updateBranding = (newBranding) => {
      form.branding = newBranding;
      debouncedSave();
    };

    const viewResults = () => {
      window.location.href = generateUrl('/apps/formvox/results/{fileId}', { fileId: props.fileId });
    };

    const goBack = () => {
      window.location.href = generateUrl('/apps/formvox');
    };

    // Page routing
    const openRouting = (pageIndex) => {
      showRouting.value = pageIndex;
    };

    const updatePageRouting = (routing) => {
      if (showRouting.value !== null && form.pages[showRouting.value]) {
        form.pages[showRouting.value].routing = routing;
        debouncedSave();
      }
    };

    // Presence
    const sendHeartbeat = async () => {
      try {
        await axios.post(
          generateUrl('/apps/formvox/api/form/{fileId}/presence', { fileId: props.fileId })
        );
      } catch (e) { /* ignore */ }
    };

    const fetchPresence = async () => {
      try {
        const res = await axios.get(
          generateUrl('/apps/formvox/api/form/{fileId}/presence', { fileId: props.fileId })
        );
        activeEditors.value = res.data.editors || [];
        if (res.data.myFirstSeen) {
          myFirstSeen.value = res.data.myFirstSeen;
        }
      } catch (e) { /* ignore */ }
    };

    onMounted(() => {
      sendHeartbeat();
      fetchPresence();
      presenceInterval = setInterval(() => {
        sendHeartbeat();
        fetchPresence();
      }, 30000);
    });

    onBeforeUnmount(() => {
      if (presenceInterval) {
        clearInterval(presenceInterval);
      }
    });

    return {
      form,
      showSettings,
      showShare,
      showBranding,
      showPreview,
      currentPageIndex,
      hasPages,
      currentPageQuestions,
      publicPreviewUrl,
      canEdit,
      canEditSettings,
      hasEditPermission,
      lockedByOther,
      canViewResponses,
      canShare,
      debouncedSave,
      addSection,
      editorDisplayItems,
      onDragEnd,
      moveQuestionToSection,
      deleteSectionById,
      addQuestion,
      updateQuestion,
      deleteQuestion,
      duplicateQuestion,
      togglePages,
      addPage,
      deletePage,
      renamePage,
      getQuestionIndex,
      updateQuestionById,
      deleteQuestionById,
      duplicateQuestionById,
      onQuestionDragEnd,
      moveQuestionToPage,
      updateSettings,
      updatePermissions,
      updateBranding,
      viewResults,
      goBack,
      showRouting,
      openRouting,
      updatePageRouting,
      activeEditors,
      lockingEditor,
      t,
    };
  },
};
</script>

<style scoped lang="scss">
.editor-container {
  max-width: 800px;
  margin: 0 auto;
  padding: 20px;
}

.editor-topbar {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 0;
  margin-bottom: 24px;
  border-bottom: 1px solid var(--color-border);
  position: sticky;
  top: 0;
  z-index: 5;
  background: var(--color-main-background);
}

.editor-topbar__tabs {
  display: flex;
  gap: 4px;
  margin-left: 12px;
}

.editor-topbar__actions {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 8px;
}

.permission-banner {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 16px;
  margin-bottom: 20px;
  background: var(--color-warning-light, #fff3cd);
  border: 1px solid var(--color-warning, #ffc107);
  border-radius: var(--border-radius-large);
  color: var(--color-warning-text, #856404);

  .permission-icon {
    font-size: 20px;
  }

  &--lock {
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
    background: var(--color-background-hover);
    border: 1px solid var(--color-border);
    color: var(--color-main-text);

    .lock-editor-info {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .lock-editor-text {
      font-size: 14px;

      strong {
        font-weight: 600;
      }
    }

    .lock-subtitle {
      font-size: 13px;
      color: var(--color-text-maxcontrast);
    }
  }
}

.editor-header {
  margin-bottom: 24px;
}

.form-field {
  margin-bottom: 20px;

  &:last-child {
    margin-bottom: 0;
  }
}

.form-label {
  display: block;
  font-size: 14px;
  font-weight: 600;
  margin-bottom: 8px;
  color: var(--color-main-text);
}

.title-input {
  :deep(.input-field__input) {
    font-size: 24px;
    font-weight: 600;
    padding: 10px;
  }
}

.description-input {
  // Standaard NcTextArea styling
}

.add-question-rail {
  display: flex;
  gap: 8px;
  justify-content: center;
  align-items: center;
  margin: 24px 0;
  padding: 18px;
  border: 1.5px dashed var(--color-border);
  border-radius: var(--border-radius-large);
  background: transparent;
  transition: border-color 0.15s ease, background 0.15s ease;

  &:hover {
    border-color: var(--color-primary-element);
    background: var(--color-background-hover);
  }
}

@media (max-width: 768px) {
  .editor-topbar {
    flex-wrap: wrap;
    gap: 8px;
  }

  .editor-topbar__tabs {
    margin-left: 0;
    order: 2;
    width: 100%;
  }

  .editor-topbar__actions {
    flex-wrap: wrap;
  }
}

.page-tabs {
  display: flex;
  gap: 4px;
  margin-bottom: 20px;
  padding: 4px;
  background: var(--color-background-hover);
  border-radius: var(--border-radius-large);
  overflow-x: auto;

  .page-tab {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: transparent;
    border: none;
    border-radius: var(--border-radius);
    cursor: pointer;
    white-space: nowrap;
    transition: background-color 0.2s;

    &:hover {
      background: var(--color-background-dark);
    }

    &.active {
      background: var(--color-main-background);
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }

    .page-title {
      font-weight: 500;
    }

    .page-question-count {
      font-size: 12px;
      color: var(--color-text-maxcontrast);
    }

    .page-actions {
      margin-left: auto;
    }
  }
}

.questions-container {
  min-height: 200px;
}

.section-header-wrapper,
.in-section-wrapper {
  background: var(--color-primary-element-light, rgba(0, 96, 223, 0.06));
  border-left: 2px solid var(--color-primary-element);
  border-right: 2px solid var(--color-primary-element);
  padding: 8px 16px;
  margin-bottom: 0;

  .question-editor {
    margin-bottom: 0;
  }
}

.section-header-wrapper {
  border-top: 2px solid var(--color-primary-element);
  border-top-left-radius: var(--border-radius-large);
  border-top-right-radius: var(--border-radius-large);
  padding-top: 12px;
}

/* Last in-section item gets bottom border/radius */
.in-section-wrapper:has(+ :not(.in-section-wrapper)),
.in-section-wrapper:last-child {
  border-bottom: 2px solid var(--color-primary-element);
  border-bottom-left-radius: var(--border-radius-large);
  border-bottom-right-radius: var(--border-radius-large);
  padding-bottom: 12px;
  margin-bottom: 16px;
}

/* Section header without children */
.section-header-wrapper:has(+ :not(.in-section-wrapper)),
.section-header-wrapper:last-child {
  border-bottom: 2px solid var(--color-primary-element);
  border-bottom-left-radius: var(--border-radius-large);
  border-bottom-right-radius: var(--border-radius-large);
  padding-bottom: 12px;
  margin-bottom: 16px;
}

.empty-questions {
  text-align: center;
  padding: 60px 20px;
  color: var(--color-text-maxcontrast);
  border: 2px dashed var(--color-border);
  border-radius: var(--border-radius-large);
}

.preview-container {
  background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
  border-radius: var(--border-radius-large);
  padding: 0;
  min-height: calc(100vh - 200px);
}

.preview-iframe-wrapper {
  width: 100%;
  height: 100%;
  min-height: calc(100vh - 200px);
}

.preview-iframe {
  width: 100%;
  height: calc(100vh - 200px);
  min-height: calc(100vh - 200px);
  border: none;
  border-radius: var(--border-radius-large);
}

.preview-no-link {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 300px;
  text-align: center;
  color: var(--color-text-maxcontrast);

  p {
    margin-bottom: 16px;
  }
}

/* Fix icon vertical alignment in NcActionButton and NcButton */
:deep(.action-button__icon),
:deep(.button-vue__icon) {
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

</style>
