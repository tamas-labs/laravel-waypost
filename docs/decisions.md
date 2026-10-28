# Döntések – laravel-waypost

Ami a fejlesztői specifikációban nincs eldöntve, vagy attól eltér, itt szerepel. Formátum:

```markdown
## ÉÉÉÉ-HH-NN – Rövid cím

- **Kérdés:** …
- **Döntés:** …
- **Indok:** …
- **Érintett:** fájlok, feladatok (M#-##)
```

A közös szerződést (sémák, lánc, menetrend-kibontás) érintő döntések a `../waypost-schema/docs/decisions.md`-ben vannak.

## 2026-10-28 – Fejlesztői környezet: Docker Compose, MySQL

- **Kérdés:** Hol fusson a PHP, a Composer és az adatbázis fejlesztés közben, és milyen adatbázison fussanak a tesztek?
- **Döntés:** Docker Compose (`compose.yaml`): egy `php` szolgáltatás saját képből (`docker/php/Dockerfile`: `php:8.4-cli-alpine` + Composer 2 + `pdo_mysql`, `pdo_pgsql`, `intl`, `gd`, `bcmath`, `pcntl`, `pcov`, `zip`) és egy `mysql` szolgáltatás (`mysql:8.4`). A konténer a szülőkönyvtárat a `/work`-be csatolja, hogy a `../waypost-schema` path repository feloldódjon. A workbench a `waypost`, a tesztek a `waypost_test` adatbázist használják (az utóbbit a `docker/mysql/init.sql` hozza létre), így a tesztek nem törlik a workbench adatait. A Composer-gyorsítótár a host `~/.cache/composer` könyvtára. A workbench a hoston a 8088-as porton érhető el.
- **Indok:** Helyben nincs PHP és Composer (a `waypost-schema` is Dockerrel dolgozik). A specifikáció PHP 8.4-et ír elő, a `composer:2` kép viszont már PHP 8.5-öt hoz, ezért kell saját kép. A felhasználó döntése: az adatbázis MySQL; a tesztek is ezen futnak, hogy a `datetime(3)`, az egyedi kulcsok és a tranzakciók a valódi motoron legyenek kipróbálva. A PostgreSQL 16-os próba (M2-03) külön szolgáltatásként jöhet hozzá.
- **Érintett:** `compose.yaml`, `docker/`, `phpunit.xml.dist`, `testbench.yaml`, M2-01, M2-03.

## 2026-10-28 – Függőségek és verziók

- **Kérdés:** Milyen csomagokra és verziókra épüljön a váz?
- **Döntés:** `laravel/framework ^13.0` (a telepítéskor 13.39), `laravel/sanctum ^4.3`, `opis/json-schema ^2.6`, `barryvdh/laravel-dompdf ^3.1`, `waypost/schema ^0.1`; fejlesztéshez `orchestra/testbench ^11.3` (Laravel 13), `pestphp/pest ^5.3` (PHPUnit 13) és `pestphp/pest-plugin-laravel ^5.0`, `larastan/larastan ^3.13`, `laravel/pint ^1.32`. Az egyes `illuminate/*` komponensek helyett a teljes `laravel/framework` a függőség.
- **Indok:** A csomag a keretrendszer szinte minden részét használja (HTTP, adatbázis, queue, mail, scheduler, console, storage), és csak Laravel 13 alkalmazásba települ; a komponensenkénti felsorolás csak elcsúszási lehetőség lenne. A Pint nem szerepel a specifikációban, de olcsó, és a stílusvitákat megszünteti.
- **Érintett:** `composer.json`, M2-01.

## 2026-10-28 – A `waypost/schema` verziója a path repositoryból

- **Kérdés:** A Composer a `../waypost-schema` könyvtárat `dev-main` verziónak látja (a munkakönyvtár nem a `v0.1.0` tagen áll), így a `^0.1` feltétel nem teljesül. Hogyan hivatkozzunk rá?
- **Döntés:** A `path` repository `options.versions` mezője rögzíti a verziót (`"waypost/schema": "0.1.0"`), a `require` pedig `^0.1`. A sémacsomag új verziójánál (a `package.json` verziójával együtt) ezt is át kell írni. A csomag symlinkként kerül a `vendor/` alá, így a sémák változása azonnal látszik.
- **Indok:** Így a `require` a valódi, semver szerinti függést mondja ki, és nem kell `@dev` stabilitás. A gazda-alkalmazásba telepítéskor (M7-01) a `waypost/schema` forrását (VCS-repository vagy privát Packagist) akkor kell eldönteni.
- **Érintett:** `composer.json`, M2-01, M7-01.

