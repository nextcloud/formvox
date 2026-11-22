# Beveiligings-gids

Deze gids beschrijft beveiligings-features en best practices voor FormVox-beheerders.

## Toegangscontrole

### Nextcloud-permissies

FormVox respecteert het Nextcloud-permissie-systeem:

| Permissie | Kan bekijken | Kan bewerken | Kan delen | Kan verwijderen |
|-----------|--------------|--------------|-----------|------------------|
| Read | Ja | Nee | Nee | Nee |
| Edit | Ja | Ja | Nee | Nee |
| Share | Ja | Ja | Ja | Nee |
| Delete | Ja | Ja | Ja | Ja |

### File-based beveiliging

Omdat formulieren bestanden zijn:

- Standaard Nextcloud-bestand-permissies gelden
- Encryptie (inclusief E2E) wordt ondersteund
- Delen volgt bestand-deel-regels

### Formulier-niveau-permissies

Binnen FormVox:

- **Resultaten bekijken** — wie kan antwoorden zien
- **Formulier bewerken** — wie kan vragen wijzigen
- **Delen beheren** — wie kan toegang wijzigen

## Beveiliging van publieke formulieren

### Wachtwoord-bescherming

Voeg een wachtwoord toe aan publieke formulieren:

1. Open formulier-instellingen → Delen
2. Schakel **Wachtwoord-bescherming** in
3. Stel een sterk wachtwoord in
4. Deel het wachtwoord apart van de link

**Best practices:**

- Gebruik unieke wachtwoorden per formulier
- Wijzig wachtwoorden periodiek
- Neem het wachtwoord niet op in dezelfde mail als de link

### Wie een openbare link mag maken

Sinds 1.6.0 volgt FormVox de instellingen van Nextcloud voor openbare links, dezelfde
instellingen als voor een openbare deellink in Bestanden. Alleen wie van Nextcloud
deellinks mag maken, kan de antwoord-link van een formulier maken of vervangen door een
nieuwe. Onder **Instellingen → Beheer → Delen** vraagt dat:

- **Sta gebruikers toe om te delen via een link en e-mail** aan;
- de persoon niet in een groep onder **Sta deze groepen niet toe om deellinks te maken**;
- onder **Beperk delen op basis van groepen**: de persoon niet in een uitgesloten groep,
  of, bij een lijst van toegestane groepen, wel in een toegestane groep.

Anders antwoordt **Create response link** met *Public links are not allowed* (in het
Nederlands: *Openbare links zijn niet toegestaan*), en mislukt **Replace with a new
link**. Vóór 1.6.0 keek FormVox niet naar deze instellingen, dus de melding kan na de
update voor het eerst verschijnen.

FormVox kijkt alleen naar degene die de link maakt of vervangt, niet naar de
respondenten. Wil je openbare links voor de meeste accounts dichthouden, sta ze dan alleen
toe voor de groepen die formulieren beheren. Dit geldt ook voor formulieren waarbij
respondenten moeten inloggen, of waarvan de link beperkt is tot gebruikers of groepen:
ook die bereiken respondenten via de antwoord-link.

De configuratie-presets (`config_preset`) veranderen deze instellingen niet. Uitlezen
op de commandoregel:

```bash
occ config:app:get core shareapi_allow_links
occ config:app:get core shareapi_allow_links_exclude_groups
occ config:app:get core shareapi_exclude_groups
occ config:app:get core shareapi_exclude_groups_list
```

Een lege uitkomst betekent de standaard: deellinks toegestaan en geen groepen
uitgesloten.

### Linkwachtwoord verplicht door Nextcloud

Sinds 1.6.0 volgt FormVox de regels van Nextcloud voor wachtwoorden op openbare links,
dezelfde regels als voor een openbare deellink in Bestanden:

- **Dwing wachtwoordbeveiliging af** onder **Instellingen → Beheer → Delen**: staat die
  aan, dan krijgt een nieuwe antwoord-link een gegenereerd wachtwoord, één keer getoond
  aan degene die de link maakt, en kan het wachtwoord er niet meer af. Links die al
  bestonden voordat de instelling aan ging, blijven werken zoals ze zijn, zonder
  wachtwoord, tot iemand er een instelt.
- **Wachtwoordbeleid:** een wachtwoord dat iemand instelt, moet voldoen aan het
  wachtwoordbeleid van Nextcloud voor delen (de app `password_policy`), en een
  gegenereerd wachtwoord komt uit die app als die aan staat.
- **Vraag altijd naar een wachtwoord** gebruikt FormVox niet: zonder afdwingen blijft
  het wachtwoord op de link van een formulier optioneel.

Dit geldt ook voor formulieren waarbij respondenten moeten inloggen, of waarvan de link
beperkt is tot gebruikers of groepen. Respondenten loggen dan in én vullen het
wachtwoord in.

#### Standaard aan bij een configuratie-preset

