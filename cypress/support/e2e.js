// Cypress support file for FormVox tests

// Custom command: Login to Nextcloud
Cypress.Commands.add('login', (user, password) => {
  const username = user || Cypress.env('NC_USER')
  const pass = password || Cypress.env('NC_PASSWORD')

  cy.log(`Attempting login with user: ${username}`)

  cy.session([username, pass], () => {
    cy.visit('/login')
    cy.wait(3000) // Wait for JS to load

    // Debug: log what we see on the page
    cy.get('body').then(($body) => {
      cy.log('Page HTML preview: ' + $body.html().substring(0, 500))
      cy.log('Found #user: ' + $body.find('#user').length)
      cy.log('Found input[name=user]: ' + $body.find('input[name="user"]').length)
      cy.log('Found fieldset: ' + $body.find('fieldset').length)
    })

    // Take screenshot before login attempt
    cy.screenshot('login-page-before')

    // Nextcloud login - try the most common selector first
    cy.get('body').then(($body) => {
      if ($body.find('#user').length) {
        cy.log('Using #user selector')
        cy.get('#user').clear().type(username)
        cy.get('#password').clear().type(pass)
        cy.get('form').submit()
      } else if ($body.find('input[name="user"]').length) {
        cy.log('Using input[name=user] selector')
        cy.get('input[name="user"]').clear().type(username)
        cy.get('input[name="password"]').clear().type(pass)
        cy.get('form').submit()
      } else {
        cy.log('Using fallback selector')
        cy.get('input').first().clear().type(username)
        cy.get('input[type="password"]').clear().type(pass)
        cy.get('form').submit()
      }
    })

    // Take screenshot after login attempt
    cy.wait(3000)
    cy.screenshot('login-page-after')

    // Check for error messages
    cy.get('body').then(($body) => {
      const bodyText = $body.text()
      if (bodyText.includes('Wrong') || bodyText.includes('Invalid') || bodyText.includes('incorrect')) {
        cy.log('LOGIN ERROR DETECTED: ' + bodyText.substring(0, 200))
      }
    })

    // Wait for redirect after login
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
  cy.get('#app-content, #content, .app-content, [data-app="formvox"]', { timeout: 30000 }).should('exist')
})

// Custom command: Create a new form
Cypress.Commands.add('createForm', (title) => {
  cy.get('body').then(($body) => {
    const newFormBtn = $body.find('button:contains("New form"), button:contains("Nieuw formulier"), [data-cy="new-form"]')
    if (newFormBtn.length) {
      cy.wrap(newFormBtn).first().click()
    } else {
      cy.contains(/New form|Nieuw formulier/i).click()
    }
  })

  cy.get('input[type="text"]', { timeout: 10000 }).first().clear().type(title)
  cy.contains('button', /^Create$|^Maken$|^Aanmaken$/i).click()
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
  cy.wait(2000)
})

// Prevent uncaught exceptions from failing tests
Cypress.on('uncaught:exception', (err, runnable) => {
  return false
})
// trigger Mon Feb  2 12:16:27 CET 2026
