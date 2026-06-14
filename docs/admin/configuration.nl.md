# Configuratie

Deze gids beschrijft beheerder-instellingen en configuratie-opties voor FormVox.

## Beheer-instellingen openen

1. Log in als Nextcloud-beheerder
2. Ga naar **Instellingen** (klik op je profiel → Instellingen)
3. Klik onder **Beheer** op **FormVox**

## Beheer-instellingen-tabbladen

### Branding-tabblad

Configureer organisatie-brede branding voor alle formulieren.

#### Default-branding

- **Header-afbeelding-URL** — standaard-logo/-banner voor nieuwe formulieren
- **Achtergrond-kleur** — standaard formulier-achtergrond-kleur
- **Accent-kleur** — standaard knop- en highlight-kleur

#### Branding-overerving

Formulieren kunnen:

- Organisatie-defaults gebruiken
- Overschrijven met formulier-specifieke branding

### Statistieken-tabblad

Bekijk gebruiks-statistieken over je Nextcloud-instantie.

#### Beschikbare statistieken

- **Totaal aantal formulieren** — aantal aangemaakte formulieren
- **Totaal aantal antwoorden** — som van alle inzendingen
- **Actieve gebruikers** — gebruikers die formulieren hebben aangemaakt (laatste 30 dagen)

#### Statistieken-refresh

Statistieken worden asynchroon geladen wanneer je het statistieken-tabblad opent, zodat de beheer-instellingen-pagina direct laadt zonder te wachten op de berekening van statistieken.

### Embedding-tabblad

Bepaal hoe formulieren in externe websites geëmbed kunnen worden.

#### Toegestane domeinen

Beperk welke externe domeinen FormVox-formulieren mogen embedden:

1. Ga naar **FormVox** beheer-instellingen
2. Klik op het **Embedding**-tabblad (of het **Instellingen**-tabblad)
3. Voeg toegestane domeinen toe (één per regel):

   ```
   sharepoint.company.com
   intranet.company.com
   *.trusted-domain.com
   ```

4. Sla instellingen op

**Opties:**

- Laat leeg om alle domeinen toe te staan (default)
- Gebruik `*` als wildcard voor subdomeinen
- Specificeer exacte domeinen voor strikte controle

**Beveiligings-notitie:** door embed-domeinen te beperken voorkom je dat je formulieren op ongeautoriseerde websites worden geëmbed, wat het risico op phishing-aanvallen verlaagt.

### Telemetrie-tabblad

Configureer anonieme telemetrie-rapportage.

#### Wat wordt verzameld

- Aantal formulieren
- Aantal antwoorden
- Aantal actieve gebruikers
- Nextcloud-versie
- FormVox-versie
- PHP-versie

#### Wat NIET wordt verzameld

- Formulier-content
- Antwoord-data
- Gebruikers-informatie
- Server-URLs of IPs

#### Opt-out

Om telemetrie uit te schakelen:

1. Ga naar **FormVox** beheer-instellingen
2. Vink **Anonieme telemetrie inschakelen** uit
3. Sla instellingen op

## App-configuratie

### occ-commando's

FormVox ondersteunt deze occ-commando's:

```bash
# Alle formulieren oplijsten
sudo -u www-data php occ formvox:list

# Formulier-statistieken tonen
sudo -u www-data php occ formvox:stats

# MIME-types repareren
sudo -u www-data php occ formvox:repair
```

### Config-waarden

Stel configuratie in via config.php of occ:

```bash
# Telemetrie uitschakelen
sudo -u www-data php occ config:app:set formvox telemetry_enabled --value=0

# Standaard-branding-kleur instellen
sudo -u www-data php occ config:app:set formvox default_accent_color --value=#0082c9
```

## Bestandsopslag

### Waar formulieren worden opgeslagen

Formulieren worden opgeslagen als `.fvform`-bestanden in de Nextcloud-bestandsopslag van gebruikers:

- Standaard-locatie: root-map van de gebruiker
- Gebruikers kiezen de locatie bij het aanmaken van formulieren
- Formulieren volgen standaard Nextcloud-bestand-permissies

### Opslag-overwegingen

Elk formulier-bestand bevat:

- Formulier-definitie (vragen, instellingen)
- Alle antwoorden

Bestandsgroottes:

- Leeg formulier: ~2-5 KB
- Formulier met 100 antwoorden: ~50-200 KB
- Formulier met 1000 antwoorden: ~500 KB – 2 MB

### Quota's

Formulieren tellen mee voor gebruikers-opslag-quota's. Overweeg:

- Formulieren met veel antwoorden groeien in de tijd
- Bestand-upload-vragen vergroten de grootte aanzienlijk
- Monitor heavy users als quota's beperkt zijn

## Background-jobs

FormVox gebruikt Nextcloud's background-job-systeem.

### Telemetrie-job

Bij ingeschakelde telemetrie:

- Draait dagelijks
- Rapporteert anonieme gebruiks-statistieken
- Minimale server-impact

### Zorgen dat jobs draaien

Verifieer dat cron is geconfigureerd:

```bash
sudo -u www-data php occ background:cron
```

Check job-status:

```bash
sudo -u www-data php occ background-job:list | grep formvox
```

## Logging

### Log-niveaus

FormVox logt naar het Nextcloud-log-bestand:

```
/path/to/nextcloud/data/nextcloud.log
```

Log-niveaus:

- **Error** — kritieke problemen
- **Warning** — niet-kritieke problemen
- **Info** — algemene operaties
- **Debug** — gedetailleerd debuggen (inschakelen in Nextcloud-instellingen)

### Issues debuggen

Om debug-logging in te schakelen:

1. Zet `'loglevel' => 0` in config.php
2. Reproduceer het issue
3. Check het log-bestand
4. Reset log-niveau na afloop

## Integratie-instellingen

### Externe systemen

FormVox ondersteunt integratie via:

- REST-API (zie [API-referentie](../architecture/api-reference.md))
- Bestandssysteem-toegang (`.fvform`-bestanden zijn JSON)
- Nextcloud's sharing-API

### Externe API & webhooks

FormVox ondersteunt een externe API met API-key-authenticatie en webhooks voor real-time notificaties. Zie de [Externe-API-&-webhooks-documentatie](../architecture/external-api.md) voor details over:

- API-key-beheer (per formulier, configureerbare permissies)
- CRUD-operaties op antwoorden
- Webhook-events (`response.created`, `response.updated`, `response.deleted`)
- HMAC-SHA256-gesigneerde payloads

## Performance-tuning

### Voor grote installaties

Als je veel formulieren of antwoorden hebt:

1. **Schakel APCu-caching in** in Nextcloud
2. **Gebruik SSDs** voor opslag
3. **Configureer een goede cron** (geen AJAX-cron)

### Antwoord-limieten

Voor formulieren met duizenden antwoorden:

- Resultaten laden progressief
- Exports kunnen langer duren
- Overweeg oude antwoorden te archiveren

## Beveiligings-configuratie

Zie de dedicated [Beveiligings-gids](security.md) voor:

- Rate limiting
- Toegangscontrole
- Wachtwoord-beleid

## Volgende stappen

- Configureer [Beveiligings-instellingen](security.md)
- Bekijk [Architectuur](../architecture/overview.md) voor technische details
- Check [API-referentie](../architecture/api-reference.md) voor integraties
