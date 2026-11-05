# laravel-waypost – megfelelőségi mátrix

- **Audit dátuma:** 2026-11-04
- **Vizsgált állapot:** `main`, `4fc8a6c` (0.2.0)
- **Követelményforrások:**
  - `../waypost/docs/Waypost – fejlesztői specifikáció (1. lépcső).md` (a továbbiakban: *Spec*, szakasz szerint hivatkozva);
  - `../waypost/docs/tasks.md` (mérföldkövek);
  - a repó `CLAUDE.md` *Szabályok* pontja (csomagszabályok);
  - `laravel/package-skeleton` (élő repó, az audit napján).
- **Kapcsolódó dokumentumok:** [AUDIT-REPORT.md](AUDIT-REPORT.md), [AUDIT-ISSUES.md](AUDIT-ISSUES.md)

**Státuszok:**

| Státusz | Jelentés |
| --- | --- |
| PASS | teljesül, bizonyítékkal |
| PARTIAL | részben teljesül, vagy indokolt, dokumentált eltéréssel |
| FAIL | nem teljesül |
| NOT TESTED | ebben az auditban nem volt ellenőrizhető (az ok a megjegyzésben) |
| NOT APPLICABLE | nem ennek a repónak a felelőssége |

A „Bizonyíték” oszlopban:
- **teszt** = a Pest-tesztkészlet, amely ebben az auditban MySQL 8.4-en és PostgreSQL 16-on is lefutott (606 teszt, 3714 assertion, mindkettőn zöld);
- **kód** = a forrás olvasása;
- **dönt.** = `docs/decisions.md`.

Csak a specifikációban szereplő követelmények kerültek a mátrixba; a hiányzó részletekre nem találtam ki szabályt.

---

## 1. Technológiai stack és csomagszabályok (Spec: *Repo és technológiai stack*)

| ID | Követelmény | Státusz | Fájlok | Bizonyíték | Megjegyzés |
| --- | --- | --- | --- | --- | --- |
| STK-01 | Laravel 13 csomag, PHP 8.4 | PASS | `composer.json` | kód; a teszt Laravel 13.39-en és PHP 8.4.27-en futott | `php ^8.4`, `laravel/framework ^13.0` |
| STK-02 | Telepítés: `composer require`, majd `php artisan waypost:install` (konfiguráció és migrációk publikálása) | PASS | `src/Console/InstallCommand.php`, `tests/Install/` | teszt (`tests/Install`, workbench nélküli alkalmazás) | A `scripts/install-smoke.sh` (valódi `laravel/laravel` 13) **nem futott** ebben az auditban: hálózat kell hozzá |
| STK-03 | Minden tábla `waypost_` előtagú (konfigurálható), a gazda tábláihoz nem nyúl | PASS | `src/Support/Table.php`, `database/migrations/*` | kód (grep: beégetett `waypost_` csak a `Table` alapértékében); teszt (`MigrationsTest`, `TableTest`) | A gazda *konfigurációjába* egy kulcsot ír, lásd WP-BE-027 |
| STK-04 | Saját `Waypost\Models\User`, Sanctum tokenekkel, a gazda `users` táblája nélkül | PASS | `src/Models/User.php`, `src/Models/PersonalAccessToken.php`, `src/Models/Concerns/HasWaypostTokens.php`, `src/Auth/TokenGuard.php` | kód; teszt (`AuthenticationTest`); dönt. Q8 | Saját tokentábla; a `Sanctum::usePersonalAccessTokenModel()` nincs használva (grep) |
| STK-05 | Útvonalak a konfigurált előtag alatt, alapból `api/waypost/v1` | PASS | `config/waypost.php`, `src/WaypostServiceProvider.php` | kód; teszt (`EndpointsTest`) | |
| STK-06 | A bemenő JSON a `waypost/schema` sémáival (opis/json-schema) is ellenőrizve; a FormRequest csak az adatbázisfüggő szabályokat nézi | PASS | `src/Http/Middleware/ValidateSchema.php`, `src/Validation/SchemaValidator.php`, `routes/api.php` | kód; teszt (`SchemaValidationTest`); dönt. „Konfigurációs CRUD” | FormRequest egyáltalán nincs, a controller nézi az adatbázisfüggő szabályokat. Ez a követelmény szándékának megfelel, dokumentált döntés |
| STK-07 | PDF: `barryvdh/laravel-dompdf` | PARTIAL | `src/Reports/Pdf.php`, `composer.json` | kód; dönt. „A napi, heti és havi jelentés” | Közvetlenül a `dompdf/dompdf`. Indok: a gazda `config/dompdf.php`-je ne írja felül a biztonsági beállításokat. Indokolt eltérés |
| STK-08 | Fájlok: Laravel Storage, privát lemez, aláírt, lejáró URL | PASS | `src/Http/Controllers/MediaController.php:148-155`, `src/Http/SignedUrls.php` | kód; teszt (`MediaUploadTest`, `ReportsTest`) | A lejáratot a kliens `taken_at`-je befolyásolja, lásd WP-BE-014 |
| STK-09 | Háttérfeladatok: queue és scheduler | PASS | `src/Jobs/*`, `src/WaypostServiceProvider.php:170-175` | kód; teszt | Nincs `onOneServer`, lásd WP-BE-019 |
| STK-10 | Backend tesztek: Pest + Orchestra Testbench, PHPStan (Larastan) 8-as szint | PASS | `composer.json`, `phpstan.neon.dist`, `tests/` | `composer analyse`: „[OK] No errors”; `composer test`: 606/606 | |
| STK-11 | Idő a szerveren UTC-ben | PASS | `src/Support/Time.php`, `src/Models/Concerns/StoresUtcMilliseconds.php` | kód; teszt (`TimeTest`, `MigrationsTest`: `datetime(3)`, nem `timestamp`) | |
| STK-12 | A gazda MySQL 8 vagy PostgreSQL 16 adatbázisát használja | PARTIAL | `database/migrations/*` | teszt MySQL 8.4-en és PostgreSQL 16-on: zöld | A MySQL 8.0 nincs kipróbálva (WP-BE-042). PostgreSQL-en hiányzó FK-indexek (WP-BE-006) |
| STK-13 | Szerződésváltozás előbb a `waypost-schema`-ban, új verzióval | PASS | `composer.json` (`waypost/schema ^0.5`) | kód; `../waypost-schema/docs/decisions.md` | |

