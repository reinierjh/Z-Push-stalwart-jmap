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

Deploy gebeurt via git, niet via scp:

1. Op de server: `git pull` in de checkout.
2. En dan: `sudo systemctl restart php8.2-fpm`.

## Project-achtergrond

- Z-Push met JMAP-backend tegen Stalwart.
- Bekende fix: raw MIME alleen leveren als `mimeIsClean()`; anders `buildCleanMime()` (UTF-8 MIME uit JMAP bodyValues). Zie `src/backend/jmap/jmap.php`.
- Linten kan met `php -l <file>` (of de gegenereerde lint-run vanuit de repo).