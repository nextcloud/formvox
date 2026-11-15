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


#### PDF-bevestiging

> De PDF-bevestiging is ontwikkeld op verzoek van, en gefinancierd door, het Landesinstitut für Schulqualität und Lehrerbildung Sachsen-Anhalt (LISA). De functie zit in de publieke FormVox-code en is beschikbaar voor iedere FormVox-gebruiker.

Of een formulier überhaupt PDF's heeft, bepaalt het formulier zelf: **Bewaar een PDF van elk antwoord** in het
deelvenster, onder de bevestigingsmail, standaard uit. Aan: een achtergrondtaak maakt de PDF direct na elke inzending en
bewaart hem bij het antwoord; de download in de resultaten, **PDF's exporteren (ZIP)**, de externe API, het blok `pdf` in
de webhooks en de bevestigingsmail geven alle datzelfde document. Uit: er wordt nergens een PDF voor het formulier
gemaakt. De resultaten tonen geen PDF-download of ZIP, de PDF-route van de API antwoordt `409` met reden `pdf_disabled`,
webhooks sturen `"pdf": null` mee en **PDF van het antwoord meesturen** is niet aan te zetten. Een import krijgt nooit
PDF's, wat de schakelaar ook zegt.

Formulieren kunnen invullers hun antwoorden als PDF meesturen met de bevestigingsmail. Een ingelogde invuller krijgt de
bevestiging, met PDF, op het e-mailadres van zijn account; een account zonder adres krijgt geen bevestiging. Standaard
krijgen alleen zij die PDF: een anonieme invuller typt het adres zelf in, en dat wordt niet gecontroleerd. Onder
**Instellingen → PDF-bevestiging** zet **PDF ook naar anonieme invullers sturen** dat ook voor anonieme invullers aan.
Dan gaan er hooguit 3 van zulke mails per dag naar één adres en 200 per formulier per uur; daarboven gaat de mail zonder
PDF. Om die limieten te bewaken is Redis of Memcached nodig als geheugencache van Nextcloud: APCu telt in elk
PHP-proces apart. Zonder staat de schakelaar uit en is hij niet aan te zetten; het overzicht met setup-checks van
Nextcloud meldt dat.

#### PDF's en webhooks: serverinstellingen die ertoe doen

- **`overwrite.cli.url`** in `config.php`: webhooks gaan uit vanuit een achtergrondtaak, waar Nextcloud zijn eigen adres
  alleen uit deze instelling kent. Zet hem op het adres dat je integraties gebruiken, anders wijst `pdf.url` in een
  webhook ergens anders heen (`http://localhost/…`).
- **`allow_local_remote_servers`**: Nextcloud stuurt geen webhook naar een adres op zijn eigen netwerk. Draait de
  ontvanger daar (bijvoorbeeld Windmill op hetzelfde netwerk), zet dan `'allow_local_remote_servers' => true`.
- **`default_timezone`** en **`default_language`**: een PDF toont tijden in de tijdzone van de invuller, als diens browser
  die doorgaf, en de labels in de taal waarin het formulier is ingevuld. Een antwoord zonder die gegevens, zoals een
  antwoord via de API of een import, krijgt `default_timezone` (UTC als die niet gezet is), en `default_language` als de
  PDF op de achtergrond of via de API wordt gemaakt; een download in de app maakt hem in de taal van wie downloadt.
- **Schriften**: de PDF gebruikt het lettertype DejaVu Sans. Latijnse, Griekse en Cyrillische tekst staat er zoals
  getypt; Chinees, Japans, Koreaans, Thai, Indiase schriften en emoji worden vakjes, Arabische letters staan los van
  elkaar, en Arabisch en Hebreeuws lopen van links naar rechts.

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

FormVox voegt deze occ-commando's toe:

```bash
# Alle formulieren die nog in hun bestand staan nu naar de database verplaatsen, en afronden wat eerdere omzettingen liet liggen
sudo -u www-data php occ formvox:migrate

# Laten zien wat er nog moet gebeuren: formulieren nog in hun bestand, omgezette formulieren nog af te ronden, kapotte bestanden
sudo -u www-data php occ formvox:migrate --status

# Alleen dit formulier omzetten, via het bestands-id van het formulier-bestand
sudo -u www-data php occ formvox:migrate --file-id=<id>

# Formulieren oplijsten waarvan het bestand verdween zonder dat het in Nextcloud is verwijderd (na 30 dagen automatisch weg)
sudo -u www-data php occ formvox:orphans

# Eén wees nu verwijderen, met zijn antwoorden en bestanden
sudo -u www-data php occ formvox:orphans --remove=<form id>

# Antwoorden van een formulier handmatig weer koppelen aan een bestand, als dat elders een nieuw id kreeg dan waar het stond
sudo -u www-data php occ formvox:rebind <file-id> <form-id>

# Een formulier bevrijden waarvan de schrijver werd gedood tijdens het opslaan, voordat FormVox's eigen lock zelf verloopt
sudo -u www-data php occ formvox:unlock <fileId>
```

