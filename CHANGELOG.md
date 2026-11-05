# Changelog

A csomag minden lényeges változása ebben a fájlban szerepel.

A formátum a [Keep a Changelog](https://keepachangelog.com/hu/1.1.0/) ajánlását követi, a verziózás [Semantic Versioning](https://semver.org/lang/hu/).

## [Unreleased]

A 2026-11-05-i audit javításai (`docs/AUDIT-ISSUES.md`, WP-BE-001 – WP-BE-029) és a `waypost/schema` 0.8.0. Három új migráció (`foreign key` indexek, `reports.delivered_to`, morph-aliasok) és új követelmény (`ext-fileinfo`): frissítés után `php artisan waypost:install --migrate`.

### Security

- A telephelyi telefonon (`X-Acting-User`) a supervisor a saját e-mail-címét, jelszavát és PIN-kódját csak akkor módosíthatja, ha a telefon telephelye az egyetlen telephelye (WP-BE-001).
- A lánc hivatkozásai (útvonalverzió, menetrend, pont, leolvasás) csak az eszköz saját telephelyére mutathatnak; a más telephelyre mutató bejegyzés alkalmazatlan marad (WP-BE-002).
- Jelszócserekor a felhasználó többi tokenje törlődik, a kérés sajátja megmarad (WP-BE-005).
- A sorrenden kívül érkező, várakozó bejegyzés csak érvényes HMAC-kal tárolódik (WP-BE-017).
- Belépésnél ismeretlen címre is lefut egy jelszóhash, így a válaszidő nem árulja el, létezik-e a cím; új, IP-nkénti korlát: `waypost.rate_limits.login_ip` (`WAYPOST_LOGIN_IP_RATE_LIMIT`, 30/perc) (WP-BE-012).
- Külön korlát a PIN-t adó kérésekre (`POST /users`, `PUT /users/{id}/pin`, `POST /sites/{id}/users`): `waypost.rate_limits.pin` (`WAYPOST_PIN_RATE_LIMIT`, 20/perc) (WP-BE-011).
- A törölt pont fotóját is csak a pont telephelyén lehet feltölteni; eddig a törölt pont még nem létezőnek számított, és bárki tehetett rá fotót (WP-BE-015).
- A fotó és a tárolt jelentés letöltése `X-Content-Type-Options: nosniff` fejlécet kap (WP-BE-016).

### Added

- A csomag modelljei `waypost.` előtagú morph-aliasokat kapnak (`Relation::morphMap()`), így a csomag a morph mapjét kikényszerítő gazdában is működik; új migráció írja át a tárolt osztályneveket (`media.owner_type`, `idempotency_keys.owner_type`, `personal_access_tokens.tokenable_type`) (WP-BE-034).
- Naplózás a `waypost.log_channel` (`WAYPOST_LOG_CHANNEL`) csatornán: elutasított láncbejegyzések, a hivatkozásukra váró bejegyzések, a még futó vagy félbemaradt `Idempotency-Key`, az újraküldött jelentés és az éjszakai takarítás összegzése (WP-BE-035).
- `waypost.guard` (`WAYPOST_GUARD`): a csomag guardjának neve; ha a gazdának már van ilyen nevű, más guardja, az alkalmazás hibával áll le (WP-BE-027).
- `waypost.pull.settle_ms` (`WAYPOST_PULL_SETTLE_MS`, 5000): a pull ennyi ideig nem küldi a friss változásokat (WP-BE-022).
- `composer test:types`: típuslefedettség, 100% (WP-BE-029).
- A tesztek a csomag útvonalait a `waypost/schema` végponttáblájával (`endpoints.json`) vetik össze: név, metódus, útvonal, hívók, `Idempotency-Key`, a törzs sémája.
- README: *Üzemeltetés* szakasz (az eszköztoken kockázata, `APP_KEY`-csere, mentés, frissítés, queue worker, több szerver, NTP, TrustProxies) (WP-BE-009, WP-BE-028).
- CI: MySQL 8.4 és PostgreSQL 16, a legalacsonyabb függőségekkel is, `composer analyse`, `composer test:types`, `composer lint`, és a telepítés egy friss Laravel-alkalmazásba (`scripts/install-smoke.sh`) (WP-BE-007), a `waypost-schema` rögzített tagjén.
- `X-Waypost-Schema` fejléc a csomag útvonalainak minden válaszában: a `waypost/schema` verziója (`Paths::VERSION`), amellyel a szerver dolgozik.

### Changed

- Az ütemezett jobok `onOneServer()` és `ShouldBeUnique`, egy időszak jelentése egyszerre csak egyszer van sorban; a cache-nek közösnek kell lennie a szerverek között (WP-BE-019).
- Egy push a korábbi pushok alkalmazatlan bejegyzéseiből legfeljebb 500-at próbál újra, a hiányzó fotók listája is legfeljebb 500 elemű (WP-BE-018).
- A pull két lépésben tölt be (előbb a kulcsok, aztán csak a lap rekordjai a kapcsolataikkal) (WP-BE-021).
- A jelentés egy lekérdezéssel olvassa a megőrzési időt; a `Summarizer` a várt köröket az `ExpectedRounds`-ból, a körsorokat a `RoundRows`-ból kapja (WP-BE-025, WP-BE-026).
- A lemondott jelentés-előfizetés a lemondás után még a saját megőrzési idejéig (havinál 62 nap) számít a megőrzésbe, így a havi jelentés lemondása nem törli azonnal a 31–62 napos adatot (WP-BE-039).
- A PIN-hozzárendelés zárolja az érintett telephelyeket a mentés commitjáig, így két egyidejű kérés nem adhatja ugyanazt a PIN-t (WP-BE-010).
- Index minden idegen kulcson PostgreSQL-en és SQLite-on is (új migráció; MySQL-en nem változik semmi) (WP-BE-006).
- MIT licenc (`LICENSE`, a `composer.json`-ban eddig „proprietary”) (WP-BE-024).
- Új követelmény: `ext-fileinfo`; `.gitattributes` (a kiadott archívumból kimaradnak a tesztek és a fejlesztői fájlok), `.editorconfig`, a `composer.json` metaadatai (WP-BE-023, WP-BE-024).
- A `waypost/schema` függőség `^0.8` (a 0.7.0 és a 0.8.0 az audit javításai); a várt tag a `.waypost-schema-version` fájlban, egy teszt veti össze a `Paths::VERSION`-nel és a `composer.json`-nal. A gyalogidők felső korlátja (86 400 s) a sémából jön: a nagyobb érték `422` az `500` helyett (WP-BE-013).
- A `validation_failed` hibának mindig van `details`-e: a törzs egészét érintő hibánál (nem JSON, nem objektum, rossz tartalomtípus) és a keretrendszer saját 422-es hibájánál az üzenet az üres kulcs alatt (`{"": ["…"]}`; `waypost/schema` 0.8.0).
- `POST /sync/push-final`: `done: false` mellett üres köteg nem küldhető (`422`; `waypost/schema` 0.8.0).

### Fixed

- Menetrend-kibontás: az ablaknál hosszabb lépésű intervallumos menetrend (pl. naponta egyszer, `interval_every_min = 1440`) `ValueError`-ral leállt; most egy kört ad (a `waypost/schema` `every-1440` vektora).
- Egy jelentés nem veszhet el egy küldés közben meghalt job miatt: a 15 percen belül el nem küldött foglalás felszabadul, és a túl nagy fotó nem bontódik ki a PDF-hez (WP-BE-004). Ha a küldés egy címzettnél bukik, a következő próbálkozás csak a maradéknak küld (`reports.delivered_to`, új migráció) (WP-BE-020).
- A tokenek `last_used_at`-je eddig soha nem frissült; most percenként legfeljebb egyszer (WP-BE-038).
- A félbemaradt kérés `Idempotency-Key`-e 5 perc után újra futtatható, eddig 24 óráig `409`-et adott (WP-BE-003).
- Egy hibás bejegyzés vetülete nem állítja meg a push többi bejegyzését: saját savepointban fut, a hiba a naplóba kerül, a bejegyzés alkalmazatlan marad (WP-BE-008).
- A soha létre nem jött pont fotója a feltöltés után 31 nappal törlődik (WP-BE-015).
- A fotó `taken_at`-ja nem lehet későbbi a szerver idejénél, így az aláírt link sem élhet tovább a megőrzésnél (WP-BE-014).
- Menetrend-kibontás (`Expansion::resolve`): a tavaszi óraátállítás nem létező helyi idejénél kivétellel leállt, ha a PHP a rendszer időzóna-adatbázisát használja (pl. Ubuntu, slim TZif), mert a `getTransitions()` ott nem adja vissza a csak szabállyal megadott váltásokat. A váltás pillanatát most az eltolás alapján keresi; a beépített adatbázissal az eredmény ugyanaz. A CI-n derült ki.

## [0.2.0] – 2026-11-04

Az M6 mérföldkő: jelentések, „Nincs adat” figyelmeztetés, adatmegőrzés. Új tábla (`no_data_alerts`) és új követelmény (`ext-gd`): frissítés után `php artisan waypost:install --migrate`.

### Added

- `GET /reports`: a jelentésarchívum supervisor tokennel, a legújabb időszak elöl, 100-as oldalakban (`next_cursor`), `?site_id=` és `?type=` szűréssel; a tárolt PDF aláírt, lejáró linkjével (`waypost-schema` 0.5.0).
- Aláírt, token nélküli letöltés: `GET /media/{id}/file` (a fotó, az adatmegőrzés végéig) és `GET /reports/{id}/file` (a tárolt jelentés, `waypost.signed_urls.report_ttl_min` percig, alapból 60); a lejárt vagy módosított link `403`.
- Menetrend-kibontás PHP-ban (`Waypost\Schedule\Expansion`, `Deadline`): ugyanazok a szabályok, mint az appban, a `schedule/test-vectors.json` szerint.
- Jelentésadat-összesítő (`Waypost\Reports\Summarizer`): egy telephely egy időszakának várt és tényleges körei, elmaradt, hiányos és teljesített körei, műszakjai, jelölései, eseményei, a lánc állapota és a „nem teljes adat” jelzés; az adatmegőrzési idő (`Waypost\Reports\Retention`).
- Műszakjelentés: a `shift_closed` feldolgozása indítja (`SendShiftReport`), legfeljebb 30 percig vár a hiányzó fotókra; HTML e-mail a design sablonja szerint; a lecserélt telefon nyitva maradt műszakjáé az utolsó feltöltés lezárásakor megy ki.
- Napi, heti és havi jelentés: 15 percenként ütemezett job (`SendDueReports`) küldi telephelyenként a helyi 10:00-kor; HTML e-mail, a heti és a havi PDF-fel (dompdf, DejaVu Sans, beágyazott bélyegképek), amely a `waypost/reports` alá is tárolódik a jelentésarchívumnak.
- Címzettek a `report_subscriptions` szerint (a telephely aktív supervisorai), egy jelentés a `reports` táblába kerül, és nem megy ki kétszer.
- A sablonok publikálhatók: `vendor:publish --tag=waypost-views`.
- „Nincs adat” figyelmeztetés: 15 percenként ütemezett job (`SendNoDataAlerts`) e-mailt küld a telephely supervisorainak, ha az utolsó várt kör határideje óta `no_data_alert_after_min` perc eltelt, és a telephely telefonja azóta nem jelentkezett; menetrendi ablakonként egyszer (új tábla: `no_data_alerts`).
- Adatmegőrzés: naponta 03:30-kor futó job (`PruneExpiredData`) törli a megőrzési időnél (31 vagy 62 nap) régebbi láncbejegyzéseket, műszakokat, köröket, leolvasásokat, eseményeket, fotókat, diagnosztikai jelzéseket és tárolt PDF-eket; a `devices.last_seq` és `last_hash` megmarad.
- A workbenchben: jelentés-demóadat (`composer seed-reports`, 40 éjszaka) és a jelentések előnézete a `/reports` alatt.

### Changed

- A `waypost/schema` függőség `^0.5`.
- A PDF-hez a `dompdf/dompdf` közvetlenül (a `barryvdh/laravel-dompdf` helyett), saját beállításokkal; új követelmény az `ext-gd`.

## [0.1.1] – 2026-11-03

### Fixed

- A csomag gate-je (`Waypost\Auth\Gate`) már nem Laravel-gate, csak becsomagol egyet: a gazda `callAfterResolving(Gate::class, …)` callbackjei (pl. a `spatie/laravel-permission` `before`-callbackje) így nem kerülnek rá, és nem dobnak `TypeError`-t a `Caller`-re (500 minden jogosultság-ellenőrző végponton).

## [0.1.0] – 2026-11-02

### Added

- `POST /sync/push`: a láncbejegyzések fogadása és ellenőrzése (`seq`, `prev_hash`, HMAC), hiánypótlás, vetületek (`shifts`, `rounds`, `scans`, `incidents`, `media`) és a szerver jelölései (`time_suspect`, `too_fast`, `far_from_checkpoint`, `late_sync`).
- `POST /sync/push-final`: a visszavont telefon utolsó feltöltése kötegekben, a `done: true` zárja le.
- `PUT /media/{id}`: a fotók feltöltése (multipart, SHA-256-ellenőrzés, privát lemez); a pontok `photo_media_id` mezője.
- `POST /devices/diagnostics`: az eszköz jelzése az elutasított láncbejegyzéseiről (`device_diagnostics` tábla, Q2).

### Changed

- Új oszlopok a meglévő migrációkban: `chain_entries.projected_at`, `devices.upload_closed_at`.
- A létrehozó végpontok (`POST /sites`, `/checkpoints`, `/routes`, `/routes/{id}/versions`, `/schedules`, `/users`, `/report-subscriptions`) ugyanarra az app által generált `id`-ra idempotensek: a megismételt létrehozás `200` a rekord jelenlegi állapotával, `409` helyett (`waypost-schema` 0.4.0).
- A még feldolgozás alatt álló `Idempotency-Key` `409`-e `Retry-After: 5` fejlécet kap, így megkülönböztethető a kérés saját ütközésétől.
