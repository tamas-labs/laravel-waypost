# laravel-waypost

A Waypost backendje Laravel 13 csomagként (`waypost/laravel-waypost`): migrációk, modellek, REST API (login, eszköz-hozzárendelés, konfigurációs CRUD, pull, push, média), a láncolt napló ellenőrzése és vetületei, jelentések, figyelmeztetések, adatmegőrzés. Egy tetszőleges Laravel 13 alkalmazásba telepíthető (`composer require waypost/laravel-waypost`, `php artisan waypost:install`).

## Mérvadó dokumentumok

- Fejlesztői specifikáció: [`../waypost/docs/Waypost – fejlesztői specifikáció (1. lépcső).md`](../waypost/docs/Waypost%20–%20fejlesztői%20specifikáció%20(1.%20lépcső).md) – minden viselkedés, szabály, tábla és végpont forrása. Ennek a repónak a fő szakaszai: *Repo és technológiai stack*, *Adatbázis*, *Azonosítás*, *API-szerződés*, *Láncolt napló*, *Jelentések, figyelmeztetések és adatmegőrzés*.
- Feladatlista: [`../waypost/docs/tasks.md`](../waypost/docs/tasks.md) – ennek a repónak az M2 és az M6 mérföldkő, az M4 és az M5 backendfeladatai és az M7-01 szól. A nyitott kérdések (`Q#`) a fájl végén.
- Döntések: [`docs/decisions.md`](docs/decisions.md) – ami a specifikációban nincs eldöntve, ide kerül (dátum, kérdés, döntés, indok, érintett).
- A közös szerződés: `../waypost-schema` (`waypost/schema`, Composer `path` repository) – JSON Schemák, lánc- és menetrend-tesztvektorok, a `Waypost\Schema\Paths` segéd. A sémadöntések a `../waypost-schema/docs/decisions.md`-ben. Ha az API vagy a lánc formátuma változik, **előbb ott** kell módosítani, új verzióval, és csak utána itt. A várt tag a `.waypost-schema-version` fájlban van (a `tests/Unit/SchemaPackageTest.php` veti össze a `Paths::VERSION`-nel és a `composer.json` `require` és `options.versions` értékével): új sémaverziónál mindhármat át kell írni, utána `composer update waypost/schema`. Minden válasz `X-Waypost-Schema` fejlécet kap (`FormatApiResponse`).

## Szerkezet

