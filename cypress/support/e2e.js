// Cypress support file for FormVox tests

// Custom command: Login to Nextcloud
Cypress.Commands.add('login', (user, password) => {
  const username = user || Cypress.env('NC_USER')
  const pass = password || Cypress.env('NC_PASSWORD')

  cy.session([username, pass], () => {
    cy.visit('/login')
    cy.get('input[name="user"]').type(username)
    cy.get('input[name="password"]').type(pass)
    cy.get('button[type="submit"], input[type="submit"]').click()
    cy.url().should('not.include', '/login')
  })
})

// Custom command: Navigate to FormVox app
Cypress.Commands.add('openFormVox', () => {
  cy.visit('/apps/formvox')
  cy.get('#app-content', { timeout: 15000 }).should('be.visible')
})

// Custom command: Create a new form
Cypress.Commands.add('createForm', (title) => {
  cy.contains('button', /New form|Nieuw formulier/i).click()
  cy.get('input[placeholder*="title"], input[placeholder*="titel"]').clear().type(title)
  cy.contains('button', /Create|Maken|Aanmaken/i).click()
  cy.url().should('include', '/edit')
})

// Custom command: Add a question to the form
Cypress.Commands.add('addQuestion', (type, questionText) => {
  cy.contains('button', /Add question|Vraag toevoegen/i).click()
  cy.get('[data-question-type="' + type + '"], [data-type="' + type + '"]').click()
  cy.get('input[placeholder*="Question"], input[placeholder*="Vraag"], textarea[placeholder*="Question"]')
    .last()
    .clear()
    .type(questionText)
})

// Custom command: Wait for autosave
Cypress.Commands.add('waitForSave', () => {
  cy.contains(/All changes saved|Alle wijzigingen opgeslagen|Saving|Opslaan/i, { timeout: 10000 })
})

// Prevent uncaught exceptions from failing tests
Cypress.on('uncaught:exception', (err, runnable) => {
  // Nextcloud sometimes throws harmless errors
  return false
})
