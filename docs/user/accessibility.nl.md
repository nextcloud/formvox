# Toegankelijkheid

FormVox bevat ingebouwde toegankelijkheids-features om formulieren bruikbaar te maken voor iedereen, inclusief gebruikers met visuele beperkingen of motorische handicaps.

## Text-to-speech (TTS)

Elke vraag op een publiek formulier heeft een speaker-icoon naast het vraag-label. Wanneer geklikt, leest de browser de vraag voor met de Web Speech-API.

### Wat wordt voorgelezen

- De vraag-tekst
- De vraag-beschrijving (indien aanwezig)
- Antwoord-opties voor choice-, multiple-choice- en dropdown-vragen
- Schaal-bereik en labels voor lineaire-schaal-vragen
- Aantal sterren voor rating-vragen
- Rij- en kolom-labels voor matrix-vragen

### Hoe te gebruiken

1. Klik op het speaker-icoon naast een vraag
2. De browser leest de vraag en haar opties hardop voor
3. Klik nogmaals op het icoon om te stoppen
4. De taal wordt automatisch gedetecteerd uit je Nextcloud-taal-instelling

> **Let op:** TTS vereist een moderne browser met Web-Speech-API-ondersteuning (Chrome, Firefox, Safari, Edge). Het speaker-icoon verschijnt alleen wanneer TTS ondersteund wordt.

## Screenreader-ondersteuning

Alle vraagtypen bevatten correcte ARIA-attributen voor screenreaders zoals VoiceOver (macOS/iOS), NVDA (Windows) of TalkBack (Android):

- Vragen worden aangekondigd met hun label en verplicht-status
- Validatie-fouten worden automatisch aangekondigd zodra ze verschijnen
- Pagina-wijzigingen en inzending-status worden aangekondigd
- Matrix-vragen gebruiken correcte tabel-semantiek

### Ondersteunde screenreaders

| Screenreader | Platform | Ondersteund |
|--------------|----------|-------------|
| VoiceOver | macOS / iOS | Ja |
| NVDA | Windows | Ja |
| JAWS | Windows | Ja |
| TalkBack | Android | Ja |

## Toetsenbord-navigatie

Formulieren kunnen volledig met alleen het toetsenbord worden bediend:

| Toets | Actie |
|-------|-------|
| **Tab** | Tussen vragen en formulier-controls navigeren |
| **Pijltjes** | Tussen schaal-waarden en sterren-rating-opties navigeren |
| **Home / End** | Spring naar de eerste of laatste optie in schaal/rating |
| **Enter / Spatie** | Knoppen en de bestand-upload-zone activeren |

### Skip-link

Druk op **Tab** bovenaan het formulier om een "Spring naar formulier-vragen"-link te onthullen. Druk op **Enter** om header-content over te slaan en direct naar de vragen te gaan.

## Focus-beheer

FormVox beheert focus automatisch om toetsenbord- en screenreader-gebruikers te helpen:

- **Validatie-fouten** — focus springt naar de eerste vraag met een fout, en de foutmelding wordt door screenreaders aangekondigd
- **Pagina-navigatie** — focus springt naar de eerste vraag op de nieuwe pagina
- **Formulier-inzending** — focus springt naar het bedankt-bericht zodat screenreaders het aankondigen

## ARIA-attributen

De volgende ARIA-attributen worden door de hele formulier-response-interface gebruikt:

| Attribuut | Doel |
|-----------|------|
| `role="group"` | Groepeert elke vraag met haar label en input |
| `role="radiogroup"` | Identificeert single choice, schaal en rating als radio-groepen |
| `role="alert"` | Kondigt validatie-fouten direct aan |
| `aria-required` | Geeft verplichte vragen aan |
| `aria-invalid` | Geeft velden met validatie-fouten aan |
| `aria-describedby` | Linkt inputs aan hun beschrijving en foutmeldingen |
| `aria-live="polite"` | Kondigt pagina-wijzigingen en inzending-status aan |
| `aria-live="assertive"` | Kondigt formulier-fouten direct aan |
| `aria-label` | Levert labels voor icon-knoppen (speaker, bestand verwijderen) |

## Browser-compatibiliteit

| Feature | Chrome | Firefox | Safari | Edge |
|---------|--------|---------|--------|------|
| ARIA / screenreader | Ja | Ja | Ja | Ja |
| Toetsenbord-navigatie | Ja | Ja | Ja | Ja |
| Text-to-speech | Ja | Ja | Ja | Ja |

## Volgende stappen

- Leer over [Geavanceerde features](advanced-features.md)
- Leer over [Delen en publiceren](sharing-publishing.md)
- Bekijk en analyseer [Resultaten](results-analysis.md)