```text
config/waypost.php      # route prefix és middleware, tábla-előtag, storage disk, queue, mail feladó
database/migrations/    # a csomag táblái; publikálja (waypost-migrations tag), nem tölti be
database/factories/     # Waypost\Database\Factories\ – minden modellhez (a modelleken #[UseFactory])
routes/api.php          # az API útvonalai (WaypostServiceProvider::apiRoutes() tölti be)
src/
  WaypostServiceProvider.php
  Models/               # Model (alaposztály) és a táblák modelljei; PersonalAccessToken (saját Sanctum-tokentábla); MorphMap (a polimorf oszlopok `waypost.` aliasai)
    Concerns/           # HasPrefixedTable, HasUuidV7PrimaryKey, StoresUtcMilliseconds, HasWaypostTokens
  Enums/                # a felsorolások (Role, TagType, ChainEntryType …) – az értékek a sémákéi
  Casts/                # LocalTime (HH:MM ↔ time oszlop)
  Console/              # waypost:install, waypost:create-supervisor
  Pin/                  # PinHasher (PBKDF2, generálás), PinHash, SitePins (egyediség telephelyen belül)
  Support/              # Table (tábla-előtag), Uuid (UUIDv7), Time (UTC, ISO 8601 ezredmásodperccel), Logger (a csomag naplója, waypost.log_channel)
  Auth/                 # TokenGuard ("waypost" guard), Caller (a kérés hívója), Gate + Policies/ (a csomag saját gate-je)
  Http/                 # ErrorRenderer (hibaformátum), Entities (a rekordok API-alakja, a pullé is), SignedUrls (aláírt fotó- és PDF-linkek), Exceptions/ApiException
    Middleware/         # FormatApiResponse, Authenticate, ParseMultipartBody, HandleIdempotencyKey, ValidateSchema
    Controllers/        # Controller (alaposztály: newId, write, invalid …), Concerns/AssignsPins, a végpontok
  Validation/           # SchemaValidator (opis/json-schema, magyar üzenetek mezőnként)
  Jobs/                 # PruneIdempotencyKeys (óránként), SendShiftReport (a shift_closed indítja), SendDueReports (15 percenként) → SendPeriodReport,
                        # SendNoDataAlerts (15 percenként, no_data_alerts), PruneExpiredData (naponta 03:30, adatmegőrzés)
  Schedule/             # Expansion (menetrend → várt körök, DST a Q5 szerint), Deadline (a kör határideje), TimeLimit (az időkeret alapértéke)
  Sync/                 # Pull (a pull egy lapja: láthatóság, deleted, reset), PullCursor
  Chain/                # CanonicalJson, EntryHash (HMAC), ChainProcessor (a push), Projector (vetületek), ServerFlags, TrustedTime, UnreadableDeviceSecret (az APP_KEY-csere után)
  Reports/              # Summarizer (ExpectedRounds: a várt időpontok, RoundRows: a körsorok) → Summary (egy időszak vagy műszak jelentésadata: RoundRow, RoundOutcome, IncompleteReason), Retention (megőrzési idő),
                        # ReportPeriod (mikor esedékes), ReportContent (a sablonok adata; Labels, LocalFormat, Palette), ReportSender (címzettek, egyszeri küldés, reports sor), Pdf (dompdf), Thumbnails (GD)
  Mail/                 # ReportMail (minden jelentés e-mailje, a heti/havi PDF-fel), NoDataAlertMail
resources/views/        # waypost:: nézetek: mail/ (shift-report, period-report, no-data-alert, partials/), pdf/period-report
tests/                  # Pest: Unit/, Feature/ (Api/: az API-keret), Fixtures/ (csak tesztben használt modellek); Pest.php: schema(), schemaPattern(), WAYPOST_TABLES, artisanCommand(), toMatchSchema(), supervisorToken(), deviceToken(), supervisorOf(), callApi(), requestApi(), pullAs(), pushAs(), uploadMedia(), jpegBytes(), realJpegBytes(), packageLog(), és a jelentések adatai: patrolledSite(), shiftOf(), roundOf(), scanOf(); Fixtures/ChainBuilder: érvényesen aláírt lánc
  Install/              # InstallTestCase: workbench nélküli alkalmazás, mint a composer require után
scripts/install-smoke.sh  # telepítés egy friss laravel/laravel 13-ba (Docker, hálózat kell)
workbench/              # Testbench workbench: routes/web.php (állapotoldal, /reports: a jelentések előnézete), database/seeders (demóadat; ReportDemoSeeder: 40 éjszaka jelentésadat)
testbench.yaml          # a workbench alkalmazás: env (MySQL), provider, migrációk, build lépések
docker/ compose.yaml    # fejlesztői környezet: PHP 8.4 + MySQL 8.4 (+ PostgreSQL 16 a pgsql profillal)
```

