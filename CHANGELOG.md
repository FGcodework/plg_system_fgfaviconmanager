# Changelog — plg_system_fgfaviconmanager

## 1.16.7 (2026-10-13)
- **SVG safety pre-scan now fails closed when `ext-dom` is missing.** Previously, without the PHP DOM extension the plugin fell back to a raw-byte regex that cannot see URL schemes hidden in XML character references and does not inspect CSS, so the safety policy silently depended on an extension being installed. The regex fallback is removed: without `ext-dom` an SVG is not rasterised at all (nothing untrusted reaches ImageMagick or its SVG delegate), the vector icon is still published, only the PNG/ICO/Apple fallbacks are skipped, and the reason is recorded for Debug Mode.
- `isSvgSafeToRasterize()` asks a new overridable `domAvailable()`, so the fail-closed path is covered by tests without uninstalling the extension.
- README and the Info tab (en-GB, sk-SK) now list the PHP DOM extension next to Imagick/rsvg-convert as a requirement for SVG fallbacks.
- 3 new unit tests (benign SVG accepted with DOM; benign SVG rejected without DOM with an `ext-dom` error recorded - failed before the change; obfuscated `&#104;ttp://` scheme still rejected): 63/63 pass. Not verified on a server with Imagick in this environment, only the pre-scan decision itself.

## 1.16.6 (2026-10-13)
- **PWA manifest is now written atomically and self-heals.** `ensureManifest()` wrote the manifest straight into its final file and treated "file exists" as "file is valid". Because the file name is a hash of the content, a partial or zero-byte file left by a concurrent request, a crash or a full disk had the correct name and was served indefinitely. Now:
  - the whole JSON is written to a uniquely named temp file in the same folder, the number of bytes written is verified, and only then it is `rename()`d onto the final name (new `writeFileAtomic()`; a failure removes the temp file and the manifest link is simply omitted for that request);
  - an existing manifest is accepted only if it matches the expected content byte for byte (size first, hash only if the size matches, `isManifestIntact()`); a truncated, empty or corrupted file is rewritten on the next request.
- The markup cache file (`markup-*.json`) uses the same helper, so its write now also verifies the byte count.
- 5 new unit tests running the real `ensureManifest()` (truncated, zero-byte and same-size-corrupted manifests are repaired - these 3 failed before the fix; an intact manifest is not rewritten; no temp leftovers): 60/60 pass.

## 1.16.5 (2026-10-13)
- **Cache publishing no longer replaces an already complete folder under concurrent requests.** `publishBuild()` swapped any existing final folder out to `.old-*` before moving the new staging folder in. When two requests generated the same version at once, the slower one threw away the complete set the faster one had just published, and between the two `rename()` calls the final icon URLs returned 404. `publishBuild()` now takes a completeness check and re-validates the existing folder at publish time: if it is already complete, the new staging copy is discarded and the existing folder is left untouched. The swap is used only for a missing/zero-byte/damaged set. Wired up for all five publish sites (raster, SVG, maskable x2, splash).
- Measured: 8 parallel processes generating the same icon set while a poller watched for the published files (6 rounds): the previous code lost the published folder 441-931 times per round (3669 polls total) after it first appeared, the new code 0.
- 4 new unit tests (complete folder kept and not renamed, incomplete folder replaced, no-check legacy behaviour, publish into empty slot): 55/55 pass.

## 1.16.4 (2026-10-13)
- **Fixed light SVG not being scoped to the light color scheme when a dark variant is configured.** Raster icons already got `media="(prefers-color-scheme: light)"` next to a dark variant, but an SVG main source was emitted without `media`. In a dark theme both SVG icons were then eligible and the browser could pick the light one. The light SVG link now carries `media="(prefers-color-scheme: light)"` whenever a dark variant exists, with or without PNG/ICO fallbacks; without a dark variant no `media` attribute is added.
- Isolated change in `buildHeadMarkup()`: image generator and icon cache format are untouched. The markup cache key now carries a `MARKUP_FORMAT` number (bumped to 2), so markup cached by an older version is dropped automatically after the update - no manual "Clear Icon Cache" needed. Bump it whenever the markup output changes for unchanged settings.
- 2 new unit tests running the real `buildHeadMarkup()` against SVG fixtures (the dark-variant test failed before the fix): 51/51 pass.

## 1.16.3 (2026-10-13)
- **Fixed incomplete HTML markup cache key** (`computeMarkupCacheKey()`). The key was built from each source image's mtime and size only, so switching a slot (favicon, dark favicon, admin favicon, Apple touch icon) from file A to file B could keep the same key when both files shared size and mtime, and the plugin then served the cached `<link>`/`<meta>` markup with icon URLs generated from file A. The key now includes the resolved path of every source.
- The key also includes `Uri::root()` and `Uri::root(true)`, so moving the site to another domain or into/out of a subfolder no longer serves cached markup with old icon URLs or an old manifest `id` / `start_url` / `scope`. (The manifest file itself is content-hashed, so a new file was already generated; only the cached markup could point at the old one.)
- No extra I/O: still one `stat()` per source, no hashing of image contents on requests.
- 5 new unit tests (reproduced the bug first: 4 of them failed before the fix): 49/49 pass.

## 1.16.2 (2026-10-12)
- **Joomla 5 support.** The plugin now installs and updates on Joomla 5.x as well as 6.x: `updates.xml` target platform is `[56]\..*` and the minimum PHP is 8.1 (the Joomla 5 minimum). The code itself only uses APIs available in both versions; the J6-only restriction was purely in the update metadata.
- Replaced the deprecated `Joomla\CMS\Filesystem\Folder` (scheduled for removal in Joomla 7) with plain PHP: new `IconGenerator::makeDir()` and `IconGenerator::removeTree()` (symlinks are unlinked, never followed). Behaviour is unchanged.
- 3 new unit tests (nested/idempotent create, blocked path, recursive delete that does not follow symlinks): 44/44 pass. Icon generation + cache build re-checked end to end with GD.

## 1.16.1 (2026-10-12)
- Initial public repository release (first version published on GitHub; 1.16.0 was the internal rename build).
- Added the FG series "Plugin" tab: unlabeled `basic` fieldset containing only the Ko-fi / fgcodework.github.io support note; the former first tab (favicon source + theme color) is now the labelled `settings` fieldset "Frontend Favicon".
- Added `<updateservers>` (raw `updates.xml` on `master`) and `<inlinehelp button="show"/>`.
- **Fixed sk-SK language file:** `PLG_SYSTEM_FGFAVICONMANAGER_FIELD_QUICKSTART_DESC` contained an unescaped ASCII `"` that terminated the string early and made the whole file fail `parse_ini_file()` (introduced in 1.15.1). Inner quotes in the QUICKSTART (en-GB) and MASKABLE (sk-SK) descriptions are now typographic quotes, so every `.ini` parses cleanly (74 keys in both languages).
- Repo files: README, LICENSE (GPL-2.0), `.gitignore`, `.gitattributes` (`*.ini text eol=lf`), `updates.xml`, `assets/logo.{svg,png}`.