## 2026-10-28 – A workbench és a tesztek alkalmazása

- **Kérdés:** Hogyan töltődjön be a csomag a workbench-ben és a tesztekben?
- **Döntés:** Egy közös `testbench.yaml`: környezeti változók (MySQL), a `WaypostServiceProvider` a `providers` listában, a csomag és a workbench migrációi a `migrations` listában, a build lépései `db-wipe`, `migrate-fresh` és `db-seed` (a demó-seederrel, lásd lent). A `tests/TestCase` a `WithWorkbench` traittel ugyanezt az alkalmazást használja; a teszt-adatbázist és a teszt-`APP_KEY`-t a `phpunit.xml.dist` adja. A workbench `/` útvonala egy JSON-állapotoldal (API-előtag, adatbázis).
- **Indok:** A Testbench a csomagfelderítést (`extra.laravel.providers`) a workbench-ben és a tesztekben kikapcsolja, ezért a providert kifejezetten meg kell adni; egy helyen elég. Az `APP_KEY` a titkosított castokhoz kell (`devices.secret`, M2-05).
- **Érintett:** `testbench.yaml`, `tests/TestCase.php`, `phpunit.xml.dist`, M2-01, M2-06.

## 2026-10-28 – Tábla-előtag a modellekben

- **Kérdés:** Hogyan kapja meg minden modell és migráció a konfigurálható `waypost_` előtagot?
- **Döntés:** Egy segéd, `Waypost\Support\Table::name('users')`, a `waypost.table_prefix` alapján; a migrációk ezt hívják. A modellek a `HasPrefixedTable` traittel a példányosításkor egyszer teszik az előtagot a tábla nevére (a kifejezett `$table` elé vagy az Eloquent alapértelmezett nevére). A Laravel adatbázis-kapcsolatának saját `prefix` beállítását nem használjuk.
- **Indok:** A `getTable()` felülírása kétszeres előtagot adna, mert az Eloquent a már feloldott nevet `setTable()`-lel másolja át az új példányokba (`newInstance()`, `replicate()`). A kapcsolatszintű prefix a gazda-alkalmazás tábláira is hatna.
- **Érintett:** `src/Support/Table.php`, `src/Models/Concerns/HasPrefixedTable.php`, M2-02, M2-03, M2-04.

## 2026-10-28 – UTC és ezredmásodperc a gazda-alkalmazás időzónájától függetlenül

- **Kérdés:** Az Eloquent az időbélyeget az alkalmazás időzónájának faliórás idejeként, átalakítás nélkül írja az adatbázisba, és másodperc pontossággal. Hogyan lesz minden tárolt idő UTC, `datetime(3)`?
- **Döntés:** A `StoresUtcMilliseconds` trait: az adatbázisformátum `Y-m-d H:i:s.v`; íráskor minden dátum UTC-be alakul; az adatbázisból olvasott (zóna nélküli) érték UTC-ként értelmeződik, minden más érték a saját zónájából alakul UTC-re; a szerializálás `Time::iso()` (`2026-10-27T20:00:00.000Z`). A lekérdezésekben a kód `Time::now()`-t és UTC-s példányokat használ, mert a query builder kötött paramétereit ez a trait nem éri el. Az ezredmásodperc alatti rész levágódik, nem kerekítődik.
- **Indok:** A gazda-alkalmazás bármilyen időzónát beállíthat (pl. `Europe/Budapest`); a lánc, a pull kurzor és a jelentések csak egységes UTC-értékkel működnek helyesen. A levágás megegyezik a JS `toISOString()` viselkedésével, és így az adatbázisban és az API-ban ugyanaz az érték szerepel.
- **Érintett:** `src/Support/Time.php`, `src/Models/Concerns/StoresUtcMilliseconds.php`, M2-02 és minden időt kezelő feladat.

## 2026-10-28 – Kódstílus: Pint `per` preset

- **Kérdés:** Milyen kódstílust kövessen a csomag?
- **Döntés:** Pint `per` preset (PER Coding Style) két eltéréssel: `fn ()` szóközzel, és az üres törzs nem kerül egy sorba. Minden fájlban `declare(strict_types=1)`.
- **Indok:** Ez a `waypost-schema` meglévő PHP-kódjának stílusa (`src/Paths.php`).
- **Érintett:** `pint.json`.

## 2026-10-28 – Törlések a pullban (Q3)

