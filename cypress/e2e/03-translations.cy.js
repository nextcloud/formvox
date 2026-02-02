/**
 * FormVox Test: Translations
 * Test that UI correctly shows translations
 */

describe('FormVox - Translations', () => {
  before(() => {
    cy.login()
  })

  beforeEach(() => {
    cy.login()
  })

  it('should display UI elements in FormVox', () => {
    cy.openFormVox()

    // Check that key UI elements are present (in any language)
    cy.get('button').should('have.length.at.least', 1)

    // Check for "New form" button in either English or Dutch
    cy.contains(/New form|Nieuw formulier|Neues Formular|Nouveau formulaire/i).should('be.visible')
  })

  it('should have translatable strings in the app', () => {
    cy.openFormVox()

    // Try to create a form to see more UI elements
    cy.contains(/New form|Nieuw formulier/i).click()

    // Modal should appear with form creation options
    cy.get('.modal, [role="dialog"], .dialog').should('be.visible')

    // Check for translated labels
    cy.contains(/Form title|Formuliertitel|Titre du formulaire/i).should('exist')

    // Close modal
    cy.get('body').type('{esc}')
  })

  it('should show question types in the editor', () => {
    const formTitle = `Translation Test ${Date.now()}`

    cy.openFormVox()
    cy.createForm(formTitle)

    // Open question type menu
    cy.contains(/Add question|Vraag toevoegen/i).click()

    // Check for question types (in any supported language)
    cy.contains(/Short text|Korte tekst|Kurzer Text|Texte court/i).should('be.visible')
    cy.contains(/Multiple choice|Meerkeuze|Mehrfachauswahl|Choix multiple/i).should('be.visible')

    // Close the dropdown
    cy.get('body').type('{esc}')
  })

  it('should show form settings labels', () => {
    cy.openFormVox()

    // Click on an existing form or create one
    cy.get('body').then(($body) => {
      if ($body.find('.form-card, .form-list-item, [data-cy="form-item"]').length) {
        cy.get('.form-card, .form-list-item, [data-cy="form-item"]').first().click()
      } else {
        cy.createForm(`Settings Test ${Date.now()}`)
      }
    })

    // Look for settings button
    cy.contains(/Settings|Instellingen|Einstellungen|Paramètres/i).should('exist')
  })
})
