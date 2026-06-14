# FormVox-API-referentie

> **Let op:** de complete API-referentie is uitvoerig technisch en wordt in zijn geheel onderhouden in het Engels in de FormVox-repository. Voor de actuele endpoint-specificatie, request-/response-schema's en code-voorbeelden, raadpleeg de [Engelse API-referentie](api-reference.en.md).

Op deze pagina vind je een Nederlandstalige inleiding tot de FormVox-REST-API en pointers naar de relevante secties van de volledige Engelstalige referentie.

## Twee API-varianten

FormVox heeft twee API's, voor verschillende use-cases:

| API | Doel | Authenticatie |
|-----|------|---------------|
| **Interne API** | Frontend, Nextcloud-gebruikers | Sessie-cookies / app-wachtwoord |
| **Externe API** | Externe systemen, integraties | Per-formulier API-keys (`fvx_*`) |

De interne API wordt op deze pagina behandeld; voor de externe API zie [Externe API & webhooks](external-api.md).

## Authenticatie

Voor de interne API gebruik je een Nextcloud-app-wachtwoord:

```bash
curl -u "username:app-password-token" \
  https://your-nextcloud.com/apps/formvox/api/forms
```

Maak een app-wachtwoord aan via **Nextcloud-instellingen → Beveiliging → Apparaten & sessies**.

## Base-URLs

| Stijl | Base-URL | Doel |
|-------|----------|------|
| **Interne API** | `/apps/formvox/api/...` | Frontend, sessie-gebaseerd |
| **OCS-API** | `/ocs/v2.php/apps/formvox/api/v1/...` | Externe systemen, app-password |

## Endpoint-categorieën

De Engelstalige referentie behandelt de volgende categorieën:

| Categorie | Doel |
|-----------|------|
| **Formulieren** | CRUD voor formulieren (`.fvform`-bestanden) |
| **Antwoorden** | Inzendingen ophalen, exporteren, verwijderen |
| **Publieke formulieren** | Hash-based toegang, public-submit-endpoint |
| **Templates** | Voorgedefinieerde formulier-templates |
| **Presence** | Real-time aanwezigheid voor gezamenlijk bewerken |
| **Export** | CSV/JSON/Excel-downloads, ZIP voor bestand-uploads |
| **Webhooks** | Event-notificaties voor externe systemen |
| **Rate limiting** | Limiet-headers en throttling-respons |
| **Error-codes** | Standaard-error-formaat en statuscodes |

## Snelstart

### Formulieren oplijsten

```bash
curl -u "username:app-password" \
  https://your-nextcloud.com/apps/formvox/api/forms
```

### Antwoorden ophalen

```bash
curl -u "username:app-password" \
  "https://your-nextcloud.com/apps/formvox/api/forms/{fileId}/responses"
```

### CSV-export

```bash
curl -u "username:app-password" \
  -o responses.csv \
  "https://your-nextcloud.com/apps/formvox/api/forms/{fileId}/export?format=csv"
```

### Publiek formulier indienen

```bash
curl -X POST \
  -H "Content-Type: application/json" \
  -d '{"answers": {"q1": "Jan Jansen", "q2": "5"}}' \
  https://your-nextcloud.com/apps/formvox/public/submit/{publicHash}
```

## Voor de complete referentie

Zie de [Engelse API-referentie](api-reference.en.md) voor:

- Volledige request-/response-schema's per endpoint
- HTTP-statuscodes en error-formaat
- Code-voorbeelden in cURL, JavaScript, Python en PHP
- Rate-limiting-details en presence-protocol
- Webhook-payload-formaten en signing-verificatie

## Gerelateerd

- [Externe API & webhooks](external-api.md) — API-key-gebaseerde externe integratie
- [Bestandsformaat](file-format.md) — `.fvform`-JSON-schema
- [Architectuur-overzicht](overview.md) — systeem-design
- [Beveiliging](../admin/security.md) — toegangscontrole en best-practices
