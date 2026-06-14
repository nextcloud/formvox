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

FormVox werkt met Nextcloud's server-side encryptie:

- Bestanden encrypted at rest
- Transparant voor gebruikers
- Standaard bestand-encryptie-instellingen gelden

### End-to-end-encryptie

FormVox is compatibel met E2E-encryptie:

- Formulieren kunnen in E2E-mappen worden opgeslagen
- Content wordt op de client versleuteld
- Server kan formulier-data niet lezen

**Let op:** publieke links werken niet met E2E-versleutelde formulieren.

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