## 2. Adatbázis (Spec: *Adatbázis*)

| ID | Követelmény | Státusz | Fájlok | Bizonyíték | Megjegyzés |
| --- | --- | --- | --- | --- | --- |
| DB-01 | Minden elsődleges kulcs UUIDv7 | PASS | `src/Models/Concerns/HasUuidV7PrimaryKey.php`, `src/Support/Uuid.php`, migrációk | kód; teszt (`UuidTest`, `ModelConventionsTest`) | A pivot és a `route_version_checkpoints` összetett kulcsú, ahogy a Spec írja |
| DB-02 | A konfigurációs táblák mezői (users, sites, site_user, devices, checkpoints, routes, route_versions, route_version_checkpoints, schedules, report_subscriptions) | PASS | `database/migrations/2026_10_28_000001` – `000008` | kód; teszt (`MigrationsTest`, `ModelsTest`) | Dokumentált eltérés: `lat_e7` / `lng_e7` / `accuracy_dm` egész számok a `decimal` / `float` helyett (dönt. „Az adatmodell további részletei”; a Spec *Láncolt napló* pontja maga indokolja) |
| DB-03 | Egyedi kulcsok: `users.email`, `checkpoints(site_id, tag_uid)`, `checkpoints(qr_payload)`, `route_versions(route_id, version)`, `route_version_checkpoints` PK `(route_version_id, position)`, `report_subscriptions(user_id, site_id, frequency)` | PASS | migrációk (pl. `…000006…routes_table.php:36`, `:50`; `…000005…:32-33`; `…000008…:23`) | kód; teszt | Soft delete mellett a `not_deleted` generált oszloppal |
| DB-04 | `chain_entries` mezői, `UNIQUE(device_id, seq)`, `INDEX(device_id, received_at)` | PASS | `…000009_create_waypost_chain_entries_table.php:35-37` | kód | `projected_at` és index is (halasztott vetület) |
| DB-05 | `shifts`, `rounds`, `scans`, `incidents`, `media`, `reports` mezői | PASS | `…000010` – `000013` | kód; teszt | A `media` a hivatkozáskor jön létre, `path = null`-lal (Spec: „path? (null, amíg nincs feltöltve)”) |
| DB-06 | A vetülettáblákat a szerver a lánc feldolgozásakor tölti | PASS | `src/Chain/Projector.php`, `src/Chain/ChainProcessor.php` | kód; teszt (`Chain/*`) | |
| DB-07 | Felsorolások (enum) | PASS | `src/Enums/*`, migrációk (`string`) | teszt (`EnumsTest`: a sémával összevetve); dönt. Q14 | A DB-`enum` helyett szöveg + PHP enum, ami a 2. lépcső bővíthetősége miatt előnyös |

## 3. Azonosítás (Spec: *Azonosítás*)

