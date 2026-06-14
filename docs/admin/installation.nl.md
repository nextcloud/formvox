# Installatie-gids

Deze gids beschrijft het installeren en updaten van FormVox op je Nextcloud-server.

## Vereisten

### Nextcloud-versie

- **Minimum:** Nextcloud 28
- **Maximum:** Nextcloud 33
- **Aanbevolen:** laatste stabiele release

### PHP-versie

- **Minimum:** PHP 8.2
- **Aanbevolen:** PHP 8.2 of hoger

### Server-vereisten

- Geen aanvullende database-vereisten (file-based opslag)
- Standaard Nextcloud-server-setup
- Voldoende schijfruimte voor formulier-bestanden

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

FormVox registreert het `.fvform`-bestandstype automatisch tijdens installatie. Als bestanden niet het juiste icoon tonen:

1. Voer de repair-stap uit:

   ```bash
   sudo -u www-data php occ maintenance:repair
   ```

2. Leeg de file-cache:

   ```bash
   sudo -u www-data php occ files:scan --all
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

Formulier-bestanden (`.fvform`) blijven in de bestandsopslag van gebruikers.

### Volledige verwijdering

Om alles inclusief formulier-bestanden te verwijderen:

1. Verwijder alle `.fvform`-bestanden uit gebruikers-opslag
2. Volg de stappen hierboven om de app te deïnstalleren

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

2. Leeg caches:

   ```bash
   sudo -u www-data php occ maintenance:mimetype:update-db
   sudo -u www-data php occ maintenance:mimetype:update-js
   ```

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
