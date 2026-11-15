# Externe API & webhooks

> **Let op:** de complete externe-API-referentie is uitvoerig technisch en wordt in zijn geheel onderhouden in het Engels in de FormVox-repository. Voor de actuele endpoint-specificatie, request-/response-schema's en webhook-payload-formats, raadpleeg de [Engelse externe-API-documentatie](external-api.md).

## Inleiding

FormVox biedt een veilige externe API voor programmatic toegang tot formulieren en antwoorden vanuit externe systemen. Deze API gebruikt API-keys voor authenticatie in plaats van Nextcloud-sessie-cookies, wat hem ideaal maakt voor integraties met derde-partij-systemen.

### Use-cases

- Antwoorden naar een CRM, ticket-systeem of data-warehouse pushen
- Een formulier real-time embedded in een externe applicatie ophalen
- Webhook-notificaties triggeren bij nieuwe antwoorden
- Migratie- en sync-scripts bouwen tussen FormVox en andere systemen

## Authenticatie

### API-keys

API-keys worden per formulier gegenereerd. De bcrypt-hash van elke key wordt verzegeld in de database bewaard
(`formvox_secrets`), niet in het `.fvform`-bestand; alleen de formulier-definitie zelf staat nog in het bestand. Elke
key heeft configureerbare permissies.

**Formaat van een API-key:**

```
fvx_<32 willekeurige alfanumerieke tekens>
```

Voorbeeld: `fvx_v3eaAuWwIvgUe2NQMcy826smmlFdX0Jd`

### API-keys gebruiken

Neem de API-key op in de `X-FormVox-API-Key`-header:

```bash
curl -X GET \
  -H "X-FormVox-API-Key: fvx_your_api_key_here" \
  https://your-nextcloud.com/apps/formvox/api/v1/external/forms/{fileId}
```

### Permissies

Elke API-key kan één of meer van deze permissies hebben:

| Permissie | Beschrijving |
|-----------|--------------|
| `read_form` | Formulier-titel, -beschrijving en -instellingen lezen |
| `read_responses` | Individuele antwoorden oplijsten en lezen |
| `write_responses` | Antwoorden aanmaken en bijwerken |
| `delete_responses` | Antwoorden verwijderen |

Een key werkt alleen zolang de maker ervan dit in Nextcloud nog met het formulier mag: het formulier lezen vraagt
leesrecht; antwoorden lezen, ook als PDF, downloadrecht, net als een export in de app; antwoorden aanmaken, wijzigen en
verwijderen bewerkrecht. Is dat recht weg, dan krijgt de key `401`.

### Limieten

Per adres mogen er 100 verzoeken per minuut naar de routes die lezen (het formulier, het schema, de antwoorden, één
antwoord, de PDF) en 50 per minuut naar de routes die schrijven (aanmaken, wijzigen, verwijderen). Daarboven antwoordt
Nextcloud met `429 Too Many Requests`. Verzoeken met een verkeerde key worden bovendien vertraagd door de
brute-force-bescherming van Nextcloud.

## API-endpoints

Base-URL: `/apps/formvox/api/v1/external`

Het complete endpoint-overzicht:

| Methode | Endpoint | Permissie |
|---------|----------|-----------|
| GET | `/forms/{fileId}` | `read_form` |
| GET | `/forms/{fileId}/schema` | `read_form` |
| GET | `/forms/{fileId}/responses` | `read_responses` |
| GET | `/forms/{fileId}/responses/{responseId}` | `read_responses` |
| GET | `/forms/{fileId}/responses/{responseId}/pdf` | `read_responses` |
| POST | `/forms/{fileId}/responses` | `write_responses` |
| PUT | `/forms/{fileId}/responses/{responseId}` | `write_responses` |
| DELETE | `/forms/{fileId}/responses/{responseId}` | `delete_responses` |

Zonder parameters geeft `GET /forms/{fileId}/responses` alle antwoorden in één keer, oudste eerst. Bij grote formulieren
vraag je pagina's: `?limit=500&after={next}` geeft hooguit `limit` (maximaal 1000) antwoorden na `after`. Een antwoord met
pagina's bevat `next`: de `after` voor de volgende pagina, of `null` op de laatste. `count` is altijd het totaal van het
formulier. Geef `next` ongewijzigd terug; een antwoord dat tussendoor wordt verwijderd, laat de pagina's niet te vroeg
stoppen.

`PUT` vervangt alle antwoorden van een antwoord: een antwoord dat je weglaat, verdwijnt. Stuur dus elk antwoord mee dat
moet blijven. Het antwoord krijgt een nieuwe PDF, die de webhook `response.updated` noemt.

De PDF-route geeft `application/pdf`: het antwoord zoals de invuller het formulier zag, alleen met de vragen die hij te
zien kreeg. De PDF wordt één keer gemaakt en bewaard: hetzelfde document als bij de bevestigingsmail en als in de webhook.

De route werkt alleen op een formulier waarvan de eigenaar **Bewaar een PDF van elk antwoord** heeft aangezet; elk
ander formulier antwoordt `409` met reden `pdf_disabled` (`PDF generation is disabled for this form`). De route geeft
alleen de PDF die de achtergrondtaak heeft bewaard: gevraagd vóór die taak liep, antwoordt hij `409` met reden
`pdf_not_ready` (`PDF is not ready yet`). Vraag even later opnieuw, of wacht op de webhook, die de PDF noemt zodra hij
er is.

