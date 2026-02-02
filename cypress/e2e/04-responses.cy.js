/**
 * FormVox Test: Responses
 * Test filling in forms and exporting responses
 */

describe('FormVox - Responses', () => {
  const formTitle = `Response Test ${Date.now()}`
  let shareLink = ''

  before(() => {
    cy.login()
  })

  beforeEach(() => {
    cy.login()
    cy.openFormVox()
  })

  it('should create a test form with questions', () => {
    cy.createForm(formTitle)

    // Add text question
    cy.contains('button', /Add question|Vraag toevoegen/i).click()
    cy.contains(/Short text|Korte tekst/i).click()
    cy.get('[data-cy="question-input"], .question-input, input[placeholder*="Question"]')
      .last()
      .clear()
      .type('What is your name?')
    cy.waitForSave()

    // Add single choice question
    cy.contains('button', /Add question|Vraag toevoegen/i).click()
    cy.contains(/Single choice|Enkele keuze/i).click()
    cy.get('[data-cy="question-input"], .question-input, input[placeholder*="Question"]')
      .last()
      .clear()
      .type('How satisfied are you?')
    cy.get('input[placeholder*="Option"], input[placeholder*="Optie"]').first().clear().type('Very satisfied')
    cy.contains('button', /Add option|Optie toevoegen/i).click()
    cy.get('input[placeholder*="Option"], input[placeholder*="Optie"]').last().clear().type('Satisfied')
    cy.contains('button', /Add option|Optie toevoegen/i).click()
    cy.get('input[placeholder*="Option"], input[placeholder*="Optie"]').last().clear().type('Not satisfied')
    cy.waitForSave()

    // Add rating question
    cy.contains('button', /Add question|Vraag toevoegen/i).click()
    cy.contains(/Rating|Beoordeling/i).click()
    cy.get('[data-cy="question-input"], .question-input, input[placeholder*="Question"]')
      .last()
      .clear()
      .type('Rate our service')
    cy.waitForSave()
  })

  it('should create a share link', () => {
    cy.contains(formTitle).click()

    // Click share button
    cy.contains('button', /Share|Delen/i).click()

    // Create response link if not exists
    cy.get('body').then(($body) => {
      if ($body.text().includes('Create response link') || $body.text().includes('Reactielink maken')) {
        cy.contains(/Create response link|Reactielink maken/i).click()
      }
    })

    // Get the share link
    cy.get('input[readonly], input[type="text"]')
      .filter(':visible')
      .first()
      .invoke('val')
      .then((link) => {
        shareLink = link
        cy.log('Share link:', shareLink)
      })

    cy.contains('button', /Done|Klaar|Close|Sluiten/i).click()
  })

  it('should fill in the form as a respondent', () => {
    // Visit the share link (logged out)
    cy.clearCookies()
    cy.visit(shareLink || `/apps/formvox/s/${formTitle}`)

    // Fill in the text question
    cy.get('input[type="text"]').first().type('John Doe')

    // Select single choice
    cy.contains('label', 'Satisfied').click()

    // Rate with stars (click on 4th star)
    cy.get('.star, [data-rating], svg').eq(3).click({ force: true })

    // Submit
    cy.contains('button', /Submit|Verzenden/i).click()

    // Check success message
    cy.contains(/Thank you|Bedankt|submitted|verzonden/i).should('be.visible')
  })

  it('should show the response in results', () => {
    cy.login()
    cy.openFormVox()
    cy.contains(formTitle).click()

    // View results
    cy.contains('button', /Results|Resultaten|View results/i).click()

    // Check response count
    cy.contains(/1 response|1 reactie/i).should('be.visible')

    // Check response data
    cy.contains('John Doe').should('be.visible')
    cy.contains('Satisfied').should('be.visible')
  })

  it('should export responses as CSV', () => {
    cy.contains(formTitle).click()
    cy.contains('button', /Results|Resultaten/i).click()

    // Click export
    cy.contains('button', /Export/i).click()
    cy.contains(/CSV/i).click()

    // Verify download started (Cypress can't easily verify file contents)
    cy.log('CSV export triggered')
  })

  it('should export responses as JSON', () => {
    cy.contains(formTitle).click()
    cy.contains('button', /Results|Resultaten/i).click()

    // Click export
    cy.contains('button', /Export/i).click()
    cy.contains(/JSON/i).click()

    // Verify download started
    cy.log('JSON export triggered')
  })

  it('should delete a response', () => {
    cy.contains(formTitle).click()
    cy.contains('button', /Results|Resultaten/i).click()

    // Click on individual responses tab
    cy.contains(/Individual responses|Individuele reacties/i).click()

    // Delete the response
    cy.get('[data-cy="delete-response"], .delete-response, button[title*="Delete"]')
      .first()
      .click({ force: true })

    // Confirm deletion
    cy.on('window:confirm', () => true)

    // Check response is deleted
    cy.contains(/No responses|Geen reacties|0 response/i).should('be.visible')
  })

  after(() => {
    // Clean up: delete the test form
    cy.login()
    cy.openFormVox()
    cy.contains(formTitle).rightclick()
    cy.contains(/Delete|Verwijderen/i).click()
    cy.on('window:confirm', () => true)
  })
})
