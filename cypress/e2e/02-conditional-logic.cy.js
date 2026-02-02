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

    // Add first question (trigger question)
    cy.contains('button', /Add question|Vraag toevoegen/i).click()
    cy.contains(/Single choice|Enkele keuze/i).click()
    cy.get('[data-cy="question-input"], .question-input, input[placeholder*="Question"]')
      .last()
      .clear()
      .type('Do you want more details?')

    // Add options
    cy.get('input[placeholder*="Option"], input[placeholder*="Optie"]').first().clear().type('Yes')
    cy.contains('button', /Add option|Optie toevoegen/i).click()
    cy.get('input[placeholder*="Option"], input[placeholder*="Optie"]').last().clear().type('No')

    cy.waitForSave()

    // Add second question (conditional question)
    cy.contains('button', /Add question|Vraag toevoegen/i).click()
    cy.contains(/Long text|Lange tekst/i).click()
    cy.get('[data-cy="question-input"], .question-input, input[placeholder*="Question"]')
      .last()
      .clear()
      .type('Please provide more details')

    cy.waitForSave()
  })

  it('should add a condition to the second question', () => {
    cy.contains(formTitle).click()

    // Find the second question and add condition
    cy.get('.question-card, [data-cy="question"]').last().within(() => {
      cy.contains(/Conditions|Voorwaarden/i).click({ force: true })
    })

    // Configure condition: Show when first question equals "Yes"
    cy.get('[data-cy="condition-modal"], .condition-modal, .modal').within(() => {
      cy.contains('button', /Add condition|Voorwaarde toevoegen/i).click()

      // Select trigger question
      cy.get('select, [data-cy="question-select"]').first().select(1) // Select first question

      // Select operator (equals)
      cy.contains(/equals|is gelijk aan/i).click({ force: true })

      // Select value "Yes"
      cy.get('input[placeholder*="value"], input[placeholder*="waarde"], select')
        .last()
        .type('Yes{enter}')

      // Save condition
      cy.contains('button', /Save|Opslaan|Done|Klaar/i).click()
    })

    cy.waitForSave()
  })

  it('should show/hide question based on condition in preview', () => {
    cy.contains(formTitle).click()

    // Go to preview
    cy.contains('button', /Preview|Voorbeeld/i).click()

    // Initially the conditional question should be hidden
    cy.contains('Please provide more details').should('not.exist')

    // Select "Yes" in the first question
    cy.contains('label', 'Yes').click()

    // Now the conditional question should appear
    cy.contains('Please provide more details').should('be.visible')

    // Select "No" in the first question
    cy.contains('label', 'No').click()

    // The conditional question should be hidden again
    cy.contains('Please provide more details').should('not.exist')
  })
})