- **Kérdés:** Honnan tudja a pull, mi került a `deleted` listába? A specifikáció adatmodelljében csak a `users` és a `sites` táblán van `deleted_at`.
- **Döntés:** Minden saját ID-val rendelkező, szinkronizált tábla (`users`, `sites`, `devices`, `checkpoints`, `routes`, `schedules`, `report_subscriptions`) kap `created_at`, `updated_at` és `deleted_at` oszlopot (soft delete), és egy `(updated_at, id)` indexet a pull kurzorához. A törlés az `updated_at`-et is frissíti, így a kurzor a törlést is elhozza. A `devices` és a `report_subscriptions` táblán az időbélyegek csak szerveroldaliak (az entitássémában nincsenek). A `route_versions` nem változik és nem törlődik, a kurzora a `created_at`; a `route_version_checkpoints` a verzióval együtt jön. Ami a hívó számára láthatatlanná válik (pl. az őrt leválasztják a telephelyről), azt a `updated_at` frissítése hozza el, és a pull (M2-23) a láthatóság alapján teszi a `deleted` listába. Közös törlésnapló-tábla nincs.
- **Indok:** A pontokra, útvonalakra, menetrendekre és felhasználókra a láncból készült vetületek és az útvonalverziók hivatkoznak, ezért ezek a sorok fizikailag úgysem törölhetők; a soft delete egyetlen mechanizmus mindenre, és nem kell egy második táblát tranzakcióban szinkronban tartani.
- **Érintett:** `database/migrations/*`, M2-03, M2-16 – M2-21, M2-23.

## 2026-10-28 – Egyediség csak a nem törölt sorok között

- **Kérdés:** A soft delete mellett a `UNIQUE(site_id, tag_uid)`, a `UNIQUE(qr_payload)`, a `users.email` és a `UNIQUE(user_id, site_id, frequency)` a törölt sorokra is vonatkozna: egy törölt pont címkéje nem lenne újra kiosztható, egy lemondott jelentés nem lenne újra előfizethető. A MySQL nem ismeri a részleges indexet.
- **Döntés:** Ezeken a táblákon egy tárolt, generált oszlop: `not_deleted = case when deleted_at is null then 1 end`, és az egyedi index ezt is tartalmazza. A törölt sorban az értéke `null`, és a `null` sosem ütközik. Az oszlop a modellekben `$guarded` és `$hidden`.
- **Indok:** Ugyanúgy működik MySQL 8-on, PostgreSQL 16-on és SQLite-on, és az adatbázis maga biztosítja az egyediséget (nem csak a FormRequest, versenyhelyzetben is). Ütközéskor `UniqueConstraintViolationException` jön; PostgreSQL-en tranzakción belül savepointban (`DB::transaction()`) kell próbálkozni, mert ott a hibás utasítás az egész tranzakciót megszakítja (M2-17, M2-18, M2-21).
- **Érintett:** `users`, `checkpoints`, `report_subscriptions` migrációja, M2-17, M2-18, M2-21.

## 2026-10-28 – Időoszlopok: `datetime(3)`, nem `timestamp(3)`

- **Kérdés:** A Laravel `timestamps()` és `softDeletes()` MySQL-ben `timestamp` oszlopot hoz létre. Gond-e ez?
- **Döntés:** Igen; minden időoszlop `datetime(3)`: `$table->dateTime('x', 3)`, `$table->datetimes(3)`, `$table->softDeletesDatetime(precision: 3)`. A migrációs teszt minden táblára ellenőrzi.
- **Indok:** A MySQL `timestamp` a kapcsolat időzónájával alakít. Egy `Europe/Budapest` időzónájú szerveren a nyári időszámítás kezdetére eső UTC-értéket (pl. `2026-03-29 02:30:00`) nem létező helyi időként eltolná, és 2038 után nem tárol. A `datetime` értéket a csomag maga tartja UTC-ben (`StoresUtcMilliseconds`).
- **Érintett:** `database/migrations/*`, `CLAUDE.md`, M2-03, M2-04.

## 2026-10-28 – Felsorolások: szöveges oszlop + PHP enum (Q14)

