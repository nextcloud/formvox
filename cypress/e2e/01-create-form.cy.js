/**
 * FormVox Test: Create form with different question types
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
    cy.contains(formTitle).should('be.visible')
  })

  it('should add a short text question', () => {
    // Open the created form
    cy.contains(formTitle).click()
    cy.contains('button', /Add question|Vraag toevoegen/i).click()

    // Select short text type
    cy.contains(/Short text|Korte tekst/i).click()

    // Fill in question
    cy.get('[data-cy="question-input"], .question-input, input[placeholder*="Question"]')
      .last()
      .clear()
      .type('What is your name?')

    cy.waitForSave()
  })

  it('should add a multiple choice question', () => {
    cy.contains(formTitle).click()
    cy.contains('button', /Add question|Vraag toevoegen/i).click()

    // Select multiple choice type
    cy.contains(/Multiple choice|Meerkeuze/i).click()

    // Fill in question
    cy.get('[data-cy="question-input"], .question-input, input[placeholder*="Question"]')
      .last()
      .clear()
      .type('What features do you like?')

    // Add options
    cy.get('input[placeholder*="Option"], input[placeholder*="Optie"]').first().clear().type('Easy to use')
    cy.contains('button', /Add option|Optie toevoegen/i).click()
    cy.get('input[placeholder*="Option"], input[placeholder*="Optie"]').last().clear().type('Fast')

    cy.waitForSave()
  })

  it('should add a single choice question', () => {
    cy.contains(formTitle).click()
    cy.contains('button', /Add question|Vraag toevoegen/i).click()

    // Select single choice type
    cy.contains(/Single choice|Enkele keuze/i).click()

    // Fill in question
    cy.get('[data-cy="question-input"], .question-input, input[placeholder*="Question"]')
      .last()
      .clear()
      .type('How satisfied are you?')

    // Add options
    cy.get('input[placeholder*="Option"], input[placeholder*="Optie"]').first().clear().type('Very satisfied')
    cy.contains('button', /Add option|Optie toevoegen/i).click()
    cy.get('input[placeholder*="Option"], input[placeholder*="Optie"]').last().clear().type('Not satisfied')

    cy.waitForSave()
  })

  it('should add a scale/rating question', () => {
    cy.contains(formTitle).click()
    cy.contains('button', /Add question|Vraag toevoegen/i).click()

    // Select scale type
    cy.contains(/Scale|Rating|Schaal|Beoordeling/i).click()

    // Fill in question
    cy.get('[data-cy="question-input"], .question-input, input[placeholder*="Question"]')
      .last()
      .clear()
      .type('Rate this app from 1-10')

    cy.waitForSave()
  })

  it('should add a long text question', () => {
    cy.contains(formTitle).click()
    cy.contains('button', /Add question|Vraag toevoegen/i).click()

    // Select long text type
    cy.contains(/Long text|Lange tekst/i).click()

    // Fill in question
    cy.get('[data-cy="question-input"], .question-input, input[placeholder*="Question"]')
      .last()
      .clear()
      .type('Any additional feedback?')

    cy.waitForSave()
  })

  it('should add a date question', () => {
    cy.contains(formTitle).click()
    cy.contains('button', /Add question|Vraag toevoegen/i).click()

    // Select date type
    cy.contains(/^Date$|^Datum$/i).click()

    // Fill in question
    cy.get('[data-cy="question-input"], .question-input, input[placeholder*="Question"]')
      .last()
      .clear()
      .type('When did you start using FormVox?')

    cy.waitForSave()
  })

  it('should mark a question as required', () => {
    cy.contains(formTitle).click()

    // Find first question and mark as required
    cy.get('.question-card, [data-cy="question"]').first().within(() => {
      cy.contains(/Required|Verplicht/i).click({ force: true })
    })

    cy.waitForSave()
  })
})