| ID | Követelmény | Státusz | Fájlok | Bizonyíték | Megjegyzés |
| --- | --- | --- | --- | --- | --- |
| AUTH-01 | Az első supervisort a `waypost:create-supervisor` hozza létre; nincs regisztráció | PASS | `src/Console/CreateSupervisorCommand.php` | teszt (`CreateSupervisorCommandTest`) | |
| AUTH-02 | `POST /auth/login` (email, password, device_name) → `supervisor` képességű token, amely nem jár le, és kijelentkezéskor törlődik | PASS | `src/Http/Controllers/AuthController.php:26-41` | teszt (`LoginTest`) | Jelszócserekor a régi tokenek maradnak (WP-BE-005). Időzítési felderítés lehetséges (WP-BE-012) |
| AUTH-03 | A supervisor csak a hozzárendelt telephelyeit látja és módosítja (Policy-k) | PASS | `src/Auth/Gate.php`, `src/Auth/Policies/*`, `src/Auth/Caller.php` | teszt (`PoliciesTest`, `EndpointsTest`, a végpontok tesztjei); dönt. „Policy-k (M2-15)” | Saját tokennel teljesül. Az `X-Acting-User` esete külön sorban (AUTH-09) |
| AUTH-04 | A supervisor más supervisorokat és őröket hozhat létre, és a saját telephelyeihez rendelheti | PASS | `src/Http/Controllers/UserController.php:51-71`, `SiteUserController.php:40-64` | teszt (`UsersTest`, `SiteUsersTest`) | Szigorúbb a Spec-nél: más felhasználót csak az módosíthat, aki annak minden telephelyét kezeli (dokumentált) |
| AUTH-05 | Enroll: `POST /devices/enroll` supervisor tokennel; `devices` rekord, 32 bájtos titok, `device` képességű token és base64 titok, amelyeket később soha nem ad ki | PASS | `src/Http/Controllers/DeviceController.php` | teszt (`DevicesTest`) | A titok `encrypted` casttal (WP-BE-033). Az idempotencia-válasz titkosítva tárolódik |
| AUTH-06 | Új telefonnál a régi visszavonása; a visszavont kérése 401; `POST /sync/push-final` egyszer | PASS | `src/Http/Middleware/Authenticate.php`, `SyncController.php` | teszt (`SyncPushFinalTest`, `AuthenticationTest`) | `device_revoked` (401) |
| AUTH-07 | PIN: 6 jegy; ha nincs megadva, a szerver generál, és egyszer mutatja; PBKDF2-HMAC-SHA256, 16 bájtos só, 100 000 iteráció; a hash, a só és az iterációszám a pullal az eszközre kerül | PASS | `src/Pin/PinHasher.php`, `src/Sync/Pull.php`, `src/Http/Entities.php` | teszt (`PinHasherTest` a `pin/test-vectors.json`-nal, `UsersTest`, `SyncPullTest`) | Offline visszafejthetőség: tervezési kockázat (WP-BE-031) |
| AUTH-08 | Ugyanaz a PIN nem lehet két felhasználónál egy telephelyen; a szerver ellenőrzi | PARTIAL | `src/Pin/SitePins.php`, `Concerns/AssignsPins.php` | teszt (`UsersTest`, `SitePinsTest`) | Versenyhelyzetben nem garantált (WP-BE-010); CPU-igényes (WP-BE-011) |
| AUTH-09 | A telephelyi telefonon a supervisor műveletei az `X-Acting-User` nevében, **csak azon a telephelyen, amelyhez az eszköz tartozik** | FAIL | `src/Auth/Policies/UserPolicy.php:48-51`, `src/Http/Controllers/UserController.php:87-123` | kód | A saját e-mail, jelszó és PIN átírásával a supervisor összes telephelyére kiterjed (WP-BE-001). A többi végpontnál a korlát teljesül (`Caller::siteIds()`) |
| AUTH-10 | PIN-zárolás (5 hiba → 5 perc, duplázás), uptime alapján | NOT APPLICABLE | — | — | Az app felelőssége |

## 4. API-szerződés (Spec: *API-szerződés*)