Vanaf Nextcloud 32 zetten de presets School, Universiteit, Hostingbedrijf, Kleine
organisatie en de twee grotere organisaties (`config_preset` 4 t/m 9 in `config.php`)
**Dwing wachtwoordbeveiliging af** aan, tenzij een beheerder die instelling zelf heeft
gezet. **Vraag altijd naar een wachtwoord** blijft dan uit, dus de instellingenpagina
toont **Dwing wachtwoordbeveiliging af** als aan, maar grijs.

Afdwingen uitzetten:

1. Ga naar **Instellingen → Beheer → Delen**
2. Zet **Vraag altijd naar een wachtwoord** aan; pas dan is de schakelaar eronder te
   wijzigen
3. Zet **Dwing wachtwoordbeveiliging af** uit
4. Zet **Vraag altijd naar een wachtwoord** weer uit als je dat niet wilt voor Bestanden

Dit verandert de regel voor elke openbare link op je Nextcloud, niet alleen voor
FormVox.

#### Groepen uitzonderen

Wil je afdwingen houden voor de meeste gebruikers, maar niet voor wie de formulieren
beheert, voeg hun groepen dan toe onder **Sluit groepen uit van wachtwoordvereisten**.
FormVox kijkt naar de groepen van degene die de link maakt of wijzigt, niet naar die van
de respondenten. Het veld verschijnt alleen als `config.php` dit bevat:

```php
'sharing.allow_disabled_password_enforcement_groups' => true,
```

en is, net als de schakelaar erboven, alleen te wijzigen zolang **Vraag altijd naar een
wachtwoord** aan staat.

### Vervaldatums

Stel automatische verloop in:

1. Open formulier-instellingen → Delen
2. Schakel **Vervaldatum** in
3. Kies datum en tijd

Na verloop:

- Link geeft een error terug
- Bestaande antwoorden blijven bewaard
- Reactiveer door de datum te verwijderen of verlengen

### Toegangs-beperkingen

Beperk wie publieke formulieren kan benaderen:

1. Schakel **Toegang beperken** in
2. Selecteer toegestane gebruikers/groepen
3. Gebruikers moeten inloggen om in te dienen

Use-cases:

- Interne enquêtes met publieke-stijl URL
- Afdelings-specifieke formulieren

## Rate limiting

Bescherm tegen spam en misbruik.

### Inzending-rate-limits

Voor publieke formulieren:

- Maximum aantal inzendingen per minuut
- Per-IP-tracking
- Automatisch blokkeren van snelle inzendingen

### Configuratie

Rate-limits worden per formulier geconfigureerd:

1. Open formulier-instellingen → Beveiliging
2. Stel **Max-inzendingen per minuut** in
3. Default: 10 per minuut

### Geblokkeerde requests

Bij rate-limiting:

- Gebruiker ziet een vriendelijke foutmelding
- Moet wachten voor opnieuw proberen
- Legitieme gebruikers worden zelden geraakt

## Duplicaten voorkomen

### Methodes

Voorkom meerdere inzendingen van dezelfde persoon:

| Methode | Hoe het werkt | Beperkingen |
|---------|---------------|-------------|
| Browser-fingerprint | Track browser/device | Kan omzeild worden |
| Nextcloud-gebruiker | Eén per ingelogde gebruiker | Vereist login |
| Cookie-based | Slaat inzending-cookie op | Wissbaar door gebruiker |

### Configuratie

1. Open formulier-instellingen → Inzending
2. Schakel **Duplicaten voorkomen** in
3. Kies methode

## Databescherming

### Antwoord-data

Formulier-antwoorden bevatten potentieel gevoelige data:

**Aanbevelingen:**

- Verzamel alleen noodzakelijke informatie
- Informeer respondenten over data-gebruik
- Stel passende toegangs-permissies in
- Verwijder oude antwoorden regelmatig

### AVG-compliance

Voor EU-compliance:

1. **Privacy-melding** — voeg beschrijving toe die data-gebruik uitlegt
2. **Toestemming** — neem een toestemmings-checkbox op indien vereist
3. **Data-export** — gebruikers kunnen hun data opvragen
4. **Verwijdering** — verwijder antwoorden zodra niet meer nodig

### Data-retentie

Implementeer een retentie-beleid:

1. Exporteer en archiveer oude antwoorden
2. Verwijder antwoorden uit actieve formulieren
3. Documenteer je retentie-periode

## Encryptie

### Server-side encryptie

Het `.fvform`-bestand van FormVox werkt met Nextcloud's server-side encryptie: het wordt at rest versleuteld als elk
ander bestand, transparant voor gebruikers, onder de standaard bestand-encryptie-instellingen.

Sinds 1.6.0 staan de antwoorden en geheimen van een formulier (link-token, link-wachtwoord-hash, API-keys,
webhook-secrets) in de database, en staan de uploads van invullers, huisstijl-afbeeldingen, het ODT-sjabloon en
bewaarde PDF's in FormVox's app-data, buiten de bestanden van elke gebruiker. **Geen van beide wordt gedekt door
Nextclouds server-side bestands-encryptie.** Het `.fvform`-bestand zelf bevat alleen nog de formulier-definitie, dus
er staat weinig meer in dat bestands-encryptie kan beschermen.