## 1.16.0 (2026-10-12)
- **Renamed to "FG Favicon Manager"** to match the FG plugin series. This is a new extension identity, not an in-place upgrade: element `faviconmanager` -> `fgfaviconmanager`, namespace `Joomla\Plugin\System\FaviconManager` -> `FG\Plugin\System\Fgfaviconmanager`, class `Fgfaviconmanager`, manifest `fgfaviconmanager.xml`, language files `plg_system_fgfaviconmanager.ini/.sys.ini`, language keys `PLG_SYSTEM_FGFAVICONMANAGER_*`, AJAX event `onAjaxFgfaviconmanager`, icon cache folder `media/plg_system_fgfaviconmanager/cache/`.
- Display name is "System - FG Favicon Manager" in both en-GB and sk-SK (brand name, not translated). Added `authorUrl` to the manifest.
- Migration: uninstall the old `plg_system_faviconmanager` first (otherwise both plugins would inject favicons), install this one and re-enter the settings (parameters are stored per extension and do not carry over). The old `media/plg_system_faviconmanager/` folder can be deleted.
- No functional changes. Tests renamed accordingly; 41/41 pass.

## 1.15.1 (2026-08-13)
- **Doplnený „Quick Start" popis do tabu Info.** Doteraz bolo v Info tabe len jedno technické poznámkové pole (veľkosti ikon, GD/Imagick, SVG požiadavky) — užitočná referencia, ale nič, čo by bežnému správcovi vysvetlilo, čo plugin robí a ako s ním jednoducho začať.
- Nový text stojí ako prvý v tabe: čo plugin robí v bežnej reči, 3-krokový postup (vyber obrázok → voliteľne farba → ulož), vysvetlenie, že ostatné taby majú rozumné predvolené hodnoty a netreba sa nimi zaoberať, a odkaz na Debug Mode pri probléme. Pôvodná technická poznámka zostáva pod ním, premenovaná na „Technical Reference" pre jasné odlíšenie od úvodného textu.
- Preložené do en-GB aj sk-SK, 73/73 kľúčov zhoda v oboch jazykoch.

## 1.15.0 (2026-08-13)
- **UX: prvý tab zjednodušený z 7 na 2 polia.** Fieldsety v Joomla plugin edite sa zobrazujú ako samostatné taby, takže problém nebol v celkovom počte nastavení (24 naprieč 6 tabmi), ale v tom, že prvý tab „Frontend Favicon" — ten, ktorý uvidí každý ako prvý — mal 7 polí, z ktorých len 2 (Favicon Source, Theme Color) sú skutočne potrebné pre bežné použitie.
- Nový tab **„Advanced"** preberá zvyšných 5 polí: Dark Mode Favicon, Theme Color (dark), Apple Touch Icon override, Template Conflict Strip, Debug Mode. Nič sa nemaže ani nemení funkčne — len sa to prerozdeľuje, aby prvý dojem zodpovedal typickému „nastav faviconu a ulož" prípadu použitia.
- Bežný správca teraz po otvorení nastavení vidí len favicon obrázok a farbu témy; zvyšok (admin favicon, PWA/manifest, iOS web-app, maskable ikony, pokročilé strip/debug voľby, tlačidlo na vyčistenie cache) zostáva dostupný v ostatných taboch s rozumnými defaultmi, ale nevynucuje sa do zorného poľa.
- Overené: presunom sa nestratilo ani neduplikovalo žiadne z 24 polí (kontrola cez XML parser), jazykové kľúče zostávajú 1:1 zhodné v en-GB aj sk-SK, a celý PHPUnit balík (41 testov) prechádza bez zmeny, keďže ide výlučne o reorganizáciu XML, nie o zmenu logiky.

## 1.14.0 (2026-08-13)
- **Pridaný PHPUnit test balík** (`tests/`) pokrývajúci `resolveSourcePath()`, `containSize()`, `normalizeColor()` a `isSvgSafeToRasterize()` — 41 testov, 54 assertions, všetky prechádzajú.
- Testy požadujú **skutočné produkčné súbory** priamo (`require`), nie upravené kópie — `tests/bootstrap.php` rieši jedinú prekážku (`FaviconManager extends CMSPlugin implements SubscriberInterface`, čo mimo Joomla neexistuje) minimálnymi stub triedami, dostatočnými len na to, aby sa trieda dala deklarovať; samotné testované metódy sa volajú cez Reflection na inštancii vytvorenej bez konštruktora, keďže žiadna z testovaných metód nepotrebuje živý Joomla dispatcher ani params Registry.
- `IconGenerator.php` nepotrebuje žiadny stub vôbec — je to obyčajná trieda bez Joomla predka.
- Fixture súborový strom (`tests/bootstrap.php`) zámerne obsahuje presne tie dva prípady, na ktorých padá naivná path-traversal kontrola: symlink smerujúci mimo `JPATH_ROOT` a sibling priečinok so zdieľaným reťazcovým prefixom (`webroot` vs `webroot-other`).
- Balík odchytáva reálne regresie, nielen prechádza naprázdno — overené zámerným vrátením path-traversal kontroly na slabšiu verziu (`strpos` bez hranice oddeľovača): príslušný test okamžite zlyhal s presným popisom problému, presne tej triedy bypassu, ktorá sa v tejto konverzácii riešila skôr.
- `tests/`, `phpunit.xml.dist` a `composer.json` nie sú deklarované v `<files>` manifestu, takže sa pri inštalácii pluginu na server nekopírujú — zostávajú len v ZIP-e pre lokálny vývoj (`phpunit` alebo `vendor/bin/phpunit` po `composer install --dev`).
- PHPUnit inštalovaný cez systémový `apt` balík (nezávisle od Composeru/Packagistu), keďže ten v tomto vývojovom prostredí nie je sieťovo dostupný — `composer.json` je pripravený pre bežné vývojárske prostredie s prístupom na Packagist.

