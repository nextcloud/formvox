/**
 * FormVox Test: Translations
 * Test that UI correctly translates when switching languages
 */

describe('FormVox - Translations', () => {
  before(() => {
    cy.login()
  })

  beforeEach(() => {
    cy.login()
  })

  it('should display Dutch translations when language is set to Dutch', () => {
    // Set language to Dutch via Nextcloud settings
    cy.visit('/settings/user')
    cy.get('select[name="language"], #language').select('nl')
    cy.wait(1000)

    // Navigate to FormVox
    cy.openFormVox()

    // Check Dutch translations
    cy.contains(/Nieuw formulier|Nieuwe formulier/i).should('be.visible')
  })

  it('should display German translations when language is set to German', () => {
    // Set language to German
    cy.visit('/settings/user')
    cy.get('select[name="language"], #language').select('de')
    cy.wait(1000)

    // Navigate to FormVox
    cy.openFormVox()

    // Check German translations
    cy.contains(/Neues Formular|Formular erstellen/i).should('be.visible')
  })

  it('should display French translations when language is set to French', () => {
    // Set language to French
    cy.visit('/settings/user')
    cy.get('select[name="language"], #language').select('fr')
    cy.wait(1000)

    // Navigate to FormVox
    cy.openFormVox()

    // Check French translations
    cy.contains(/Nouveau formulaire|Créer un formulaire/i).should('be.visible')
  })

  it('should display English translations when language is set to English', () => {
    // Set language back to English
    cy.visit('/settings/user')
    cy.get('select[name="language"], #language').select('en')
    cy.wait(1000)

    // Navigate to FormVox
    cy.openFormVox()

    // Check English translations
    cy.contains(/New form|Create form/i).should('be.visible')
  })

  it('should translate question type names', () => {
    // Test in Dutch
    cy.visit('/settings/user')
    cy.get('select[name="language"], #language').select('nl')
    cy.wait(1000)

    cy.openFormVox()

    // Create a new form to see question types
    cy.contains(/Nieuw formulier/i).click()
    cy.get('input[placeholder*="titel"]').type('Translation Test')
    cy.contains('button', /Maken|Aanmaken/i).click()

    // Check question type translations
    cy.contains('button', /Vraag toevoegen/i).click()

    cy.contains(/Korte tekst/i).should('be.visible')
    cy.contains(/Lange tekst/i).should('be.visible')
    cy.contains(/Enkele keuze/i).should('be.visible')
    cy.contains(/Meerkeuze/i).should('be.visible')
    cy.contains(/Datum/i).should('be.visible')

    // Reset to English
    cy.visit('/settings/user')
    cy.get('select[name="language"], #language').select('en')
  })
})