- **Kérdés:** A specifikáció `enum(...)` oszlopokat ír (`role`, `tag_type`, `chain_entries.type` …). A 2. lépcső új értékeket hoz (`sos`, esetleg diszpécser szerep).
- **Döntés:** Minden felsorolás `string` oszlop (16, a `chain_entries.type` 32 karakter), a modell egy PHP backed enumra castol (`src/Enums`). Az érvényes értékeket a sémák ellenőrzik; egy unit teszt (`EnumsTest`) összeveti az enumokat a sémák `enum` listáival.
- **Indok:** Egy adatbázis-`enum` bővítése MySQL-en táblaátírással járó migráció; így egy új érték csak séma- és kódváltozás. Az enum-teszt miatt a PHP és a séma nem csúszhat el.
- **Érintett:** `src/Enums/*`, a migrációk, M2-04, Q14.

## 2026-10-28 – Idempotenciatábla (Q4)

- **Kérdés:** Hol tárolódnak az `Idempotency-Key` fejlécek és a hozzájuk tartozó válaszok?
- **Döntés:** `waypost_idempotency_keys`: `owner` (a token gazdája: `User` vagy `Device`, morph), `key` (UUID), `method`, `path`, `request_hash` (SHA-256), `response_status` és `response_body` (`null`, amíg az első kérés fut), `created_at`, `expires_at` (indexelve, a takarításhoz). Egyedi: `(owner_type, owner_id, key)`.
- **Indok:** A kulcs hívónként egyedi, így két eszköz véletlenül egyező kulcsa nem keveredik; a még futó kérés jelölése az egyidejű ismétlést is kezeli (M2-12).
- **Érintett:** `2026_10_28_000014_…`, `src/Models/IdempotencyKey.php`, M2-12, Q4.

## 2026-10-28 – Sanctum: saját tokentábla, a `Device` a token gazdája (Q8)

- **Kérdés:** A Sanctum alapból a gazda-alkalmazás `personal_access_tokens` tábláját használja, pedig a csomag nem nyúlhat a gazda tábláihoz. A `Sanctum::usePersonalAccessTokenModel()` globális, a gazda saját Sanctum-tokenjeit is átirányítaná. Kié legyen az eszköztoken?
- **Döntés:** A csomagnak saját táblája (`waypost_personal_access_tokens`, UUIDv7 kulccsal) és modellje van (`Waypost\Models\PersonalAccessToken`, a Sanctumé leszármazottja). A `User` és a `Device` a `HasWaypostTokens` traitet használja: ez a Sanctum `HasApiTokens`-e, de a `tokens()` reláció a saját modellre mutat. A globális Sanctum-beállítás érintetlen. Az eszköztoken gazdája a `Device` (nem egy technikai felhasználó). Következmény az M2-13-ra: a Sanctum `sanctum` guardja a globális modellben keres, ezért a csomag saját guardot regisztrál, amely a saját modell `findToken()`-jét hívja; a `tokenCan()` és a képességek a Sanctumé.
- **Indok:** Így a csomag egy saját Sanctumot használó gazda-alkalmazásba is telepíthető, és egyik fél tokenjei sem látszanak a másiknál. A `Device` mint gazda a visszavonást (`revoked_at`) és a `last_seen_at` frissítését közvetlenül adja.
- **Érintett:** `2026_10_28_000002_…`, `src/Models/PersonalAccessToken.php`, `src/Models/Concerns/HasWaypostTokens.php`, M2-05, M2-13, M2-14, M2-22, Q8.

## 2026-10-28 – Az adatmodell további részletei