De PDF heeft overal dezelfde naam (deze route, een download in de app, de ZIP, de bevestigingsmail en `filename` in de
webhook): `{titel}_{datum}_{reactie-id}.pdf`. De titel staat in gewone ASCII: ä, ö, ü en ß worden ae, oe, ue en ss,
andere accenten vallen weg, elk ander teken wordt `-`, hoogstens 60 tekens, en `response` als er geen letter overblijft.
De datum is de dag van insturen, zoals de PDF hem toont. Het reactie-id staat er altijd helemaal in.

### Foutmeldingen

Een fout komt als JSON met `error` (een melding voor mensen) en `reason` (een vaste code om in je integratie op te
reageren), bijvoorbeeld `{"error": "Invalid API key", "reason": "invalid_api_key"}`.

| HTTP-status | `reason` | Betekenis |
|-------------|----------|-----------|
| 400 | `invalid_input` | Het verzoek is niet wat de route verwacht |
| 400 | `invalid_answer` | Een antwoord dat het formulier niet aanneemt; `question` noemt de vraag |
| 401 | `missing_api_key` | Geen header `X-FormVox-API-Key` |
| 401 | `invalid_api_key` | De key klopt niet, of de maker ervan mag dit in Nextcloud niet meer met het formulier |
| 403 | `key_permission` | De key mist de permissie; `permission` noemt hem |
| 404 | `not_found` | Dat formulier of antwoord bestaat niet |
| 422 | `broken_form` | Het bestand is geen FormVox-formulier |
| 429 | | Te veel verzoeken (zie Limieten) |
| 500 | `internal_error` | De server faalde; met `requestId` vindt je beheerder het in het Nextcloud-log |
| 503 | `busy` | Een andere opslag houdt het formulier even vast: probeer het opnieuw na de seconden in `Retry-After` |

## Webhooks

FormVox kan webhook-notificaties versturen wanneer antwoorden veranderen. Webhook-payloads worden gesigneerd met HMAC-SHA256 voor authenticiteit.

### Events

- `response.created` — nieuw antwoord ingediend
- `response.updated` — bestaand antwoord bijgewerkt
- `response.deleted` — antwoord verwijderd; de webhook stuurt alleen het `id` mee (weg is weg)

Webhooks gaan kort na het event uit via een achtergrondtaak, per antwoord in volgorde, en worden ongeveer een uur lang
opnieuw geprobeerd als de ontvanger onbereikbaar is. Is een antwoord verwijderd voordat zijn `response.created` uitging,
dan komt alleen de verwijdering: behandel het verwijderen van een onbekend `id` als gedaan, en gebruik
`X-FormVox-Event-Id` om een event dat je al had over te slaan.

Het blok `form` in de payload noemt het formulier met `fileId`, het id waarmee de externe API het kent, en `title`;
`form.id` is hetzelfde nummer onder de naam die integraties verwachten. Bovenaan staat het event-id als `id` en als
`event_id`, hetzelfde als de header `X-FormVox-Event-Id`.

Bij `response.created` en `response.updated` zegt `data` ook welke antwoorden van toepassing waren: `applicable_answers`
bevat de antwoorden op de vragen die de invuller te zien kreeg (een deel van `answers`, in dezelfde vorm) en
`not_applicable` de ids van de vragen die niet van toepassing waren, verborgen door een voorwaarde, in een verborgen
sectie of op een pagina die de routering oversloeg. `answers` blijft zoals het is opgeslagen. De externe API geeft
dezelfde twee velden bij een los antwoord en in de lijst.

### PDF in de webhook

Bij `response.created` en `response.updated` staat in de payload een blok `pdf` met `url`, `sha256`, `size`,
`filename`, `status` en `generated_at`. Haal de PDF op via `url` met een API-key die antwoorden mag lezen en vergelijk
de SHA-256; `filename` is de naam die de PDF overal heeft; `status` is `stored` (de genoemde PDF is bewaard en is wat de
API geeft) en `generated_at` zegt wanneer hij gemaakt is (ISO 8601 met offset). De PDF zelf gaat nooit mee. Kan de PDF nog niet gemaakt worden, dan wacht de webhook en
wordt hij later opnieuw geprobeerd; lukt het bewaren na drie pogingen nog niet, dan gaat de webhook met `"pdf": null`,
zodat het antwoord zelf altijd aankomt. De PDF is via de API op te halen zodra hij er is. Ook `response.deleted` heeft
`"pdf": null`, net als elk event van een formulier dat geen PDF's bewaart (**Bewaar een PDF van elk antwoord** uit): zo'n
webhook gaat meteen, en de PDF-route antwoordt voor dat formulier `409`.

### Payload-signing

Elke webhook-call bevat een `X-FormVox-Signature`-header met een HMAC-SHA256-handtekening van de payload, gegenereerd met de webhook-secret van het formulier.

Verifieer de handtekening in je endpoint om te bevestigen dat de request van FormVox komt.

## Voor de complete referentie

Zie de [Engelse externe-API-documentatie](external-api.md) voor:

- Volledige request-/response-schema's per endpoint
- Webhook-payload-voorbeelden voor alle events
- HMAC-signing-verificatie-code-voorbeelden (PHP, Python, Node.js)
- Antwoord-formaat-conventies (text-, choice-, matrix-vragen)
- Error-codes en rate-limiting-gedrag
- API-key-management via beheer-UI en occ

## Gerelateerd

- [API-referentie](api-reference.md) — interne API (sessie-authenticatie)
- [Bestandsformaat](file-format.md) — `.fvform`-JSON-schema
- [Beveiliging](../admin/security.md) — toegangscontrole en best-practices
- [Voorbeeld: PDF's naar een Nextcloud-map met Windmill](windmill-example.md) — webhook, PDF-controle en inhalen in één script (Engels)
