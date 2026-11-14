# Software Bill of Materials

`sbom.cdx.json` is een handmatig samengestelde SBOM in CycloneDX 1.6-formaat. De inventarisatie betreft de meegeleverde broncode onder `src/`, inclusief backends die bij een installatie mogelijk niet actief zijn. De Git-treehash van `src/` staat in de SBOM als `inventory:source-tree`; de oorspronkelijke inventarisatie betreft commit `12124620cafa58c8cd28f8ee533d147621bb3d5a`. De SBOM is een momentopname en geen garantie dat alle gekopieerde code is gevonden.

Versies van aangepaste bibliotheken kunnen verwijzen naar de oorspronkelijke implementatie en betekenen niet dat de code identiek is aan die upstream-release. Onbekende versies zijn niet ingevuld. Licentiegegevens zijn indicatief; raadpleeg ook de licentieteksten in de bronbestanden en `LICENSE`, inclusief de aanvullende merkbepalingen.

De SBOM bevat geen inventaris van de daadwerkelijk geïnstalleerde PHP-versie en extensies, besturingssysteempakketten, configuratie, of de externe Stalwart-server. Stel voor een concrete installatie of een gebouwd Debian/RPM-pakket aanvullend een eigen inventarisatie op. De root-`composer.json` vereist PHP 7.4 of hoger; er is geen Composer-lockfile die geïnstalleerde versies vastlegt.

Controleer de SBOM bij iedere wijziging en werk hem bij wanneer componenten, afhankelijkheden, versies, licenties of de scope veranderen. Werk bij wijzigingen onder `src/` ook de treehash bij: stage de bronwijzigingen en gebruik `git rev-parse "$(git write-tree):src"`. Een optionele aanvullende scan met Syft is `syft ./src -o cyclonedx-json`; zo'n scan kan handmatig gekopieerde PHP-code missen en vervangt daarom de handmatige controle niet.