| ID | Követelmény | Státusz | Fájlok | Bizonyíték | Megjegyzés |
| --- | --- | --- | --- | --- | --- |
| API-01 | REST, JSON, `/v1` előtag, Bearer token | PASS | `routes/api.php`, `config/waypost.php`, `src/Auth/TokenGuard.php` | teszt | |
| API-02 | Minden válaszban `server_time` (ISO 8601, UTC) | PASS | `src/Http/Middleware/FormatApiResponse.php`, `src/Http/ErrorRenderer.php` | teszt (`ErrorFormatTest`, a végpontok `toMatchSchema`-ja) | A hibákban és az útvonalon kívüli válaszokban is |
| API-03 | Minden író kérés `Idempotency-Key`-t visz; ugyanarra a kulcsra 24 órán át ugyanaz a válasz | PARTIAL | `src/Http/Middleware/HandleIdempotencyKey.php` | teszt (`IdempotencyTest`, 15 eset); dönt. M2-12, M5-03 | Félbeszakadt kérés után 24 órás „busy” (WP-BE-003). A login és a logout dokumentált kivétel |
| API-04 | Az összes végpont a táblázat szerinti tokennel (login, logout, enroll, pull, push, push-final, media, CRUD, reports) | PASS | `routes/api.php` | teszt (`EndpointsTest`: végpont × hívó mátrix) | Plusz `POST /devices/diagnostics` (Q2) és a két aláírt letöltés |
| API-05 | Pull: `cursor` (updated_at + id, átlátszatlan), üres kurzorral teljes állapot, legfeljebb 500 rekord, `has_more`, `deleted` | PASS | `src/Sync/Pull.php`, `src/Sync/PullCursor.php` | teszt (`SyncPullTest`, 14 eset) | A `SETTLE_MS` feltételezés (WP-BE-022); memóriaigény (WP-BE-021) |
| API-06 | Push: legfeljebb 200 bejegyzés, `seq` szerint növekvő; az újraküldés nem hiba; `accepted`, `last_seq`, `rejected` (`expected_seq`), `media_missing` | PASS | `src/Http/Controllers/SyncController.php`, `src/Chain/ChainProcessor.php`, `PushResult.php` | teszt (`SyncPushTest`, 28 eset) | `media_missing` korlát nélkül (WP-BE-018) |
| API-07 | Hibaformátum `{error:{code,message,details}}`, kódok: unauthenticated, device_revoked, forbidden, not_found, validation_failed, conflict, chain_*, rate_limited | PASS | `src/Http/ErrorRenderer.php`, `src/Enums/ErrorCode.php`, `src/Http/Exceptions/ApiException.php` | teszt (`ErrorFormatTest`) | `bad_request` és `server_error` a séma döntése szerint. A kivétel szövege nem szivárog ki |
| API-08 | Magyar, ember által olvasható üzenetek | PASS | `src/Validation/SchemaValidator.php`, controllerek | teszt | |

## 5. Szinkron protokoll – szerveroldal (Spec: *Szinkron protokoll*)

| ID | Követelmény | Státusz | Fájlok | Bizonyíték | Megjegyzés |
| --- | --- | --- | --- | --- | --- |
| SYN-01 | A konfigurációt a szerver, a láncot az eszköz birtokolja | PASS | `ChainProcessor.php`, a CRUD controllerek | kód | |
| SYN-02 | A szerver ellenőrzi a fotó sha256 lenyomatát | PASS | `src/Http/Controllers/MediaController.php:44-55` | teszt (`MediaUploadTest`, 23 eset) | A feltöltött és a bejegyzésben rögzített lenyomatot is. A képméretet nem (WP-BE-004) |
| SYN-03 | Az outbox 4xx-et kap a kliens hibájára (nem 5xx-et), és a 409 + Retry-After az újrapróbálás jele | PARTIAL | `ErrorRenderer.php`, `HandleIdempotencyKey.php` | teszt | Túl nagy gyalogidő → 500 (WP-BE-013); árva kulcs → végtelen 409 (WP-BE-003) |
| SYN-04 | Az entitás UUID-jét az app adja; ismételt létrehozás idempotens | PASS | `src/Http/Controllers/Controller.php` (`newId`, `existing`) | teszt (`RepeatedCreateTest`) | |
| SYN-05 | A futó kör nem vált útvonalverziót | PASS | `src/Models/Round.php`, `Projector.php:149-157` | kód | A kör a `route_version_id`-t a megnyitáskor rögzíti; a verzió nem módosítható |

## 6. Láncolt napló (Spec: *Láncolt napló*)

