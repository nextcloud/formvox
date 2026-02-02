/**
 * FormVox Test: Responses
 * Test filling in forms and viewing results
 */

describe('FormVox - Responses', () => {
  const formTitle = `Response Test ${Date.now()}`

  before(() => {
    cy.login()
  })

  beforeEach(() => {
    cy.login()
    cy.openFormVox()
  })

  it('should create a test form with questions', () => {
    cy.createForm(formTitle)
    cy.url().should('include', '/edit')

    // Add text question (default type)
    cy.contains('button', /Add question|Vraag toevoegen/i).click()
    cy.wait(1000)

    // Fill in question text in the question editor
    cy.get('.question-editor').last().within(() => {
      cy.get('input[type="text"], textarea').first().clear().type('What is your name?')
    })
    cy.waitForSave()

    // Add single choice question
    cy.contains('button', /Add question|Vraag toevoegen/i).click()
    cy.wait(1000)
    cy.get('select.type-select, .question-editor select').last().select('choice')

    cy.get('.question-editor').last().within(() => {
      cy.get('input[type="text"], textarea').first().clear().type('How satisfied are you?')
    })
    cy.waitForSave()

    // Add rating question
    cy.contains('button', /Add question|Vraag toevoegen/i).click()
    cy.wait(1000)
    cy.get('select.type-select, .question-editor select').last().select('rating')

    cy.get('.question-editor').last().within(() => {
      cy.get('input[type="text"], textarea').first().clear().type('Rate our service')
    })
    cy.waitForSave()
  })

  it('should open share dialog', () => {
    cy.contains(formTitle).click()
    cy.url().should('include', '/edit')

    // Click share button
    cy.contains('button', /Share|Delen/i).click()
    cy.wait(1000)

    // Share dialog should appear
    cy.get('.share-dialog, [class*="share"], .modal-mask').should('exist')

    // Close by pressing escape
    cy.get('body').type('{esc}')
  })

  it('should navigate to results view', () => {
    cy.contains(formTitle).click()
    cy.url().should('include', '/edit')

    // Click Results button
    cy.contains('button', /Results|Resultaten/i).click()

    // Should navigate to results page
    cy.url().should('include', '/results')
  })

  it('should show results page elements', () => {
    cy.contains(formTitle).click()
    cy.url().should('include', '/edit')

    cy.contains('button', /Results|Resultaten/i).click()
    cy.url().should('include', '/results')

    // Results page should have export option
    cy.contains(/Export|Exporteren/i).should('exist')
  })

  it('should have export options available', () => {
    cy.contains(formTitle).click()
    cy.contains('button', /Results|Resultaten/i).click()

    // Click export button
    cy.contains('button', /Export/i).click()

    // Export options should appear (CSV, JSON, etc)
    cy.contains(/CSV|JSON|Excel/i).should('exist')
  })

  after(() => {
    // Clean up: try to delete the test form
    cy.login()
    cy.openFormVox()

    // Only try to delete if the form exists
    cy.get('body').then(($body) => {
      if ($body.text().includes(formTitle)) {
        cy.contains(formTitle).rightclick()
        cy.contains(/Delete|Verwijderen/i).click({ force: true })
      }
    })
  })
})
