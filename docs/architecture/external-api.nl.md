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

API-keys worden per formulier gegenereerd en veilig opgeslagen als bcrypt-hashes in het `.fvform`-bestand. Elke key heeft configureerbare permissies.

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

## API-endpoints

Base-URL: `/apps/formvox/api/v1/external`

Het complete endpoint-overzicht:

| Methode | Endpoint | Permissie |
|---------|----------|-----------|
| GET | `/forms/{fileId}` | `read_form` |
| GET | `/forms/{fileId}/schema` | `read_form` |
| GET | `/forms/{fileId}/responses` | `read_responses` |
| GET | `/forms/{fileId}/responses/{responseId}` | `read_responses` |
| POST | `/forms/{fileId}/responses` | `write_responses` |
| PUT | `/forms/{fileId}/responses/{responseId}` | `write_responses` |
| DELETE | `/forms/{fileId}/responses/{responseId}` | `delete_responses` |

## Webhooks

FormVox kan webhook-notificaties versturen wanneer antwoorden veranderen. Webhook-payloads worden gesigneerd met HMAC-SHA256 voor authenticiteit.

### Events

- `response.created` — nieuw antwoord ingediend
- `response.updated` — bestaand antwoord bijgewerkt
- `response.deleted` — antwoord verwijderd

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