## 1.13.0 (2026-08-13)
- **SVG bezpečnostná predkontrola prepísaná z regexu na `DOMDocument`.** Nie plný allowlist elementov s XML validáciou (to by pre favicon bolo naozaj zbytočné), ale rozumný medzikrok: parser namiesto pattern-matchingu nad surovými bajtmi, ktorý rieši presne tie triedy chýb, ktoré regex principiálne nevie zachytiť.
- **Tri reálne obídenia regexu, potvrdené pred opravou aj po nej:** URL schéma zakódovaná cez číselné XML entity (`href="&#104;ttp://evil..."` — regex vidí len surové bajty, nie dekódovanú hodnotu, DOM ju dekóduje automaticky ako súčasť bežného parsovania), externý zdroj cez CSS `@import`/`url()` v `<style>` elemente alebo `style=""` atribúte (regex CSS vôbec nekontroloval), a inline event handlery (`onload`, `onerror` a pod. — regex kontroloval len `<script>` tag, nie atribúty).
- Nová kontrola naviac odmieta akýkoľvek `DOCTYPE` bezpodmienečne (namiesto hľadania `<!ENTITY` v texte) — legitímna SVG favicon ho nikdy nepotrebuje, a je to štrukturálne, čo XXE/billion-laughs útokom zamedzí, nie hľadanie jednej konkrétnej syntaxe deklarácie.
- Fallback na pôvodný regex zostáva pre prípad, že `ext-dom` na serveri chýba (v jednom z testovacích prostredí sa to reálne stalo — nie je to len teoretická poistka) — funguje ďalej, len s menšou presnosťou, čo je aj v kóde aj v debug hlásení zdokumentované.
- Overené na 20 prípadoch: 6 legitímnych SVG techník (interný fragment odkaz cez `use`, `data:` URI, `<style>` bez `url()`, `style=""` bez `url()`) prechádza bez zmeny, 14 škodlivých/nevalidných vstupov (vrátane všetkých 3 potvrdených obídení, malformed XML, a zlého root elementu) sa odmieta. Globálny stav `libxml_use_internal_errors()` sa vždy korektne obnoví, aby to neovplyvnilo iný Joomla kód spracúvajúci XML v tom istom requeste.

## 1.12.0 (2026-08-13)
- **Nový prepínač: „Use Frontend Dark Mode Favicon in Admin"** vo fieldsete Administrator Favicon. Doteraz sa dark-mode variant v administrácii nepoužíval vôbec (natvrdo vynulovaný). Teraz sa dá zapnúť — znovupoužije rovnaký súbor nastavený v „Frontend Favicon" → Dark Mode Favicon, aplikovaný cez `prefers-color-scheme` rovnako ako na frontende.
- Predvolene vypnuté a funguje spolu s vlastnou Administrator Favicon (ak je nastavená, dark variant sa naviaže na ňu, nie na frontend ikonu) — administrátor si sám posúdi, či to vizuálne dáva zmysel.
- Maskable ikony sa v admin naďalej nepoužívajú vôbec — to je zámerné a nemenené, keďže PWA/manifest koncept sa na administráciu nevzťahuje.
- Nový parameter doplnený do cache kľúča zostaveného markupu (viď 1.11.0), takže zapnutie/vypnutie prepínača cache správne invaliduje.
- Overené na 7 kombináciách (frontend, admin vypnuté/zapnuté, s/bez vlastnej admin ikony, bez nastaveného dark zdroja, a Joomla radio pole ako string `"1"` namiesto int) — všetky správne.

## 1.11.1 (2026-08-13)
- **Dokumentácia: WebP a AVIF explicitne spomenuté ako podporované zdrojové formáty.** Fungovali už predtým (kód nikdy nerozlišoval podľa prípony, len podľa toho, čo GD/Imagick vedia dekódovať), len o tom popis poľa mlčal — uvádzal len „ICO, PNG alebo SVG".
- Pred úpravou textu overené end-to-end na reálnych súboroch cez skutočný `IconGenerator`, nie len teoreticky: WebP zdroj (GD ho v bežnom prostredí dekóduje priamo) aj AVIF zdroj (v testovanom prostredí ho GD nevedelo dekódovať vôbec — `AVIF Support: no` v `gd_info()` aj na PHP 8.3 — vygenerovaná kompletná 7-súborová sada správne prešla cez Imagick fallback).
- Doplnená poznámka, že podpora AVIF sa medzi servermi výrazne líši (niektoré zostavenia GD/Imagick ho vôbec nevedia dekódovať) a že plugin sa v takom prípade ticho prepne na druhú knižnicu alebo, ak nevie ani jedna, dôvod uvedie v Debug Mode — bez rozbitia stránky. Pri neistote zostáva najbezpečnejšou voľbou PNG.
- Doplnená rovnaká rada ako pri ICO v predchádzajúcej verzii: ak sa WebP/AVIF nezobrazí vo výbere Media Manager, treba ho pridať do Content → Media → Options → Legal Image Extensions (aktuálne Joomla defaulty pre AVIF sa naprieč verziami a zdrojmi líšia, preto sa popis nespolieha na konkrétny predvolený stav).

## 1.11.0 (2026-08-13)
- **Skorý „skip", ak nie je nastavený žiadny zdroj.** `onAfterRender` beží na úplne každom requeste, takže na webe s vysokou návštevnosťou má zmysel skončiť čo najskôr, ak plugin ešte nie je nakonfigurovaný. Nová kontrola beží pred akýmkoľvek prístupom k telu odpovede.
- **Vedľajší efekt, ktorý je zároveň oprava reálneho bugu:** strip predtým bežal bezpodmienečne, PRED kontrolou, či je vôbec nastavený zdroj. Keďže predvolený strip mód je „soft", čerstvo nainštalovaný, ešte nenakonfigurovaný plugin by ticho odstránil favicon zo šablóny a nedal namiesto neho nič — web by ostal úplne bez ikonky, kým by admin nezvolil zdrojový obrázok. Teraz sa strip ani nespustí, kým nie je nastavený aspoň jeden zdroj.
- **Cache zostaveného HTML markupu**, nielen súborov ikon. Zostavený blok `<link>`/`<meta>` tagov je pre každého návštevníka identický, kým sa nezmenia nastavenia pluginu alebo zdrojový súbor — namiesto opakovaného resolvovania ciest, kontroly úplnosti cache ikon a skladania reťazca pri každom requeste sa výsledok uloží do `media/plg_system_faviconmanager/cache/markup-{site|admin}.json`, kľúčovaný hashom zo všetkých vstupov, ktoré markup ovplyvňujú (relevantné parametre + mtime/veľkosť každého nastaveného zdroja + prítomnosť cudzieho manifestu na danej stránke).
- Cache sa sama invaliduje pri zmene nastavení alebo výmene zdrojového obrázka — netreba nič manuálne čistiť pri bežnom používaní. Existujúce tlačidlo „Clear Icon Cache" túto cache tiež maže (je to obyčajný top-level súbor v tom istom priečinku).
- Uchováva sa až posledných 5 variantov naraz (nie len 1), pretože prítomnosť cudzieho manifestu môže byť na rôznych stránkach toho istého webu odlišná (napr. iné rozšírenie pridáva manifest len na časti stránok) — jednovstupová cache by sa medzi oboma stavmi zbytočne prepisovala pri každom requeste namiesto toho, aby cachovala oba.
- Debug Mode cache vždy obchádza (čítanie aj zápis), takže diagnostika zostáva vždy živá a presne odzrkadľuje aktuálny beh; pridané rozlíšenie „served from cache" / „generated fresh" priamo v debug komentári.
- Zápis cache súboru je cez temp-súbor + `rename()`, takže súbežný request nikdy nevidí čiastočne zapísaný JSON.
- Overené 12 kontrolami: stabilita kľúča pri nezmenenom vstupe, zmena kľúča pri zmene parametra / mtime zdroja / stavu cudzieho manifestu, roundtrip zápisu a čítania, multi-variant cache, eviction po 4 záznamoch, oddelené cache pre site a admin.