- **Kérdés:** Mi az, amit a specifikáció adatmodellje nem dönt el, vagy ami a sémadöntések miatt eltér tőle?
- **Döntés:**
  - Helyadat egész számokkal a `checkpoints`, `scans` és `incidents` táblán: `lat_e7`, `lng_e7` (`int`), `accuracy_dm` (`unsigned int`) – a `waypost-schema` döntése szerint, a specifikáció `decimal` / `float` oszlopai helyett.
  - A vetülettáblák (`shifts`, `rounds`, `scans`, `incidents`), a `chain_entries`, a `media` és a `reports` a specifikáció szerint saját időmezőkkel élnek, `created_at` / `updated_at` nélkül; az `idempotency_keys` és a `route_versions` csak `created_at`-et kap.
  - `media`: a sor már akkor létrejön, amikor egy bejegyzés vagy pont hivatkozik a fotóra (`sha256`-tal); a `mime`, `size_bytes`, `path` és `uploaded_at` a feltöltésig `null`.
  - Új egyedi kulcsok: `route_version_checkpoints (route_version_id, checkpoint_id)` (egy pont egy verzióban egyszer szerepel, mert a körön belüli ismétlés nem számít), `reports (site_id, type, period_start)` (egy ismételt job nem küldi el kétszer).
  - Körkörös idegen kulcsok: a `routes.current_version_id` a `route_versions`, a `checkpoints.photo_media_id` a `media` migrációjában kap kényszert. A pivotok és az előfizetések a szülővel együtt törlődnek (`cascade`), a `current_version_id` és a `photo_media_id` `null`-ra áll; minden más hivatkozás védett (az adatmegőrzési job, M6, a gyerekektől a szülők felé töröl).
  - A modellek: a `User` és a `Device` is a `Waypost\Models\Model`-ből származik, és a Laravel `Authenticatable` (a `User` az `Authorizable`) traitjét használja; a `RouteVersionCheckpoint` egy `Pivot` (saját ID nélkül). A tömeges értékadás nyitott (`$guarded = []`), mert a kérés törzse csak a `additionalProperties: false` sémaellenőrzés után ér a modellhez. A `Shift` őr-relációja `guardUser()`, mert az Eloquentnek van `guard()` metódusa.
  - Factory-k a csomagban (`database/factories`, `Waypost\Database\Factories\`, a modelleken `#[UseFactory]`), hogy a gazda-alkalmazás tesztjei is használhassák. A sorszámot (`version`, `seq`, `position`) az adatbázisból számolják, ezért egyesével kell létrehozni őket (`count()` nélkül).
- **Indok:** A legegyszerűbb megoldás, amely a specifikációval, a sémákkal és a csomagszabályokkal összhangban van.
- **Érintett:** `database/`, `src/Models/*`, M2-03 – M2-05, M4, M6.

## 2026-10-28 – A migrációk publikálása és a PostgreSQL-próba

- **Kérdés:** A csomag betöltse (`loadMigrationsFrom`) vagy publikálja a migrációkat? Hogyan fut a PostgreSQL 16-os próba?
- **Döntés:** Publikálja (`publishesMigrations`, `waypost-migrations` tag; a `waypost:install`, M2-07 ezt hívja). A workbench és a tesztek a `testbench.yaml` `migrations` listájából töltik be őket. A PostgreSQL 16 egy `pgsql` profilú Compose-szolgáltatás (`postgres:16-alpine`, tmpfs-en); a teljes tesztkészlet környezeti változókkal fut rajta (`CLAUDE.md`). A fejlesztői MySQL bináris napló és commitonkénti lemezre írás nélkül fut, mert a migrációk sok DDL-utasítása így kb. kétszer gyorsabb.
- **Indok:** A specifikáció szerint a telepítés publikálja a migrációkat; ha a csomag be is töltené őket, a publikált példányokkal együtt kétszer futnának. A PostgreSQL-próba így nem kézi, hanem ugyanaz a tesztkészlet.
- **Érintett:** `src/WaypostServiceProvider.php`, `testbench.yaml`, `compose.yaml`, M2-03, M2-07.

## 2026-10-28 – A demó-seeder

- **Kérdés:** Milyen ismert adatokkal dolgozzon a workbench és az app M3-as tesztje, és hogyan fusson a seeder?
- **Döntés:** `Workbench\Database\Seeders\DatabaseSeeder`: „Demó telephely”; supervisor `supervisor@waypost.test` / `password`, PIN `111111`; őrök: Kiss Péter (`222222`), Nagy Anna (`333333`); hat pont (négy NFC, rögzített UID-del `04A1B2C3D4E501`-től `…04`-ig, és két QR); „Külső kör” kötött sorrenddel (1–4. pont) és „Belső kör” szabad sorrenddel (3–6. pont), egy-egy verzióval, 60 másodperces gyalogidőkkel; „Ötpercenként” menetrend a külső körre (`00:00`–`23:55`, 5 perc, 5 perces időkeret). A seeder a workbench build egyik lépése (`composer build`), vagy üres adatbázison `composer seed`, és nem a `testbench.yaml` `seeders` listájában van. A PIN-t egyelőre a `UserFactory::withPin()` számolja; az M2-09 PIN-szolgáltatása váltja ki.
- **Indok:** A `seeders` listát a Testbench minden `RefreshDatabase` után is lefuttatná, ami a tesztek adatait összekeverné. Az M2-06 „kész” feltétele (a pull a teljes demóállapotot adja) az M2-23-ban ellenőrizhető; addig egy feature teszt nézi a seedelt állapotot.
- **Érintett:** `workbench/database/seeders/DatabaseSeeder.php`, `testbench.yaml`, `composer.json`, M2-06, M2-09, M2-23, M3.
