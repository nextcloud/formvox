# Architectuur-overzicht

Dit document beschrijft de technische architectuur van FormVox.

## Systeem-design

### File-based architectuur

FormVox gebruikt een unieke file-based aanpak:

```
┌─────────────────────────────────────────────────┐
│                   Nextcloud                      │
├─────────────────────────────────────────────────┤
│  ┌──────────────┐    ┌──────────────────────┐   │
│  │   FormVox    │    │    Nextcloud Files    │   │
│  │     -app     │◄──►│    (.fvform-bestanden)│   │
│  └──────────────┘    └──────────────────────┘   │
│         │                                        │
│         ▼                                        │
│  ┌──────────────┐                               │
│  │   Vue.js     │                               │
│  │   frontend   │                               │
│  └──────────────┘                               │
└─────────────────────────────────────────────────┘
```

### Kern-principes

1. **Geen database-tabellen** — alle data opgeslagen in bestanden
2. **Native integratie** — gebruikt het Nextcloud-bestandssysteem
3. **Portability** — formulieren kunnen gekopieerd, verplaatst, gedeeld worden als elk ander bestand
4. **Encryptie-compatibel** — werkt met server-side en E2E-encryptie

## Componenten

### Backend (PHP)

In `lib/`:

```
lib/
├── AppInfo/
│   └── Application.php      # App-bootstrap
├── Controller/
│   ├── PageController.php   # Hoofd-pagina-routes
│   ├── FormController.php   # Formulier-CRUD-operaties
│   └── PublicController.php # Publieke-formulier-toegang
├── Service/
│   └── FormService.php      # Business-logica
├── Migration/
│   └── RegisterMimeType.php # MIME-type-registratie
├── Settings/
│   ├── AdminSettings.php    # Admin-paneel
│   └── AdminSection.php     # Admin-navigatie
└── BackgroundJob/
    └── TelemetryJob.php     # Telemetrie-rapportage
```

### Frontend (Vue.js)

In `src/`:

```
src/
├── main.js                  # App entry-point
├── files.js                 # Files-app-integratie
├── App.vue                  # Hoofd-component
├── views/
│   ├── FormEditor.vue       # Formulier-bewerken
│   ├── FormResults.vue      # Resultaten-dashboard
│   └── PublicForm.vue       # Publieke inzending
├── components/
│   ├── QuestionTypes/       # Vraag-componenten
│   ├── Settings/            # Instellingen-panelen
│   └── Results/             # Resultaten-componenten
└── store/
    └── index.js             # Vuex-store
```

### Build-output

In `js/`:

```
js/
├── formvox-main.js          # Hoofd-app-bundle
└── formvox-files.js         # Files-integratie
```

## Data-flow

### Formulier-creatie

```
Gebruiker klikt "Nieuw formulier"
        │
        ▼
┌───────────────────┐
│ Frontend maakt    │
│ formulier-        │
│ structuur         │
└───────────────────┘
        │
        ▼
┌───────────────────┐
│ API-POST-request  │
│ naar /api/forms   │
└───────────────────┘
        │
        ▼
┌───────────────────┐
│ FormService       │
│ maakt .fvform     │
└───────────────────┘
        │
        ▼
┌───────────────────┐
│ Bestand opgeslagen│
│ via Nextcloud     │
│ Files             │
└───────────────────┘
```

### Antwoord-inzending

```
Gebruiker dient formulier in
        │
        ▼
┌───────────────────┐
│ Frontend valideert│
│ en stuurt data    │
└───────────────────┘
        │
        ▼
┌───────────────────┐
│ API-POST-request  │
│ naar /api/submit  │
└───────────────────┘
        │
        ▼
┌───────────────────┐
│ FormService       │
│ - Verwerft lock   │
│ - Leest bestand   │
│ - Voegt antwoord  │
│   toe             │
│ - Schrijft bestand│
│ - Geeft lock vrij │
└───────────────────┘
```

## Gelijktijdige toegang

FormVox handelt meerdere gelijktijdige inzendingen af via file-locking:

```php
// Vereenvoudigd locking-mechanisme
$lock = $this->lockManager->acquireLock($fileId);
try {
    $content = $this->readFile($fileId);
    $content['responses'][] = $newResponse;
    $this->writeFile($fileId, $content);
} finally {
    $lock->release();
}
```

### Lock-types

- **Exclusive lock** — voor schrijven (formulier bewerken, antwoord indienen)
- **Shared lock** — voor lezen (resultaten bekijken)

### Conflict-resolutie

- Locks hebben een timeout om deadlocks te voorkomen
- Mislukte locks proberen opnieuw met exponential backoff
- Gebruikers zien een foutmelding als lock niet verkregen kan worden

## Bestandsformaat

Zie [Bestandsformaat](file-format.md) voor het complete `.fvform`-JSON-schema.

## API-architectuur

### REST-endpoints

| Methode | Endpoint | Beschrijving |
|---------|----------|--------------|
| GET | `/api/forms` | Formulieren van gebruiker oplijsten |
| POST | `/api/forms` | Nieuw formulier maken |
| GET | `/api/forms/{id}` | Formulier-details ophalen |
| PUT | `/api/forms/{id}` | Formulier bijwerken |
| DELETE | `/api/forms/{id}` | Formulier verwijderen |
| GET | `/api/forms/{id}/responses` | Antwoorden ophalen |
| POST | `/api/submit/{hash}` | Antwoord indienen |

Zie [API-referentie](api-reference.md) voor complete documentatie.

## Beveiligings-architectuur

### Authenticatie

- Interne API: Nextcloud-sessie-authenticatie
- Publieke formulieren: hash-based toegang met optioneel wachtwoord

### Autorisatie

```
Request → Middleware → Permissie-check → Controller
                            │
                            ▼
                    ┌───────────────┐
                    │ Bestandssysteem-
                    │ permissies    │
                    └───────────────┘
```

### Data-validatie

- Frontend: Vue.js-validatie
- Backend: PHP type-checking en sanitization
- Bestand: JSON-schema-validatie

## Performance-overwegingen

### Caching

- Formulier-structuur: gecached in browser
- Bestand-content: Nextcloud-file-cache
- Resultaten: on-demand berekend

### Schaalbaarheid

| Scenario | Performance |
|----------|-------------|
| Kleine formulieren (<100 antwoorden) | Instant |
| Middelgrote formulieren (100-1000) | Snel |
| Grote formulieren (1000+) | Paginering aanbevolen |

### Optimalisatie-tips

1. Gebruik paginering voor grote result-sets
2. Archiveer oude antwoorden periodiek
3. Schakel APCu-caching in Nextcloud in

## Integratie-punten

### Nextcloud-integratie

- **Files-app** — custom file-handler voor `.fvform`
- **Sharing** — gebruikt Nextcloud-sharing-API
- **Search** — formulieren doorzoekbaar via Unified Search
- **Activity** — formulier-events in activity-stream

### Externe integratie

- REST-API voor programmatic toegang
- JSON-export voor data-analyse
- Webhook-ondersteuning (gepland)

## Development-architectuur

### Build-systeem

```bash
# Development-build met watch
npm run watch

# Production-build
npm run build
```

### Technologieën

- **Backend**: PHP 8.1+, Nextcloud-app-framework
- **Frontend**: Vue 3, Vuex, Nextcloud-Vue-componenten
- **Build**: Webpack
- **Styling**: SCSS, Nextcloud-styles

## Volgende stappen

- [Bestandsformaat](file-format.md) — compleet JSON-schema
- [API-referentie](api-reference.md) — REST-API-documentatie
- [Vergelijking](comparison.md) — vs Nextcloud Forms
