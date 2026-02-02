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
  // Click new form button - look for it within the app content area
  cy.get('#app-content, #content').within(() => {
    cy.contains(/New form|Nieuw formulier/i).click()
  })

  // Wait for modal to appear - NcModal from @nextcloud/vue
  // The modal may take time to render due to Vue reactivity
  cy.wait(2000)

  // NcModal renders with role="dialog" - wait for it to be in the DOM and visible
  // The modal content is inside .modal-container or .new-form-modal
  cy.get('body').then(($body) => {
    // Debug: log what modal elements we find
    const modalMask = $body.find('.modal-mask')
    const modalContainer = $body.find('.modal-container')
    const dialog = $body.find('[role="dialog"]')
    const newFormModal = $body.find('.new-form-modal')

    cy.log(`Found: modal-mask=${modalMask.length}, modal-container=${modalContainer.length}, dialog=${dialog.length}, new-form-modal=${newFormModal.length}`)
  })

  // Try multiple selectors for the modal - NcModal structure varies by version
  // Use filter to find only visible modals
  cy.get('.modal-mask, .modal-container, [role="dialog"], .new-form-modal', { timeout: 15000 })
    .filter(':visible')
    .first()
    .should('exist')

  // Find the title input - it's an NcTextField with label "Form title" or similar
  // The input might be inside the modal or directly in the body (portaled)
  cy.get('input[type="text"]:visible')
    .not('#contactsmenu__menu__search')
    .not('[id*="search"]')
    .not('[placeholder*="Search"]')
    .not('[aria-label*="Search"]')
    .first()
    .clear()
    .type(title)

  // Click Create button
  cy.contains('button:visible', /^Create$|^Maken$|^Aanmaken$|Creating/i).click()

  // Wait for form editor to load
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
