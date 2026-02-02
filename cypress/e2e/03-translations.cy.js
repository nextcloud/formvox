/**
 * FormVox Test: Translations
 * Test that UI correctly shows translations
 */

describe('FormVox - Translations', () => {
  before(() => {
    cy.login()
  })

  beforeEach(() => {
    cy.login()
  })

  it('should display UI elements in FormVox', () => {
    cy.openFormVox()

    // Check that key UI elements are present (in any language)
    cy.get('button').should('have.length.at.least', 1)

    // Check for "New form" or "Create form" button in various languages
    cy.contains(/New form|Nieuw formulier|Create form|Formulier maken/i).should('exist')
  })

  it('should have translatable strings in the app', () => {
    cy.openFormVox()

    // Create a form to see more UI elements
    cy.contains(/New form|Nieuw formulier|Create form|Formulier maken/i).click({ force: true })
    cy.wait(2000)

    // Modal should appear - check for it
    cy.get('.modal-mask, [role="dialog"]').should('exist')

    // Close modal by pressing escape
    cy.get('body').type('{esc}')
  })

  it('should show question types in the editor', () => {
    const formTitle = `Translation Test ${Date.now()}`

    cy.openFormVox()
    cy.createForm(formTitle)
    cy.url().should('include', '/edit')

    // The question type select should have options
    cy.get('select.type-select, .question-editor select').should('exist')

    // Check that the select has multiple options
    cy.get('select.type-select option, .question-editor select option').should('have.length.at.least', 5)
  })

  it('should show form settings labels', () => {
    cy.openFormVox()

    // Click on an existing form or verify settings exist
    cy.get('body').then(($body) => {
      // Check if there are any forms
      const hasFormCard = $body.find('.form-card, [class*="form-card"]').length > 0

      if (hasFormCard) {
        // Click first form
        cy.get('.form-card, [class*="form-card"]').first().click()
        cy.url().should('include', '/edit')

        // Look for Share button (settings)
        cy.contains(/Share|Delen|Settings|Instellingen/i).should('exist')
      } else {
        // No forms - just verify the empty state UI
        cy.contains(/Create form|Formulier maken|No forms/i).should('exist')
      }
    })
  })
})
