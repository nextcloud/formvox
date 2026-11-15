# Installatie-gids

Deze gids beschrijft het installeren en updaten van FormVox op je Nextcloud-server.

## Vereisten

### Nextcloud-versie

- **Minimum:** Nextcloud 32
- **Maximum:** Nextcloud 35
- **Aanbevolen:** laatste stabiele release

### PHP-versie

- **Minimum:** PHP 8.2
- **Aanbevolen:** PHP 8.2 of hoger

### Server-vereisten

- Standaard Nextcloud-server-setup
- Voldoende schijfruimte voor formulier-bestanden, uploads en bewaarde PDF's
- MySQL, MariaDB of PostgreSQL aanbevolen: FormVox bewaart antwoorden en geheimen in Nextclouds database, en SQLite
  verwerkt maar één schrijfactie tegelijk, waardoor veel invullers op hetzelfde moment daarop errors kunnen krijgen.
  Nextclouds eigen setup-checks (**Instellingen → Beheer → Overzicht**) waarschuwen hiervoor bij SQLite en linken naar
  `occ db:convert-type` om over te zetten.

## Installatie-methodes

### Vanuit de App Store (aanbevolen)

1. Log in als beheerder in Nextcloud
2. Ga naar **Apps** (klik op je profiel → Apps)
3. Zoek naar "FormVox"
4. Klik op **Download en inschakelen**

### Handmatige installatie

1. Download de laatste release vanaf [GitHub](https://github.com/nextcloud/formvox/releases)

2. Pak uit in je Nextcloud-apps-directory:

   ```bash
   cd /path/to/nextcloud/apps
   tar -xzf formvox-x.x.x.tar.gz
   ```

3. Stel correcte permissies in:

   ```bash
   chown -R www-data:www-data formvox
   ```

4. Schakel de app in:

   ```bash
   sudo -u www-data php occ app:enable formvox
   ```

   Of inschakelen via de web-interface in **Apps** → **Uitgeschakelde apps**.

## Na de installatie

### Installatie verifiëren

1. Check dat de app als ingeschakeld in **Apps** staat
2. Zoek het FormVox-icoon in de navigatiebalk
3. Maak een test-formulier om de functionaliteit te verifiëren

### MIME-type-registratie

FormVox registreert het `.fvform`-bestandstype automatisch tijdens installatie. Een formulier toont het FormVox-icoon (een paars klembord) via de previews van Nextcloud, dus in de lijst en het raster van Bestanden, in zoekresultaten, activiteit en shares. Staan previews uit (`enable_previews` op `false`), dan tonen formulieren het gewone bestandsicoon van Nextcloud. In zoekresultaten op Nextcloud 32 tot en met 34 schemert dat grijze icoon licht door achter het formuliericoon.

Na een update die het icoon verandert, vervangt een achtergrondtaak eenmalig de opgeslagen thumbnails van bestaande formulieren. Tot die taak heeft gedraaid, en tot een dag lang in een browser die het oude plaatje nog heeft, kan een formulier het oude icoon tonen. Als bestanden niet het juiste icoon tonen:

1. Voer de repair-stap uit:

   ```bash
   sudo -u www-data php occ maintenance:repair
   ```

2. Leeg de file-cache:

   ```bash
   sudo -u www-data php occ files:scan --all
   ```

3. Blijft de oude thumbnail staan, verwijder dan alle opgeslagen previews; Nextcloud maakt ze opnieuw aan wanneer ze nodig zijn:

   ```bash
   sudo -u www-data php occ preview:cleanup
   ```

## FormVox updaten

### Via App Store

1. Ga naar **Apps**
2. Check op updates
3. Klik op **Updaten** naast FormVox

### Handmatige update

1. Download de nieuwe versie
2. Schakel de app uit:

   ```bash
   sudo -u www-data php occ app:disable formvox
   ```

3. Vervang de app-map:

   ```bash
   rm -rf /path/to/nextcloud/apps/formvox
   tar -xzf formvox-x.x.x.tar.gz -C /path/to/nextcloud/apps/
   ```

4. Schakel de app weer in:

   ```bash
   sudo -u www-data php occ app:enable formvox
   ```

5. Draai upgrades:

   ```bash
   sudo -u www-data php occ upgrade
   ```

## Deïnstallatie

### Data behouden

Om te deïnstalleren maar formulier-bestanden te behouden:

1. Schakel de app uit:

   ```bash
   sudo -u www-data php occ app:disable formvox
   ```

2. Verwijder de app-map:

   ```bash
   rm -rf /path/to/nextcloud/apps/formvox
   ```

Formulier-bestanden (`.fvform`) blijven in de bestandsopslag van gebruikers; hun antwoorden, geheimen en bestanden van
invullers blijven in de database en FormVox's app-data, want beide zijn app-data die het uitschakelen van de app niet
raakt.

### Volledige verwijdering

Om alles te verwijderen, inclusief antwoorden en geüploade bestanden:

1. Verwijder alle `.fvform`-bestanden uit gebruikers-opslag
2. Volg de stappen hierboven om de app te deïnstalleren
3. Het volledig verwijderen van de eigen data van de app (database-tabellen, app-data) is aan Nextclouds eigen
   app-verwijder-tooling, en valt hier buiten; uitschakelen en de app-map verwijderen zoals hierboven is normaal
   gesproken genoeg.

## Problemen oplossen

### App verschijnt niet

Als FormVox niet verschijnt na installatie:

1. Check dat de PHP-versie aan de vereisten voldoet:

   ```bash
   php -v
   ```

2. Check de Nextcloud-versie:

   ```bash
   sudo -u www-data php occ status
   ```

3. Check dat de app is ingeschakeld:

   ```bash
   sudo -u www-data php occ app:list | grep formvox
   ```

4. Check logs op fouten:

   ```bash
   tail -f /path/to/nextcloud/data/nextcloud.log
   ```

### Bestandstype niet herkend

Als `.fvform`-bestanden niet correct openen:

1. Voer maintenance-repair uit:

   ```bash
   sudo -u www-data php occ maintenance:repair
   ```

2. Werk de bestandstypen in de database bij:

   ```bash
   sudo -u www-data php occ maintenance:mimetype:update-db
   ```

   FormVox heeft `occ maintenance:mimetype:update-js` niet nodig; dat commando herschrijft een ondertekend core-bestand.

### Permissie-issues

Als je permissie-errors ziet:

1. Check app-map-permissies:

   ```bash
   ls -la /path/to/nextcloud/apps/formvox
   ```

2. Fix eigenaarschap:

   ```bash
   chown -R www-data:www-data /path/to/nextcloud/apps/formvox
   ```

## Volgende stappen

- [Configureer FormVox](configuration.md)-instellingen
- Stel [Beveiliging](security.md)-opties in
- Maak je [eerste formulier](../getting-started.md)