| ID | Követelmény | Státusz | Fájlok | Bizonyíték | Megjegyzés |
| --- | --- | --- | --- | --- | --- |
| CH-01 | Eszközönként 1-től folyamatos `seq` | PASS | `ChainProcessor.php:132-139` | teszt (`SyncPushTest`) | |
| CH-02 | `hash = hex(HMAC-SHA256(device_secret, canonical_json(entry)))`, RFC 8785 | PASS | `src/Chain/EntryHash.php`, `src/Chain/CanonicalJson.php` | teszt (`CanonicalJsonTest`, `EntryHashTest` a `chain/test-vectors.json`-nal) | `hash_equals`. `{}` / nem ASCII kulcsok: lásd WP-BE-030 (ma nincs hatása) |
| CH-03 | A `null` mezők benne maradnak | PASS | `src/Models/ChainEntry.php` (`content()`) | teszt (vektorok) | |
| CH-04 | A lenyomatolt adatban nincs lebegőpontos szám | PASS | `CanonicalJson.php:44-51` | teszt | Nem egész lebegőpontos számra kivételt dob |
| CH-05 | Bejegyzéstípusok és payloadok (6 típus) | PASS | `src/Enums/ChainEntryType.php`, `Projector.php:43-50` | teszt (`EnumsTest`, `ProjectorTest`) | |
| CH-06 | A fotó sha256-ja a payloadban (`media: [{id, sha256}]`) | PASS | `Projector.php:58-78`, `MediaController.php:53-55` | teszt | |
| CH-07 | Feldolgozás eszközönként, `seq` sorrendben, zárolt tranzakcióban | PASS | `ChainProcessor.php:38-65` | kód (`lockForUpdate`); teszt | Egy vetületkivétel az egészet visszagörgeti (WP-BE-008) |
| CH-08 | 1. `gap`: tárolódik, a vetületbe csak a hiány pótlása után kerül; 2. `fork`; 3. `bad_hmac`; 4. `ok` → `last_seq`, `last_hash`, idempotens vetület a payload ID-k alapján | PASS | `ChainProcessor.php:69-186`, `Projector.php` | teszt (gap, fork, bad_hmac, ismétlés, várakozó bejegyzés elfogadása) | A gap HMAC nélkül tárolódik (WP-BE-017; a Spec ezt megengedi) |
| CH-09 | A vetület az eszköz telephelyére vonatkozik (a Spec *Leolvasás 1.* és *Azonosítás* szabályaiból következik) | PARTIAL | `Projector.php:143-147`, `:182-185`, `:267-271` | kód | A műszak és a kör az eszközhöz kötött; az útvonalverzió, a menetrend, a pont és a leolvasás nem (WP-BE-002) |
| CH-10 | Szerverjelölések: `time_suspect`, `too_fast`, `far_from_checkpoint`, `late_sync` a táblázat feltételeivel | PASS | `src/Chain/ServerFlags.php`, `TrustedTime.php`, `Projector.php:121-135`, `:187-198` | teszt (`ServerFlagsTest`, `ProjectorTest`) | A `ref_server_time` kliensadat (a Spec szerint) |
| CH-11 | Az `ndef_raw` eltárolódik | PASS | `chain_entries.payload` | kód | A payload változatlanul tárolódik |
| CH-12 | A megőrzési törlés után a `devices.last_seq` és a `last_hash` megmarad | PASS | `src/Jobs/PruneExpiredData.php`, `ChainProcessor.php:120-130` | teszt (`Reports/PruneExpiredDataTest`), kód | A már törölt bejegyzés ismétlését a HMAC dönti el |

## 7. Állapotgépek és üzleti szabályok – szerveroldal (Spec: *Állapotgépek és üzleti szabályok*)

| ID | Követelmény | Státusz | Fájlok | Bizonyíték | Megjegyzés |
| --- | --- | --- | --- | --- | --- |
| ST-01 | A menetrend a telephely időzónájában, DST-vel | PASS | `src/Schedule/Expansion.php` | teszt (`schedule/test-vectors.json`); dönt. Q5 | |
| ST-02 | `list` és `interval` mód, mindkét vég benne; éjfélen átnyúló ablak a kezdő napé; `weekdays` a kezdő napra | PASS | `Expansion.php` | teszt (vektorok) | |
| ST-03 | Az időkeret alapértéke: `ceil(walk_duration_s × 1,5 / 300) × 5`, mért érték nélkül 20 perc; felülírható vagy `null` | PASS | `src/Schedule/TimeLimit.php`, `ScheduleController.php` | teszt (`TimeLimitTest`, `SchedulesTest`) | |
| ST-04 | Határidő: `scheduled_at + time_limit_min`; időkeret nélkül a következő kör, a nap utolsójánál +60 perc | PASS | `src/Schedule/Deadline.php` | teszt | |
| ST-05 | Az elmaradt köröket a szerver a menetrendből számolja | PASS | `src/Reports/Summarizer.php:84-135` | teszt (`Reports/*`) | |
| ST-06 | A kör „folyamatban” az első érvényes leolvasástól (`started_at`) | PASS | `Projector.php:218-228` | teszt | Ismeretlen címke nem indítja |
| ST-07 | A kör zárásának állapota (completed / incomplete / missed), a műszak szabályai, a leolvasás feloldása | NOT APPLICABLE | `Projector.php:162-173` | — | Az app domainje; a szerver a `round_closed` állapotát elfogadja |
| ST-08 | Ugyanaz a címke nem tartozhat két ponthoz egy telephelyen (409) | PASS | `…000005…checkpoints_table.php:32`, `CheckpointController.php` | teszt (`CheckpointsTest`) | |
| ST-09 | Minden módosítás új útvonalverziót hoz létre; a verzió nem változik | PASS | `RouteVersionController.php`, `src/Models/RouteVersion.php` | teszt (`RoutesTest`); kód (a modell frissítéskor kivételt dob) | |

