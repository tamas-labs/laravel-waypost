# Changelog

A csomag minden lényeges változása ebben a fájlban szerepel.

A formátum a [Keep a Changelog](https://keepachangelog.com/hu/1.1.0/) ajánlását követi, a verziózás [Semantic Versioning](https://semver.org/lang/hu/).

## [Unreleased]

### Added

- `POST /sync/push`: a láncbejegyzések fogadása és ellenőrzése (`seq`, `prev_hash`, HMAC), hiánypótlás, vetületek (`shifts`, `rounds`, `scans`, `incidents`, `media`) és a szerver jelölései (`time_suspect`, `too_fast`, `far_from_checkpoint`, `late_sync`).
- `POST /sync/push-final`: a visszavont telefon utolsó feltöltése kötegekben, a `done: true` zárja le.
- `PUT /media/{id}`: a fotók feltöltése (multipart, SHA-256-ellenőrzés, privát lemez); a pontok `photo_media_id` mezője.

### Changed

- Új oszlopok a meglévő migrációkban: `chain_entries.projected_at`, `devices.upload_closed_at`.
