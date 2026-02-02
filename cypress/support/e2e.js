// Cypress support file for FormVox tests

// Custom command: Login to Nextcloud
Cypress.Commands.add('login', (user, password) => {
  const username = user || Cypress.env('NC_USER')
  const pass = password || Cypress.env('NC_PASSWORD')

  cy.session([username, pass], () => {
    cy.visit('/login')
    cy.wait(3000)

    cy.get('body').then(($body) => {
      if ($body.find('#user').length) {
        cy.get('#user').clear().type(username)
        cy.get('#password').clear().type(pass)
        cy.get('form').submit()
      } else if ($body.find('input[name="user"]').length) {
        cy.get('input[name="user"]').clear().type(username)
        cy.get('input[name="password"]').clear().type(pass)
        cy.get('form').submit()
      }
    })

    cy.url({ timeout: 30000 }).should('not.include', '/login')
  }, {
    validate() {
      cy.visit('/apps/dashboard')
      cy.url().should('not.include', '/login')
    }
  })
})

// Custom command: Navigate to FormVox app
Cypress.Commands.add('openFormVox', () => {
  cy.visit('/apps/formvox')
  cy.get('#app-content, #content, .app-content', { timeout: 30000 }).should('exist')
  cy.wait(2000) // Wait for Vue app to fully load
})

// Custom command: Create a new form
Cypress.Commands.add('createForm', (title) => {
  // Find and click the "New form" button with force to handle any overlays
  cy.contains('button', /New form|Nieuw formulier/i, { timeout: 10000 })
    .should('be.visible')
    .click({ force: true })

  // Wait for Vue to update the DOM
  cy.wait(3000)

  // Debug: Check what's in the DOM after clicking
  cy.get('body').then(($body) => {
    const html = $body.html()
    const hasModalMask = html.includes('modal-mask')
    const hasNewFormModal = html.includes('new-form-modal')
    const hasDialog = $body.find('[role="dialog"]').length > 0

    cy.log(`DOM check: modal-mask=${hasModalMask}, new-form-modal=${hasNewFormModal}, dialog=${hasDialog}`)

    // Log the display style of modal-mask if it exists
    const modalMask = $body.find('.modal-mask')
    if (modalMask.length) {
      cy.log(`modal-mask display: ${modalMask.css('display')}`)
    }
  })

  // NcModal in @nextcloud/vue uses .modal-mask with display toggle
  // Wait for the modal to have display != none
  cy.get('.modal-mask', { timeout: 15000 })
    .should('exist')
    .and('not.have.css', 'display', 'none')

  // Now find the input inside the modal
  cy.get('.modal-mask .modal-container, .new-form-modal', { timeout: 10000 })
    .should('be.visible')
    .find('input[type="text"]')
    .first()
    .clear()
    .type(title)

  // Click Create button inside the modal
  cy.get('.modal-mask .modal-container, .new-form-modal')
    .contains('button', /^Create$|^Maken$|^Aanmaken$/i)
    .click()

  // Wait for navigation to editor
  cy.url({ timeout: 20000 }).should('include', '/edit')
  cy.wait(2000)
})

// Custom command: Add a question to the form
Cypress.Commands.add('addQuestion', (type, questionText) => {
  cy.get('#app-content, #content').within(() => {
    cy.contains(/Add question|Vraag toevoegen/i).click()
  })
  cy.wait(500)
  cy.contains(new RegExp(type, 'i')).click()
  cy.wait(500)

  // Find the question input within the last question card
  cy.get('.question-card, .question-item, [class*="question"]').last().within(() => {
    cy.get('input:visible, textarea:visible').first().clear().type(questionText)
  })
})

// Custom command: Wait for autosave
Cypress.Commands.add('waitForSave', () => {
  cy.wait(2000)
})

// Prevent uncaught exceptions from failing tests
Cypress.on('uncaught:exception', (err, runnable) => {
  return false
})