## 8. Jelentések, figyelmeztetések, adatmegőrzés (Spec: *Jelentések, figyelmeztetések és adatmegőrzés*)

| ID | Követelmény | Státusz | Fájlok | Bizonyíték | Megjegyzés |
| --- | --- | --- | --- | --- | --- |
| REP-01 | Műszakjelentés a `shift_closed` feldolgozásakor, legfeljebb 30 perc várakozással a fotókra, HTML e-mail | PASS | `src/Jobs/SendShiftReport.php`, `Projector.php:102-119` | teszt (`Reports/ShiftReportTest`) | |
| REP-02 | Napi: minden nap 10:00 (telephelyi idő), az előző napra | PASS | `src/Jobs/SendDueReports.php`, `src/Reports/ReportPeriod.php` | teszt | 12 órás pótlás leállás után (dokumentált) |
| REP-03 | Heti: hétfő 10:00, az előző hétfő–vasárnap, e-mail + PDF | PASS | `SendPeriodReport.php`, `Pdf.php` | teszt | |
| REP-04 | Havi: elseje 10:00, az előző hónap, e-mail + PDF | PASS | ugyanott | teszt | |
| REP-05 | Tartalom: műszak, napi, heti/havi elemei; a PDF beágyazott bélyegképekkel | PASS | `ReportContent.php`, `Summarizer.php`, `resources/views/*`, `Thumbnails.php` | teszt (`Mail::fake()`, `ReportMail::$content`) | A PDF-ek **kézi átnézését** (M6 elfogadása) nem végeztem el; a `tasks.md` szerint megtörtént |
| REP-06 | A nem teljes adat jelzése; a kiküldött műszakjelentés nem megy ki újra; az összesítők a teljes adatból készülnek | PASS | `Summarizer.php` (`incompleteReasons`), `ReportSender.php` | teszt | |
| REP-07 | Címzettek a `report_subscriptions` szerint; minden kiküldött jelentés a `reports` táblába; magyar szöveg; feladó a konfigurációból | PASS | `ReportSender.php:39-52`, `config/waypost.php` | teszt | |
| REP-08 | Egy jelentés egyszer megy ki (Spec: „nem megy ki újra”; dönt. „Egyszer”) | PARTIAL | `ReportSender.php:59-103` | kód | Részleges hiba után kettős küldés (WP-BE-020); fatális hiba után végleges elvesztés (WP-BE-004) |
| REP-09 | „Nincs adat”: 15 percenként, `no_data_alert_after_min` és `last_seen_at` szerint, ablakonként legfeljebb egyszer | PASS | `src/Jobs/SendNoDataAlerts.php`, `…000017_no_data_alerts…` | teszt (`Reports/NoDataAlertTest`) | Egyedi `(site_id, window_day)` |
| RET-01 | Megőrzés: `max(31, 2 × leghosszabb előfizetett időszak)` | PASS | `src/Reports/Retention.php` | teszt | A lemondás azonnali hatása: WP-BE-039 |
| RET-02 | Naponta futó job: a régebbi láncbejegyzések, vetületek, médiafájlok és PDF-ek törlése | PASS | `src/Jobs/PruneExpiredData.php`, `WaypostServiceProvider.php:174` | teszt | Kliensidők befolyása: WP-BE-014 |
| RET-03 | A konfigurációs adat nem törlődik automatikusan | PASS | `PruneExpiredData.php` | kód | Az árva pontfotó sem (WP-BE-015) |

## 9. Tesztelés (Spec: *Tesztelés*, mérföldkövek elfogadása)