A gyökérnévtér `Waypost\` (`src/`), és a sémacsomagé `Waypost\Schema\` – ezért itt **nem lehet** `src/Schema/` könyvtár (a sémaellenőrzés pl. `src/Validation/` alá kerüljön).

## Szabályok

- A specifikáció *Alapszabályok* pontjai érvényesek: a felhasználónak szóló szöveg (hibaüzenet, e-mail, PDF) magyar; a kód, az azonosítók, a kommentek és a commitüzenetek angolok. A 2. lépcső funkcióit nem építjük meg, csak nem zárjuk ki.
- A csomag nem nyúl a gazda-alkalmazás tábláihoz. Minden tábla neve `Table::name('users')` (alapból `waypost_users`, a `waypost.table_prefix` szerint) – migrációban, `constrained()`-ben, `belongsToMany` pivotnál és nyers lekérdezésben is. Beégetett `waypost_` sehol.
- Modell: a `Waypost\Models\Model`-ből származik (előtag + UUIDv7 kulcs + UTC ezredmásodperccel; `$guarded = []`, mert a törzs a sémaellenőrzés után ér ide), és a `Waypost\Database\Factories\` alatt factoryja van. Ami nem származhat belőle (az id nélküli pivot, a Sanctum tokenmodellje), az a `Models/Concerns` traitjeit használja. Minden `@property` docblockban (a Larastan a dinamikus táblanevek miatt nem olvassa ki a migrációkból).
- Tokenek: a `User` és a `Device` a `HasWaypostTokens` traitet használja, a tokenek a saját `waypost_personal_access_tokens` táblában vannak. A `Sanctum::usePersonalAccessTokenModel()` tilos (globális, a gazda tokenjeit is érintené), és a Sanctum `sanctum` guardja nem látja ezeket a tokeneket.
- Elsődleges kulcs: UUIDv7, kisbetűs kanonikus alak (`Uuid::v7()`, `Uuid::isV7()`); a kliens által adott ID-t a modell megtartja. Migrációban `$table->uuid('id')->primary()`, idegen kulcs `foreignUuid()`.
- Idő: az adatbázisban UTC, `datetime(3)`: `$table->dateTime('x', 3)`, `$table->datetimes(3)`, `$table->softDeletesDatetime(precision: 3)`. **Nem** `timestamps()` / `softDeletes()` / `timestamp()`: a MySQL `timestamp` a kapcsolat időzónájával alakít (a migrációs teszt ellenőrzi). A gazda-alkalmazás időzónája nem számít: a modell UTC-be alakít íráskor és UTC-ként olvas. Aktuális idő: `Time::now()` (nem `now()`), különösen lekérdezési feltételben, mert a query builder nem alakít időzónát. API-kimenet: `Time::iso()` → `2026-10-27T20:00:00.000Z` (a modell `toArray()`/JSON is ezt adja); bemenet: `Time::fromIso()`. Az ezredmásodperc alatti rész levágódik.
- Szinkronizált tábla (saját ID-val): `datetimes(3)` + `softDeletesDatetime` + `index(['updated_at', 'id'])` (pull kurzor, Q3). Egyedi index soft delete mellett: a `not_deleted` generált oszloppal (`case when deleted_at is null then 1 end`, lásd `users`), a modellben `$guarded` és `$hidden`. Ütközés várható helyen (409) savepointban próbálkozz (`DB::transaction()`), mert PostgreSQL-en a hibás utasítás az egész tranzakciót megszakítja.
- Polimorf oszlop (`media.owner_type`, `idempotency_keys.owner_type`, `tokenable_type`): a `MorphMap` aliasát tárolja, ezért feltételben `MediaOwnerType::Scan->stored()` / `MorphMap::alias(Scan::class)`, sosem `Scan::class`. Új modell a `MorphMap::ALIASES`-ba is kerül (a `MorphMapTest` ellenőrzi).
- Felsorolás: `string` oszlop, nem DB-`enum`; a modell egy `src/Enums` backed enumra castol, amelynek értékeit a `tests/Unit/EnumsTest.php` a sémával veti össze. Új migráció új táblája a `tests/Pest.php` `WAYPOST_TABLES` listájába is kerüljön. A 0.2.0 óta minden sémaváltozás új migráció (a kiadott migrációk nem változnak). Minden idegen kulcsnak legyen indexe (a PostgreSQL és az SQLite nem hoz létre magától; a `MigrationsTest` ellenőrzi).
- PIN: a kódban csak a `Waypost\Pin\PinHasher`-rel (nem közvetlen `hash_pbkdf2`; teszt független ellenőrzésként számolhat így), az egyediség a `SitePins`-szel; a paramétereket a `waypost-schema` `pin/test-vectors.json`-ja rögzíti. E-mail-cím: a `User` kisbetűsen tárolja, keresni is kisbetűsítve kell.
- API-útvonal: a `routes/api.php`-ba, middleware-sorrend: `Authenticate::using(...)` (`supervisor` / `device` / `acting`), `ThrottleRequests::using('waypost')`, író kérésnél `HandleIdempotencyKey`, törzzsel `ValidateSchema::using('api/…request')`. A hibát `ApiException` (vagy Laravel-kivétel) jelzi, a választ az `ErrorRenderer` formázza; a controller (a `Http\Controllers\Controller` leszármazottja) tömböt ad vissza `server_time` nélkül (az `FormatApiResponse` teszi bele), létrehozáskor `self::created()` (201). A rekord a válaszban mindig a `Waypost\Http\Entities` alakjában (az entitássémáé, a pullé is); útvonal-paraméter `whereUuid`-dal. Jogosultság: a csomag `Waypost\Auth\Gate`-je a `Caller`-rel (nem a gazda `Gate` facade-ja), a policy-k a `src/Auth/Policies` alatt; a telephelyszűrés a `Caller::siteIds()` szerint. A válasz tesztje: `expect($response)->toMatchSchema('api/…')`; kérés a tesztben: `callApi($method, $path, $supervisor, $body, phone: $device)`, más fejlécekkel `requestApi()`, pull: `pullAs($userOrDevice, $cursor)` (az utolsó `Pull::SETTLE_MS` változásai még nem jönnek, a teszt utazzon előre); új útvonal a `tests/Feature/Api/EndpointsTest.php` listájába is; próba-útvonal: `WaypostServiceProvider::apiRoutes(fn () => …)`.
- Jelentés: az adat a `Summarizer`-ből (`period()` / `shift()`), a sablon csak a `ReportContent` kész, formázott értékeit kapja (magyar szöveg a `Labels`-ben, idő a telephely óráján a `LocalFormat`-tal); küldés csak a `ReportSender`-en át (egyszer, a `reports` sorral). E-mail-sablon: táblázatos, inline stílusú, 600 px; Blade-direktíva betű után nem működik (`szó@if` → `szó @if`). PDF: a `Pdf::render()`, a fotó data URI-ként (`Thumbnails`), hálózat és fájl nélkül; hosszú lista 25 soros, `table-layout: fixed` táblázatokban (a dompdf memóriája egy táblázat soraival négyzetesen nő), több kép egymás mellett `float`-tal. A tesztben `Mail::fake()` és `ReportMail::$content`; késleltetett jobhoz `queue.default` ≠ `sync` és `Queue::fake()`.
- Artisan-parancs kimenete magyar; a `$this->components->info()` pontot tesz a végére, ezért parancsjavaslattal ne végződjön az üzenet. A tesztben `artisanCommand()` (a `Pest\Laravel\artisan()` típusa `PendingCommand|int`).
- Napló: `Logger::channel()->…('Waypost: …', [azonosítók])`, nem a `Log` facade (a csatorna a `waypost.log_channel`); angol üzenet, a kontextusban azonosítók, titok és személyes adat nem. A tesztben `packageLog()` (spy a csomag csatornáján).
- Lebegőpontos szám a láncolt adatban sehol; a koordináta `lat_e7` / `lng_e7`, a pontosság `accuracy_dm` (egészek).
- Lánc: csak a `ChainProcessor` ír a `chain_entries`-be és a `devices.last_seq` / `last_hash`-be, zárolt tranzakcióban. A vetületet csak a `Projector` írja, idempotensen, és nem függhet a bejegyzések sorrendjétől (ismeretlen hivatkozásnál `false`, a következő push újrapróbálja). A vetület ideje `TrustedTime::of()`, nem a `device_time`. A `chain_entries.flags` csak a szerver jelöléseit tartalmazza (`ChainFlag`).
- Lekérdezési feltételbe ezredmásodperces időt szövegként tegyél (`format(Time::DATABASE_FORMAT)`), vagy hasonlíts PHP-ban: a query builder a `DateTimeInterface`-t másodpercre vágja.
- A bemenő JSON-t a `waypost/schema` sémái ellenőrzik (opis/json-schema); FormRequest nincs, a controller csak az adatbázisfüggő szabályokat nézi, és magyar hibát ad (`self::invalid()`, `ApiException::conflict()`). Ami csak a tárolt rekorddal együtt dönthető el, azt az entitásséma ellenőrzi mentés előtt (`SchemaValidator::assertValid()`). Ha egy sémamintát PHP-ban is használunk, a PCRE-be `D` módosítóval kerül (`/…$/D`), különben a `$` a záró sortörés előtt is illeszkedik; a teszt a sémafájlból olvasott mintával veti össze (`schemaPattern()`).
- PHPStan (Larastan) 8-as szint a `src`, `config`, `tests` és `workbench` alatt; `ignoreErrors` csak indokolt, szűk (`identifier` + `path`) kivétellel. A Pest-tesztekben `$this->…` helyett a `Pest\Laravel\…` függvények (`travelTo()`, `actingAs()` …), mert a PHPStan a closure `$this`-ét nem ismeri.
- Kódstílus: Pint, `per` preset (`pint.json`), `declare(strict_types=1)` minden fájlban.
- Ha egy feladat lezárul: `composer test`, `composer analyse`, `composer test:types` és `composer lint` zöld; a `../waypost/docs/tasks.md`-ben pipa; az eldöntött kérdés a `docs/decisions.md`-be kerül.

## Parancsok

Helyben nincs PHP és Composer: minden a Docker Compose `php` szolgáltatásában fut (PHP 8.4, a szülőkönyvtár a `/work`-be csatolva, hogy a `../waypost-schema` path repository feloldódjon). A `mysql` szolgáltatás (MySQL 8.4) magától elindul; adatbázisok: `waypost` (workbench) és `waypost_test` (tesztek).

- `docker compose run --rm php composer install` – függőségek. A `composer.lock` nincs commitolva (könyvtárcsomag).
- `docker compose run --rm php composer test` – Pest (MySQL, `waypost_test`). Egy fájl / szűrő: `… php vendor/bin/pest tests/Unit/TimeTest.php --filter=…`.
- `docker compose run --rm php composer analyse` – PHPStan.
- `docker compose run --rm php composer test:types` – típuslefedettség (Pest type coverage, `--min=100`): minden paraméter, visszatérési érték és tulajdonság típusos, a closure-öké is.
- `docker compose run --rm php composer lint` – Pint ellenőrzés; `composer format` – javítás.
- PostgreSQL 16-on: `docker compose --profile pgsql up -d --wait pgsql`, majd `docker compose run --rm -e DB_CONNECTION=pgsql -e DB_HOST=pgsql -e DB_PORT=5432 php composer test`. Migrációt vagy nyers lekérdezést érintő változásnál futtasd.
- `docker compose run --rm php sh scripts/install-smoke.sh` – a csomag telepítése egy friss Laravel 13 alkalmazásba (`composer require`, `waypost:install --migrate`, `waypost:create-supervisor`, belépés az alkalmazás HTTP-kernelén át, SQLite). A telepítést, a service providert vagy a csomag függőségeit érintő változásnál futtasd: a gazda valódi exception handlerét csak ez próbálja ki.
- `docker compose run --rm php composer build` – a workbench adatbázisának újraépítése (wipe + migrate + demó-seeder). `composer seed` – csak a seeder (üres, migrált adatbázison). `composer seed-reports` – a jelentések demóadata (`ReportDemoSeeder`, a `build` után; a `serve` mellett másik terminálban), az előnézet a workbench `/reports` oldalán. A demó belépési adatai a `workbench/database/seeders/DatabaseSeeder.php` konstansaiban.
- `docker compose run --rm --service-ports php composer serve` – build, majd a workbench a `http://localhost:8088` címen (`WAYPOST_PORT` felülírja; a 8000 a hoston foglalt).
- `docker compose run --rm php vendor/bin/testbench <artisan parancs>` – artisan a workbench alkalmazásban.
- `docker compose down` – leállítás; `down -v` a MySQL-adatokat is törli.
- CI: `.github/workflows/ci.yml` – `main`-pushra és PR-re: a `waypost-schema` a `.waypost-schema-version` tagjén, `composer test` MySQL 8.4-en, PostgreSQL 16-on és a legalacsonyabb függőségekkel (`--prefer-lowest`), `analyse`, `test:types`, `lint`, és a `scripts/install-smoke.sh`.
- Ha a hoston a 3306-os port foglalt (más projekt MySQL-je), `WAYPOST_MYSQL_PORT=3307 docker compose …`; a `-T` kapcsoló kell, ha nincs terminál (`docker compose run --rm -T php …`).
