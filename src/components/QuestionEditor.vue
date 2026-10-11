<template>
  <div
    class="question-editor"
    :class="{ collapsed, 'has-color': localQuestion.color, 'is-section': localQuestion.type === 'section', 'is-descriptor': localQuestion.type === 'descriptor' }"
    :style="localQuestion.color ? { '--question-color': localQuestion.color } : {}"
  >
    <!-- Descriptor / info block — markdown content only, no answer (#64) -->
    <template v-if="localQuestion.type === 'descriptor'">
      <div class="section-editor-header">
        <span class="drag-handle" :class="{ disabled: readonly }">
          <DragIcon :size="20" />
        </span>
        <!-- TRANSLATORS: Badge of a text-only block in the editor; keep it short -->
        <span class="section-badge">{{ t('formvox', 'Info block') }}</span>
        <div class="question-actions">
          <NcButton v-if="!readonly" type="error" @click="$emit('delete')">
            <template #icon>
              <CloseIcon :size="20" />
            </template>
          </NcButton>
        </div>
      </div>

      <div class="section-editor-body">
        <MarkdownEditor
          v-model="localQuestion.description"
          :align="localQuestion.descriptorAlign || 'left'"
          :disabled="readonly"
          :placeholder="t('formvox', 'Markdown content shown to respondents (no answer required)')"
          @update:model-value="emitUpdate"
          @update:align="updateDescriptorAlign"
        />

        <div v-if="localQuestion.showIf && !showConditions" class="condition-section">
          <div class="condition-display">
            <!-- TRANSLATORS: Label: this question has a condition for being shown -->
            <span class="condition-label">{{ t('formvox', 'Condition active') }}</span>
            <!-- TRANSLATORS: Verb: button -->
            <NcButton type="tertiary" @click="showConditions = true">{{ t('formvox', 'Edit') }}</NcButton>
            <!-- TRANSLATORS: Verb: button -->
            <NcButton type="tertiary" @click="updateCondition(null)">{{ t('formvox', 'Remove') }}</NcButton>
          </div>
        </div>
        <ConditionEditor
          v-if="showConditions"
          :condition="localQuestion.showIf"
          :questions="questions.filter(q => q.id !== localQuestion.id && q.type !== 'section' && q.type !== 'descriptor')"
          @update="(val) => { updateCondition(val); showConditions = false; }"
          @close="showConditions = false"
        />
        <NcButton v-else-if="!localQuestion.showIf && !readonly" type="tertiary" @click="showConditions = true">
          {{ t('formvox', 'Add condition') }}
        </NcButton>
      </div>
    </template>

    <!-- Section header (special rendering for type=section) -->
    <template v-else-if="localQuestion.type === 'section'">
      <div class="section-editor-header">
        <span class="drag-handle" :class="{ disabled: readonly }">
          <DragIcon :size="20" />
        </span>
        <!-- TRANSLATORS: Noun: badge of a section heading in the editor; keep it short -->
        <span class="section-badge">{{ t('formvox', 'Section') }}</span>
        <div class="question-actions">
          <NcButton v-if="!readonly" type="error" @click="$emit('delete')">
            <template #icon>
              <CloseIcon :size="20" />
            </template>
          </NcButton>
        </div>
      </div>

      <div class="section-editor-body">
        <!-- TRANSLATORS: Placeholder of the title field of a section -->
        <NcTextField :placeholder="t('formvox', 'Section title')"
          v-model="localQuestion.question"
          :disabled="readonly"
          class="section-title-input"
          @update:model-value="emitUpdate"
        />
        <MarkdownEditor
          v-model="localQuestion.description"
          :disabled="readonly"
          :placeholder="t('formvox', 'Section description (optional)')"
          @update:model-value="emitUpdate"
        />

        <div v-if="localQuestion.showIf && !showConditions" class="condition-section">
          <div class="condition-display">
            <span class="condition-label">{{ t('formvox', 'Condition active') }}</span>
            <NcButton type="tertiary" @click="showConditions = true">{{ t('formvox', 'Edit') }}</NcButton>
            <NcButton type="tertiary" @click="updateCondition(null)">{{ t('formvox', 'Remove') }}</NcButton>
          </div>
        </div>
        <ConditionEditor
          v-if="showConditions"
          :condition="localQuestion.showIf"
          :questions="questions.filter(q => q.id !== localQuestion.id && q.type !== 'section')"
          @update="(val) => { updateCondition(val); showConditions = false; }"
          @close="showConditions = false"
        />
        <NcButton
          v-if="!localQuestion.showIf && !showConditions && !readonly"
          type="tertiary"
          class="add-condition-btn"
          @click="showConditions = true"
        >
          {{ t('formvox', '+ Add condition') }}
        </NcButton>
      </div>
    </template>

    <!-- Normal question editor -->
    <template v-else>
    <div class="question-header">
      <span class="drag-handle" :class="{ disabled: readonly }">
        <DragIcon :size="20" />
      </span>

      <div class="question-number">{{ index + 1 }}</div>

      <select v-model="localQuestion.type" class="type-select" :disabled="readonly" @change="onTypeChange">
        <!-- TRANSLATORS: Noun: group of text question types, and a label for text -->
        <optgroup :label="t('formvox', 'Text')">
          <!-- TRANSLATORS: Question type: a one-line answer -->
          <option value="text">{{ t('formvox', 'Short text') }}</option>
          <!-- TRANSLATORS: Question type: an answer of several lines -->
          <option value="textarea">{{ t('formvox', 'Long text') }}</option>
          <!-- TRANSLATORS: Question type, and a table column type -->
          <option value="number">{{ t('formvox', 'Number') }}</option>
        </optgroup>
        <!-- TRANSLATORS: Group of question types where the respondent picks options -->
        <optgroup :label="t('formvox', 'Choice')">
          <!-- TRANSLATORS: Question type: pick one option -->
          <option value="choice">{{ t('formvox', 'Single choice') }}</option>
          <!-- TRANSLATORS: Question type: pick one or more options -->
          <option value="multiple">{{ t('formvox', 'Multiple choice') }}</option>
          <!-- TRANSLATORS: Question type: pick one option from a dropdown list -->
          <option value="dropdown">{{ t('formvox', 'Dropdown') }}</option>
          <option value="consent">{{ t('formvox', 'Consent (single checkbox)') }}</option>
        </optgroup>
        <optgroup :label="t('formvox', 'Date & time')">
          <option value="date">{{ t('formvox', 'Date') }}</option>
          <option value="datetime">{{ t('formvox', 'Date & time') }}</option>
          <!-- TRANSLATORS: Noun: time of day; question type and label of a time field -->
          <option value="time">{{ t('formvox', 'Time') }}</option>
        </optgroup>
        <!-- TRANSLATORS: Group of question types for rating something -->
        <optgroup :label="t('formvox', 'Rating')">
          <!-- TRANSLATORS: Question type: a numbered scale, for example 1 to 10 -->
          <option value="scale">{{ t('formvox', 'Scale') }}</option>
          <!-- TRANSLATORS: Question type: a rating with stars -->
          <option value="rating">{{ t('formvox', 'Stars') }}</option>
          <!-- TRANSLATORS: Question type: a grid of rows and columns -->
          <option value="matrix">{{ t('formvox', 'Matrix') }}</option>
        </optgroup>
        <!-- TRANSLATORS: Group of remaining question types -->
        <optgroup :label="t('formvox', 'Other')">
          <!-- TRANSLATORS: Question type: rows the respondent fills in -->
          <option value="table">{{ t('formvox', 'Table') }}</option>
          <!-- TRANSLATORS: Question type: the respondent uploads files -->
          <option value="file">{{ t('formvox', 'File upload') }}</option>
        </optgroup>
      </select>

      <div class="question-actions">
        <!-- Color picker -->
        <NcActions v-if="!readonly" ref="colorPickerRef" class="color-picker-action">
          <template #icon>
            <span
              class="color-indicator"
              :style="localQuestion.color ? { backgroundColor: localQuestion.color } : {}"
              :class="{ 'no-color': !localQuestion.color }"
            />
          </template>
          <NcActionButton
            v-for="color in colorOptions"
            :key="color.value"
            :close-after-click="true"
            :class="{ 'color-selected': localQuestion.color === color.value }"
            @click="localQuestion.color = color.value; emitUpdate()"
          >
            <template #icon>
              <span
                class="color-swatch"
                :style="color.value ? { backgroundColor: color.value } : {}"
                :class="{ 'no-color-swatch': !color.value }"
              />
            </template>
            {{ color.label }}
          </NcActionButton>
        </NcActions>

        <NcButton
          type="tertiary"
          @click="collapsed = !collapsed"
        >
          <template #icon>
            <ChevronIcon :size="20" :class="{ rotated: collapsed }" />
          </template>
        </NcButton>

        <NcActions v-if="!readonly">
          <NcActionButton @click="$emit('duplicate')">
            <template #icon>
              <CopyIcon :size="20" />
            </template>
            <!-- TRANSLATORS: Verb: menu entry that copies a question -->
            {{ t('formvox', 'Duplicate') }}
          </NcActionButton>
          <NcActionButton @click="showConditions = true">
            <template #icon>
              <BranchIcon :size="20" />
            </template>
            <!-- TRANSLATORS: Menu entry that opens the conditions for showing a question -->
            {{ t('formvox', 'Conditions') }}
          </NcActionButton>
          <NcActionButton
            v-if="localQuestion.sectionId"
            @click="removeFromSection"
          >
            <template #icon>
              <CloseIcon :size="20" />
            </template>
            {{ t('formvox', 'Remove from section') }}
          </NcActionButton>
          <NcActionButton
            v-for="section in availableSections"
            :key="'section-' + section.id"
            @click="assignToSection(section.id)"
          >
            <template #icon>
              <SectionIcon :size="20" />
            </template>
            <!-- TRANSLATORS: Menu entry; {section} is the title of a section -->
            {{ t('formvox', 'Move to "{section}"', { section: sectionName(section) }) }}
          </NcActionButton>
          <template v-if="otherPages.length > 0">
            <NcActionButton
              v-for="page in otherPages"
              :key="page.id"
              @click="moveToPage(page.index)"
            >
              <template #icon>
                <PagesIcon :size="20" />
              </template>
              <!-- TRANSLATORS: Menu entry; {page} is the title of a page, or "Page 2" -->
              {{ t('formvox', 'Move to {page}', { page: pageName(page) }) }}
            </NcActionButton>
          </template>
          <NcActionButton @click="$emit('delete')">
            <template #icon>
              <DeleteIcon :size="20" />
            </template>
            {{ t('formvox', 'Delete') }}
          </NcActionButton>
        </NcActions>
      </div>
    </div>

    <div v-show="!collapsed" class="question-body">
      <div class="form-field">
        <!-- TRANSLATORS: Label of the question text field -->
        <label class="form-label">{{ t('formvox', 'Question') }}</label>
        <NcTextField
          v-model="localQuestion.question"
          :disabled="readonly"
          :placeholder="t('formvox', 'Enter your question')"
          class="question-input"
          @update:model-value="emitUpdate"
        />
      </div>

      <div class="form-field">
        <!-- TRANSLATORS: Label of the description field of a question -->
        <label class="form-label">{{ t('formvox', 'Description (optional)') }}</label>
        <MarkdownEditor
          v-model="localQuestion.description"
          :disabled="readonly"
          :placeholder="t('formvox', 'Add a description or instructions')"
          @update:model-value="emitUpdate"
        />
      </div>

      <!-- Options for choice-based questions -->
      <div v-if="hasOptions" class="options-editor">
        <h4>{{ t('formvox', 'Options') }}</h4>
        <draggable
          v-model="localQuestion.options"
          item-key="id"
          handle=".option-handle"
          :disabled="readonly"
          @end="emitUpdate"
        >
          <template #item="{ element: option, index: optIndex }">
            <div class="option-item">
              <span class="option-handle">
                <DragIcon :size="16" />
              </span>
              <!-- TRANSLATORS: Placeholder of an empty answer option; {n} is its number -->
              <NcTextField :placeholder="t('formvox', 'Option {n}', { n: optIndex + 1 })"
                v-model="option.label"
                :disabled="readonly"
                class="option-label-input"
                @update:model-value="emitUpdate"
              />
              <!-- TRANSLATORS: Placeholder of the points an answer option is worth in a quiz -->
              <NumberField :placeholder="t('formvox', 'Score')"
                v-if="isQuizMode"
                :model-value="option.score ?? null"
                :disabled="readonly"
                class="score-input"
                @update:model-value="(val) => updateOptionScore(option, val)"
              />
              <!-- TRANSLATORS: Tooltip of the capacity field of an answer option -->
              <NumberField :title="t('formvox', 'Maximum times this option can be selected — leave empty for unlimited')"
                :model-value="option.capacity ?? null"
                min="0"
                :disabled="readonly"
                :placeholder="t('formvox', 'Max')"
                class="capacity-input"
                @update:model-value="(val) => updateOptionCapacity(option, val)"
              />
              <NcButton
                v-if="!readonly"
                type="tertiary"
                @click="removeOption(optIndex)"
              >
                <template #icon>
                  <CloseIcon :size="20" />
                </template>
              </NcButton>
            </div>
          </template>
        </draggable>
        <NcButton v-if="!readonly" @click="addOption">
          <template #icon>
            <PlusIcon :size="20" />
          </template>
          <!-- TRANSLATORS: Verb: button that adds an answer option -->
          {{ t('formvox', 'Add option') }}
        </NcButton>
      </div>

      <!-- Scale settings -->
      <div v-if="localQuestion.type === 'scale'" class="scale-settings">
        <div class="scale-row">
          <NumberField
            :model-value="localQuestion.scaleMin ?? null"
            :disabled="readonly"
            :label="t('formvox', 'Min')"
            @update:model-value="(val) => updateQuestionNumber('scaleMin', val)"
          />
          <NumberField
            :model-value="localQuestion.scaleMax ?? null"
            :disabled="readonly"
            :label="t('formvox', 'Max')"
            @update:model-value="(val) => updateQuestionNumber('scaleMax', val)"
          />
        </div>
        <div class="scale-row">
          <!-- TRANSLATORS: Label of the text at the low end of a scale -->
          <NcTextField :label="t('formvox', 'Min label')"
            v-model="localQuestion.scaleMinLabel"
            :disabled="readonly"
            :placeholder="t('formvox', 'e.g. Not at all')"
            @update:model-value="emitUpdate"
          />
          <!-- TRANSLATORS: Label of the text at the high end of a scale -->
          <NcTextField :label="t('formvox', 'Max label')"
            v-model="localQuestion.scaleMaxLabel"
            :disabled="readonly"
            :placeholder="t('formvox', 'e.g. Very much')"
            @update:model-value="emitUpdate"
          />
        </div>
      </div>

      <!-- Rating settings -->
      <div v-if="localQuestion.type === 'rating'" class="rating-settings">
        <!-- TRANSLATORS: Label of the number of stars in a rating question -->
        <NumberField :label="t('formvox', 'Maximum stars')"
          :model-value="localQuestion.ratingMax ?? null"
          :disabled="readonly"
          @update:model-value="(val) => updateQuestionNumber('ratingMax', val)"
        />
      </div>

      <!-- Date/Time range settings -->
      <div v-if="['date', 'datetime', 'time'].includes(localQuestion.type)" class="scale-settings">
        <h4>{{ t('formvox', 'Restrict selectable range (optional)') }}</h4>
        <div class="scale-row">
          <template v-if="localQuestion.type !== 'time'">
            <!-- TRANSLATORS: Placeholder of an empty minimum field -->
            <NcDateTimePicker :placeholder="t('formvox', 'No minimum')"
              :model-value="localQuestion.dateMin ? new Date(localQuestion.dateMin) : null"
              :type="localQuestion.type === 'datetime' ? 'datetime' : 'date'"
              :clearable="true"
              :disabled="readonly"
              @update:model-value="updateDateMin"
            />
            <!-- TRANSLATORS: Placeholder of an empty maximum field -->
            <NcDateTimePicker :placeholder="t('formvox', 'No maximum')"
              :model-value="localQuestion.dateMax ? new Date(localQuestion.dateMax) : null"
              :type="localQuestion.type === 'datetime' ? 'datetime' : 'date'"
              :clearable="true"
              :disabled="readonly"
              @update:model-value="updateDateMax"
            />
          </template>
          <template v-else>
            <div class="form-field">
              <!-- TRANSLATORS: Label of the earliest allowed time -->
              <label class="form-label">{{ t('formvox', 'Earliest time') }}</label>
              <input
                type="time"
                :value="localQuestion.timeMin || ''"
                class="time-input"
                :disabled="readonly"
                @input="localQuestion.timeMin = $event.target.value || undefined; emitUpdate()"
              />
            </div>
            <div class="form-field">
              <!-- TRANSLATORS: Label of the latest allowed time -->
              <label class="form-label">{{ t('formvox', 'Latest time') }}</label>
              <input
                type="time"
                :value="localQuestion.timeMax || ''"
                class="time-input"
                :disabled="readonly"
                @input="localQuestion.timeMax = $event.target.value || undefined; emitUpdate()"
              />
            </div>
          </template>
        </div>
      </div>

      <!-- File upload settings -->
      <div v-if="localQuestion.type === 'file'" class="file-settings">
        <div class="form-field">
          <label class="form-label">{{ t('formvox', 'Allowed file types') }}</label>
          <select v-model="localQuestion.allowedTypePreset" :disabled="readonly" class="type-select" @change="onFileTypePresetChange">
            <!-- TRANSLATORS: Choice of which files may be uploaded -->
            <option value="images">{{ t('formvox', 'Images only') }}</option>
            <option value="documents">{{ t('formvox', 'Documents (PDF, Word, Excel)') }}</option>
            <!-- TRANSLATORS: Choice of which files may be uploaded -->
            <option value="pdf">{{ t('formvox', 'PDF only') }}</option>
            <!-- TRANSLATORS: Choice of which files may be uploaded -->
            <option value="all">{{ t('formvox', 'All files') }}</option>
            <!-- TRANSLATORS: Choice: the user sets which file types are allowed -->
            <option value="custom">{{ t('formvox', 'Custom') }}</option>
          </select>
        </div>

        <div v-if="localQuestion.allowedTypePreset === 'custom'" class="form-field">
          <label class="form-label">{{ t('formvox', 'Custom MIME types or extensions') }}</label>
          <NcTextField
            v-model="customTypesString"
            :disabled="readonly"
            :placeholder="t('formvox', 'e.g. .pdf, .docx, image/*')"
            @update:model-value="onCustomTypesChange"
          />
          <small class="hint">{{ t('formvox', 'Comma-separated list of extensions (.pdf) or MIME types (image/*)') }}</small>
        </div>

        <div class="form-field">
          <label class="form-label">{{ t('formvox', 'Maximum file size (MB)') }}</label>
          <NumberField
            :model-value="localQuestion.maxFileSize ?? null"
            :disabled="readonly"
            :min="1"
            :max="100"
            @update:model-value="(val) => updateQuestionNumber('maxFileSize', val)"
          />
        </div>

        <div class="form-field">
          <NcCheckboxRadioSwitch
            :model-value="localQuestion.maxFiles > 1"
            :disabled="readonly"
            @update:model-value="localQuestion.maxFiles = $event ? 5 : 1; emitUpdate()"
          >
            {{ t('formvox', 'Allow multiple files') }}
          </NcCheckboxRadioSwitch>
        </div>

        <div v-if="localQuestion.maxFiles > 1" class="form-field">
          <label class="form-label">{{ t('formvox', 'Maximum number of files') }}</label>
          <NumberField
            :model-value="localQuestion.maxFiles ?? null"
            :disabled="readonly"
            :min="2"
            :max="20"
            @update:model-value="(val) => updateQuestionNumber('maxFiles', val)"
          />
        </div>
      </div>

      <!-- Multiple-choice selection limits (#113) -->
      <div v-if="localQuestion.type === 'multiple'" class="scale-settings">
        <div class="scale-inline">
          <div class="form-field">
            <!-- TRANSLATORS: Label: how many options must be picked at least -->
            <label class="form-label">{{ t('formvox', 'Minimum selections') }}</label>
            <NumberField
              :model-value="localQuestion.minSelections ?? null"
              :disabled="readonly"
              :min="0"
              :placeholder="t('formvox', 'No minimum')"
              @update:model-value="(val) => updateQuestionNumber('minSelections', val)"
            />
          </div>
          <div class="form-field">
            <!-- TRANSLATORS: Label: how many options may be picked at most -->
            <label class="form-label">{{ t('formvox', 'Maximum selections') }}</label>
            <NumberField
              :model-value="localQuestion.maxSelections ?? null"
              :disabled="readonly"
              :min="0"
              :placeholder="t('formvox', 'No maximum')"
              @update:model-value="(val) => updateQuestionNumber('maxSelections', val)"
            />
          </div>
        </div>
        <small class="hint">{{ t('formvox', 'Leave empty for no limit. Respondents cannot submit fewer than the minimum or more than the maximum.') }}</small>
      </div>

      <!-- Long text character limit (#113) -->
      <div v-if="localQuestion.type === 'textarea'" class="scale-settings">
        <div class="form-field">
          <!-- TRANSLATORS: Label of the length limit of a text answer -->
          <label class="form-label">{{ t('formvox', 'Maximum characters') }}</label>
          <!-- TRANSLATORS: Placeholder of an empty limit field -->
          <NumberField :placeholder="t('formvox', 'No limit')"
            :model-value="localQuestion.maxLength ?? null"
            :disabled="readonly"
            :min="0"
            @update:model-value="(val) => updateQuestionNumber('maxLength', val)"
          />
          <small class="hint">{{ t('formvox', 'Leave empty for no limit. A live counter is shown to respondents.') }}</small>
        </div>
      </div>

      <!-- Matrix settings -->
      <div v-if="localQuestion.type === 'matrix'" class="matrix-settings">
        <div class="matrix-section">
          <!-- TRANSLATORS: Heading above the rows of a grid question -->
          <h4>{{ t('formvox', 'Rows') }}</h4>
          <div v-for="(row, rowIndex) in localQuestion.rows" :key="row.id" class="matrix-item">
            <!-- TRANSLATORS: Placeholder of an empty row; {n} is its number -->
            <NcTextField :placeholder="t('formvox', 'Row {n}', { n: rowIndex + 1 })"
              v-model="row.label"
              :disabled="readonly"
              @update:model-value="emitUpdate"
            />
            <NcButton v-if="!readonly" type="tertiary" @click="removeRow(rowIndex)">
              <template #icon>
                <CloseIcon :size="20" />
              </template>
            </NcButton>
          </div>
          <NcButton v-if="!readonly" @click="addRow">
            <template #icon>
              <PlusIcon :size="20" />
            </template>
            <!-- TRANSLATORS: Verb: button -->
            {{ t('formvox', 'Add row') }}
          </NcButton>
        </div>

        <div class="matrix-section">
          <!-- TRANSLATORS: Heading above the columns of a grid or table question -->
          <h4>{{ t('formvox', 'Columns') }}</h4>
          <div v-for="(col, colIndex) in localQuestion.columns" :key="col.id" class="matrix-item">
            <!-- TRANSLATORS: Placeholder of an empty column; {n} is its number -->
            <NcTextField :placeholder="t('formvox', 'Column {n}', { n: colIndex + 1 })"
              v-model="col.label"
              :disabled="readonly"
              @update:model-value="emitUpdate"
            />
            <NcButton v-if="!readonly" type="tertiary" @click="removeColumn(colIndex)">
              <template #icon>
                <CloseIcon :size="20" />
              </template>
            </NcButton>
          </div>
          <NcButton v-if="!readonly" @click="addColumn">
            <template #icon>
              <PlusIcon :size="20" />
            </template>
            <!-- TRANSLATORS: Verb: button -->
            {{ t('formvox', 'Add column') }}
          </NcButton>
        </div>
      </div>

      <!-- Table column configuration -->
      <div v-if="localQuestion.type === 'table'" class="table-settings">
        <h4>{{ t('formvox', 'Columns') }}</h4>
        <div v-for="(col, colIndex) in localQuestion.columns" :key="col.id" class="table-column-item">
          <div class="table-column-row">
            <NcTextField
              v-model="col.label"
              :disabled="readonly"
              :placeholder="t('formvox', 'Column {n}', { n: colIndex + 1 })"
              class="column-label"
              @update:model-value="emitUpdate"
            />
            <select
              v-model="col.inputType"
              :disabled="readonly"
              class="column-type-select"
              @change="emitUpdate"
            >
              <option value="text">{{ t('formvox', 'Text') }}</option>
              <option value="number">{{ t('formvox', 'Number') }}</option>
              <option value="date">{{ t('formvox', 'Date') }}</option>
              <option value="dropdown">{{ t('formvox', 'Dropdown') }}</option>
            </select>
            <NcButton v-if="!readonly" type="tertiary" @click="removeTableColumn(colIndex)">
              <template #icon>
                <CloseIcon :size="20" />
              </template>
            </NcButton>
          </div>
          <NcTextField
            v-if="col.inputType === 'dropdown'"
            v-model="col.optionsText"
            :disabled="readonly"
            :placeholder="t('formvox', 'Options (comma separated)')"
            class="column-options"
            @update:model-value="updateTableColumnOptions(colIndex); emitUpdate()"
          />
        </div>
        <NcButton v-if="!readonly" @click="addTableColumn">
          <template #icon>
            <PlusIcon :size="20" />
          </template>
          {{ t('formvox', 'Add column') }}
        </NcButton>
      </div>

      <!-- Question settings -->
      <div class="question-settings">
        <NcCheckboxRadioSwitch
          :model-value="localQuestion.required"
          :disabled="readonly"
          @update:model-value="localQuestion.required = $event; emitUpdate()"
        >
          <!-- TRANSLATORS: Switch that makes a question mandatory, and the tooltip of the * marker -->
          {{ t('formvox', 'Required') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          v-if="hasOptions"
          :model-value="isQuizMode"
          :disabled="readonly"
          @update:model-value="toggleQuizMode"
        >
          {{ t('formvox', 'Quiz mode (with scores)') }}
        </NcCheckboxRadioSwitch>

      </div>

      <!-- Custom validation (text types only) -->
      <div v-if="supportsValidation" class="validation-settings">
        <NcCheckboxRadioSwitch
          :model-value="hasValidation"
          :disabled="readonly"
          @update:model-value="toggleValidation"
        >
          <!-- TRANSLATORS: Switch that turns on an input check for a text answer -->
          {{ t('formvox', 'Validation pattern') }}
        </NcCheckboxRadioSwitch>

        <div v-if="hasValidation" class="validation-fields">
          <!-- Easy mode: preset patterns -->
          <div class="form-field">
            <!-- TRANSLATORS: Label of the kind of input check -->
            <label class="form-label">{{ t('formvox', 'Validation type') }}</label>
            <NcSelect
              v-model="validationPreset"
              :disabled="readonly"
              :options="validationPresetOptions"
              :placeholder="t('formvox', 'Select a validation type')"
              label="label"
              track-by="value"
              @update:model-value="onValidationPresetChange"
            />
          </div>

          <!-- Custom regex input (only shown for 'custom' preset) -->
          <div v-if="validationPreset?.value === 'custom'" class="form-field">
            <!-- TRANSLATORS: Technical term: a search pattern; use the common term in your language -->
            <label class="form-label">{{ t('formvox', 'Regular expression') }}</label>
            <!-- TRANSLATORS: Placeholder of an input field; {example} is a sample value such as a pattern or a key, kept as is -->
            <NcTextField :placeholder="t('formvox', 'e.g. {example}', { example: '^[0-9]{4}[A-Z]{2}$' })"
              v-model="localQuestion.validation.pattern"
              :disabled="readonly"
              @update:model-value="emitUpdate"
            />
            <!-- TRANSLATORS: {pattern1} and {pattern2} are regular expressions; they stay as they are -->
            <small class="hint">{{ t('formvox', 'Examples: {pattern1} (license plate), {pattern2} (10 digits)', { pattern1: '^[A-Z]{2}[0-9]{4}$', pattern2: '^\\d{10}$' }) }}</small>
          </div>

          <!-- Custom error message -->
          <div class="form-field">
            <!-- TRANSLATORS: Label of the message shown when the check fails -->
            <label class="form-label">{{ t('formvox', 'Error message') }}</label>
            <NcTextField
              v-model="localQuestion.validation.errorMessage"
              :disabled="readonly"
              :placeholder="validationPreset?.defaultError || t('formvox', 'e.g. Please enter a valid value')"
              @update:model-value="emitUpdate"
            />
          </div>

        </div>
      </div>

      <!-- Condition indicator -->
      <div v-if="localQuestion.showIf" class="condition-indicator">
        <BranchIcon :size="16" />
        {{ t('formvox', 'This question has conditions') }}
        <NcButton type="tertiary" @click="showConditions = true">
          {{ t('formvox', 'Edit') }}
        </NcButton>
      </div>
    </div>

    <ConditionEditor
      v-if="showConditions"
      :condition="localQuestion.showIf"
      :questions="questions"
      :current-question-id="localQuestion.id"
      @update="updateCondition"
      @close="showConditions = false"
    />
    </template>
  </div>
</template>

<script>
import { ref, reactive, computed, watch, nextTick } from 'vue';
import {
  NcButton,
  NcActions,
  NcActionButton,
  NcTextField,
  NcTextArea,
  NcCheckboxRadioSwitch,
  NcSelect,
  NcDateTimePicker,
} from '@nextcloud/vue';
import { v4 as uuidv4 } from 'uuid';
import { t } from '@/utils/l10n';
import draggable from 'vuedraggable';
import ConditionEditor from './ConditionEditor.vue';
import MarkdownEditor from './MarkdownEditor.vue';
import NumberField from './NumberField.vue';
import DragIcon from './icons/DragIcon.vue';
import ChevronIcon from './icons/ChevronIcon.vue';
import CopyIcon from './icons/CopyIcon.vue';
import BranchIcon from './icons/BranchIcon.vue';
import DeleteIcon from './icons/DeleteIcon.vue';
import CloseIcon from './icons/CloseIcon.vue';
import PlusIcon from './icons/PlusIcon.vue';
import PagesIcon from './icons/PagesIcon.vue';
import SectionIcon from 'vue-material-design-icons/CardText.vue';

export default {
  name: 'QuestionEditor',
  components: {
    NcButton,
    NcActions,
    NcActionButton,
    NcTextField,
    NumberField,
    NcTextArea,
    NcCheckboxRadioSwitch,
    NcSelect,
    NcDateTimePicker,
    draggable,
    ConditionEditor,
    MarkdownEditor,
    DragIcon,
    ChevronIcon,
    CopyIcon,
    BranchIcon,
    DeleteIcon,
    CloseIcon,
    PlusIcon,
    PagesIcon,
  },
  props: {
    question: {
      type: Object,
      required: true,
    },
    index: {
      type: Number,
      required: true,
    },
    questions: {
      type: Array,
      required: true,
    },
    pages: {
      type: Array,
      default: () => [],
    },
    currentPageIndex: {
      type: Number,
      default: 0,
    },
    readonly: {
      type: Boolean,
      default: false,
    },
  },
  emits: ['update', 'delete', 'duplicate', 'move', 'move-to-section'],
  setup(props, { emit }) {
    const collapsed = ref(false);
    const showConditions = ref(false);
    // Deep copy to preserve nested objects like validation
    // Normalise legacy option shapes in place. Options are expected to be
    // objects, but a bare string is a valid shape elsewhere in a .fvform (table
    // columns store options that way) and the REST API accepts questions
    // unvalidated, so a string can reach us here. Assigning to a property of a
    // string throws in strict mode and took down the whole options editor, so
    // convert those to objects before touching any property.
    // Question-level number settings bound with v-model.number. A null here hits
    // the same NcTextField crash as capacity did, so the whole settings block
    // fails to render and the fields are simply gone (#134). An absent key is
    // the correct "not set" state for all of these.
    const NULLABLE_NUMBER_SETTINGS = [
      'scaleMin', 'scaleMax', 'ratingMax', 'maxFileSize', 'maxFiles',
      'minSelections', 'maxSelections', 'maxLength',
    ];

    // A null in any text field bound to an NcTextField crashes the same way a
    // null number does: NcTextField renders with `modelValue.value.toString()`
    // and its default '' only covers undefined, not an explicit null — so the
    // whole settings block fails to render (#134). The editor never types a null
    // (it types '' or deletes the key), but the REST API stores questions
    // verbatim and imports build them, so a null can arrive via external data.
    // Coerce every nullable text field to '' on load. Helper mutates in place.
    const coerceNullText = (obj, key) => {
      if (obj && obj[key] === null) {
        obj[key] = '';
      }
    };

    const migrateQuestion = (question) => {
      // Numeric settings: an absent key is the correct "not set" state.
      NULLABLE_NUMBER_SETTINGS.forEach((key) => {
        if (question[key] === null) {
          delete question[key];
        }
      });

      // Question-level text fields.
      coerceNullText(question, 'question');
      coerceNullText(question, 'description');
      coerceNullText(question, 'scaleMinLabel');
      coerceNullText(question, 'scaleMaxLabel');
      if (question.validation && typeof question.validation === 'object') {
        coerceNullText(question.validation, 'pattern');
        coerceNullText(question.validation, 'errorMessage');
      }

      // Matrix rows and matrix/table columns carry their own labels.
      if (Array.isArray(question.rows)) {
        question.rows.forEach((row) => coerceNullText(row, 'label'));
      }
      if (Array.isArray(question.columns)) {
        question.columns.forEach((col) => {
          coerceNullText(col, 'label');
          coerceNullText(col, 'optionsText');
        });
      }

      if (!Array.isArray(question.options)) {
        return;
      }
      question.options.forEach((opt, i) => {
        if (typeof opt === 'string' || typeof opt === 'number') {
          const id = `opt${uuidv4().split('-')[0]}`;
          question.options[i] = { id, label: String(opt), value: id };
          return;
        }
        if (!opt || typeof opt !== 'object') {
          const id = `opt${uuidv4().split('-')[0]}`;
          question.options[i] = { id, label: '', value: id };
          return;
        }
        if (!opt.value) {
          opt.value = opt.id;
        }
        // A null label on a well-formed object option is the #134 smoking gun:
        // the numeric Max/Score siblings were guarded in 1.4.4 but the label
        // itself was not, so the option row still crashed on load.
        coerceNullText(opt, 'label');
        // A null capacity crashes NcTextField the same way. "No limit" is the
        // absent key, not null — drop it so the field comes back.
        if (opt.capacity === null) {
          delete opt.capacity;
        }
        if (opt.score === null) {
          opt.score = 0;
        }
      });
    };

    const localQuestion = reactive(JSON.parse(JSON.stringify(props.question)));
    migrateQuestion(localQuestion);
    const customTypesString = ref('');

    // Color options for question highlighting
    const colorOptions = [
      // TRANSLATORS: Choice in the color list of a section
      { value: '', label: t('formvox', 'No color') },
      // TRANSLATORS: Color name
      { value: '#0082c9', label: t('formvox', 'Blue') },
      // TRANSLATORS: Color name
      { value: '#00a86b', label: t('formvox', 'Green') },
      // TRANSLATORS: Color name
      { value: '#f4a100', label: t('formvox', 'Orange') },
      // TRANSLATORS: Color name
      { value: '#e53935', label: t('formvox', 'Red') },
      // TRANSLATORS: Color name, also the name of a color theme
      { value: '#9c27b0', label: t('formvox', 'Purple') },
      // TRANSLATORS: Color name
      { value: '#00bcd4', label: t('formvox', 'Cyan') },
      // TRANSLATORS: Color name
      { value: '#795548', label: t('formvox', 'Brown') },
    ];

    // File type presets
    const fileTypePresets = {
      images: ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'],
      documents: ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
      pdf: ['application/pdf'],
      all: ['*/*'],
    };

    watch(() => props.question, (newVal) => {
      // Deep copy to preserve nested objects like validation
      Object.keys(localQuestion).forEach(key => {
        delete localQuestion[key];
      });
      Object.assign(localQuestion, JSON.parse(JSON.stringify(newVal)));
      migrateQuestion(localQuestion);
    }, { deep: true });

    const hasOptions = computed(() => {
      return ['choice', 'multiple', 'dropdown'].includes(localQuestion.type);
    });

    const isQuizMode = computed(() => {
      return localQuestion.options?.some(opt => typeof opt.score === 'number');
    });

    // Validation support for text-based types
    const supportsValidation = computed(() => {
      return ['text', 'textarea', 'number'].includes(localQuestion.type);
    });

    const hasValidation = computed(() => {
      return localQuestion.validation !== undefined && localQuestion.validation !== null;
    });

    // Validation presets for easy mode
    const validationPresets = {
      email: { pattern: '^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}$', defaultError: t('formvox', 'Please enter a valid email address') },
      phone_nl: { pattern: '^(\\+31|0)[1-9][0-9]{8}$', defaultError: t('formvox', 'Please enter a valid Dutch phone number') },
      phone_intl: { pattern: '^\\+?[1-9]\\d{6,14}$', defaultError: t('formvox', 'Please enter a valid phone number') },
      postal_nl: { pattern: '^[1-9][0-9]{3}\\s?[A-Za-z]{2}$', defaultError: t('formvox', 'Please enter a valid Dutch postal code (e.g. 1234 AB)') },
      postal_be: { pattern: '^[1-9][0-9]{3}$', defaultError: t('formvox', 'Please enter a valid Belgian postal code (e.g. 1000)') },
      url: { pattern: '^https?:\\/\\/[\\w\\-]+(\\.[\\w\\-]+)+[/#?]?.*$', defaultError: t('formvox', 'Please enter a valid URL') },
      digits_only: { pattern: '^[0-9]+$', defaultError: t('formvox', 'Please enter only digits') },
      letters_only: { pattern: '^[a-zA-Z]+$', defaultError: t('formvox', 'Please enter only letters') },
      alphanumeric: { pattern: '^[a-zA-Z0-9]+$', defaultError: t('formvox', 'Please enter only letters and numbers') },
      custom: { pattern: '', defaultError: '' },
    };

    const validationPresetOptions = [
      { value: 'email', label: t('formvox', 'Email address'), defaultError: validationPresets.email.defaultError },
      { value: 'phone_nl', label: t('formvox', 'Phone number (NL)'), defaultError: validationPresets.phone_nl.defaultError },
      { value: 'phone_intl', label: t('formvox', 'Phone number (international)'), defaultError: validationPresets.phone_intl.defaultError },
      { value: 'postal_nl', label: t('formvox', 'Postal code (NL)'), defaultError: validationPresets.postal_nl.defaultError },
      { value: 'postal_be', label: t('formvox', 'Postal code (BE)'), defaultError: validationPresets.postal_be.defaultError },
      // TRANSLATORS: Name of an input check that only accepts web addresses
      { value: 'url', label: t('formvox', 'Website URL'), defaultError: validationPresets.url.defaultError },
      // TRANSLATORS: Name of an input check
      { value: 'digits_only', label: t('formvox', 'Digits only'), defaultError: validationPresets.digits_only.defaultError },
      // TRANSLATORS: Name of an input check
      { value: 'letters_only', label: t('formvox', 'Letters only'), defaultError: validationPresets.letters_only.defaultError },
      { value: 'alphanumeric', label: t('formvox', 'Letters and numbers'), defaultError: validationPresets.alphanumeric.defaultError },
      // TRANSLATORS: Name of an input check where the user writes their own pattern
      { value: 'custom', label: t('formvox', 'Custom (advanced)'), defaultError: '' },
    ];

    // Determine current validation preset based on pattern
    const validationPreset = ref(null);

    // Initialize validationPreset when question changes
    watch(() => localQuestion.validation?.pattern, (pattern) => {
      if (!pattern) {
        validationPreset.value = null;
        return;
      }
      // Find matching preset
      const found = Object.entries(validationPresets).find(([key, preset]) => preset.pattern === pattern);
      if (found) {
        validationPreset.value = validationPresetOptions.find(opt => opt.value === found[0]) || null;
      } else {
        // Custom pattern
        validationPreset.value = validationPresetOptions.find(opt => opt.value === 'custom') || null;
      }
    }, { immediate: true });

    const onValidationPresetChange = (selected) => {
      if (!selected) {
        localQuestion.validation.pattern = '';
        localQuestion.validation.errorMessage = '';
      } else if (selected.value === 'custom') {
        // Keep existing pattern if any, or clear
        if (!localQuestion.validation.pattern) {
          localQuestion.validation.pattern = '';
        }
      } else {
        const preset = validationPresets[selected.value];
        localQuestion.validation.pattern = preset.pattern;
        // Only set default error if user hasn't customized it
        if (!localQuestion.validation.errorMessage) {
          localQuestion.validation.errorMessage = preset.defaultError;
        }
      }
      emitUpdate();
    };

    // The names in the "Move to" menus: a nested t() would share the outer
    // call's translator hint, so these have their own.
    // TRANSLATORS: Name of a section without a title
    const sectionName = (section) => section.question || t('formvox', 'Untitled section');
    // TRANSLATORS: Name of a page without a title; {n} is its number, for example 2
    const pageName = (page) => page.title || t('formvox', 'Page {n}', { n: page.index + 1 });

    // Get other pages (excluding current page) for "Move to page" menu
    // Available sections for "Move to section" menu
    const availableSections = computed(() => {
      return props.questions.filter(q => q.type === 'section' && q.id !== localQuestion.id);
    });

    const assignToSection = (sectionId) => {
      emit('move-to-section', sectionId);
    };

    const removeFromSection = () => {
      delete localQuestion.sectionId;
      emitUpdate();
    };

    const otherPages = computed(() => {
      if (!props.pages || props.pages.length <= 1) return [];
      return props.pages
        .map((page, index) => ({ ...page, index }))
        .filter((_, index) => index !== props.currentPageIndex);
    });

    const moveToPage = (targetPageIndex) => {
      emit('move', targetPageIndex);
    };

    const emitUpdate = () => {
      // Deep copy to preserve nested objects like validation
      emit('update', JSON.parse(JSON.stringify(localQuestion)));
    };

    // The NumberField wrapper already guards the render (never passes null to
    // NcTextField) and emits a clean number|null: null means "empty". Here we
    // only decide what "empty" means for a given key — drop it, or fall back to
    // a default. The render-crash that was #134 is handled by the wrapper, so a
    // null can no longer reach the input regardless of what we store.
    const setNumberField = (target, key, value, fallback) => {
      if (value === null) {
        if (fallback === undefined) {
          delete target[key];
        } else {
          target[key] = fallback;
        }
      } else {
        target[key] = value;
      }
      emitUpdate();
    };

    // Scale bounds, star count, file limits, selection limits and the character
    // limit. All of these treat an absent key as "not set": the renderer falls
    // back to its own defaults, so clearing one is safe.
    const updateQuestionNumber = (key, value) => {
      setNumberField(localQuestion, key, value);
    };

    const updateOptionCapacity = (option, value) => {
      setNumberField(option, 'capacity', value);
    };

    // Score clears to 0 rather than an absent key: isQuizMode is derived from a
    // numeric score, so deleting it would switch quiz mode off entirely.
    const updateOptionScore = (option, value) => {
      setNumberField(option, 'score', value, 0);
    };

    const updateDescriptorAlign = (align) => {
      localQuestion.descriptorAlign = align;
      emitUpdate();
    };

    const onToggleRespondentEmail = (val) => {
      localQuestion.useAsRespondentEmail = !!val;
      emitUpdate();
    };

    const onTypeChange = () => {
      // Strip orphaned type-specific fields from the previous type so they
      // don't bleed into the new type (e.g. options on a text question, or a
      // text-only validation pattern on a multiple-choice question).
      const t = localQuestion.type;
      const hasOpts = ['choice', 'multiple', 'dropdown'].includes(t);
      if (!hasOpts) {
        delete localQuestion.options;
      }
      if (t !== 'scale') {
        delete localQuestion.scaleMin;
        delete localQuestion.scaleMax;
        delete localQuestion.scaleMinLabel;
        delete localQuestion.scaleMaxLabel;
      }
      if (t !== 'rating') {
        delete localQuestion.ratingMax;
      }
      if (t !== 'matrix') {
        delete localQuestion.rows;
        // columns is shared with table — only drop if also not a table
        if (t !== 'table') delete localQuestion.columns;
      }
      if (t !== 'table') {
        delete localQuestion.minRows;
        delete localQuestion.maxRows;
      }
      if (t !== 'file') {
        delete localQuestion.allowedTypePreset;
        delete localQuestion.allowedTypes;
        delete localQuestion.maxFileSize;
        delete localQuestion.maxFiles;
      }
      // text/textarea-only validation
      if (!['text', 'textarea'].includes(t)) {
        delete localQuestion.validation;
      }
      // number/date-only min/max bounds
      if (!['number'].includes(t)) {
        // keep number-only min/max separate from scale's; FormVox stores them at top-level for number too
      }
      if (!['date', 'datetime'].includes(t)) {
        delete localQuestion.dateMin;
        delete localQuestion.dateMax;
      }

      // Initialize type-specific properties
      if (hasOptions.value && (!localQuestion.options || localQuestion.options.length === 0)) {
        const id1 = `opt${uuidv4().split('-')[0]}`;
        const id2 = `opt${uuidv4().split('-')[0]}`;
        localQuestion.options = [
          { id: id1, label: '', value: id1 },
          { id: id2, label: '', value: id2 },
        ];
      }

      if (localQuestion.type === 'scale') {
        localQuestion.scaleMin = localQuestion.scaleMin ?? 1;
        localQuestion.scaleMax = localQuestion.scaleMax ?? 5;
      }

      if (localQuestion.type === 'rating') {
        localQuestion.ratingMax = localQuestion.ratingMax ?? 5;
      }

      if (localQuestion.type === 'matrix') {
        localQuestion.rows = localQuestion.rows ?? [
          { id: 'r1', label: '' },
          { id: 'r2', label: '' },
        ];
        localQuestion.columns = localQuestion.columns ?? [
          { id: 'c1', label: '', value: 1 },
          { id: 'c2', label: '', value: 2 },
          { id: 'c3', label: '', value: 3 },
        ];
      }

      if (localQuestion.type === 'table') {
        localQuestion.columns = localQuestion.columns ?? [
          { id: `col${uuidv4().split('-')[0]}`, label: '', inputType: 'text', options: [], optionsText: '' },
          { id: `col${uuidv4().split('-')[0]}`, label: '', inputType: 'text', options: [], optionsText: '' },
        ];
        localQuestion.minRows = localQuestion.minRows ?? 1;
        localQuestion.maxRows = localQuestion.maxRows ?? 50;
      }

      if (localQuestion.type === 'file') {
        localQuestion.allowedTypePreset = localQuestion.allowedTypePreset ?? 'all';
        localQuestion.allowedTypes = localQuestion.allowedTypes ?? fileTypePresets.all;
        localQuestion.maxFileSize = localQuestion.maxFileSize ?? 10;
        localQuestion.maxFiles = localQuestion.maxFiles ?? 1;
      }

      emitUpdate();
    };

    const onFileTypePresetChange = () => {
      const preset = localQuestion.allowedTypePreset;
      if (preset && preset !== 'custom') {
        localQuestion.allowedTypes = fileTypePresets[preset] || fileTypePresets.all;
      }
      emitUpdate();
    };

    const onCustomTypesChange = (value) => {
      customTypesString.value = value;
      // Parse comma-separated values
      localQuestion.allowedTypes = value
        .split(',')
        .map(t => t.trim())
        .filter(t => t.length > 0);
      emitUpdate();
    };

    const addOption = () => {
      if (!localQuestion.options) {
        localQuestion.options = [];
      }
      const id = `opt${uuidv4().split('-')[0]}`;
      localQuestion.options.push({
        id,
        label: '',
        value: id,
      });
      emitUpdate();
    };

    const removeOption = (index) => {
      localQuestion.options.splice(index, 1);
      emitUpdate();
    };

    const toggleQuizMode = (enabled) => {
      if (enabled) {
        localQuestion.options.forEach((opt, i) => {
          opt.score = 0;
        });
      } else {
        localQuestion.options.forEach(opt => {
          delete opt.score;
        });
      }
      emitUpdate();
    };

    const toggleValidation = (enabled) => {
      if (enabled) {
        localQuestion.validation = {
          pattern: '',
          errorMessage: '',
        };
      } else {
        delete localQuestion.validation;
      }
      emitUpdate();
    };

    const updateValidationPattern = (value) => {
      if (!localQuestion.validation) {
        localQuestion.validation = {};
      }
      localQuestion.validation.pattern = value;
      emitUpdate();
    };

    const updateValidationErrorMessage = (value) => {
      if (!localQuestion.validation) {
        localQuestion.validation = {};
      }
      localQuestion.validation.errorMessage = value;
      emitUpdate();
    };

    const addRow = () => {
      if (!localQuestion.rows) {
        localQuestion.rows = [];
      }
      localQuestion.rows.push({
        id: `r${uuidv4().split('-')[0]}`,
        label: '',
      });
      emitUpdate();
    };

    const removeRow = (index) => {
      localQuestion.rows.splice(index, 1);
      emitUpdate();
    };

    const addColumn = () => {
      if (!localQuestion.columns) {
        localQuestion.columns = [];
      }
      const nextValue = localQuestion.columns.length + 1;
      localQuestion.columns.push({
        id: `c${uuidv4().split('-')[0]}`,
        label: '',
        value: nextValue,
      });
      emitUpdate();
    };

    const removeColumn = (index) => {
      localQuestion.columns.splice(index, 1);
      emitUpdate();
    };

    const addTableColumn = () => {
      if (!localQuestion.columns) {
        localQuestion.columns = [];
      }
      localQuestion.columns.push({
        id: `col${uuidv4().split('-')[0]}`,
        label: '',
        inputType: 'text',
        options: [],
        optionsText: '',
      });
      emitUpdate();
    };

    const removeTableColumn = (index) => {
      localQuestion.columns.splice(index, 1);
      emitUpdate();
    };

    const updateTableColumnOptions = (colIndex) => {
      const col = localQuestion.columns[colIndex];
      if (col) {
        col.options = (col.optionsText || '')
          .split(',')
          .map(s => s.trim())
          .filter(s => s.length > 0);
      }
    };

    const updateDateMin = (date) => {
      if (!date) {
        delete localQuestion.dateMin;
      } else if (localQuestion.type === 'datetime') {
        localQuestion.dateMin = date.toISOString();
      } else {
        localQuestion.dateMin = date.toISOString().split('T')[0];
      }
      emitUpdate();
    };

    const updateDateMax = (date) => {
      if (!date) {
        delete localQuestion.dateMax;
      } else if (localQuestion.type === 'datetime') {
        localQuestion.dateMax = date.toISOString();
      } else {
        localQuestion.dateMax = date.toISOString().split('T')[0];
      }
      emitUpdate();
    };

    const updateCondition = (condition) => {
      localQuestion.showIf = condition;
      emitUpdate();
      showConditions.value = false;
    };

    return {
      collapsed,
      showConditions,
      localQuestion,
      customTypesString,
      colorOptions,
      hasOptions,
      isQuizMode,
      supportsValidation,
      hasValidation,
      validationPreset,
      validationPresetOptions,
      onValidationPresetChange,
      otherPages,
      availableSections,
      assignToSection,
      removeFromSection,
      SectionIcon,
      emitUpdate,
      updateOptionCapacity,
      updateOptionScore,
      updateQuestionNumber,
      updateDescriptorAlign,
      onToggleRespondentEmail,
      onTypeChange,
      onFileTypePresetChange,
      onCustomTypesChange,
      addOption,
      removeOption,
      toggleQuizMode,
      toggleValidation,
      updateValidationPattern,
      updateValidationErrorMessage,
      addRow,
      removeRow,
      addColumn,
      removeColumn,
      addTableColumn,
      removeTableColumn,
      updateTableColumnOptions,
      updateDateMin,
      updateDateMax,
      updateCondition,
      moveToPage,
      t,
      sectionName,
      pageName,
    };
  },
};
</script>

<style scoped lang="scss">
.question-editor {
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius-large);
  margin-bottom: 16px;
  transition: box-shadow 0.2s, border-color 0.2s;

  &.has-color {
    border-left: 4px solid var(--question-color);
  }

  &:hover {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
  }

  &.is-section {
    border: none;
    background: transparent;
    box-shadow: none;
  }

  .add-condition-btn {
    border: 1px solid var(--color-border-dark) !important;
    background: white !important;
    margin-top: 8px;

    &:hover {
      background: var(--color-background-hover) !important;
    }
  }

  &:focus-within {
    border-color: var(--color-primary-element);

    &.has-color {
      border-left-color: var(--question-color);
    }
  }

  &.collapsed {
    .question-body {
      display: none;
    }
  }
}

.question-header {
  display: flex;
  align-items: center;
  padding: 12px 16px;
  gap: 12px;
  border-bottom: 1px solid var(--color-border);

  .drag-handle {
    cursor: grab;
    color: var(--color-text-maxcontrast);

    &:active {
      cursor: grabbing;
    }

    &.disabled {
      cursor: default;
      opacity: 0.3;
      pointer-events: none;
    }
  }

  .question-number {
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-primary-element);
    color: white;
    border-radius: 50%;
    font-size: 12px;
    font-weight: bold;
  }

  .type-select {
    padding: 8px 32px 8px 12px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    font-size: 14px;
    cursor: pointer;
    min-width: 140px;
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23666' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 10px center;

    &:hover {
      border-color: var(--color-primary-element);
    }

    &:focus {
      outline: 2px solid var(--color-primary-element);
      outline-offset: -2px;
    }
  }

  .question-actions {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 4px;

    :deep(.action-item__menutoggle) {
      svg {
        display: block;
      }
    }
  }
}

/* Fix NcActionButton icon alignment */
:deep(.action-item) {
  .action-button {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;

    .action-button__icon {
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;

      svg {
        display: block;
      }
    }

    .action-button__text {
      flex: 1;
      text-align: left;
    }
  }
}

.question-body {
  padding: 16px;
}

.form-field {
  margin-bottom: 16px;

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
  // Uitlijnen met input padding (12px is Nextcloud standaard input padding)
  padding-left: 12px;
}

.question-input {
  // Standaard NcTextField styling
}

.description-input {
  width: 100%;
  padding: 10px 12px;
  border: 2px solid var(--color-border-maxcontrast);
  border-radius: var(--border-radius-large);
  background: var(--color-main-background);
  font-family: inherit;
  font-size: 14px;
  line-height: 1.5;
  resize: none;
  box-sizing: border-box;

  &:focus {
    border-color: var(--color-primary-element);
    outline: none;
  }

  &:disabled {
    opacity: 0.7;
  }
}

.options-editor {
  margin-bottom: 16px;

  h4 {
    margin: 0 0 12px;
    font-size: 14px;
    font-weight: 600;
  }

  .option-item {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
    flex-wrap: wrap;

    .option-handle {
      cursor: grab;
      color: var(--color-text-maxcontrast);
      flex-shrink: 0;
    }

    // The label field is the only one allowed to absorb the free space, so a
    // long label no longer eats into the fixed-width fields beside it.
    .option-label-input {
      flex: 1 1 200px;
      min-width: 0;
    }

    // Never let these shrink: width alone is only a basis, and the default
    // flex-shrink: 1 squeezed the Max box down to ~46px on a narrow pane.
    .score-input,
    .capacity-input {
      flex: 0 0 80px;
      width: 80px;
    }

    :deep(.button-vue) {
      flex-shrink: 0;
    }
  }
}

@media (max-width: 768px) {
  .options-editor .option-item {
    // Too narrow for one line. The DOM order is already handle, label, score,
    // max, delete — so rather than reordering anything, give the label a basis
    // that fills the first line and let the number fields and the delete button
    // wrap onto the second, keeping the delete button last where it belongs.
    .option-label-input {
      flex: 1 1 calc(100% - 24px);
      min-width: 0;
    }
  }
}

.scale-settings,
.rating-settings {
  margin-bottom: 16px;

  .scale-row {
    display: flex;
    gap: 16px;
    margin-bottom: 12px;
  }
}

.matrix-settings {
  margin-bottom: 16px;

  .matrix-section {
    margin-bottom: 16px;

    h4 {
      margin: 0 0 12px;
      font-size: 14px;
      font-weight: 600;
    }

    .matrix-item {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 8px;
    }
  }
}

.table-settings {
  margin-bottom: 16px;

  h4 {
    margin: 0 0 12px;
    font-size: 14px;
    font-weight: 600;
  }

  .table-column-item {
    margin-bottom: 12px;
    padding: 10px;
    background: var(--color-background-hover);
    border-radius: var(--border-radius-large);

    .table-column-row {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .column-label {
      flex: 1;
    }

    .column-type-select {
      min-width: 110px;
      padding: 8px 10px;
      border: 1px solid var(--color-border-dark);
      border-radius: var(--border-radius);
      background: var(--color-main-background);
      font-size: 14px;
    }

    .column-options {
      margin-top: 8px;
    }
  }
}

.file-settings {
  margin-bottom: 16px;

  .hint {
    display: block;
    margin-top: 4px;
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    padding-left: 12px;
  }
}

.question-settings {
  display: flex;
  flex-wrap: wrap;
  gap: 20px;
  padding-top: 16px;
  border-top: 1px solid var(--color-border);
}

/* Color picker in header */
.color-picker-action {
  .color-indicator {
    display: block;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    border: 2px solid var(--color-border);

    &.no-color {
      background: linear-gradient(135deg, transparent 45%, var(--color-error) 45%, var(--color-error) 55%, transparent 55%);
      background-color: var(--color-background-hover);
    }
  }
}

.color-swatch {
  display: block;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  border: 1px solid rgba(0, 0, 0, 0.1);

  &.no-color-swatch {
    background: linear-gradient(135deg, transparent 45%, var(--color-error) 45%, var(--color-error) 55%, transparent 55%);
    background-color: var(--color-background-hover);
  }
}

.color-selected {
  background: var(--color-primary-element-light);
}

.validation-settings {
  margin-top: 16px;
  padding-top: 16px;
  border-top: 1px solid var(--color-border);

  .validation-fields {
    margin-top: 12px;
    padding-left: 28px;

    .form-field {
      margin-bottom: 12px;
    }

    .hint {
      display: block;
      margin-top: 8px;
      font-size: 12px;
      color: var(--color-text-maxcontrast);
      padding-left: 12px;
    }
  }
}

.condition-indicator {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 16px;
  padding: 8px 12px;
  background: var(--color-background-hover);
  border-radius: var(--border-radius);
  font-size: 14px;
  color: var(--color-text-maxcontrast);
}

.rotated {
  transform: rotate(-90deg);
}

/* Fix icon vertical alignment in NcActionButton and NcButton */
:deep(.action-button__icon),
:deep(.button-vue__icon) {
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.section-editor-header {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 16px;
  border-bottom: 1px solid var(--color-border);

  .drag-handle {
    cursor: grab;
    color: var(--color-text-maxcontrast);

    &:active {
      cursor: grabbing;
    }
  }

  .section-badge {
    background: var(--color-primary-element);
    color: white;
    padding: 2px 10px;
    border-radius: var(--border-radius-pill);
    font-size: 12px;
    font-weight: 600;
  }

  .question-actions {
    margin-left: auto;
  }
}

.section-editor-body {
  padding: 16px;

  .section-title-input {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 8px;
  }

  .condition-section {
    margin-top: 12px;
  }

  .condition-display {
    display: flex;
    align-items: center;
    gap: 4px;

    .condition-label {
      font-size: 13px;
      color: var(--color-text-maxcontrast);
      margin-right: 4px;
    }
  }
}
</style>
