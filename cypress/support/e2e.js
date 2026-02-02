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
  // FormVox has two different buttons depending on state:
  // - Empty state: "Create form" / "Formulier maken"
  // - With forms: "New form" / "Nieuw formulier"
  // Also the button might be NcButton which renders as <button> with nested content

  cy.get('body').then(($body) => {
    // Try to find any button that creates a new form
    const newFormBtn = $body.find('button').filter((i, el) => {
      const text = el.innerText.toLowerCase()
      return text.includes('new form') ||
             text.includes('nieuw formulier') ||
             text.includes('create form') ||
             text.includes('formulier maken')
    })

    if (newFormBtn.length > 0) {
      cy.wrap(newFormBtn.first()).click({ force: true })
    } else {
      // Fallback: use contains with broader pattern
      cy.contains(/New form|Nieuw formulier|Create form|Formulier maken/i).click({ force: true })
    }
  })

  // Wait for Vue to update the DOM and modal to appear
  // The modal uses v-if so it won't be in DOM until showNewFormModal = true
  cy.wait(2000)

  // The NewFormModal component wraps NcModal
  // Wait for the modal to appear in DOM (v-if renders it)
  cy.get('.modal-mask, [role="dialog"]', { timeout: 15000 })
    .should('exist')

  // Wait for it to be visible (not display:none)
  cy.get('.modal-mask', { timeout: 10000 })
    .should('be.visible')

  // Find the title input inside the modal
  // NcTextField renders an input inside the modal
  cy.get('.modal-container input[type="text"], .new-form-modal input[type="text"]', { timeout: 10000 })
    .first()
    .should('be.visible')
    .clear()
    .type(title)

  // Click the Create button inside the modal
  cy.get('.modal-container, .new-form-modal')
    .contains('button', /^Create$|^Maken$|^Aanmaken$/i)
    .click()

  // Wait for navigation to the form editor
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
