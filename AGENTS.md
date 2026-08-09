# AGENTS.md

## Git workflow (verplicht)

Alle wijzigingen lopen via git — nooit direct via scp of andere methodes.

1. **Bewerken**: wijzig `src/backend/jmap/*.php` in de werkdirectory.
2. **Commit**: commit automatisch na elke afgeronde (deel)taak. Komt duidelijk bij de wijzigingen.
   - Branch: `develop`
   - Identiteit: `reinierjh <34122078+reinierjh@users.noreply.github.com>` (al lokaal geconfigureerd)
   - Verwijder nooit secrets; controleer diff vooraf op credentials.
3. **Push**: `git push -u origin develop` naar de fork `https://github.com/reinierjh/Z-Push-stalwart-jmap.git`.
4. **Status altijd schoon** aan het einde van een sessie (working tree clean, up to date met origin/develop).

## Deploy

Deploy gebeurt via git, niet via scp. Let op: de repo heeft alles onder `src/`, de server-boom staat plat in `/usr/share/z-push` (zonder `src/`-pad). Deploy is dus: pull in een aparte clone + `rsync` van `src/` naar de server-boom, met de twee config-bestanden uitgesloten.

Eerste rollout (eenmalig):

1. Back-up serverconfig (unieke namen!): `cp -av /usr/share/z-push/config.php ~/zpush-config-backup/config.php` en `cp -av /usr/share/z-push/backend/jmap/config.php ~/zpush-config-backup/jmap_config.php`
2. `git clone -b develop https://github.com/reinierjh/Z-Push-stalwart-jmap.git /opt/z-push-git`
3. `rsync -a --delete --chown=www-data:www-data --exclude '/config.php' --exclude '/backend/jmap/config.php' /opt/z-push-git/src/ /usr/share/z-push/`
4. Controle: `cmp /usr/share/z-push/config.php ~/zpush-config-backup/config.php` en `cmp /usr/share/z-push/backend/jmap/config.php ~/zpush-config-backup/jmap_config.php` (beide moeten OK zijn).
5. `sudo systemctl restart php8.2-fpm`.

Elke volgende update:

1. `cd /opt/z-push-git && git pull`
2. `rsync -a --delete --chown=www-data:www-data --exclude '/config.php' --exclude '/backend/jmap/config.php' /opt/z-push-git/src/ /usr/share/z-push/`
3. `sudo systemctl restart php8.2-fpm`

## Project-achtergrond

- Z-Push met JMAP-backend tegen Stalwart.
- Bekende fix: raw MIME alleen leveren als `mimeIsClean()`; anders `buildCleanMime()` (UTF-8 MIME uit JMAP bodyValues). Zie `src/backend/jmap/jmap.php`.
- Linten kan met `php -l <file>` (of de gegenereerde lint-run vanuit de repo).

## Contributen (upstream Z-Push regels)

- PRs en issues indienen via https://github.com/Z-Hub/Z-Push (of de desbetreffende fork-keten).
- Alle bijdragen vallen onder AGPLv3. Bij elke PR expliciet en publiekelijk één van deze verklaringen opnemen:
  - `Released under the GNU Affero General Public License (AGPL), version 3.`
  - `Released under the GNU Affero General Public License (AGPL), version 3 and Trademark Additional Terms.`
- Code of Conduct van Z-Push nazien.