## 1.10.0 (2026-08-13)
- **Zjednotená rasterizácia SVG: contain-fit namiesto deformácie.** Dve miesta v Imagick vetve stále používali `thumbnailImage($w, $h, false, false)` — s `bestfit=false` sa vynucujú presné rozmery bez ohľadu na pomer strán zdroja, takže neštvorcové SVG (napr. logo 200×100) sa deformovalo do štvorca. GD vetva pritom už od verzie 1.6.0 zachovávala pomer strán (`containSize()`).
- Opravené: `rasterizeSvgToPng()` (PNG fallback sada zo SVG — 16/32/48/192/512, apple-touch, favicon.ico) a `loadSourceAsGd()` (medzikrok SVG→GD používaný pre maskable ikony a splash screens). Obe teraz škálujú so zachovaním pomeru strán (`bestfit=true`) a výsledok vycentrujú na priehľadné plátno cieľovej veľkosti — rovnaký princíp ako pri GD.
- Druhé miesto bolo obzvlášť zákerné: keďže sa SVG deformovalo už pri prevode na "štvorcový" medziobrázok, downstream contain-fit logika (ktorá deformáciu inak rieši) videla už štvorcový vstup a nemala čo opravovať — deformácia bola v tom bode neviditeľná pre ďalší kód, hoci vo výsledných ikonách bola prítomná.
- Overené na neštvorcovom SVG (pomer 2:1, kruh v strede): kruh zostal kruhom (pomer 1,000) vo všetkých veľkostiach bežnej PNG fallback sady aj v maskable ikonách generovaných cez GD cestu zo SVG zdroja.

## 1.9.2 (2026-08-13)
- **Oprava: tlačidlo „Clear Icon Cache" hlásilo „Something went wrong" pri každom kliknutí.** Príčina: `onAjaxFaviconmanager()` výsledok len `return`-oval, ale pod Joomla moderným event systémom com_ajax návratovú hodnotu listenera vôbec nezbiera — výsledok sa musí vložiť priamo do `$event` objektu, presne podľa oficiálnej Joomla dokumentácie („Ajax Plugin for Ad Hoc Jobs"), cez `ResultAwareInterface::addResult()` alebo staršie `getArgument('result')`/`setArgument('result', ...)`.
- Handler teraz akceptuje `Event $event` a výsledok doručí oboma spôsobmi naraz (skúsi `addResult()`, ak rozhranie existuje, inak `getArgument`/`setArgument`), takže funguje bez ohľadu na to, ktorý mechanizmus konkrétna Joomla verzia interne používa. Návratová hodnota metódy zostáva zachovaná ako neškodný fallback pre priame volanie (napr. testy).
- Žiadna zmena v JS tlačidla — obe cesty doručenia výsledku produkujú rovnaký tvar odpovede (`data.data[0]` = `{ok, removed, error}`), ktorý JS už spracúval správne.

## 1.9.1 (2026-08-13)
- **Oprava: tlačidlo „Clear Icon Cache" sa nezobrazovalo.** XML definícia poľa mala plne kvalifikovaný názov triedy priamo v atribúte `type` (`type="Joomla\\Plugin\\...\\ClearCacheButtonField"`), čo Joomla takto nevyhodnocuje. Správny vzor je `type="ClearCacheButton"` spolu s `addfieldprefix="Joomla\\Plugin\\System\\FaviconManager\\Field"` — Joomla si triedu poskladá ako `{addfieldprefix}\\{type}Field`. Keď sa trieda poľa nenašla, popisok a nápoveda sa vykreslili (tie ide cez form layout nezávisle od poľa), ale samotný input (tlačidlo) nie — presne stav, ktorý bol nahlásený.
- Žiadna zmena v PHP triede poľa — jej `$type = 'ClearCacheButton'` už bol správny, chyba bola len v XML zápise.

## 1.9.0 (2026-08-13)
- **Nové tlačidlo „Clear Icon Cache" v nastaveniach pluginu** (fieldset „Cache Maintenance"). Rieši medzeru v automatickej invalidácii cache: tá je založená na čase poslednej zmeny a veľkosti zdrojového súboru, nie na jeho obsahu, takže obnova súboru so zachovaným pôvodným časom aj veľkosťou (napr. `rsync -a`, niektoré nástroje na obnovu zo zálohy) sa nezachytí a cache zostane stará.
- Implementované ako custom form field s AJAX volaním cez `com_ajax` (`onAjaxFaviconmanager`) — štandardný Joomla vzor pre akcie priamo v konfiguračnom formulári pluginu, bez potreby vlastného administračného view.
- Vymaže všetko pod `media/plg_system_faviconmanager/cache/` — bežné sady ikon, maskable varianty, splash screens, manifesty aj prípadné zabudnuté `.tmp-`/`.old-` priečinky — samotný cache priečinok ostáva zachovaný. Ide o explicitnú administrátorskú akciu, takže na rozdiel od automatického upratovania sa nič nevynecháva podľa veku.
- Bezpečnosť: kontrola klienta (len administrácia), CSRF token (`Session::checkToken('request')`) a oprávnenie `core.edit` na `com_plugins`. Handler nikdy nevyhodí nezachytenú výnimku — inak by `com_ajax` vrátil chybovú HTML stránku namiesto JSON a tlačidlo by nevedelo výsledok zobraziť.
- Overené testom na reálnej štruktúre cache priečinka: odstránených všetkých 6 top-level položiek (2 bežné sady, maskable, splash, `.tmp-` aj voľný súbor), cache priečinok samotný zostal.
- Preklad do en-GB aj sk-SK, 68/68 kľúčov zhoda v oboch jazykoch.

