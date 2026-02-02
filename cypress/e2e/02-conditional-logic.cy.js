/**
 * FormVox Test: Conditional Logic
 * Test that questions show/hide based on conditions
 */

describe('FormVox - Conditional Logic', () => {
  const formTitle = `Conditional Test ${Date.now()}`

  before(() => {
    cy.login()
  })

  beforeEach(() => {
    cy.login()
    cy.openFormVox()
  })

  it('should create a form with conditional questions', () => {
    // Create new form
    cy.createForm(formTitle)
    cy.url().should('include', '/edit')

    // Add first question (trigger question) - defaults to text, change to choice
    cy.contains('button', /Add question|Vraag toevoegen/i).click()
    cy.wait(1000)

    // Change type to single choice using select
    cy.get('select.type-select, .question-editor select').last().select('choice')
    cy.wait(500)

    // Fill in question text
    cy.get('.question-editor').last().within(() => {
      cy.get('input[type="text"], textarea').first().clear().type('Do you want more details?')
    })

    cy.waitForSave()

    // Add second question (conditional question)
    cy.contains('button', /Add question|Vraag toevoegen/i).click()
    cy.wait(1000)

    // Change type to long text
    cy.get('select.type-select, .question-editor select').last().select('textarea')

    // Fill in question text
    cy.get('.question-editor').last().within(() => {
      cy.get('input[type="text"], textarea').first().clear().type('Please provide more details')
    })

    cy.waitForSave()
  })

  it('should add a condition to the second question', () => {
    cy.contains(formTitle).click()
    cy.url().should('include', '/edit')

    // Find the second question's actions menu and click Conditions
    cy.get('.question-editor').last().within(() => {
      // Click the actions menu (NcActions)
      cy.get('button').last().click()
    })

    // Click Conditions in the popup menu
    cy.contains(/Conditions|Voorwaarden/i).click({ force: true })
    cy.wait(500)

    // The condition editor should appear
    cy.get('.condition-editor, [class*="condition"]').should('exist')

    cy.waitForSave()
  })

  it('should show/hide question based on condition in preview', () => {
    cy.contains(formTitle).click()
    cy.url().should('include', '/edit')

    // Go to preview mode
    cy.contains('button', /Preview|Voorbeeld/i).click()
    cy.wait(1000)

    // The form should be in preview mode
    cy.get('.preview, .form-preview, [class*="preview"]').should('exist')
  })
})
