# Software Bill of Materials

`sbom.cdx.json` is a manually compiled SBOM in CycloneDX 1.6 format. It covers the source code shipped under `src/`, including backends that may not be active in a particular installation. The Git tree hash of `src/` is recorded in the SBOM as `inventory:source-tree` (currently `7b72f81232bbacf38cb1c99185ec914575ff6b05`). The tree combines the upstream Z-Push `2.7.x` development line, the upstream `2.7.7` release and local JMAP changes, so no single upstream commit identifies it; the tree hash is authoritative. The SBOM is a snapshot and does not guarantee that all copied code has been identified.

Versions of modified libraries may refer to the original implementation and do not mean that the code is identical to that upstream release. Unknown versions are omitted. License information is indicative; also consult the license texts in the source files and `LICENSE`, including the additional trademark terms.

The SBOM does not inventory the PHP version and extensions actually installed, operating system packages, configuration, or the external Stalwart server. Create a separate inventory for a specific installation or built Debian/RPM package. The root `composer.json` requires PHP 7.4 or later; there is no Composer lockfile recording installed versions.

Review the SBOM with every change and update it when components, dependencies, versions, licenses, or scope change. For changes under `src/`, also update the tree hash: stage the source changes and run `git rev-parse "$(git write-tree):src"`. An optional additional scan with Syft is `syft ./src -o cyclonedx-json`; such a scan may miss manually copied PHP code and therefore does not replace manual review.
