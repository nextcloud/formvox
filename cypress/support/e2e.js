// Cypress support file for FormVox tests

// Custom command: Login to Nextcloud
Cypress.Commands.add('login', (user, password) => {
  const username = user || Cypress.env('NC_USER')
  const pass = password || Cypress.env('NC_PASSWORD')

  cy.session([username, pass], () => {
    cy.visit('/login')
    cy.wait(2000) // Wait for JS to load

    // Try different Nextcloud login form selectors
    cy.get('body').then(($body) => {
      // Nextcloud 28+ uses different selectors
      if ($body.find('#user').length) {
        cy.get('#user').type(username)
        cy.get('#password').type(pass)
        cy.get('#submit-form, button[type="submit"], .login-form button').first().click()
      } else if ($body.find('input[name="user"]').length) {
        cy.get('input[name="user"]').type(username)
        cy.get('input[name="password"]').type(pass)
        cy.get('button[type="submit"], input[type="submit"]').first().click()
      } else {
        // Fallback: find any login form
        cy.get('input[type="text"], input[type="email"]').first().type(username)
        cy.get('input[type="password"]').first().type(pass)
        cy.get('button[type="submit"], input[type="submit"]').first().click()
      }
    })

    // Wait for redirect after login
    cy.url({ timeout: 30000 }).should('not.include', '/login')
  }, {
    validate() {
      // Validate session by checking we can access dashboard
      cy.visit('/apps/dashboard')
      cy.url().should('not.include', '/login')
    }
  })
})

// Custom command: Navigate to FormVox app
Cypress.Commands.add('openFormVox', () => {
  cy.visit('/apps/formvox')
  // Wait for app to load - try multiple selectors
  cy.get('#app-content, #content, .app-content, [data-app="formvox"]', { timeout: 30000 }).should('exist')
})

// Custom command: Create a new form
Cypress.Commands.add('createForm', (title) => {
  // Click new form button
  cy.get('body').then(($body) => {
    const newFormBtn = $body.find('button:contains("New form"), button:contains("Nieuw formulier"), [data-cy="new-form"]')
    if (newFormBtn.length) {
      cy.wrap(newFormBtn).first().click()
    } else {
      cy.contains(/New form|Nieuw formulier/i).click()
    }
  })

  // Wait for modal and fill title
  cy.get('input[type="text"]', { timeout: 10000 }).first().clear().type(title)

  // Click create button
  cy.contains('button', /^Create$|^Maken$|^Aanmaken$/i).click()

  // Wait for form editor to load
  cy.url({ timeout: 15000 }).should('include', '/edit')
})

// Custom command: Add a question to the form
Cypress.Commands.add('addQuestion', (type, questionText) => {
  cy.contains(/Add question|Vraag toevoegen/i).click()
  cy.contains(new RegExp(type, 'i')).click()
  cy.get('input, textarea').last().clear().type(questionText)
})

// Custom command: Wait for autosave
Cypress.Commands.add('waitForSave', () => {
  // Wait a bit for autosave to trigger
  cy.wait(2000)
})

// Prevent uncaught exceptions from failing tests
Cypress.on('uncaught:exception', (err, runnable) => {
  return false
})