## 1.8.0 (2026-08-13)
- **Nová funkcia: podpora iOS Web App tagov.** Doplnené `apple-mobile-web-app-capable` / `mobile-web-app-capable`, `apple-mobile-web-app-status-bar-style` (default / black / black-translucent) a `apple-touch-startup-image`. Explicitný opt-in (predvolene vypnuté) v novom fieldsete „iOS / Apple Web App" — na rozdiel od ostatných tagov v pluginu totiž viditeľne mení správanie Safari pre návštevníka, ktorý si web pridá na plochu (skryje rozhranie Safari).
- Bonus pole „Home Screen Title" (`apple-mobile-web-app-title`) — nie jeden z pôvodne požadovaných troch tagov, ale bežne sa nastavuje spolu s nimi a je prakticky zadarmo.
- **Splash screens pre 9 bežných profilov** (iPhone SE/6/7/8, 12/13/14, 14 Pro/15, 12/13/14 Pro Max, 15 Pro Max; iPad Mini, 10.2", Pro 11", Pro 12.9") — logo vycentrované na plnej farbe pozadia, presné rozmery na pixel pre každý profil vrátane pomeru zariadenia. Iba portrait orientácia, zámerne kvôli rozsahu; zariadenie mimo zoznamu jednoducho nedostane splash obrázok, rovnako ako pred touto funkciou.
- Generovanie využíva rovnaký GD loader ako zvyšok pluginu (vrátane rasterizácie SVG cez Imagick), takže nepribudla žiadna samostatná Imagick vetva — bez GD sa funkcia jednoducho vynechá.
- **Presunuté z lazy generovania pri návšteve stránky do momentu uloženia nastavení.** Namerané: prvé vygenerovanie 9 profilov (najväčší 2048×2732 px) trvá cez 1,3 s — na rozdiel od ostatných assetov pluginu (rádovo desiatky ms) je to citeľná prestávka. Administrátor po kliknutí na Save krátku pauzu očakáva, náhodný návštevník webu nie. Pri chybe (napr. chýbajúce GD) sa generovanie ticho preskočí a doženie sa lazy pri prvej návšteve ako doteraz.
- PNG kompresný level pre splash znížený z 9 na 6 — na veľkoplošných, prevažne jednofarebných plátnach ide o ~40 % kratší čas pri prakticky identickej veľkosti súboru (namerané 33 KB vs 34 KB na najväčšom profile).
- Splash sa generuje vždy z hlavného zdroja favicony (`favicon_source`), nie z prípadného Apple Touch Icon override — má reprezentovať celý web, nie len ikonovú variantu pre iOS.
- Pridaný slovenský aj anglický preklad všetkých nových reťazcov (60/60 kľúčov zhoda v oboch jazykoch).

## 1.7.4 (2026-08-13)
- **Pridaný slovenský preklad (sk-SK).** Doteraz bol dostupný len en-GB. Nové súbory `language/sk-SK/plg_system_faviconmanager.ini` a `.sys.ini` pokrývajú všetkých 46 reťazcov — názov a popis pluginu v zozname rozšírení aj kompletné popisky a nápovedy všetkých konfiguračných polí.
- Registrované v manifeste (`<languages>`), žiadna zmena v `<files>` nebola potrebná — `<folder>language</folder>` pokrýva oba jazyky.
- Overená úplná zhoda kľúčov medzi en-GB, sk-SK a XML manifestom (46/46, obojsmerne) a UTF-8 kódovanie bez BOM.

## 1.7.3 (2026-08-13)
- **Oprava zastaraného textu upozornenia.** Hláška pri neštvorcovom zdroji stále hovorila „will be squashed" (zdeformuje sa), hoci od verzie 1.6.0 sa zdroj už nedeformuje, len sa contain-fitne s vyplnením okolia. Text teraz opisuje skutočné správanie a závažnosť znížená z `warning` na `notice` (konzistentne s upozornením na malú veľkosť zdroja) — už to nie je chyba výsledku, len odporúčanie na lepšie využitie plochy ikony.

## 1.7.2 (2026-08-13)
- **Ochrana proti path traversal pri skladaní cesty k zdrojovému obrázku.** Doteraz sa cesta skladala jednoduchou konkatenáciou (`JPATH_ROOT . '/' . $hodnota`), takže hodnota ako `../../../../etc/passwd` (nech by sa do parametrov dostala akokoľvek — ručná úprava, import konfigurácie z iného webu) by sa prečítala a skopírovala do verejne servírovaného cache priečinka.
- Nová `resolveSourcePath()` skladá cestu, rozbalí ju cez `realpath()` (odstráni `..` segmenty aj symlinky) a overí, že výsledok skutočne leží pod `JPATH_ROOT`. Nasadené na všetkých piatich miestach, kde sa cesta k zdroju používa — hlavný favicon, dark variant, admin favicon, oba výskyty Apple Touch Icon aj validácia pri ukladaní nastavení.
- Kontrola zachytáva aj dva prípady, ktoré jednoduchšia implementácia (porovnanie reťazcov cez `strpos`) nezachytí: **symlink** vedúci mimo `JPATH_ROOT` (realpath ho rozbalí na skutočný cieľ) a **sibling adresár so zhodným prefixom** (napr. `webroot` vs `webroot-other`, kde by porovnanie bez explicitnej hranice adresára falošne prešlo).
- Overené na 12 prípadoch: klasický aj hlboký traversal, traversal uprostred cesty, symlink, prefix-bypass adresár, adresár namiesto súboru, prázdna a neexistujúca hodnota — všetky správne odmietnuté; legitímne cesty s aj bez úvodného lomítka, v podpriečinkoch a SVG naďalej fungujú bez zmeny.

## 1.7.1 (2026-08-13)
- **Potlačené chyby už neznamenajú stratenú diagnostiku.** Operátor `@` zostáva (plugin beží v `onAfterRender`, nepotlačený warning by sa vypísal priamo do HTML stránky), ale skutočný dôvod zlyhania sa teraz vyzdvihne cez `error_get_last()` a zobrazí v Debug režime. Namiesto „generovanie zlyhalo" dostaneš „Permission denied", „No space left on device" alebo konkrétnu hlášku ImageMagicku.
- Poznámka k pôvodnej pripomienke: návratové hodnoty sa kontrolovali už predtým na všetkých miestach (`=== false`, `if (!$src)`, `if (!$ok)`) — `@` nerobí chybové spracovanie, len skrýva hlášku. Odstránenie `@` by teda diagnostiku nezlepšilo, iba by vypustilo warningy do výstupu stránky. Riešením je hlášku zachytiť, nie prestať potláčať.
- Pokryté prípady: chýbajúci zdroj, nečitateľný zdroj, nezapisovateľná cache, poškodený obsah súboru, zlyhaný zápis PNG/ICO, kopírovanie SVG a chyby Imagicku.
- Debug režim hlási aj nefatálne poznámky — napríklad SVG, ktoré prešlo ako vektor, ale bezpečnostná predkontrola preskočila jeho rasterizáciu.
- Testované aj pod neprivilegovaným používateľom (root práva obchádza, takže by chyby v testoch nikdy nenastali).

## 1.7.0 (2026-08-13)
- **Bezpečnostná predkontrola SVG pred rasterizáciou.** SVG je aktívny formát a rasterizácia beží na serveri, takže nepriateľský alebo len patologický súbor je naša CPU, naša RAM a potenciálne naše odchádzajúce spojenia. Pred odovzdaním do ImageMagicku sa súbor kontroluje na: deklarácie entít (`<!ENTITY` — XXE a billion-laughs), odkazy na externé zdroje (`href`/`xlink:href` na `http:`, `https:`, `ftp:`, `file:` alebo protokolovo relatívne `//`), `<script>`, `<foreignObject>`, `<iframe>` a veľkosť nad 2 MB.
- Pri odmietnutí sa preskočí **iba rasterizácia** — vektorová ikona sa naďalej publikuje, takže výsledok je rovnaký ako na serveri bez Imagicku. Neodmieta sa nič, čo by inak fungovalo.
- Poznámka k expozícii: kopírovanie SVG do cache nezvyšuje dosiahnuteľnosť súboru — zdroj už leží v `images/` a servíruje sa z tej istej domény.
- **Limity zdrojov Imagicku** (pamäť, mapovanie, plocha, čas) sa nastavujú okolo rasterizácie a **vždy sa obnovujú** cez `finally`. Sú procesovo globálne, takže ponechanie našich hodnôt by potichu obmedzilo aj ďalšiu prácu s obrázkami v tom istom requeste (Joomla thumbnaily, iné rozšírenia).
- **Zmerané obmedzenie, nie predpokladané:** keď SVG dekóduje externý delegát (`rsvg-convert`), beží mimo účtovania zdrojov ImageMagicku — test potvrdil, že `TIME` limit náročné vykreslenie neprerušil a `AREA` limit len prepne ImageMagick z pamäte na diskovú cache. Limity teda účinkujú na prácu, ktorú ImageMagick robí sám (interný MSVG renderer, prevzorkovanie), nie na delegáta. Ohraničenie delegáta patrí na server, do `policy.xml`. Účinnou obranou v pluginu je predkontrola.
- Overené na 7 rizikových tvaroch SVG + nadrozmernom súbore; benígne SVG sa naďalej rasterizuje normálne a limity zostali po všetkých operáciách nedotknuté.

## 1.6.1 (2026-08-13)
- **Zjednotené spracovanie farby pozadia maskable ikon** do jednej metódy `normalizeColor()`, ktorú používa GD aj Imagick vetva.
- **Odstránený rozpor medzi knižnicami:** pri neplatnej hodnote (napr. preklep `nonsense`, `#GGGGGG`) GD ticho použil bielu a ikony vygeneroval, kým `ImagickPixel` vyhodil výnimku, ktorú náš `catch` premenil na tiché **nevygenerovanie maskable ikon vôbec**. Na serveri bez GD teda jedna preklepnutá hodnota potichu vypla celú funkciu. Obe vetvy teraz pri neplatnej hodnote zhodne použijú `#ffffff`.
- **Zmena významu prázdnej hodnoty:** prázdne pole už neznamená priehľadné pozadie, ale `#ffffff`. Maskable ikona nemá byť priehľadná — Android ju skladá do vlastného tvaru a priehľadnosť presvitá ako čierne či inak nechcené pozadie. Priehľadnosť sa dá naďalej vyžiadať explicitnou hodnotou `transparent`.
- Tolerantnejší zápis farby: funguje s mriežkou aj bez nej, 3-znakový skrátený hex (`#abc`) aj veľké písmená.
- Overené na 8 vstupoch v oboch knižniciach — výsledky sú teraz na pixel identické.

## 1.6.0 (2026-08-13)
- **Neštvorcové zdroje sa už nedeformujú.** Doteraz sa zdroj natiahol na štvorec, takže z loga 1000×500 vznikla skreslená ikona. Teraz sa škáluje so zachovaním pomeru strán ("contain") a centruje — pri bežných ikonách je okolie priehľadné, pri maskable sa vyplní nastavenou farbou pozadia.
- Platí pre bežnú sadu aj maskable ikony, v GD aj Imagick vetve. Imagick používa `thumbnailImage()` s `bestfit`, GD nový pomocník `containSize()`.
- Pri štvorcovom zdroji (odporúčaný prípad) je výsledok identický s doterajším správaním — overené, že ikona je stále vyplnená po okraj bez pridanej výplne.
- Overené meraním na zdroji 1000×500 s kruhom: kruh zostal kruhom vo všetkých veľkostiach (pomer 1,000) a GD aj Imagick vetva dávajú zhodné rozmery na pixel. Zároveň potvrdené, že výplň bežných ikon je skutočne priehľadná (alfa 127), nie biela — inak by na tmavom pozadí prehliadača vznikli pruhy.
- Upozornenie „zdroj nie je štvorcový" pri ukladaní nastavení zostáva — deformácia už nehrozí, ale štvorcový zdroj naďalej využije plochu ikony lepšie.

## 1.5.2 (2026-08-13)
- **Manifest doplnený o `id` a `scope`.** `id` pripína identitu aplikácie — bez neho prehliadače použijú `start_url`, takže neskoršia zmena `start_url` by sa u používateľov s nainštalovanou PWA prejavila ako úplne iná aplikácia. Nastavené na rovnakú hodnotu, takže existujúce inštalácie zostávajú nedotknuté.
- `scope` sa podľa špecifikácie parsuje **relatívne k URL manifestu**, a náš manifest leží v `/media/plg_system_faviconmanager/cache/<hash>/`. Relatívna hodnota by teda scope obmedzila na cache priečinok a všetky reálne stránky by vypadli mimo rozsahu (PWA by ich otvárala v bežnom prehliadači). Preto sa zapisuje absolútna URL z `Uri::root()`.
- Overené pre inštaláciu v koreni domény aj v podadresári (`https://example.sk/joomla/`) — v oboch prípadoch scope korektne pokrýva stránky webu.

## 1.5.1 (2026-08-13)
- **Strip obmedzený na `<head>`.** Doteraz regex prechádzal celé telo odpovede, takže vedel odstrániť aj `<link rel="icon">` zobrazený v obsahu článku (napr. v ukážke kódu na vzdelávacom webe). Teraz sa upravuje výlučne hlavička dokumentu.
- **Tokenové vyhodnotenie `rel`.** Podľa HTML špecifikácie je `rel` zoznam tokenov oddelených medzerami. Pôvodné porovnanie celej hodnoty ako reťazca zachytilo len presné tvary; teraz sa hodnota rozdelí na tokeny, takže fungujú aj `rel="icon shortcut"`, `rel="alternate icon"`, `rel="shortcut icon icon"`, `rel` uvedený až za `href`, hodnoty bez úvodzoviek aj veľkými písmenami. Rovnaká logika platí pre detekciu cudzieho manifestu (`rel="alternate manifest"`).
- Nesprávne odstránenie nehrozí: `stylesheet`, `canonical`, `preconnect` ani `apple-touch-startup-image` sa nedotkne. Pokryté 22 testovacími prípadmi.
- **Oprava:** pri nezachytených regex skupinách vznikali `PHP Warning: Undefined array key` na každom requeste (nezachytené koncové skupiny `preg_match` do poľa nedoplní). Overené pri `error_reporting=E_ALL`.
- Poznámka k výkonu: zmena je o ~16 µs na request pomalšia než pôvodný regex (0,034 ms vs 0,018 ms na 223 KB stránke) — réžia callbacku a kopírovania hlavičky. Dôvodom zmeny je správnosť, nie rýchlosť.

## 1.5.0 (2026-08-13)
- **Atomická generácia cache.** Ikony sa už negenerujú priamo do cieľového priečinka, ale do dočasného `.tmp-<hash>-<random>/`, ktorý sa po dokončení publikuje jediným `rename()`. Publikovaný súbor sa tým pádom nikdy neprepisuje na mieste — odpadá riziko, že web server podá prehliadaču práve prepisovaný, useknutý PNG.
- Ošetrené dva prípady, ktoré samotný `rename()` nezvláda: cieľový priečinok už existuje (rename cez neprázdny adresár na POSIXe zlyhá — starý sa najprv odloží bokom a po úspešnom publikovaní zmaže) a súbežný request, ktorý stihol publikovať tú istú verziu skôr (jeho výsledok sa ponechá).
- Staging priečinky po páde či timeoute procesu sa upratujú automaticky (`.tmp-*` a `.old-*` staršie než hodina). Sú dot-prefixované, takže ich glob na verzie nikdy nezachytí.
- SVG vetva rozlišuje, či je Imagick dostupný: bez neho je priečinok len s `icon.svg` považovaný za kompletný a negeneruje sa dookola pri každom requeste.
- Overené záťažovým testom: 32 súbežných generovaní v 4 kolách popri 21 405 kontrolných čítaniach publikovaných PNG — žiadny poškodený súbor, žiadne zvyšky staging priečinkov, všetky súbežné procesy vrátili konzistentný výsledok.

## 1.4.1 (2026-08-13)
- **Oprava: čiastočne vytvorená cache sa už nepovažuje za platnú.** Doteraz sa platnosť rastrovej cache posudzovala len podľa existencie `favicon.ico` a `apple-touch-icon.png`. Nová kontrola (`hasCompleteRasterCache()` / `hasCompleteMaskableCache()`) overuje **všetky** súbory sady a zároveň ich nenulovú veľkosť — 0-bajtový súbor z neúspešného zápisu sa teraz berie ako chýbajúci. Nasadené na všetkých 8 miestach, kde sa cache vyhodnocuje (raster, SVG fallback, maskable, GD aj Imagick vetva).
- **Oprava príčiny, nielen následku: kontrolujú sa návratové hodnoty zápisov.** `imagepng()` pri plnom disku alebo vyčerpanej `memory_limit` vráti `false` a beh doteraz pokračoval ďalej — zapísal apple aj ICO a cache vyzerala kompletná. Neúspešný zápis teraz generovanie okamžite ukončí. Rovnako pribudla kontrola pri zápise ICO (pri zlyhaní sa nedokončený súbor zmaže) a pri maskable ikonách.
- Poznámka k pôvodnému hláseniu: postupnosť „vytvorí 16/32/48 → pád procesu → ico a apple existujú" nemohla nastať, pretože `favicon.ico` sa zapisuje ako posledný. Reálnou príčinou popísaného stavu boli tiché zlyhania zápisu, ktoré sú teraz ošetrené.
- Overené testom: chýbajúci PNG uprostred sady, 0-bajtový súbor aj neúplná maskable sada sa pri ďalšom requeste automaticky dogenerujú. Cache hit zostáva bez réžie (0,012 ms, žiadne prepisovanie súborov).

## 1.4.0 (2026-08-13)
- **Cache sa už nehromadí donekonečna.** Doteraz každá zmena loga vytvorila nový hash priečinok a ten starý zostal navždy. Názov priečinka je teraz dvojdielny — stabilný hash *cesty* k zdroju + hash *verzie* (mtime, veľkosť, a pri maskable aj padding a farba pozadia), napr. `c8863c67-fb313753`. Po vygenerovaní novej verzie sa zmažú súrodenecké priečinky s rovnakým prefixom cesty.
- Čistenie je cielené: maže len staršie verzie **toho istého zdroja**, ikony ostatných zdrojov (dark variant, admin ikona, iné logo) sa nedotkne. Overené testom s dvoma súbežnými zdrojmi.
- Priečinky mladšie než 60 sekúnd sa preskakujú — súbežný request môže do takého priečinka práve zapisovať a zmazanie spod rúk by dalo rozbité ikony.
- Rovnaké upratovanie platí pre superseded manifest súbory v rámci priečinka (vznikali pri zmene theme-color alebo názvu aplikácie, keď sa zdrojový obrázok nemenil).
- Čistenie je best-effort: prípadné zlyhanie mazania je odchytené a nikdy neovplyvní doručenie ikon.
- Invalidácia cache naďalej stojí na mtime + veľkosti, nie na hashi obsahu — vedomé rozhodnutie kvôli výkonu. Známy okrajový prípad: obnova súboru so zachovaným časom *a* identickou veľkosťou (napr. `rsync -a`) cache neinvaliduje; stačí súbor znovu uložiť alebo použiť `touch`.

## 1.3.0 (2026-08-12)
- **Imagick fallback aj pre rastrové zdroje:** GD zostáva preferovaný (rýchlejší, menej pamäti), ale ak na serveri chýba alebo daný súbor nevie dekódovať, generovanie automaticky prejde na Imagick. Stačí teda ktorákoľvek z dvoch knižníc, nie nutne GD.
- Rovnaký fallback platí aj pre maskable ikony — kompozícia (pozadie + zmenšená marka v safe zóne) má teraz Imagick variantu cez `compositeImage()`.
- Ak zlyhajú obe knižnice, plugin vráti `null` a nevloží žiadne tagy — radšej žiadna ikona než odkazy na neexistujúce súbory.
- Debug hláška teraz uvádza stav GD aj Imagick zvlášť a explicitne upozorní, ak chýbajú obe.
- Overené: `getimagesizefromstring()` použitá pri stavbe `favicon.ico` je súčasťou jadra PHP, nie GD — ICO builder teda funguje aj v čisto Imagick prostredí.

## 1.2.0 (2026-08-12)
- **Maskable Android ikony:** voliteľne sa generujú extra 192/512 ikony s nastaviteľným safe-zone paddingom (0–40 %, default 12 %) a plnou farbou pozadia; v manifeste sú uvedené s `purpose: "maskable"` popri bežných `purpose: "any"`. Android adaptívne tvary (kruh/squircle) tak logo neorežú.
- **Manifest mód Off / Generate / Defer:** „Defer" preskočí náš manifest, ak stránku už vlastní iný PWA plugin (detekcia cudzieho `<link rel="manifest">` v `<head>`). Favicony, Apple ikony a theme-color sa riešia v každom prípade.
- **Odstupňovaný strip Off / Soft / Aggressive:** „Soft" (default) odstráni len favicon a apple-touch-icon tagy šablóny a nechá `mask-icon` aj cudzí manifest na pokoji; „Aggressive" odstráni aj tie. Nahrádza pôvodný áno/nie prepínač.
- **Validácia master obrázka:** po uložení nastavení plugin v administrácii upozorní, ak zdroj nie je štvorcový alebo je menší než 512×512 px (SVG sa preskakuje). Len upozornenie, nič neblokuje.
- **Admin theme-color:** samostatný `theme-color` pre administráciu, nezávislý od frontendu.
- Manifest sa teraz cachuje podľa hashu svojho *obsahu*, nie len zdrojového obrázka — zmena paddingu, farieb či názvov sa okamžite prejaví (predtým by sa servíroval starý manifest).
- Manifest sa zapisuje s `JSON_UNESCAPED_UNICODE`, takže diakritika v názve webu zostáva čitateľná.
- Spätná kompatibilita: staré parametre `remove_default_favicons` a `enable_pwa_manifest` sa naďalej čítajú ako fallback, takže upgrade nezmení doterajšie správanie, kým nastavenia znova neuložíš.

## 1.1.0 (2026-07-09)
- **Nová funkcia:** SVG zdroje teraz okrem vektorovej `<link rel="icon" type="image/svg+xml">` získajú aj plnú PNG/ICO fallback sadu (16/32/48/192/512px, favicon.ico, Apple touch icon), rasterizovanú z SVG cez Imagick. Vektor zostáva primárny; PNG sada je len doplnková (staršie prehliadače, iOS home screen, PWA manifest, ktorý PNG vyžaduje povinne).
- Rasterizácia beží per-veľkosť cez `Imagick::setSize()` (natívne vykreslenie v cieľovom rozlíšení, nie zmenšenie z jedného veľkého bitmapu) — ostré hrany aj pri 16×16.
- Vyžaduje PHP Imagick rozšírenie + binárku `rsvg-convert` na serveri (Debian/Ubuntu balík `librsvg2-bin`). Ak chýba, alebo SVG delegát zlyhá, plugin ticho pokračuje len s vektorovou ikonou (žiadny fatal error, žiadne rozbitie stránky).
- PWA manifest a Apple Touch Icon sa teraz generujú aj pre SVG zdroj, ak bol PNG fallback úspešne vytvorený.

## 1.0.2 (2026-07-09)
- **Oprava (kritická):** Joomla 6 `media` form field ukladá hodnotu vrátane metadát vo formáte `cesta#joomlaImage://...?width=..&height=..`. Plugin bral celý tento reťazec ako súborovú cestu, čím zlyhávalo `is_file()`. Pridaná `sanitizeMediaValue()`, ktorá orezáva všetko od `#` — aplikovaná na `favicon_source`, `favicon_dark_source`, `admin_favicon_source` aj `apple_touch_icon`.

## 1.0.1 (2026-07-09)
- Pridaný prepínač "Debug Mode" (skryté v Basic fieldsete): pri zapnutí vloží pred `</head>` HTML komentár s presným dôvodom, prečo sa favicon nevložil (chýbajúci zdroj, chýbajúce GD rozšírenie, nezapisovateľný cache priečinok, zlá cesta k súboru, alebo výnimka s presnou hláškou).
- `buildHeadMarkup()` teraz namiesto tichého `return ''` vyhadzuje `RuntimeException` s presnou diagnostikou, ktorá sa zobrazí len keď je Debug Mode zapnutý (produkčné správanie sa nemení — stále zlyháva ticho, ak je debug vypnutý).

## 1.0.0 (2026-07-09)
- Prvé vydanie natívneho Joomla 6 systémového pluginu Favicon Manager.
- Vlastný favicon pre frontend (ICO/PNG/SVG) + samostatný favicon pre administráciu.
- Automatické generovanie ikon vo veľkostiach 16, 32, 48, 192, 512 px + multi-rozlíšenie favicon.ico (16/32/48) priamo z jedného zdrojového obrázka (GD), s on-demand cache podľa hashu zdroja (cache-safe, žiadne manuálne čistenie).
- Apple Touch Icon (180×180) — automaticky generovaná, alebo voliteľne vlastný obrázok.
- Dark mode favicon cez `prefers-color-scheme`, vrátane samostatnej dark theme-color.
- PWA/Android manifest (`manifest.webmanifest`) generovaný a linkovaný automaticky.
- `theme-color` meta tag (svetlý aj tmavý variant).
- Automatické odstránenie predvolených favicon/apple-touch-icon tagov z výstupu šablóny (funguje s akoukoľvek šablónou, bez úprav template súborov).
- Výber zdrojových obrázkov cez Joomla Media Manager (field type `media`).
- Architektúra: PSR-4 namespace, `services/provider.php` DI kontajner, `SubscriberInterface` (`onAfterRender`).