| ID | Követelmény | Státusz | Fájlok | Bizonyíték | Megjegyzés |
| --- | --- | --- | --- | --- | --- |
| TST-01 | Pest + Testbench; feature teszt minden végpontra, jogosultsági esetekkel | PASS | `tests/Feature/Api/*`, `EndpointsTest.php` | futtatás: 606/606 (MySQL 8.4: 187 s; PostgreSQL 16: 121 s) | Hiányzó eset: az acting supervisor saját hitelesítő adatai (WP-BE-001) |
| TST-02 | Unit teszt a láncellenőrzésre, a jelölésekre és a menetrend-kibontásra | PASS | `tests/Unit/Chain/*`, `tests/Unit/Schedule/*` | futtatás | |
| TST-03 | A közös tesztvektorok (lánc, menetrend, PIN) használata | PASS | `tests/Unit/*` | futtatás | |
| TST-04 | PHPStan (Larastan) 8-as szint hibamentes | PASS | `phpstan.neon.dist` | `composer analyse`: „[OK] No errors” | Két szűk `ignoreErrors`, indoklással |
| TST-05 | M4: tesztek a gap, fork, bad_hmac és idempotencia esetekre | PASS | `tests/Feature/Api/SyncPushTest.php`, `IdempotencyTest.php` | futtatás | |
| TST-06 | M6: Pest tesztek fixture-ökkel és `Mail::fake()`-kel | PASS | `tests/Feature/Reports/*` | futtatás | |
| TST-07 | Kézi eszközteszt (`device-test.md`) | NOT TESTED | — | — | Eszköz és app kell hozzá; az app korábbi auditja szerint sem futott |
| TST-08 | Párhuzamossági, terheléses próba | NOT TESTED | — | — | A Spec nem írja elő; a WP-BE-003, -010 és -019 miatt ajánlott |

## 10. Mérföldkövek – ennek a repónak a része (Spec: *Mérföldkövek*; `tasks.md`)

| ID | Követelmény | Státusz | Fájlok | Bizonyíték | Megjegyzés |
| --- | --- | --- | --- | --- | --- |
| MS-M2 | Migrációk, modellek, install, create-supervisor, login, CRUD Policy-kkel, enroll, pull; teszt minden végpontra; sémamegfelelés; PHPStan 8 | PASS | — | futtatás; `tasks.md` kipipálva | |
| MS-M4 | Push, média, láncellenőrzés, vetületek, jelölések, push-final (backend) | PARTIAL | — | futtatás | A backend tesztjei zöldek; a „2 óra repülőgép-üzemmód” eszközpróba NOT TESTED; WP-BE-002, -008 |
| MS-M5 | A supervisor mód backendje (outbox-szerződés, `X-Acting-User`) | PARTIAL | — | futtatás | WP-BE-001 (FAIL jellegű), WP-BE-003 |
| MS-M6 | Jelentések, figyelmeztetés, adatmegőrzés | PASS | — | futtatás | A PDF kézi átnézését nem ismételtem meg |
| MS-M7 | Próbaidőszak (7 éjszaka adatvesztés nélkül, minden jelentés megérkezik), M7-01 telepítési leírás | NOT TESTED | — | `tasks.md`: nincs kipipálva | Még nem végezték el (WP-BE-042) |

## 11. A 2. lépcső előkészítése (Spec: *A 2. lépcső előkészítése*)

| ID | Követelmény | Státusz | Fájlok | Bizonyíték | Megjegyzés |
| --- | --- | --- | --- | --- | --- |
| P2-01 | A `chain_entries.type` bővíthető (pl. `sos`); a helykövetés külön táblába kerül | PASS | `…000009…` (`string`), `src/Enums/ChainEntryType.php` | kód | Új típushoz a `Projector`-ban is kell egy ág (WP-BE-041) |
| P2-02 | Verziózott API (`/v1`), új funkció új végpontként | PASS | `config/waypost.php`, `routes/api.php` | kód | |
| P2-03 | `alert_settings` tábla később; nem kizárt | PASS | — | kód | Semmi nem ütközik vele |
| P2-04 | Push tokenek később a `devices`-ben vagy új táblában | PASS | — | kód | |
| P2-05 | Az app szinkronja REST marad | PASS | — | kód | |

## 12. A repó `CLAUDE.md` szabályai (szúrópróba)

| ID | Követelmény | Státusz | Fájlok | Bizonyíték | Megjegyzés |
| --- | --- | --- | --- | --- | --- |
| CL-01 | Minden tábla neve `Table::name()`, beégetett `waypost_` sehol | PASS | `src/`, `database/`, `routes/` | grep | Csak a `Table` alapértéke |
| CL-02 | `datetime(3)`, nem `timestamps()` / `timestamp()` | PASS | migrációk | teszt (`MigrationsTest`) | |
| CL-03 | Aktuális idő `Time::now()`, nem `now()` | PASS | `src/` | grep | |
| CL-04 | `declare(strict_types=1)` minden fájlban | PASS | `src`, `config`, `database`, `routes`, `tests`, `workbench` | szkript minden PHP-fájlra | Nincs kivétel |
| CL-05 | `Sanctum::usePersonalAccessTokenModel()` tilos | PASS | `src/` | grep | Csak kommentben szerepel |
| CL-06 | Nincs `src/Schema/` könyvtár | PASS | `src/` | `ls` | |
| CL-07 | Pint `per` preset | PASS | `pint.json` | `composer lint`: PASS, 218 fájl | |
| CL-08 | Nyers SQL csak indokoltan | PASS | `src/Reports/Summarizer.php:318` | grep | Egyetlen `selectRaw`, konstans szöveggel, felhasználói bemenet nélkül |
| CL-09 | Blade: nincs escape nélküli kimenet | PASS | `resources/views/` | grep (`{!!`: nincs találat) | |

