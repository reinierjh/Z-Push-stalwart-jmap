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

## Server-tuning (php8.2-fpm)

De FPM-pool is géén onderdeel van de repo en wordt dus niet door de rsync-deploy meegenomen; pas hem handmatig aan in `/etc/php/8.2/fpm/pool.d/www.conf`.

Symptoom dat hieraan voorafging: Z-Push "gebruikte" 8 GB en liet de server met 6 GB omvallen. Twee oorzaken, beide in de pool:

1. `pm = static` met `pm.max_children = 50` (het bestand bevatte dubbele `pm`-directives; de laatste wint). Daardoor staan er permanent 50 workers klaar, elk ~162 MB RSS.
2. `memory_limit = -1`. Z-Push leest die waarde en breekt een folder-sync af bij 90% ervan (`Request::IsRequestMemoryLimitReached()`, gebruikt in `lib/request/sync.php`). Met `-1` staat die chunking uit en loopt één verzoek door tot het proces of het systeem omvalt.

Werkende configuratie (achteraan `www.conf`, alle bestaande `pm`-regels uitcommentariëren met `sed -i 's/^pm/;pm/'`):

```ini
pm = ondemand
pm.max_children = 25
pm.process_idle_timeout = 10s
pm.max_requests = 500
php_admin_value[memory_limit] = 256M
```

- `ondemand` laat workers na 10 s idle sterven: rust ≈ 3 processen / 0,3 GB in plaats van 51 / 8,1 GB.
- `max_children = 25` past bij 7 devices; iOS opent meerdere parallelle verzoeken per device (tot ~7 gezien). 25 × 162 MB ≈ 4 GB plafond. Lager (15) kan, maar dan ontstaan wachtrijen en 504's tijdens een full sync.
- `memory_limit = 256M` zet de chunking-guard op ~230 MB. Een full sync wordt dan in meer, kleinere verzoeken geknipt; `memory='…/230.00 MiB'` in de log is de guard die aanslaat, geen fout.
- `php_admin_value` geldt alleen voor FPM, niet voor de CLI, dus `z-push-admin` en `tools/jmap-tests/run.php` blijven ongemoeid.

Meten onder belasting (niet in rust):

```sh
ps -o rss= -C php-fpm8.2 | awk '{s+=$1;n++} END {printf "kinderen=%d totaal=%.1f GB gem=%.0f MB\n", n, s/1048576, (n?s/n/1024:0)}'
```

Verwacht in rust 1–3 kinderen / <0,5 GB, tijdens een full sync 5–15 kinderen / 1–2,5 GB. Verlaag de VM pas naar 6 GB nadat een volledige sync met deze instellingen is geslaagd.

## Project-achtergrond

- Z-Push met JMAP-backend tegen Stalwart.
- Bekende fix: raw MIME alleen leveren als `mimeIsClean()`; anders `buildCleanMime()` (UTF-8 MIME uit JMAP bodyValues). Zie `src/backend/jmap/jmap.php`.
- Linten kan met `php -l <file>` (of de gegenereerde lint-run vanuit de repo).

## SBOM bijhouden

- Controleer bij iedere wijziging of componenten, afhankelijkheden, versies, licenties of de inventarisatiescope veranderen en werk zo nodig `sbom.cdx.json` en `SBOM.md` bij in dezelfde commit.
- Bij elke wijziging onder `src/`: werk ook de `inventory:source-tree`-waarde bij naar de Git-treehash van de gestagede `src/` (`git rev-parse "$(git write-tree):src"`). Verwijder een verouderde `inventory:source-commit`; een commit kan zijn eigen hash niet bevatten. Pas een genoemde broncommit in `SBOM.md` overeenkomstig aan.
- Valideer de SBOM als JSON en zo mogelijk tegen het CycloneDX 1.6-schema. Claim geen onbekende versies of niet-gecontroleerde geïnstalleerde afhankelijkheden.

## Upstream Z-Push bijwerken

Deze repo is een fork van depeo's Stalwart-JMAP-fork. Die fork staat op de upstream **ontwikkelbranch `2.7.x`** (2.7.3 plus dev-commits, o.a. Find-support), niet op een release. De upstream releases 2.7.4–2.7.7 zijn maintenance-branches die vanaf 2.7.3 zijn afgesplitst en alleen fixes backporten.

Gevolg: `src/version` noemt de laatst gemergede upstream release, maar de tree bevat ook de `2.7.x`-devfeatures en de JMAP-laag. Geen enkele upstream-commit beschrijft de tree; de `inventory:source-tree`-hash in de SBOM is leidend.

Nooit bestanden overschrijven (geen tarball/rsync van upstream over `src/`): dat wist `src/backend/jmap/` en de core-patches. Altijd mergen.

Eenmalig:

1. `git remote add zhub https://github.com/Z-Hub/Z-Push.git`
2. Optioneel, voor fixes van depeo: `git remote add depeo https://github.com/depeo/Z-Push-stalwart-jmap.git`

Per upstream release:

1. `git fetch zhub --tags`
2. `git merge <tag>` (bv. `git merge 2.7.7`) op `develop`
3. Conflicten: upstream-fix overnemen waar die een fork-patch vervangt of omvat (zoals de PHP 8-guards in `streamer.php`); beide kanten houden als ze onafhankelijk zijn (zoals `ICONV_OPTION` en `DAV_SSL_VERIFY`). JMAP-gedrag nooit laten vallen.
4. `php -l` op elk gewijzigd bestand en `php tools/jmap-tests/run.php`.
5. Nieuwe config-opties uit upstream handmatig in de server-`config.php` zetten; die valt buiten de rsync-deploy.
6. SBOM bijwerken, committen, pushen, deployen volgens de deploy-procedure hierboven.

Niet doen: rebasen (herschrijft 100+ commits en herhaalt alle conflicten per release), `git pull` van upstream `2.7.x` (dat is de dev-branch, geen release), of losse bestanden kopiëren.

## Contributen (upstream Z-Push regels)

- PRs en issues indienen via https://github.com/Z-Hub/Z-Push (of de desbetreffende fork-keten).
- Alle bijdragen vallen onder AGPLv3. Bij elke PR expliciet en publiekelijk één van deze verklaringen opnemen:
  - `Released under the GNU Affero General Public License (AGPL), version 3.`
  - `Released under the GNU Affero General Public License (AGPL), version 3 and Trademark Additional Terms.`
- Code of Conduct van Z-Push nazien.
