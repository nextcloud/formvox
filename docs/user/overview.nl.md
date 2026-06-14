# FormVox-overzicht

## Wat is FormVox?

FormVox is een file-based formulieren- en polls-applicatie voor Nextcloud. Anders dan traditionele formulieren-apps die data in database-tabellen opslaan, slaat FormVox alles op in `.fvform`-bestanden — wat je formulieren portable, versioneerbaar en encryption-compatibel maakt.

## Kern-features

### File-based opslag

- Elk formulier is één `.fvform`-bestand dat zowel de formulier-definitie als alle antwoorden bevat
- Werkt met Nextcloud's file-versioning
- Compatibel met end-to-end-encryptie
- Geen database-migraties nodig

### Rijke vraagtypen

FormVox ondersteunt 12+ vraagtypen:

- Tekst en multi-line tekst
- Single en multiple choice
- Dropdown-select
- Datum-, tijd- en datetime-pickers
- Numerieke input
- Lineaire schaal en sterren-rating
- Matrix-vragen
- E-mail-validatie

### Geavanceerde features

- **Conditionele logica** — vragen tonen/verbergen op basis van eerdere antwoorden
- **Quiz-modus** — assessments met automatische scoring
- **Answer-piping** — verwijs naar eerdere antwoorden in latere vragen
- **Multi-page formulieren** — lange formulieren organiseren in pagina's

### Deel-opties

- Delen met Nextcloud-gebruikers en -groepen
- Publieke links maken met optioneel:
  - Wachtwoord-bescherming
  - Vervaldatums
  - Gebruikers-/groeps-beperkingen

### Data-export

Exporteer antwoorden naar:

- CSV (spreadsheet-compatibel)
- JSON (voor ontwikkelaars)
- Excel (.xlsx)

## Waarom FormVox?

### vs. Nextcloud Forms

| Feature | FormVox | Nextcloud Forms |
|---------|---------|-----------------|
| Opslag | File-based (.fvform) | Database |
| E2E-encryptie | Compatibel | Niet compatibel |
| Versioning | Native (file-based) | Beperkt |
| Portability | Bestanden kopiëren/verplaatsen | Database-export |
| Vraagtypen | 12+ types | Basic types |
| Conditionele logica | Ja | Beperkt |
| Quiz-modus | Ja | Nee |
| Answer-piping | Ja | Nee |

### Geschikt voor

- **Privacy-gerichte organisaties** — file-based opslag met encryptie-ondersteuning
- **Enquêtes en feedback** — rijke vraagtypen en analytics
- **Quizzes en assessments** — ingebouwde scoring en feedback
- **Event-registraties** — templates en conditionele logica
- **Data-verzameling** — export naar meerdere formaten

## Aan de slag

Klaar om je eerste formulier te maken? Bekijk de [Snelstart-gids](../getting-started.md).