---

## 13. Laravel package skeleton megfelelés

Összevetés a `laravel/package-skeleton` élő repójával (az audit napján). A skeleton egy kiindulópont, nem kötelező szabvány. Az eltérés csak akkor hiba, ha a skeleton egy minőségi garanciát ad, ami itt hiányzik.

| ID | Skeleton-elem | Státusz | Itt | Megjegyzés |
| --- | --- | --- | --- | --- |
| SK-01 | `composer.json`: `type: library`, PSR-4 `src/`, `autoload-dev` a tesztekre, `extra.laravel.providers` | PASS | `composer.json` | Auto-discovery a `WaypostServiceProvider`-re |
| SK-02 | Függőség: `illuminate/support` (és a szükséges `illuminate/*`) | PARTIAL | `laravel/framework ^13.0` | Dokumentált döntés („Függőségek és verziók”). A teljes keretrendszer nagyobb függőség, de egy teljes backendcsomagnál indokolható |
| SK-03 | Service provider: config merge, publikálható config, migrációk, nézetek | PASS | `src/WaypostServiceProvider.php` | `waypost-config`, `waypost-migrations`, `waypost-views`. A migrációkat az `waypost:install` saját maga másolja (indokolt) |
| SK-04 | `config/<csomag>.php` | PASS | `config/waypost.php` | |
| SK-05 | `database/migrations`, `database/factories` | PASS | | Factory minden modellhez (`#[UseFactory]`) |
| SK-06 | `resources/views` | PASS | | `waypost::` névtér |
| SK-07 | `lang/` (fordítások) | PARTIAL | nincs | A magyar szöveg kódban van (`Labels`, üzenetek); a Spec csak magyart kér. Indokolt, de a 2. lépcső többnyelvűségét nehezíti |
| SK-08 | Facade | NOT APPLICABLE | nincs | A csomag API-ja HTTP; nincs szükség rá |
| SK-09 | Pest + Orchestra Testbench + workbench | PASS | `tests/`, `testbench.yaml`, `workbench/` | |
| SK-10 | PHPStan (Larastan) | PASS | `phpstan.neon.dist` (8-as szint) | |
| SK-11 | Pint | PASS | `pint.json` (`per`) | A skeleton Laravel presetet használ; a `per` dokumentált döntés |
| SK-12 | Típuslefedettség (`pest-plugin-type-coverage`, `test:types`) | FAIL | nincs | WP-BE-029 |
| SK-13 | CI munkafolyamat (`.github/workflows/tests.yml`: PHP 8.3/8.4/8.5 × Laravel 12/13 × lowest/stable, Ubuntu + Windows; analyse, lint, types, unit) | FAIL | nincs | WP-BE-007. A PHP 8.3 és a Laravel 12 itt nem cél (`php ^8.4`, Laravel 13), ezért a mátrix szűkíthető |
| SK-14 | `.gitattributes` export-ignore | FAIL | nincs | WP-BE-024 |
| SK-15 | `.editorconfig` | FAIL | nincs | WP-BE-024 |
| SK-16 | LICENSE fájl | FAIL | nincs (`license: proprietary`) | WP-BE-024 |
| SK-17 | README a telepítéssel és a használattal | PASS | `README.md` | Üzemeltetési hiányok: WP-BE-028 |
| SK-18 | CHANGELOG | PASS | `CHANGELOG.md` (0.1.0, 0.1.1, 0.2.0), git tagek | |
| SK-19 | `composer validate` | PASS | | Lefutott, hiba nélkül |
| SK-20 | Telepíthetőség a gazdában verziózott forrásból | PARTIAL | `composer.json` `repositories` (path `../waypost-schema`) | A `repositories` csak a gyökérprojektben érvényes. Egy gazdában a `waypost/schema ^0.5`-nek Packagistről vagy privát tárolóból kell feloldódnia; ezt az M7-01 telepítési leírásának kell rögzítenie (nem ellenőriztem, hálózat kell) |
