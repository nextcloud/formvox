# FormVox vs Nextcloud Forms

Dit document vergelijkt FormVox met Nextcloud Forms om je te helpen de juiste oplossing te kiezen.

## Overzicht

| Aspect | FormVox | Nextcloud Forms |
|--------|---------|-----------------|
| **Opslag** | File-based (.fvform) | Database |
| **Architectuur** | Enkel bestand per formulier | Database-tabellen |
| **Eerste release** | 2026 | 2020 |
| **Status** | Actieve ontwikkeling | Mature, stabiel |

## Feature-vergelijking

### Vraagtypen

| Vraagtype | FormVox | Nextcloud Forms |
|-----------|---------|-----------------|
| Korte tekst | Ja | Ja |
| Lange tekst | Ja | Ja |
| E-mail | Ja | Nee |
| Single choice | Ja | Ja |
| Multiple choice | Ja | Ja |
| Dropdown | Ja | Ja |
| Datum | Ja | Ja |
| Tijd | Ja | Nee |
| DateTime | Ja | Nee |
| Nummer | Ja | Nee |
| Lineaire schaal | Ja | Nee |
| Sterren-rating | Ja | Nee |
| Matrix | Ja | Nee |

**FormVox-voordeel:** meer vraagtypen, vooral voor enquêtes en ratings.

### Geavanceerde features

| Feature | FormVox | Nextcloud Forms |
|---------|---------|-----------------|
| Conditionele logica | Ja (AND/OR) | Beperkt |
| Quiz-modus | Ja | Nee |
| Answer-piping | Ja | Nee |
| Multi-page formulieren | Ja | Nee |
| Custom branding | Ja | Nee |
| Bestand-uploads | Ja | Ja |

**FormVox-voordeel:** meer geavanceerde formulier-logica en customization.

### Data & export

| Feature | FormVox | Nextcloud Forms |
|---------|---------|-----------------|
| CSV-export | Ja | Ja |
| JSON-export | Ja | Nee |
| Excel-export | Ja | Nee |
| Grafieken | Ja | Ja |
| Real-time resultaten | Ja | Ja |

**FormVox-voordeel:** meer export-formaten.

### Beveiliging & privacy

| Feature | FormVox | Nextcloud Forms |
|---------|---------|-----------------|
| E2E-encryptie | Compatibel | Niet compatibel |
| Server-side encryptie | Ja | Ja |
| Wachtwoord-bescherming | Ja | Ja |
| Vervaldatums | Ja | Ja |
| Rate limiting | Ja | Beperkt |
| AVG-compliance | Ja | Ja |

**FormVox-voordeel:** E2E-encryptie-compatibiliteit.

### Integratie

| Feature | FormVox | Nextcloud Forms |
|---------|---------|-----------------|
| Files-app-integratie | Ja | Nee |
| Nextcloud-sharing | Ja | Beperkt |
| REST-API | Ja | Ja |
| Bestand-versioning | Ja | Nee |
| Back-up (bestand-kopie) | Ja | Database-back-up |

**FormVox-voordeel:** native bestand-integratie.

## Architectuur-verschillen

### FormVox: file-based

```
Gebruiker maakt formulier
       │
       ▼
┌─────────────────┐
│  .fvform-       │  ← Enkel JSON-bestand
│  bestand        │
│  - Formulier-def│
│  - Antwoorden   │
└─────────────────┘
```

**Voordelen:**

- Portable (kopiëren/verplaatsen als elk bestand)
- Werkt met E2E-encryptie
- Native bestand-versioning
- Eenvoudige back-up (kopieer bestanden)
- Geen database-migraties

**Nadelen:**

- Gelijktijdige toegang vereist locking
- Zeer grote formulieren kunnen trager zijn
- Bestandsgrootte groeit met antwoorden

### Nextcloud Forms: database

```
Gebruiker maakt formulier
       │
       ▼
┌─────────────────┐
│   Database      │
│   - forms       │  ← Meerdere tabellen
│   - questions   │
│   - responses   │
└─────────────────┘
```

**Voordelen:**

- Efficiënt voor zeer grote datasets
- Native database-queries
- Betere gelijktijdige afhandeling
- Bekende architectuur

**Nadelen:**

- Geen E2E-encryptie-ondersteuning
- Vereist database-migraties
- Lastiger om individuele formulieren te back-uppen
- Minder portable

## Wanneer FormVox gebruiken

Kies FormVox als je nodig hebt:

1. **End-to-end-encryptie** — FormVox werkt met Nextcloud's E2E-encryptie
2. **File-based workflow** — formulieren als bestanden passen beter bij je organisatie
3. **Geavanceerde vraagtypen** — rating-schalen, matrices, quizzes
4. **Conditionele logica** — complexe formulier-vertakkingen
5. **Custom branding** — per-formulier visuele customization
6. **Portability** — eenvoudig formulieren als bestanden kopiëren, verplaatsen, delen

## Wanneer Nextcloud Forms gebruiken

Kies Nextcloud Forms als je nodig hebt:

1. **Stabiliteit** — mature, battle-tested oplossing
2. **Grote schaal** — duizenden antwoorden per formulier
3. **Simpele formulieren** — basis-enquêtes zonder geavanceerde features
4. **Integratie** — onderdeel van Nextcloud's core ecosystem
5. **Lager resource-gebruik** — database kan efficiënter zijn voor grote datasets

## Migratie

### Van Nextcloud Forms naar FormVox

Momenteel is er geen geautomatiseerde migratie-tool. Om te migreren:

1. Exporteer antwoorden uit Nextcloud Forms (CSV)
2. Maak equivalent formulier in FormVox
3. Importeer antwoorden handmatig of via API

### Van FormVox naar Nextcloud Forms

1. Exporteer antwoorden uit FormVox (CSV/JSON)
2. Maak het formulier opnieuw in Nextcloud Forms
3. Let op: sommige vraagtypen zijn mogelijk niet beschikbaar

## Co-existentie

FormVox en Nextcloud Forms kunnen naast elkaar draaien:

- Verschillende apps, verschillende opslag
- Gebruikers kunnen per formulier kiezen
- Geen conflicten of interferentie

## Performance-vergelijking

### Kleine formulieren (<100 antwoorden)

| Metric | FormVox | Nextcloud Forms |
|--------|---------|-----------------|
| Formulier laden | ~100ms | ~100ms |
| Antwoord indienen | ~150ms | ~100ms |
| Resultaten bekijken | ~200ms | ~150ms |

Beide presteren vergelijkbaar voor kleine formulieren.

### Grote formulieren (1000+ antwoorden)

| Metric | FormVox | Nextcloud Forms |
|--------|---------|-----------------|
| Formulier laden | ~100ms | ~100ms |
| Antwoord indienen | ~200ms | ~100ms |
| Resultaten bekijken | ~500ms* | ~300ms |

*FormVox gebruikt paginering; initial load kan trager zijn.

## Conclusie

| Kies | Wanneer |
|------|---------|
| **FormVox** | Privacy-gericht, geavanceerde features, file-based workflow |
| **Nextcloud Forms** | Simpele behoeften, grote schaal, gevestigde oplossing |

Beide zijn uitstekende keuzes — welke past hangt af van je specifieke vereisten.
