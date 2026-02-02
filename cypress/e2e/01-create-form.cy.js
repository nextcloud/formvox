/**
 * FormVox Test: Create form with different question types
 *
 * FormVox question types (values for select):
 * - text: Short text
 * - textarea: Long text
 * - number: Number
 * - choice: Single choice
 * - multiple: Multiple choice
 * - dropdown: Dropdown
 * - date: Date
 * - datetime: Date & Time
 * - time: Time
 * - scale: Scale
 * - rating: Stars
 * - matrix: Matrix
 * - file: File upload
 */

describe('FormVox - Create Form', () => {
  const formTitle = `Test Form ${Date.now()}`

  before(() => {
    cy.login()
  })

  beforeEach(() => {
    cy.login()
    cy.openFormVox()
  })

  it('should create a new form', () => {
    cy.createForm(formTitle)
    // After createForm, we should be on the editor page
    cy.url().should('include', '/edit')
    // Wait for editor to fully load
    cy.get('.editor-container, .form-editor, #app-content').should('exist')
  })

  it('should add a short text question', () => {
    // Navigate to form list first, then click our form
    cy.contains(formTitle).click()
    cy.url().should('include', '/edit')

    // Click Add question button to add a new question
    cy.contains('button', /Add question|Vraag toevoegen/i).click()
    cy.wait(1000)

    // The new question is added with type 'text' by default
    // Find the type select and verify it exists
    cy.get('select.type-select, .question-editor select').should('exist')

    cy.waitForSave()
  })

  it('should add a multiple choice question', () => {
    cy.contains(formTitle).click()
    cy.url().should('include', '/edit')

    // Add new question
    cy.contains('button', /Add question|Vraag toevoegen/i).click()
    cy.wait(1000)

    // Change the type to multiple choice using the select
    cy.get('select.type-select, .question-editor select').last().select('multiple')

    cy.waitForSave()
  })

  it('should add a single choice question', () => {
    cy.contains(formTitle).click()
    cy.url().should('include', '/edit')

    cy.contains('button', /Add question|Vraag toevoegen/i).click()
    cy.wait(1000)

    // Change type to single choice (value: 'choice')
    cy.get('select.type-select, .question-editor select').last().select('choice')

    cy.waitForSave()
  })

  it('should add a scale/rating question', () => {
    cy.contains(formTitle).click()
    cy.url().should('include', '/edit')

    cy.contains('button', /Add question|Vraag toevoegen/i).click()
    cy.wait(1000)

    // Change type to scale
    cy.get('select.type-select, .question-editor select').last().select('scale')

    cy.waitForSave()
  })

  it('should add a long text question', () => {
    cy.contains(formTitle).click()
    cy.url().should('include', '/edit')

    cy.contains('button', /Add question|Vraag toevoegen/i).click()
    cy.wait(1000)

    // Change type to textarea (long text)
    cy.get('select.type-select, .question-editor select').last().select('textarea')

    cy.waitForSave()
  })

  it('should add a date question', () => {
    cy.contains(formTitle).click()
    cy.url().should('include', '/edit')

    cy.contains('button', /Add question|Vraag toevoegen/i).click()
    cy.wait(1000)

    // Change type to date
    cy.get('select.type-select, .question-editor select').last().select('date')

    cy.waitForSave()
  })

  it('should mark a question as required', () => {
    cy.contains(formTitle).click()
    cy.url().should('include', '/edit')

    // Find the required checkbox/toggle in the first question
    cy.get('.question-editor').first().within(() => {
      // Look for checkbox or switch for required
      cy.get('input[type="checkbox"]').first().check({ force: true })
    })

    cy.waitForSave()
  })
})