Gebruik `formvox:migrate` om direct na de update alle resterende formulieren om te zetten in plaats van te wachten op
de achtergrondtaak; `formvox:orphans` als het bestand van een formulier verdween zonder dat iemand het in Nextcloud
verwijderde (een map op schijf verwijderd, een restore waarin het ontbrak, een verwijderde teammap) en je de antwoorden wilt zien of verwijderen vóór de bewaartermijn van 30 dagen verstrijkt. Een formulier dat in
Nextcloud definitief is verwijderd, uit de prullenbak, waar geen prullenbak in gebruik is of samen met het account in wiens thuismap
of prullenbak het stond, verliest zijn antwoorden,
uploads en PDF's meteen en komt hier nooit in voor; `formvox:rebind` als een bestand ergens anders een nieuw id kreeg dan waar het
stond, zodat FormVox het niet zelf kan terugvinden; `formvox:unlock` alleen als een formulier bezet blijft gemeld
omdat het proces dat het schreef werd gedood. Zie [Waar FormVox data bewaart](#waar-formvox-data-bewaart) voor de
omzetting naar 1.6 en [Beveiliging](security.nl.md#nextclouds-eigen-bestands-lock) voor het verschil met Nextclouds
eigen bestands-lock.

### Config-waarden

Stel configuratie in via config.php of occ:

```bash
# Telemetrie uitschakelen
sudo -u www-data php occ config:app:set formvox telemetry_enabled --value=0

# Standaard-branding-kleur instellen
sudo -u www-data php occ config:app:set formvox default_accent_color --value=#0082c9
```

## Waar FormVox data bewaart

Sinds 1.6.0 staat een formulier verdeeld over drie plekken:

- **Het `.fvform`-bestand** bevat alleen nog de formulier-definitie: vragen, pagina's en instellingen. Het staat nog
  steeds in de bestandsopslag van de eigenaar of teammap en volgt standaard Nextcloud-bestand-permissies, delen en
  versies.
- **De database** bevat de antwoorden van een formulier, zijn geheimen (link-token, link-wachtwoord-hash, API-keys,
  webhook-secrets) en de tellers achter de resultatenpagina, in de tabellen `formvox_forms`, `formvox_resp`,
  `formvox_keys`, `formvox_counts`, `formvox_outbox` en `formvox_secrets`.
- **FormVox's app-data** (buiten de bestanden van elke gebruiker) bevat de uploads van invullers, huisstijl-
  afbeeldingen, het ODT-sjabloon en bewaarde PDF's, één map per formulier-id.

### Upgraden naar 1.6

Formulieren die vóór 1.6.0 zijn aangemaakt, hebben nog alles in hun `.fvform`-bestand staan. Na de update zet een
achtergrondtaak (`MigrateFormsJob`) ze automatisch om naar de database en app-data: die draait bij elke run van de
achtergrondtaken van Nextcloud (elke 5 minuten met systeemcron), zet per run tot 2 minuten lang formulieren om, en neemt
de grootste formulieren eerst, omdat die het drukst zijn. Een site met een paar duizend formulieren is in ongeveer een
uur klaar. Is niets meer over, dan
kijkt hij daarna nog maar één keer per dag, om een formulier terug te vinden dat uit de prullenbak of een back-up is
teruggezet. Een formulier wordt ook meteen omgezet, vooruitlopend op de taak, zodra er voor het eerst iets in
geschreven wordt: een antwoord, een instelling, een opslag in de editor, of een bestand dat via WebDAV of een
versie-restore over het formulier wordt gezet.

Check de voortgang met:

```bash
sudo -u www-data php occ formvox:migrate --status
```

Dit toont hoeveel formulieren nog helemaal in hun bestand staan, hoeveel al zijn omgezet maar nog hun bestanden
(uploads, huisstijl, sjabloon, PDF's) moeten verplaatsen of hun bestand moeten laten opruimen, en welke bestanden als
FormVox-formulier zijn aangemerkt maar geen formulier bevatten; zo'n bestand wordt overgeslagen en getoond, niet
opnieuw geprobeerd tot de volgende update weer kijkt. Draai `occ formvox:migrate` (zonder opties) om alles nu om te
zetten in plaats van op de achtergrondtaak te wachten.

### Back-ups

Maak een back-up van de database en FormVox's app-data samen met de bestanden; een back-up van alleen de bestanden
bevat de antwoorden of geheimen van een formulier niet meer. Alleen de bestanden terugzetten brengt de formulieren
terug, maar zonder hun antwoorden, tenzij de bijbehorende database-back-up er samen mee wordt teruggezet.

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
