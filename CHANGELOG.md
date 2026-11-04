# Changelog

A csomag minden lényeges változása ebben a fájlban szerepel.

A formátum a [Keep a Changelog](https://keepachangelog.com/hu/1.1.0/) ajánlását követi, a verziózás [Semantic Versioning](https://semver.org/lang/hu/).

## [Unreleased]

### Added

- `GET /reports`: a jelentésarchívum supervisor tokennel, a legújabb időszak elöl, 100-as oldalakban (`next_cursor`), `?site_id=` és `?type=` szűréssel; a tárolt PDF aláírt, lejáró linkjével (`waypost-schema` 0.5.0).
- Aláírt, token nélküli letöltés: `GET /media/{id}/file` (a fotó, az adatmegőrzés végéig) és `GET /reports/{id}/file` (a tárolt jelentés, `waypost.signed_urls.report_ttl_min` percig, alapból 60); a lejárt vagy módosított link `403`.
- Menetrend-kibontás PHP-ban (`Waypost\Schedule\Expansion`, `Deadline`): ugyanazok a szabályok, mint az appban, a `schedule/test-vectors.json` szerint.
- Jelentésadat-összesítő (`Waypost\Reports\Summarizer`): egy telephely egy időszakának várt és tényleges körei, elmaradt, hiányos és teljesített körei, műszakjai, jelölései, eseményei, a lánc állapota és a „nem teljes adat” jelzés; az adatmegőrzési idő (`Waypost\Reports\Retention`).

### Changed

- A `waypost/schema` függőség `^0.5`.

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
