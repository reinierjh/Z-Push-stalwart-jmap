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

1. Back-up serverconfig: `cp -av /usr/share/z-push/config.php ~/zpush-config-backup/` en idem `backend/jmap/config.php`.
2. `git clone -b develop https://github.com/reinierjh/Z-Push-stalwart-jmap.git /opt/z-push-git`
3. `rsync -a --delete --exclude '/config.php' --exclude '/backend/jmap/config.php' /opt/z-push-git/src/ /usr/share/z-push/`
4. Controle: `cmp` op beide config-bestanden tegen de back-up (moet OK zijn).
5. `sudo systemctl restart php8.2-fpm`.

Elke volgende update:

1. `cd /opt/z-push-git && git pull`
2. `rsync -a --delete --exclude '/config.php' --exclude '/backend/jmap/config.php' /opt/z-push-git/src/ /usr/share/z-push/`
3. `sudo systemctl restart php8.2-fpm`

## Project-achtergrond

- Z-Push met JMAP-backend tegen Stalwart.
- Bekende fix: raw MIME alleen leveren als `mimeIsClean()`; anders `buildCleanMime()` (UTF-8 MIME uit JMAP bodyValues). Zie `src/backend/jmap/jmap.php`.
- Linten kan met `php -l <file>` (of de gegenereerde lint-run vanuit de repo).