De geheimen die in de database staan, worden verzegeld met Nextclouds eigen encryptiesleutel (`FormSecrets`, via
`ICrypto`) voordat ze worden weggeschreven, zodat de database rechtstreeks lezen ze niet in platte tekst blootlegt;
die bescherming geldt niet voor antwoorden of voor de bestanden in app-data.

### End-to-end-encryptie

FormVox is compatibel met E2E-encryptie:

- Formulieren kunnen in E2E-mappen worden opgeslagen
- Content wordt op de client versleuteld
- Server kan formulier-data niet lezen

**Let op:** publieke links werken niet met E2E-versleutelde formulieren. Omdat de antwoorden en geheimen van het
formulier niet meer met het bestand meereizen, geldt dit alleen nog voor de formulier-definitie.

## Nextclouds eigen bestands-lock

FormVox houdt per formulier een eigen lock (een databaserij of cache-key, met een houdtijd van twee minuten) zodat
maar één schrijver tegelijk een formulier aanraakt; `occ formvox:unlock <fileId>` bevrijdt die lock met de hand als
zijn houder werd gedood.

Daarnaast neemt Nextcloud, telkens als FormVox naar het `.fvform`-bestand zelf schrijft, zijn eigen bestands-lock, in
Redis of in de database. Wordt PHP gedood terwijl die bestandsschrijfactie loopt, dan kan Nextclouds lock blijven
staan tot hij verloopt; dat hangt af van de instelling `filelocking.ttl` in `config.php` (standaard: 3600 seconden,
dus één uur). Tot die tijd meldt de editor dat het formulier bezet is, en `occ formvox:unlock` helpt dan niet: dat
bevrijdt alleen FormVox's eigen lock, niet Nextclouds bestands-lock.

Gebeurt dit, dan kan een beheerder wachten tot Nextclouds lock zelf verloopt, of `filelocking.ttl` in `config.php`
verlagen (dit geldt voor bestands-locks in heel Nextcloud, niet alleen voor FormVox) en PHP-FPM/de webserver
herstarten zodat de nieuwe waarde ingaat.

## Audit-logging

### Wat wordt gelogd

FormVox logt beveiligings-relevante events:

- Formulier aanmaken/verwijderen
- Permissie-wijzigingen
- Publieke-link-creatie
- Mislukte authenticatie-pogingen

### Logs bekijken

Check het Nextcloud-log-bestand:

```bash
tail -f /path/to/nextcloud/data/nextcloud.log | grep formvox
```

## Beveiligings-best-practices

### Voor beheerders

1. **Houd up-to-date** — installeer FormVox-updates direct
2. **Bekijk permissies** — audit formulier-toegang regelmatig
3. **Monitor gebruik** — check op ongebruikelijke activiteit
4. **Schakel HTTPS in** — gebruik altijd versleutelde verbindingen
5. **Sterke wachtwoorden** — handhaaf wachtwoord-beleid

### Voor formulier-makers

1. **Minimale data** — verzamel alleen wat je nodig hebt
2. **Passende deling** — over-share formulieren niet
3. **Wachtwoord-bescherming** — gebruik voor gevoelige formulieren
4. **Vervaldatums** — stel in voor tijdelijke formulieren
5. **Bekijk antwoorden** — verwijder zodra niet meer nodig

### Voor publieke formulieren

1. **Rate limiting** — altijd inschakelen
2. **Vervaldatum** — stel redelijke termijnen in
3. **Wachtwoorden** — gebruik voor gevoelige content
4. **CAPTCHA** — overweeg voor formulieren met veel verkeer (indien beschikbaar)

## Incident response

### Vermoeden van data-breach

Als je vermoedt dat er ongeautoriseerde toegang is geweest:

1. **Schakel delen uit** — verwijder publieke links direct
2. **Bekijk logs** — check op verdachte activiteit
3. **Exporteer data** — bewaar een kopie voor onderzoek
4. **Notificeer** — informeer betrokken gebruikers indien vereist
5. **Reset** — wijzig wachtwoorden, bekijk permissies

### Spam/misbruik

Als een formulier wordt misbruikt:

1. **Schakel rate-limiting in** — verminder inzendingen per minuut
2. **Voeg wachtwoord toe** — vereist authenticatie
3. **Beperk toegang** — limiteer tot bekende gebruikers
4. **Verwijder spam** — verwijder ongewenste antwoorden
5. **Schakel tijdelijk uit** — indien nodig

## Volgende stappen

- Bekijk [Configuratie](configuration.md)-opties
- Check [API-beveiliging](../architecture/api-reference.md)
- Lees [Architectuur-overzicht](../architecture/overview.md)
