# Changelog

A csomag minden lényeges változása ebben a fájlban szerepel.

A formátum a [Keep a Changelog](https://keepachangelog.com/hu/1.1.0/) ajánlását követi, a verziózás [Semantic Versioning](https://semver.org/lang/hu/).

## [Unreleased]

### Added

- `X-Waypost-Schema` fejléc a csomag útvonalainak minden válaszában: a `waypost/schema` verziója (`Paths::VERSION`), amellyel a szerver dolgozik.
- CI (`.github/workflows/ci.yml`): `composer test` (MySQL 8.4), `composer analyse`, `composer lint`, a `waypost-schema` rögzített tagjén.

### Changed

- A `waypost/schema` függőség `^0.6`; a várt tag a `.waypost-schema-version` fájlban, egy teszt veti össze a `Paths::VERSION`-nel és a `composer.json`-nal.

### Fixed

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
