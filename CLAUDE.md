# laravel-waypost

A Waypost backendje Laravel 13 csomagként (`waypost/laravel-waypost`): migrációk, modellek, REST API (login, eszköz-hozzárendelés, konfigurációs CRUD, pull, push, média), a láncolt napló ellenőrzése és vetületei, jelentések, figyelmeztetések, adatmegőrzés. Egy tetszőleges Laravel 13 alkalmazásba telepíthető (`composer require waypost/laravel-waypost`, `php artisan waypost:install`).

## Mérvadó dokumentumok

- Fejlesztői specifikáció: [`../waypost/docs/Waypost – fejlesztői specifikáció (1. lépcső).md`](../waypost/docs/Waypost%20–%20fejlesztői%20specifikáció%20(1.%20lépcső).md) – minden viselkedés, szabály, tábla és végpont forrása. Ennek a repónak a fő szakaszai: *Repo és technológiai stack*, *Adatbázis*, *Azonosítás*, *API-szerződés*, *Láncolt napló*, *Jelentések, figyelmeztetések és adatmegőrzés*.
- Feladatlista: [`../waypost/docs/tasks.md`](../waypost/docs/tasks.md) – ennek a repónak az M2 és az M6 mérföldkő, az M4 és az M5 backendfeladatai és az M7-01 szól. A nyitott kérdések (`Q#`) a fájl végén.
- Döntések: [`docs/decisions.md`](docs/decisions.md) – ami a specifikációban nincs eldöntve, ide kerül (dátum, kérdés, döntés, indok, érintett).
- A közös szerződés: `../waypost-schema` (`waypost/schema`, Composer `path` repository) – JSON Schemák, lánc- és menetrend-tesztvektorok, a `Waypost\Schema\Paths` segéd. A sémadöntések a `../waypost-schema/docs/decisions.md`-ben. Ha az API vagy a lánc formátuma változik, **előbb ott** kell módosítani, új verzióval, és csak utána itt.

## Szerkezet

```text
config/waypost.php      # route prefix és middleware, tábla-előtag, storage disk, queue, mail feladó
database/migrations/    # a csomag táblái; publikálja (waypost-migrations tag), nem tölti be
database/factories/     # Waypost\Database\Factories\ – minden modellhez (a modelleken #[UseFactory])
routes/api.php          # (M2-10-től)
src/
  WaypostServiceProvider.php
  Models/               # Model (alaposztály) és a táblák modelljei; PersonalAccessToken (saját Sanctum-tokentábla)
    Concerns/           # HasPrefixedTable, HasUuidV7PrimaryKey, StoresUtcMilliseconds, HasWaypostTokens
  Enums/                # a felsorolások (Role, TagType, ChainEntryType …) – az értékek a sémákéi
  Casts/                # LocalTime (HH:MM ↔ time oszlop)
  Support/              # Table (tábla-előtag), Uuid (UUIDv7), Time (UTC, ISO 8601 ezredmásodperccel)
  Http/ Policies/ Chain/ Schedule/ Reports/ Jobs/ Mail/ Console/   # a későbbi feladatokban
resources/views/        # e-mail és PDF sablonok (M6)
tests/                  # Pest: Unit/, Feature/, Fixtures/ (csak tesztben használt modellek); Pest.php: schema(), schemaPattern()
workbench/              # Testbench workbench: routes/web.php (állapotoldal), database/seeders (demóadat)
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
- Felsorolás: `string` oszlop, nem DB-`enum`; a modell egy `src/Enums` backed enumra castol, amelynek értékeit a `tests/Unit/EnumsTest.php` a sémával veti össze. Új migráció új táblája a `tests/Feature/MigrationsTest.php` listájába is kerüljön.
- Lebegőpontos szám a láncolt adatban sehol; a koordináta `lat_e7` / `lng_e7`, a pontosság `accuracy_dm` (egészek).
- A bemenő JSON-t a `waypost/schema` sémái ellenőrzik (opis/json-schema), a FormRequest csak az adatbázisfüggő szabályokat nézi. Ha egy sémamintát PHP-ban is használunk, a PCRE-be `D` módosítóval kerül (`/…$/D`), különben a `$` a záró sortörés előtt is illeszkedik; a teszt a sémafájlból olvasott mintával veti össze (`schemaPattern()`).
- PHPStan (Larastan) 8-as szint a `src`, `config`, `tests` és `workbench` alatt; `ignoreErrors` csak indokolt, szűk (`identifier` + `path`) kivétellel. A Pest-tesztekben `$this->…` helyett a `Pest\Laravel\…` függvények (`travelTo()`, `actingAs()` …), mert a PHPStan a closure `$this`-ét nem ismeri.
- Kódstílus: Pint, `per` preset (`pint.json`), `declare(strict_types=1)` minden fájlban.
- Ha egy feladat lezárul: `composer test`, `composer analyse` és `composer lint` zöld; a `../waypost/docs/tasks.md`-ben pipa; az eldöntött kérdés a `docs/decisions.md`-be kerül.

## Parancsok

Helyben nincs PHP és Composer: minden a Docker Compose `php` szolgáltatásában fut (PHP 8.4, a szülőkönyvtár a `/work`-be csatolva, hogy a `../waypost-schema` path repository feloldódjon). A `mysql` szolgáltatás (MySQL 8.4) magától elindul; adatbázisok: `waypost` (workbench) és `waypost_test` (tesztek).

- `docker compose run --rm php composer install` – függőségek. A `composer.lock` nincs commitolva (könyvtárcsomag).
- `docker compose run --rm php composer test` – Pest (MySQL, `waypost_test`). Egy fájl / szűrő: `… php vendor/bin/pest tests/Unit/TimeTest.php --filter=…`.
- `docker compose run --rm php composer analyse` – PHPStan.
- `docker compose run --rm php composer lint` – Pint ellenőrzés; `composer format` – javítás.
- PostgreSQL 16-on: `docker compose --profile pgsql up -d --wait pgsql`, majd `docker compose run --rm -e DB_CONNECTION=pgsql -e DB_HOST=pgsql -e DB_PORT=5432 php composer test`. Migrációt vagy nyers lekérdezést érintő változásnál futtasd.
- `docker compose run --rm php composer build` – a workbench adatbázisának újraépítése (wipe + migrate + demó-seeder). `composer seed` – csak a seeder (üres, migrált adatbázison). A demó belépési adatai a `workbench/database/seeders/DatabaseSeeder.php` konstansaiban.
- `docker compose run --rm --service-ports php composer serve` – build, majd a workbench a `http://localhost:8088` címen (`WAYPOST_PORT` felülírja; a 8000 a hoston foglalt).
- `docker compose run --rm php vendor/bin/testbench <artisan parancs>` – artisan a workbench alkalmazásban.
- `docker compose down` – leállítás; `down -v` a MySQL-adatokat is törli.
