# Data exporteren

FormVox laat je formulier-antwoorden in meerdere formaten exporteren voor externe analyse en archivering.

## Export-formaten

### CSV (Comma-Separated Values)

Geschikt voor:

- Spreadsheet-applicaties (Excel, Google Sheets, LibreOffice Calc)
- Eenvoudige data-analyse
- Importeren in andere systemen

Structuur:

- Eén rij per antwoord
- Eén kolom per vraag
- Eerste rij bevat vraag-titels

### JSON (JavaScript Object Notation)

Geschikt voor:

- Ontwikkelaars en programmeurs
- API-integraties
- Behoud van data-structuur

Structuur:

```json
{
  "form": {
    "title": "Klant-enquête",
    "questions": [...]
  },
  "responses": [
    {
      "submitted": "2024-01-15T10:30:00Z",
      "answers": {
        "q1": "Jan Jansen",
        "q2": "Zeer tevreden"
      }
    }
  ]
}
```

### Excel (.xlsx)

Geschikt voor:

- Microsoft-Excel-gebruikers
- Geavanceerde analyse met formules
- Delen met niet-technische gebruikers

Features:

- Geformatteerde kolommen
- Meerdere sheets (samenvatting + ruwe data)
- Grafieken (optioneel)

### ZIP (bestand-uploads)

Voor formulieren met bestand-upload-vragen, download alle geüploade bestanden:

1. Open de **Resultaten**-weergave
2. Klik op **Uploads downloaden** of het ZIP-icoon
3. Alle geüploade bestanden worden gedownload als ZIP-archief

De ZIP-bestandsstructuur:

```
uploads/
├── response_1/
│   ├── document.pdf
│   └── foto.jpg
├── response_2/
│   └── bijlage.docx
```

**Let op:** deze optie verschijnt alleen voor formulieren met bestand-upload-vragen waar bestanden zijn ingediend.

## Hoe te exporteren

### Vanuit de resultaten-weergave

1. Open je formulier
2. Klik op **Resultaten** in de toolbar
3. Klik op de **Exporteren**-knop
4. Kies je formaat (CSV, JSON of Excel)
5. Configureer opties (zie hieronder)
6. Klik op **Downloaden**

### Export-opties

**Includeer:**

- [ ] Antwoord-tijdstempels
- [ ] Antwoord-IDs
- [ ] Gedeeltelijke antwoorden (incomplete inzendingen)

**Formaat:**

- [ ] Inclusief vraag-nummers
- [ ] Gebruik vraag-IDs als headers (voor JSON)
- [ ] Matrix-vragen flatten

**Datum-range:**

- Alle antwoorden
- Laatste 7 dagen
- Laatste 30 dagen
- Custom range

## Werken met geëxporteerde data

### In Excel/spreadsheets

Na export naar CSV of Excel:

1. Open het bestand in je spreadsheet-applicatie
2. Gebruik filters om subsets te analyseren
3. Maak draaitabellen voor samenvattingen
4. Bouw grafieken voor visualisatie

**Tip:** voor CSV-bestanden, gebruik "Data > Tekst naar kolommen" als kolommen niet correct splitsen.

### In programmeertalen

Met de JSON-export:

**Python:**

```python
import json

with open('responses.json') as f:
    data = json.load(f)

for response in data['responses']:
    print(response['answers'])
```

**JavaScript:**

```javascript
const data = require('./responses.json');

data.responses.forEach(response => {
    console.log(response.answers);
});
```

## Geautomatiseerde exports

### Geplande exports

Momenteel ondersteunt FormVox geen geplande exports. Voor regelmatige exports:

1. Stel een agenda-herinnering in
2. Exporteer handmatig op vaste intervallen
3. Overweeg de API te gebruiken voor automatisering

### API-export

Voor ontwikkelaars, gebruik de FormVox-API om programmatisch te exporteren:

```bash
curl -H "Authorization: Bearer TOKEN" \
  https://your-nextcloud.com/apps/formvox/api/forms/FORM_ID/responses
```

Zie [API-referentie](../architecture/api-reference.md) voor details.

## Data-privacy

### Vóór het exporteren

Overweeg:

- Wie krijgt toegang tot het geëxporteerde bestand?
- Bevat het persoonsgegevens?
- Voldoe je aan databescherming-regelgeving (AVG, enz.)?

### Gevoelige data

Voor formulieren met gevoelige data:

- Exporteer alleen wat je nodig hebt
- Bewaar exports veilig
- Verwijder exports zodra ze niet meer nodig zijn
- Anonimiseer data indien mogelijk

## Back-up en archivering

### Regelmatige back-ups

Voor belangrijke formulieren:

1. Exporteer data regelmatig (wekelijks/maandelijks)
2. Bewaar exports op een veilige locatie
3. Bewaar meerdere versies

### Oude data archiveren

Om oude antwoorden te archiveren en wissen:

1. Exporteer alle antwoorden
2. Verifieer dat de export compleet is
3. Verwijder antwoorden uit FormVox
4. Bewaar de export voor archivering

### Formulier-bestand-back-up

Onthoud: het `.fvform`-bestand zelf bevat alle data:

- Formulier-structuur
- Alle antwoorden

Het bestand back-uppen = alles back-uppen.

## Problemen oplossen

### Grote exports

Voor formulieren met veel antwoorden:

- Gebruik datum-range-filters om in batches te exporteren
- Kies CSV (kleinere bestandsgrootte)
- Geef extra tijd voor de download

### Encoding-issues

Als speciale tekens niet correct worden weergegeven:

- Zorg voor UTF-8-encoding bij openen van CSV
- Gebruik Excel's "Importeren"-feature in plaats van dubbelklikken
- Probeer het Excel-(.xlsx-)formaat in plaats

### Ontbrekende data

Als antwoorden ontbreken:

- Check datum-range-filters
- Verifieer "gedeeltelijke antwoorden meenemen" indien nodig
- Check formulier-permissies

## Volgende stappen

- Bekijk [Resultaten-analyse](results-analysis.md)-features
- Leer over [API-toegang](../architecture/api-reference.md)
- Configureer [Beveiligings-instellingen](../admin/security.md)
