# Architectuur-overzicht

Dit document beschrijft de technische architectuur van FormVox.

## Systeem-design

### Hybride architectuur

FormVox verdeelt een formulier over het bestand waarin het is aangemaakt en Nextclouds eigen opslag:

```
┌───────────────────────────────────────────────────────────────┐
│                           Nextcloud                            │
├───────────────────────────────────────────────────────────────┤
│  ┌──────────────┐  ┌───────────────────┐  ┌─────────────────┐ │
│  │   FormVox    │  │  Nextcloud Files  │  │    Database      │ │
│  │     -app     │◄►│  (.fvform: de     │  │  (antwoorden,     │ │
│  │              │  │  formulier-       │  │  geheimen)       │ │
│  │              │  │  definitie)       │  │                  │ │
│  └──────────────┘  └───────────────────┘  └─────────────────┘ │
│         │          ┌───────────────────┐                      │
│         │          │ FormVox-app-data  │                      │
│         └─────────►│ (uploads, PDF's,  │                      │
│                    │ huisstijl, ODT)   │                      │
│                    └───────────────────┘                      │
│         │                                                      │
│         ▼                                                      │
│  ┌──────────────┐                                             │
│  │   Vue.js     │                                             │
│  │   frontend   │                                             │
│  └──────────────┘                                             │
└───────────────────────────────────────────────────────────────┘
```

### Kern-principes

1. **Het bestand bevat alleen het formulier** — vragen, pagina's en instellingen staan in `.fvform`; antwoorden,
   geheimen en bestanden van invullers staan daar niet in
2. **Native integratie** — het formulier-bestand gebruikt nog steeds het Nextcloud-bestandssysteem, met standaard
   permissies en delen
3. **Portability** — de definitie van een formulier kan gekopieerd, verplaatst, gedeeld worden als elk ander bestand;
   een kopie van een `.fvform`-bestand begint zonder eigen antwoorden
4. **Encryptie-compatibel** — het `.fvform`-bestand werkt met server-side en E2E-encryptie; de database en FormVox's
   app-data worden niet gedekt door Nextclouds server-side bestands-encryptie (zie
   [Beveiliging](../admin/security.nl.md#encryptie))
5. **Omgezet bij aanraking** — een formulier van vóór 1.6.0 heeft nog alles in zijn bestand staan; het wordt door een
   achtergrondtaak omgezet naar de database en app-data, of meteen zodra er voor het eerst in geschreven wordt (zie
   [Waar FormVox data bewaart](../admin/configuration.nl.md#waar-formvox-data-bewaart))

## Componenten

### Backend (PHP)

In `lib/`:

```
lib/
├── AppInfo/
│   └── Application.php       # App-bootstrap
├── Controller/
│   ├── PageController.php    # Hoofd-pagina-routes
│   ├── FormController.php    # Formulier-CRUD-operaties
│   ├── PublicController.php  # Publieke-formulier-toegang
│   └── ExternalApiController.php # Externe API (API-keys, webhooks)
├── Service/
│   └── FormService.php       # Business-logica
├── Store/
│   ├── FormStore.php         # Koppelt de databaserij van een formulier aan zijn bestand
│   ├── ResponseStore.php     # Antwoorden, keys, tellers, outbox
│   ├── SecretStore.php       # Link-token, wachtwoord-hash, API-keys, webhooks
│   ├── FormFolders.php       # App-data: uploads, huisstijl, sjabloon, PDF's
│   └── FormMigration.php, FormConverter.php # Een v1-formulier naar de database omzetten
├── Command/
│   ├── Migrate.php, Orphans.php, Rebind.php, Unlock.php # occ formvox:*
├── Migration/
│   └── RegisterMimeType.php  # MIME-type-registratie
├── Settings/
│   ├── AdminSettings.php     # Admin-paneel
│   └── AdminSection.php      # Admin-navigatie
└── BackgroundJob/
    ├── TelemetryJob.php      # Telemetrie-rapportage
    ├── MigrateFormsJob.php   # Zet v1-formulieren om naar de database
    └── OrphanSweepJob.php    # Verwijdert formulieren waarvan het bestand verdween, na 30 dagen
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
┌─────────────────────────────┐
│ ResponseStore                │
│ - Een v1-formulier wordt     │
│   eerst naar de database     │
│   omgezet                    │
│ - Neemt de rij van het       │
│   formulier                  │
│ - Checkt limiet/capaciteit   │
│   tegen de opgeslagen tellers│
│ - Schrijft het antwoord, zijn│
│   keys en de outbox-rij      │
└─────────────────────────────┘
```

## Gelijktijdige toegang

Antwoorden, geheimen en de tellers achter de resultatenpagina worden in de database geschreven, één formulier
tegelijk: een inzending neemt de eigen rij van het formulier (vastgehouden tot de transactie commit), zodat
wijzigingen aan één formulier na elkaar gaan, en een deadlock of lock-timeout de hele transactie opnieuw probeert. Een
concurrency-test laat veel invullers in losse processen op hetzelfde moment indienen om te checken dat het limiet en
een eventuele capaciteit per optie exact kloppen, zonder verloren of dubbel antwoord.

FormVox houdt daarnaast een eigen lock per formulier (los hiervan, zie
[Beveiliging](../admin/security.nl.md#nextclouds-eigen-bestands-lock)), zodat maar één schrijver een formulier
bewerkt, of zijn outbox verstuurt, op een moment:

### Lock-types

- **FormVox's eigen formulier-lock** — één schrijver tegelijk (bewerken, instellingen, een formulier omzetten naar de database)
- **Nextclouds bestands-lock** — genomen zodra FormVox naar het `.fvform`-bestand zelf schrijft

### Conflict-resolutie

- Een bezet formulier geeft `503` terug met `reason: busy` en een `Retry-After`-header; clients mogen simpelweg opnieuw proberen
- FormVox's eigen lock probeert opnieuw met jittered backoff tot zijn deadline, en wordt overgenomen zodra zijn houder weg is
- `occ formvox:unlock` bevrijdt FormVox's eigen vastgelopen lock met de hand; het raakt Nextclouds bestands-lock niet

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

- Externe API met API-key-authenticatie, voor programmatic toegang (zie [Externe API](external-api.nl.md))
- JSON-export voor data-analyse
- Webhooks bij antwoord aangemaakt/gewijzigd/verwijderd, HMAC-gesigneerd (zie [Externe API](external-api.nl.md))

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
