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
- **Döntés:** A `path` repository `options.versions` mezője rögzíti a verziót (`"waypost/schema": "0.1.0"`), a `require` pedig `^0.1`. A sémacsomag új verziójánál (a `package.json` verziójával együtt) ezt is át kell írni. (2026-11-04 óta a `.waypost-schema-version` adja a verziót, és teszt ellenőrzi, lásd lent.) A csomag symlinkként kerül a `vendor/` alá, így a sémák változása azonnal látszik.
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
- **Döntés:** Publikálja (`publishesMigrations`, `waypost-migrations` tag; a `waypost:install` saját maga másolja őket, lásd *A `waypost:install` parancs*). A workbench és a tesztek a `testbench.yaml` `migrations` listájából töltik be őket. A PostgreSQL 16 egy `pgsql` profilú Compose-szolgáltatás (`postgres:16-alpine`, tmpfs-en); a teljes tesztkészlet környezeti változókkal fut rajta (`CLAUDE.md`). A fejlesztői MySQL bináris napló és commitonkénti lemezre írás nélkül fut, mert a migrációk sok DDL-utasítása így kb. kétszer gyorsabb.
- **Indok:** A specifikáció szerint a telepítés publikálja a migrációkat; ha a csomag be is töltené őket, a publikált példányokkal együtt kétszer futnának. A PostgreSQL-próba így nem kézi, hanem ugyanaz a tesztkészlet.
- **Érintett:** `src/WaypostServiceProvider.php`, `testbench.yaml`, `compose.yaml`, M2-03, M2-07.

## 2026-10-28 – A demó-seeder

- **Kérdés:** Milyen ismert adatokkal dolgozzon a workbench és az app M3-as tesztje, és hogyan fusson a seeder?
- **Döntés:** `Workbench\Database\Seeders\DatabaseSeeder`: „Demó telephely”; supervisor `supervisor@waypost.test` / `password`, PIN `111111`; őrök: Kiss Péter (`222222`), Nagy Anna (`333333`); hat pont (négy NFC, rögzített UID-del `04A1B2C3D4E501`-től `…04`-ig, és két QR); „Külső kör” kötött sorrenddel (1–4. pont) és „Belső kör” szabad sorrenddel (3–6. pont), egy-egy verzióval, 60 másodperces gyalogidőkkel; „Ötpercenként” menetrend a külső körre (`00:00`–`23:55`, 5 perc, 5 perces időkeret). A seeder a workbench build egyik lépése (`composer build`), vagy üres adatbázison `composer seed`, és nem a `testbench.yaml` `seeders` listájában van. A PIN-t a `UserFactory::withPin()` a `PinHasher`-rel számolja (M2-09).
- **Indok:** A `seeders` listát a Testbench minden `RefreshDatabase` után is lefuttatná, ami a tesztek adatait összekeverné. Az M2-06 „kész” feltétele (a pull a teljes demóállapotot adja) az M2-23-ban ellenőrizhető; addig egy feature teszt nézi a seedelt állapotot. Az M2-23 óta a `DemoSeederTest` a demó-telephelyhez rendelt telefon pullját is nézi.
- **Érintett:** `workbench/database/seeders/DatabaseSeeder.php`, `testbench.yaml`, `composer.json`, M2-06, M2-09, M2-23, M3.

## 2026-10-28 – A `waypost:install` parancs

- **Kérdés:** Elég-e, ha az install a `vendor:publish`-t hívja a két taggel, és mikor fussanak a migrációk?
- **Döntés:**
  - A `config/waypost.php`-t és a migrációkat a parancs maga másolja, nem a `vendor:publish`. Egy migráció akkor kerül ki, ha a gazda-alkalmazásban sincs meg a dátum nélküli nevével (`create_waypost_users_table.php`), sem fájlként, sem a `migrations` táblában lefutottként. Az új fájlok dátuma a mostani idő, másodpercenként eggyel nő, a csomag sorrendjében, és mindig a gazda legkésőbbi migrációja után jön, akkor is, ha az a mostaninál későbbre van datálva.
  - A meglévő `config/waypost.php` csak `--force`-szal íródik felül.
  - A migrációk `--migrate`-tel kérdés nélkül futnak; interaktívan a parancs megkérdezi (alapértelmezés: igen); `--no-interaction` mellett `--migrate` nélkül nem futnak. A `migrate` `--force`-szal fut, mert a felhasználó kifejezetten kérte.
  - A parancsok kimenete magyar, mint minden, ami a felhasználónak szól.
  - Próba: `tests/Install` egy workbench nélküli alkalmazásban (ott a migrátor csak a publikált fájlokat látja, a config és a database könyvtár ideiglenes), és a `scripts/install-smoke.sh` egy valódi, friss `laravel/laravel` 13-ba telepít `composer require`-rel (SQLite).
- **Indok:** A `vendor:publish` a migrációt a mostani dátumra nevezi át, és a meglévőt az eredeti néven keresi, ezért minden újabb futás újra kimásolná az összeset, és a `migrate` „table already exists” hibával állna le. Csomagfrissítés után viszont az installnak az új migrációkat ki kell vinnie. A lefutott migrációk figyelése miatt a `schema:dump --prune` után sem másol újra semmit.
- **Érintett:** `src/Console/InstallCommand.php`, `src/WaypostServiceProvider.php`, `tests/Install/`, `tests/InstallTestCase.php`, `scripts/install-smoke.sh`, M2-07.

## 2026-10-28 – A `waypost:create-supervisor` parancs és az e-mail-címek alakja

- **Kérdés:** Mit kér be a parancs, mit ellenőriz, és hogyan egyedi az e-mail-cím?
- **Döntés:**
  - Név, e-mail-cím és jelszó: `--name`, `--email`, `--password`. Ami hiányzik, azt interaktívan bekéri, a jelszót kétszer. `--no-interaction` mellett a hiányzó érték hiba. A válaszokat beíráskor ellenőrzi; a magyar hibaüzenetek a parancsban vannak.
  - Szabályok: a név kötelező, legfeljebb 255 karakter; az e-mail-cím érvényes, legfeljebb 255 karakter, és egyedi a nem törölt felhasználók között; a jelszó 8–255 karakter. A nevet és az e-mail-címet a parancs körülvágja. Ha két futás egyszerre hozná létre ugyanazt a címet, az egyedi index miatti hibát ugyanazzal az üzenettel jelzi.
  - A supervisor aktív, és nincs se PIN-je, se telephelye. A PIN telephelyen belül egyedi, ezért telephely nélkül nincs mihez mérni; később az API adja meg (M2-17).
  - Az e-mail-címet a `User` modell körülvágva és kisbetűsen tárolja (mutator), így bárhonnan érkezik, egyedi és kis- és nagybetűtől függetlenül kereshető. **Következmény az M2-14-ben:** a belépés a beírt címet kisbetűsítve keresi.
- **Indok:** A MySQL kollációja nem különbözteti meg a kis- és nagybetűt, a PostgreSQL-é igen; a kisbetűs tárolással mindkettőn ugyanaz az egyediség és a keresés. A jelszó alsó határa a Laravel szokásos minimuma. A gazda `Password::defaults()` beállítását a parancs nem használja, mert annak üzenetei nem magyarok.
- **Érintett:** `src/Console/CreateSupervisorCommand.php`, `src/Models/User.php`, M2-08, M2-14, M2-17.

## 2026-10-28 – PIN-szolgáltatás (Q11)

- **Kérdés:** Hogyan számol a szerver PIN-t, hogyan generál, és pontosan kik között egyedi egy PIN?
- **Döntés:**
  - `Waypost\Pin\PinHasher`: `hash()` (friss só, 100 000 iteráció) → `PinHash` (`pin_hash`, `pin_salt`, `pin_iterations`); `check()` a tárolt iterációszámmal számol, hibás alakú PIN-re vagy sérült ellenőrzőre `false`; `generate()` egyenletes eloszlású 6 jegyű PIN, a vezető nullákkal együtt (`Random\Randomizer`, alapból kriptográfiai motorral). A paraméterek és az esetek a `waypost-schema` `pin/test-vectors.json`-jából jönnek (Q11, a döntés a `../waypost-schema/docs/decisions.md`-ben); a teszt a vektorfájlból olvas.
  - `Waypost\Pin\SitePins`: `isTaken($pin, $siteIds, except: $user)` és `generate($siteIds, except: $user)`. A PIN azok között egyedi, akik a megadott telephelyek bármelyikéhez hozzá vannak rendelve, és van PIN-jük. Az őr és a supervisor ugyanabban a térben van, mert a PIN egyben az azonosító. Az inaktív felhasználó számít, mert újra aktiválható; a törölt nem. A jelölt PIN-t a gép minden ilyen felhasználó sójával és iterációszámával újraszámolja (telephelyenként néhány tucat felhasználónál ez egy-két másodperc). A `generate()` legfeljebb 100 jelöltet próbál, és a már elutasítottat nem számolja újra; ha nem talál szabadot, kivételt dob.
  - **Egyidejű kérések** (2026-11-05, WP-BE-010): a PIN-hozzárendelés (`POST /users`, `PUT /users/{id}/pin`, `POST /sites/{id}/users`) az érintett telephelyek sorát az ellenőrzéstől a mentés commitjáig zárolja (`AssignsPins::assignPin()`, ID szerinti sorrendben, hogy két kérés ne várjon egymásra körben). Így két egyidejű kérés nem adhatja ugyanazt a PIN-t két felhasználónak egy telephelyen. Egyedi index nem lehet, mert a PIN sózott hash. PostgreSQL-en `FOR NO KEY UPDATE`, ami nem tartja fel a telephelyre hivatkozó sorok beszúrását. MySQL-en `FOR UPDATE`: a telephelyre hivatkozó sor beszúrása (pl. egy push vetülete) az ellenőrzés idejére, legfeljebb egy-két másodpercre várhat; ez ritka, és nem hiba.
  - Ismert korlát: egy meglévő, PIN-nel rendelkező felhasználó új telephelyhez rendelésekor a szerver nem tudja ellenőrizni az ütközést, mert csak a hasht ismeri. **Az M2-16-ban dönteni kell:** a hozzárendeléskor PIN-t kér (vagy generál), vagy elfogadja a kockázatot. Javaslat: ha a felhasználónak van PIN-je, a hozzárendelés új PIN-t generál, és egyszer visszaadja. *(Eldöntve: a hozzárendelés mindig új PIN-t ad, lásd „Telephelyek, felhasználók és a PIN a hozzárendeléskor”.)*
- **Indok:** Az app ugyanezt a PBKDF2-t számolja offline, ezért mindkét oldal ugyanazokkal a vektorokkal tesztel; ha bármelyik paraméter vagy kódolás eltér, a teszt bukik, nem a belépés a terepen. A véletlenforrás cserélhető, így a tesztben az ütközés és a kimerülés is előidézhető.
- **Érintett:** `src/Pin/`, `src/Http/Controllers/Concerns/AssignsPins.php`, `database/factories/UserFactory.php`, M2-09, M2-16, M2-17, M3-15, Q11.

## 2026-10-28 – Az API-keret: útvonalak, hibaformátum, `server_time`, rate limit

- **Kérdés:** Hogyan kerül a csomag API-ja a gazda-alkalmazásba úgy, hogy minden válasz a sémák szerinti legyen, a gazda saját hibakezelése pedig ne változzon?
- **Döntés:**
  - Útvonalak: `routes/api.php`, a `WaypostServiceProvider::apiRoutes()` tölti be (`waypost.route.prefix` alatt, `waypost.` névelőtaggal, a `waypost.route.middleware`-rel), ha a gazda nem cache-eli az útvonalakat. A tesztek ugyanezzel regisztrálnak próba-útvonalakat.
  - Hibaformátum: a `Waypost\Http\ErrorRenderer` csak az előtag alatti kérések hibáit formázza, két helyen. A csomag útvonalain a legkülső middleware (`FormatApiResponse`) a gazda handlere által renderelt választ cseréli le (a Laravel a kivételt a válaszon hagyja), így a gazda handlerétől függetlenül működik. Ami útvonalon kívül történik (nincs ilyen végpont, rossz kérésmód, karbantartás, a gazda globális middleware-je), azt a gazda handlerének egy render-callbackje formázza, ha az a Laravel szokásos `Handler`-e. Leképezés: `AuthenticationException` → `unauthenticated`; a policy-elutasítás → `forbidden`, vagy `not_found`, ha a policy így tagad; hiányzó modell → `not_found`; `ValidationException` → `validation_failed` a Laravel `errors()` alakjával mint `details`; `UniqueConstraintViolationException` → `conflict`; throttling → `rate_limited` a `Retry-After` fejléccel; minden más 4xx → `bad_request`, 5xx → `server_error` (a HTTP-státusz marad, a két új kód a `waypost-schema` döntése szerint); bármi más → `server_error` (500). A saját hibák `Waypost\Http\Exceptions\ApiException`-ök (kód, magyar üzenet, `details`); HTTP-kivételek, ezért a gazda nem naplózza őket. Az üzenet mindig a kód magyar alapszövege vagy az `ApiException`-é, a kivétel szövege soha (debug módban sem).
  - `server_time`: ugyanez a `FormatApiResponse` minden JSON-objektum válasz elejére beteszi a mostani időt, a már ott lévőt felülírva; a renderelő a csoporton kívüli hibákba maga teszi bele. A `[]`-t visszaadó controller válasza `{ "server_time": … }`.
  - Rate limit: `throttle` két névvel. `waypost`: percenként `waypost.rate_limits.api` (240) kérés a token gazdájára (felhasználó vagy eszköz), token nélkül az IP-re; az `Authenticate` után fut. `waypost-login`: percenként `waypost.rate_limits.login` (5) belépési kísérlet e-mail-cím és IP párosra, és `waypost.rate_limits.login_ip` (30) IP-címre (2026-11-05, WP-BE-012). `waypost-pin` (2026-11-05, WP-BE-011): a PIN-t adó kérésekre (`POST /users`, `PUT /users/{id}/pin`, `POST /sites/{id}/users`) percenként `waypost.rate_limits.pin` (20) a token gazdájára, a `waypost` mellett. Mindegyik a telephelyek minden PIN-es felhasználójára újraszámolja a PBKDF2-t (egy-két másodperc CPU), így a 240/perc egy hívónak percekig lekötne egy magot. A 20/perc egy offline felvett őrcsapatnak is elég: az app a 429-et a `Retry-After` után újrapróbálja, az outbox nem áll meg. A korlát a `HandleIdempotencyKey` előtt fut, mert a 429 nem tárolódhat a kulcs válaszaként.
- **Indok:** A gazda handlerét lecserélni vagy a `bootstrap/app.php`-ját módosítatni nem lehet egy csomagtól, és a csak callbackre épülő megoldás nem elég: a `scripts/install-smoke.sh` egy friss `laravel/laravel` 13-ban megmutatta, hogy parancssorból (és minden más handler-dekorátorral) a Collision becsomagolja a handlert, és a callback nem kerül fel. A middleware és a callback is csak a saját előtagot érinti, a gazda többi útvonala a megszokott választ kapja. Az útvonalon kívüli hibáknál, ha a gazda handlere nem a Laravelé, vagy a gazda előbb fut le egy saját callbackje ugyanarra a kivételre, a válasz a gazdáé marad – ezt a telepítési leírásban jelezni kell (M7-01). A 240/perc bőven elég egy eszköznek (pull-lapozás, média-feltöltés egy offline éjszaka után), egy elszabadult kliens viszont nem terheli le a szervert.
- **Érintett:** `routes/api.php`, `src/WaypostServiceProvider.php`, `src/Http/ErrorRenderer.php`, `src/Http/Exceptions/ApiException.php`, `src/Http/Middleware/FormatApiResponse.php`, `scripts/install-smoke.sh`, `src/Enums/ErrorCode.php`, `config/waypost.php`, M2-10, M7-01.

## 2026-10-28 – Sémaellenőrzés (M2-11)

- **Kérdés:** Hol és hogyan ellenőrzi a backend a bemenő JSON-t a `waypost/schema` sémáival, és milyen hibát ad?
- **Döntés:**
  - `ValidateSchema::using('api/…request')` útvonal-middleware, a FormRequest előtt. A törzsnek JSON-objektumnak kell lennie (`Content-Type: application/json`); ha nem az, vagy nem érvényes JSON, `validation_failed` `details` nélkül. A séma szerinti hibák `details`-ként: kulcs a mező pontokkal elválasztott útvonala, a hiányzó és az ismeretlen mező a saját útvonalán (`entries.0.hash`), az üzenet magyar, kulcsszavanként (`Kötelező mező.`, `Ismeretlen mező.`, `Hibás típus, egész szám kell.` …). Egy nullázható mező hibájánál a „null kell” ág üzenete elmarad.
  - A validátor (`Waypost\Validation\SchemaValidator`, opis/json-schema, legfeljebb 100 hiba) a sémákat a `Paths::SCHEMA_ID_BASE` előtagról tölti be. Az opis egy objektumban az első hibás kulcsszócsoport után a feltételes ágakat (pl. a bejegyzés payloadját) nem mindig nézi meg; ilyenkor a kliens a javítás után kapja a többi hibát.
  - Az ellenőrzött törzs lesz a kérés JSON-bemenete (`json()` és `request`), így a controller pontosan azt kapja, amit a séma elfogadott: a gazda `TrimStrings` és `ConvertEmptyStringsToNull` middleware-je nem változtat rajta.
  - Tesztben: `expect($response)->toMatchSchema('api/…')` (`tests/Pest.php`); a hibaüzenet az opis JSON-pointeres listája.
- **Indok:** A specifikáció szerint a séma a szerződés, a FormRequest csak az adatbázisfüggő szabályokat nézi; ha a gazda middleware-je a már ellenőrzött értéket módosítaná (üres szöveg → `null`), a controller a sémának ellentmondó adatot kapna.
- **Következmény az M2-16-tól:** a FormRequestek üzenetei a gazda nyelvi fájljaiból jönnének (alapból angolul); a csomag FormRequest-alaposztálya ezért saját magyar üzeneteket ad a használt szabályokra (`exists`, `unique` …). *(Másként döntve: FormRequest nincs, lásd „Konfigurációs CRUD: közös szabályok”.)*
- **Érintett:** `src/Http/Middleware/ValidateSchema.php`, `src/Validation/SchemaValidator.php`, `tests/Pest.php`, M2-11, M2-16 – M2-23.

## 2026-10-28 – `Idempotency-Key` (M2-12)

- **Kérdés:** Pontosan mikor kötelező a fejléc, mit tárol a szerver, és mi történik eltérő vagy egyidejű ismétlésnél?
- **Döntés:**
  - A `HandleIdempotencyKey` middleware minden azonosított író útvonalon fut (az `Authenticate` és a throttling után, a sémaellenőrzés előtt). A fejléc UUID (bármely verzió, kis- és nagybetűtől függetlenül); hiányzó vagy hibás fejléc: `validation_failed`, `details`: `{"Idempotency-Key": [...]}`.
  - A kulcs a token gazdájáé (felhasználó vagy eszköz; az `X-Acting-User`-rel küldött kérés az eszközé). Lenyomat: SHA-256 a módszerből, az útvonalból, a query stringből és a törzsből; multipart törzsnél a mezőkből és a fájlok tartalmának SHA-256-jából, mert a boundary próbálkozásonként más.
  - Ugyanarra a kulcsra 24 órán át ugyanaz a válasz jön (státusz és törzs, `Idempotent-Replayed: true` fejléccel), a kérés nem fut újra; a `server_time` viszont mindig a mostani, mert az app abból becsli a megbízható időt. Kliensoldali hiba (4xx) is tárolódik, szerverhiba (5xx) nem: azt ugyanazzal a kulccsal újra lehet próbálni.
  - Ugyanaz a kulcs más kéréssel: `validation_failed`. Ugyanaz a kulcs, amíg az első kérés fut: `conflict` (409). A lejárt kulcs úgy számít, mintha nem lenne.
  - Takarítás: a `PruneIdempotencyKeys` job (a `waypost.queue` szerinti sorban) óránként törli a lejártakat; a csomag a gazda ütemezőjébe regisztrálja (`waypost:prune-idempotency-keys`), ehhez a gazdának a `schedule:run`-t futtatnia kell.
  - A `POST /auth/login` és a `POST /auth/logout` kivétel: a login előtt nincs hívó, akihez a kulcs tartozna, és az ismételt login csak egy újabb tokent ad; az ismételt logout `unauthenticated`-et kap, ami ugyanazt jelenti.
- **Indok:** A specifikáció szerint ugyanarra a kulcsra ugyanaz a válasz jár; a régi `server_time` visszajátszása viszont elrontaná az app időbecslését. A futó kérés jelölése (Q4) kezeli az egyidejű ismétlést.
- **Érintett:** `src/Http/Middleware/HandleIdempotencyKey.php`, `src/Jobs/PruneIdempotencyKeys.php`, `src/WaypostServiceProvider.php`, M2-12, M3, M4, M5.

## 2026-10-28 – Azonosítás: guard, hívó, `X-Acting-User` (M2-13)

- **Kérdés:** Hogyan azonosítja a csomag a kéréseket a saját tokentáblájából (Q8), és hogyan érvényesül az `X-Acting-User` telephelyi korlátja?
- **Döntés:**
  - `waypost` guard (`Waypost\Auth\TokenGuard`, az `auth.guards.waypost` konfigurációt a csomag adja hozzá): a Bearer token `id|titok` alakú; a nem UUIDv7 ID-t lekérdezés nélkül elutasítja (PostgreSQL-en a `uuid` oszlop hibát dobna). Érvényes a token, ha nem járt le (`expires_at`, ha van), és a gazdája létezik: felhasználónál csak aktív supervisor; az eszköz visszavonva is (a middleware dönt). A gazda `sanctum.expiration` beállítása nem számít. Az eredmény a kérésen tárolódik, nem a guardon. A gazda alapértelmezett guardja nem változik.
  - Az `Authenticate::using(...)` middleware paraméterei a route által elfogadott hívók: `supervisor` (supervisor token), `device` (eszköztoken `X-Acting-User` nélkül), `acting` (eszköztoken + `X-Acting-User`). Sorrend: nincs érvényes token → `unauthenticated`; visszavont eszköz → `device_revoked` minden útvonalon; a route nem fogadja el a tokent, a token képessége nem a gazdájáé, vagy `X-Acting-User` jött supervisor tokennel vagy olyan route-on, amely nem fogad el `acting`-et → `forbidden`. Az `X-Acting-User` egy aktív supervisor ID-ja, aki az eszköz telephelyéhez van rendelve; ha nem az, `forbidden`.
  - A hívó a `Waypost\Auth\Caller` (supervisor, eszköz, vagy eszköz + supervisor), a kérés attribútuma; controllerbe injektálható. `siteIds()`: a supervisor összes telephelye, az eszköz és a nevében eljáró supervisor esetén csak az eszköz telephelye. A `$request->user()` a token gazdája (a rate limit és az idempotenciakulcs ehhez tartozik).
  - A visszavont eszköz egyetlen engedett végpontja (`POST /sync/push-final`, M4) egy további paraméterrel kerül majd be.
  - **Az eszköztoken kockázata** (2026-11-05, WP-BE-009): a PIN-ellenőrzés a specifikáció szerint offline, a telefonon történik, ezért az `X-Acting-User` mögött nincs szerveroldali bizonyíték. Az eszköztoken birtokosa a telephely bármelyik supervisoraként eljárhat, a telephelyen belül (a WP-BE-001 javítása óta a saját adatait is csak egytelephelyes supervisornál írhatja át). Ez tudatos kockázat, a README *Üzemeltetés* fejezete nevezi meg: az elveszett telefont új hozzárendeléssel azonnal le kell váltani. A rövid életű, a PIN-ből vagy az eszköz titkából származó igazolás a 2. lépcső kérdése.
- **Indok:** A Laravel `RequestGuard`-ja a guardpéldányon tárolja a felhasználót, ami hosszú életű folyamatban (és a tesztekben) a következő kérésre is átragadna. A telephelyi korlátot a `Caller` hordozza, így minden policy és lekérdezés ugyanabból a listából dolgozik.
- **Érintett:** `src/Auth/TokenGuard.php`, `src/Auth/Caller.php`, `src/Http/Middleware/Authenticate.php`, `src/Models/PersonalAccessToken.php`, M2-13, M2-22, M4 (push-final), M5.

## 2026-10-28 – Belépés és kijelentkezés (M2-14)

- **Kérdés:** Mit ellenőriz a `POST /auth/login`, és mit ad vissza hibánál?
- **Döntés:** A beírt címet kisbetűsítve keresi (a `User` kisbetűsen tárolja). Csak supervisor léphet be jelszóval; ismeretlen cím, őr és hibás jelszó ugyanazt kapja: `unauthenticated`, „Hibás e-mail-cím vagy jelszó.”. Inaktív supervisor helyes jelszóval: `unauthenticated`, „Ez a fiók inaktív, nem lehet vele belépni.”. A token `supervisor` képességű, nem jár le, a neve a `device_name`. A `POST /auth/logout` a kérés tokenjét törli, a supervisor többi tokenje marad; eszköztokennel `forbidden`.
  - **Időzítés és jelszópróbálgatás** (2026-11-05, WP-BE-012): ha nincs ellenőrizhető jelszó (ismeretlen cím, őr, jelszó nélküli supervisor), a szerver a beírt jelszót egyszer hasheli (`Hash::make()`). Ez ugyanannyi ideig tart, mint egy ellenőrzés ugyanazzal a hasherrel, így a válaszidőből nem derül ki, létezik-e a fiók. A `waypost-login` korlát két részből áll: e-mail-cím és IP páros (`waypost.rate_limits.login`, 5/perc) egy jelszó kitalálása ellen, és IP-cím (`waypost.rate_limits.login_ip`, 30/perc) a sok címen kevés jelszóval próbálkozás (password spraying) ellen. A 30/perc egy közös NAT mögötti irodának is elég. Proxy mögött az IP a gazda `TrustProxies` beállításától függ (README, *Üzemeltetés*).
  - **Jelszócsere** (2026-11-05, WP-BE-005): a `PATCH /users/{id}` `password` mezője a felhasználó összes tokenjét törli, a kérés saját tokenjét kivéve. Így egy ellopott token a jelszócsere után nem működik, aki pedig a saját jelszavát cseréli, bejelentkezve marad. Ha más (egy kollégája vagy a telephelyi telefon) cseréli a jelszavát, minden eszközén újra be kell lépnie. Más mező (név, e-mail-cím) és a PIN cseréje a tokeneket nem érinti. A lejárat nélküli token marad, ahogy a specifikáció írja; az inaktivitási lejárat a `last_used_at` frissítésével együtt (WP-BE-038) kerülhet sorra.
- **Indok:** A séma (`api/auth-login.response`) szerint a hibás belépés `401 unauthenticated`. Az inaktív fiók külön üzenete csak helyes jelszó után jelenik meg, így nem árulja el, hogy a cím létezik.
- **Érintett:** `src/Http/Controllers/AuthController.php`, `UserController.php`, `routes/api.php`, `src/WaypostServiceProvider.php`, `config/waypost.php`, M2-14.

## 2026-10-28 – Policy-k (M2-15)

- **Kérdés:** Hogyan érvényesül, hogy a supervisor csak a hozzárendelt telephelyeit és azok entitásait látja, és mit kap egy idegen telephelyre?
- **Döntés:**
  - A csomagnak saját gate-je van (`Waypost\Auth\Gate`), a policy-k a `Caller`-t kapják, nem felhasználót. Ez nem Laravel-gate (nem valósítja meg a `Gate` szerződést), csak becsomagol egyet, amelyet maga hoz létre: a konténer minden feloldott, a szerződést megvalósító objektumot átad a gazda `callAfterResolving(Gate::class, …)` callbackjeinek, és így pl. a `spatie/laravel-permission` `before`-callbackje erre is rákerülne (2026-11-03, az M5-21 próbáján egy gazda-alkalmazásban 500-at adott). A policy-k a `Waypost\Auth\Policies` névtérben vannak, mert a gazda gate-je a `Waypost\Policies\…Policy` nevet a modellek mellett magától megtalálná.
  - Szabályok: csak supervisor (saját tokennel vagy `X-Acting-User`-rel) konfigurál, a telephelyi telefon önmagában semmit (403). Telephely: a hozzárendeltek; újat csak saját tokennel lehet létrehozni. Pont, útvonal (és verziói), előfizetés: a telephelyük szerint; menetrend: az útvonala telephelye szerint. Felhasználó: aki legalább egy telephelyen osztozik a hívóval, és saját maga; saját magát senki nem törölheti.
  - Idegen telephely rekordja: `not_found` (404, `Response::denyAsNotFound()`), hogy a létezése ne derüljön ki; létrehozás idegen telephelyen: `forbidden` (403).
- **Indok:** A gazda gate-jének `before` callbackjei a saját felhasználói típusára vannak írva, és egy `Caller`-rel hibát dobnának; a különálló gate-tel a két oldal nem lát bele egymás döntéseibe. A 404 a specifikáció „csak azokat látja” szabályának pontosabb megfelelője, mint a 403.
- **Érintett:** `src/Auth/Gate.php`, `src/Auth/Policies/`, `src/WaypostServiceProvider.php`, M2-15 – M2-21.

## 2026-10-28 – Konfigurációs CRUD: közös szabályok (M2-16 – M2-21)

- **Kérdés:** Mi a közös viselkedése a `/sites`, `/users`, `/checkpoints`, `/routes`, `/schedules` és `/report-subscriptions` végpontoknak, ha a kéréseik sémája a feladatlistában csak az M5-01-ben készülne el?
- **Döntés:**
  - A sémák előbb a `waypost-schema`-ba kerültek (M5-01 előrehozva, részletek: `../waypost-schema/docs/decisions.md`), és minden törzset a `ValidateSchema` ellenőriz. **FormRequest nincs:** a controller (`Waypost\Http\Controllers\Controller` leszármazottai) csak az adatbázisfüggő szabályokat nézi, és a hibát maga adja magyarul (`ValidationException::withMessages`, `ApiException`). Ez felülírja az M2-11 „FormRequest-alaposztály” következményét: a Laravel-validátor szabályai és a gazda nyelvi fájljai nem kellenek.
  - A válasz a rekord entitásalakja (`Waypost\Http\Entities`, a pull is ezt használja), `server_time`-mal; létrehozás 201, minden más 200, törlés `{ server_time }`.
  - Létrehozáskor az app adhat `id`-t (UUIDv7, az outboxhoz). Ha az ID már foglalt (törölt rekordnál is): `conflict`. Az ugyanarra az ID-ra idempotens válasz az M5-02 feladata.
  - Az útvonal-paraméter UUID (`whereUuid`), más alakra „Nincs ilyen végpont.” (404), így a PostgreSQL `uuid` oszlopa más értéket nem kap. A lista szűrője (`?site_id=`, `?route_id=`, `?user_id=`) UUIDv7, különben `validation_failed`; idegen telephely szűrője üres listát ad.
  - Hivatkozás a törzsben: a telephely (`site_id`) a policy szerint 403, ha nem a hívóé; minden más rekord (`user_id`, `route_id`, `checkpoint_id`), ha a hívó nem látja vagy nem létezik: `validation_failed` („Nincs ilyen …”), hogy a léte ne derüljön ki.
  - A módosított rekordnak érvényes entitásnak kell maradnia: a pont és a menetrend mentés előtt az entitássémán is átmegy (`SchemaValidator::assertValid()`); a `not: null` szabály üzenete „Meg kell adni.”.
  - Törlés (soft delete) a hozzá tartozókkal együtt, hogy a pull mindet `deleted`-ként hozza: a telephelyével a pontjai, útvonalai, menetrendjei és előfizetései törlődnek, a telefonja visszavonódik (`revoked_at`; a push-final még fogadja); az útvonallal a menetrendjei; a felhasználóval az előfizetései és a tokenjei. A verziók maradnak, a régi körök hivatkoznak rájuk.
  - **Másik felhasználót csak az módosíthat** (név, e-mail, jelszó, aktív, PIN, hozzárendelés új telephelyhez) **vagy törölhet, aki a felhasználó minden telephelyét kezeli** (`UserPolicy::update` / `delete`, különben 403 „A felhasználó olyan telephelyhez is tartozik, amelyet nem kezelsz.”); látni elég egy közös telephely. Különben egy közös telephely supervisora átírhatná egy kolléga e-mail-címét és jelszavát (vagy megtudná az új PIN-jét), és belépne a kolléga többi telephelyére. Az automatikus biztonsági ellenőrzés jelezte; a hozzárendelésnél is így van, mert az új PIN a hívóhoz kerül. Saját adatait (e-mail, jelszó, PIN) mindenki módosíthatja, de saját magát senki nem inaktiválhatja és nem törölheti (403). A telephelyi telefonon át (`X-Acting-User`) a saját adatokra is ez a szabály vonatkozik: csak az módosíthatja őket, akinek a telefon telephelye az egyetlen telephelye (különben 403), mert a kérés csak az eszköztokent bizonyítja, a supervisor PIN-jét nem, az e-mail-cím, a jelszó és a PIN viszont a többi telephelyét is megnyitná (WP-BE-001, 2026-11-05).
  - Egy telephely nem maradhat aktív supervisor nélkül: a fenti szabály miatt inaktiválással vagy törléssel nem maradhat (a hívó maga is aktív supervisora a felhasználó minden telephelyének), leválasztással igen – ezért a telephely egyetlen aktív supervisora nem választható le (`conflict`), saját maga sem.
  - A policy elutasításának magyar szövege (pl. „Ehhez a telephelyhez nincs jogosultságod.”) a hibaüzenetbe kerül; ha a policy nem ad szöveget, a kód alapszövege marad.
  - Az `Idempotency-Key` tárolt válasza titkosítva van (`encrypted` cast, a gazda `APP_KEY`-ével), mert egyszer megmutatott titkot is tartalmazhat (generált PIN, később az eszköztoken és -titok).
- **Indok:** A séma az egyetlen szerződés; a második validációs réteg (FormRequest) ugyanazt a szabályt kétszer, kétféle üzenettel írná le. Az entitásséma-ellenőrzés a részleges módosítás és a tárolt rekord együttes szabályait (pl. NFC-típus címke nélkül) külön kód nélkül fedi le. A kaszkád soft delete egyszerűbb a pullnak, mint a láthatóságból kikövetkeztetni, mi tűnt el.
- **Érintett:** `routes/api.php`, `src/Http/Controllers/`, `src/Http/Entities.php`, `src/Http/ErrorRenderer.php`, `src/Validation/SchemaValidator.php`, `src/Models/IdempotencyKey.php`, M2-16 – M2-21, M2-23, M5-01, M5-02.

## 2026-10-28 – Telephelyek, felhasználók és a PIN a hozzárendeléskor (M2-16, M2-17)

- **Kérdés:** Mi történik egy meglévő, PIN-nel rendelkező felhasználó új telephelyhez rendelésekor (az M2-09 nyitott kérdése)? Hogyan jön létre, változik és jelenik meg egy felhasználó és a PIN-je?
- **Döntés:**
  - Új telephely csak saját supervisor tokennel jön létre; a létrehozó automatikusan hozzá van rendelve. Az időzónának létező IANA-zónának kell lennie (`DateTimeZone::listIdentifiers(ALL_WITH_BC)`), különben `validation_failed`.
  - **Hozzárendelés (`POST /sites/{id}/users`): mindig új PIN**, ezért csak olyan felhasználó rendelhető hozzá, akinek minden telephelyét a hívó kezeli – a megadott, vagy egy generált (egyszer visszaadva), a felhasználó összes telephelyén egyedi. A javaslat szerint, mert a régi PIN-nek csak a hashe ismert, így az új telephely felhasználóival nem vethető össze. A már hozzárendelt felhasználónál semmi nem változik (az ismételt outbox-kérés sem cserél PIN-t). A hozzárendelés és a leválasztás a felhasználó `updated_at`-jét is frissíti (a pull így hozza az új `site_ids`-t); a leválasztás a felhasználó azon telephelyi előfizetéseit is törli.
  - Felhasználó létrehozása (`POST /users`): a megadott telephelyek mind a hívóéi (különben 403); supervisornál e-mail és jelszó kötelező, őrnél tilos (séma). PIN: a megadott, vagy generált (a válaszban egyszer, `pin`); foglalt PIN vagy e-mail-cím: `conflict`. A szerep nem változtatható; e-mail-cím és jelszó csak supervisornak állítható (`PATCH`). PIN-csere: `PUT /users/{id}/pin`, a felhasználó összes telephelyén egyedi.
  - A felhasználó válaszalakja a pullé (Q9): a `site_ids` csak a hívó telephelyei, a PIN-ellenőrző (`pin_hash`, `pin_salt`, `pin_iterations`) csak a telephelyi telefonnak (az eszköztokennel, `X-Acting-User`-rel vagy anélkül) megy, a supervisor saját telefonjának nem, mert ott nincs offline PIN-belépés. E-mail-cím nincs a válaszban (az entitássémában szerveroldali mező).
- **Indok:** A PIN egyben azonosító, ezért egy telephelyen nem lehet kétszer; a hozzárendeléskori új PIN az egyetlen ellenőrizhető út. Az őrnek ez egy új PIN-t jelent minden telephelyén – ritka művelet, és a supervisor a válaszban azonnal látja.
- **Érintett:** `src/Http/Controllers/SiteController.php`, `SiteUserController.php`, `UserController.php`, `Concerns/AssignsPins.php`, `src/Auth/Policies/UserPolicy.php`, `src/Models/User.php` (`isLastSupervisor()`), M2-09, M2-16, M2-17, M2-23, Q9.

## 2026-10-28 – Pontok, útvonalverziók, menetrendek, előfizetések (M2-18 – M2-21)

- **Kérdés:** Mit ellenőriz a szerver a pontoknál, az útvonalverzióknál, a menetrendeknél és az előfizetéseknél azon túl, amit a séma kifejez?
- **Döntés:**
  - Pont: a `tag_uid` nagybetűs, elválasztó nélküli alakra kerül; telephelyen belül foglalt címke és bárhol foglalt QR-kód: `conflict` (`details` a mezővel; a törölt pont címkéje és QR-kódja újra kiosztható). Ha a kérés nem ad `qr_payload`-ot, a szerver generál (`waypost:cp:<id>:<16 karakter>`, minden címketípushoz, tartalékként); a `null` azt jelenti, nincs QR-kód. A megadott QR-kódnak a pont saját ID-ját kell tartalmaznia. Ha egy `qr` típusú pontnak módosítás után nincs QR-kódja, a szerver generál. A jelenlegi útvonalverzióban szereplő pont nem törölhető (`conflict`). A fotó az M4-ben jön (médiaséma).
  - Útvonalverzió: a következő sorszám a route sorának zárolása (`lockForUpdate`) alatt; a pontok a route telephelyéről, nem töröltek, mindegyik egyszer, az első pontnál nincs `walk_time_from_prev_s`; a hibák a pont indexével (`checkpoints.2.checkpoint_id`). A létrehozó a hívó supervisor (telephelyi telefonon az `X-Acting-User`). Az új verzió lesz a `current_version_id`. Verziót módosítani nem lehet: a `routes/{id}/versions/{version_id}` csak GET, más kérésmódra 405 `bad_request`, és a `RouteVersion` és a `RouteVersionCheckpoint` modell is kivételt dob frissítéskor.
  - Menetrend: az időkeret alapértéke, ha a kérés nem ad `time_limit_min`-t: `Waypost\Schedule\TimeLimit::default()` a jelenlegi verzió `walk_duration_s`-éből (verzió vagy mérés nélkül 20), a `schedule/test-vectors.json` `time_limit_default` esetei szerint; `null` = nincs korlát. Módváltáskor a régi mód mezői törlődnek; mód nélkül csak a jelenlegi mód mezői adhatók meg. A szerver ellenőrzi, amit a séma nem tud: a lista legfeljebb egyszer lép át a következő napra, és akkor az utolsó elem korábbi az elsőnél; `interval_from ≠ interval_to`.
  - Előfizetés: csak a telephelyhez rendelt supervisor fizethet elő (az őrnek nincs e-mail-címe); ugyanaz a `(user_id, site_id, frequency)` kétszer: `conflict`, a módosításnál is.
- **Indok:** A specifikáció és a `waypost-schema` döntései szerint; a konfliktusokat előbb lekérdezés ellenőrzi (pontos üzenet), versenyhelyzetben az egyedi index (`conflict`, savepointban).
- **Érintett:** `src/Http/Controllers/CheckpointController.php`, `RouteController.php`, `RouteVersionController.php`, `ScheduleController.php`, `ReportSubscriptionController.php`, `src/Schedule/TimeLimit.php`, `src/Models/RouteVersion.php`, `src/Models/RouteVersionCheckpoint.php`, M2-18 – M2-21, M4, M6.

## 2026-10-28 – Eszköz-hozzárendelés (M2-22)

- **Kérdés:** Ki rendelhet telefont egy telephelyhez, mi történik a régivel, és mit jelent, hogy a token és a titok „csak egyszer” adódik ki?
- **Döntés:**
  - `POST /devices/enroll` csak saját supervisor tokennel (a telephelyi telefon tokenje és az `X-Acting-User` 403), és csak a hívó telephelyére (`DevicePolicy::create`, különben 403 „Ehhez a telephelyhez nincs jogosultságod.”). A válasz 200 (a séma szerint), a teljes `device` entitással, `device` képességű tokennel és 32 véletlen bájt base64-ével; a titok titkosítva tárolódik (`encrypted` cast).
  - A telephely korábbi, vissza nem vont telefonja ugyanabban a tranzakcióban visszavonódik (`revoked_at`), a telephely sorának zárolása alatt, így két egyidejű hozzárendelés közül is csak az utolsó marad aktív. A régi telefon tokenje **megmarad**: a kéréseire `device_revoked` (401) jön, de a `POST /sync/push-final` (M4) még elfogadja; a tokenek érvénytelensége tehát az `Authenticate` döntése, nem törlés.
  - „Csak egyszer”: más végpont soha nem adja ki a tokent és a titkot. Ugyanazzal az `Idempotency-Key`-jel ismételt kérés 24 órán át ugyanazt a választ kapja (titkosítva tárolva), mert különben egy elveszett válasz után a telefon hozzárendelése használhatatlan maradna.
  - A telefonon a hozzárendeléshez használt supervisor tokent a szerver nem törli; az app törli a helyi példányt (és kijelentkezhet, M3).
- **Indok:** A specifikáció szerint; a token megtartása nélkül a visszavont telefon utolsó bejegyzései elvesznének. A zárolás nélkül két egyidejű hozzárendelés két aktív telefont hagyhatna egy telephelyen.
- **Érintett:** `src/Http/Controllers/DeviceController.php`, `src/Auth/Policies/DevicePolicy.php`, `src/Http/Entities.php` (`device()`), `routes/api.php`, M2-22, M3, M4.

## 2026-10-28 – A pull (M2-23)

- **Kérdés:** Hogyan lapoz a `GET /sync/pull` egyetlen kurzorral kilenc táblán át, mi kerül a `deleted` listákba, és mi történik, ha a hívó láthatósága megváltozik?
- **Döntés:**
  - **Kurzor:** az utoljára küldött rekord változási ideje és ID-ja (`updated_at`, az útvonalverziónál `created_at`), meg a hívó által látott telephelyek lenyomata (SHA-256 első 16 hexa jegye), base64url-ben (`Waypost\Sync\PullCursor`). Minden tábla a `(változási idő, id)` sorrendjében, sorérték-összehasonlítással (`(updated_at, id) > (?, ?)`) jön, a táblák `(updated_at, id)` indexével; a lapon a táblák jelöltjei összefésülve, ugyanebben a sorrendben. Így az azonos időbélyegű rekordok a lapozás határán sem ismétlődnek és nem vesznek el. Hiányzó vagy üres `cursor`: teljes állapot. Hibás kurzor: `validation_failed` („Hibás kurzor; kurzor nélkül a teljes állapot kérhető le.”).
  - **Lapméret:** legfeljebb 500 rekord a `changes` és a `deleted` listákban együtt. Egy útvonalverzió a pontjaival együtt egy egység, sosem kerül két lapra; ha nem fér el, a következő lapot kezdi (egymagában 501 rekord is lehet, a séma `route_version_checkpoints` listája legfeljebb 500 elemű).
  - **Ülepedés:** az utolsó 5 másodpercben (`Pull::SETTLE_MS`) változott rekordok a következő pullra várnak, mert az őket író tranzakció még nem biztos, hogy véget ért, és a kurzor különben átléphetne rajtuk.
  - **Láthatóság:** a telephelyi telefon a saját telephelyét, a supervisor a hozzárendelt telephelyeit (a Q9 szerint PIN-mezők nélkül) és saját magát látja. A jelöltek mindig csak a hívó telephelyeinek rekordjai (törölt sorokkal együtt), így a `deleted` lista más telephely rekordjainak ID-ját sem árulja el. Ami jelölt, de nem látható, az a `deleted`-be kerül: törölt (soft delete) pont, útvonal, menetrend, előfizetés, felhasználó; visszavont vagy törölt telefon; a hívó telephelyeiről leválasztott felhasználó. A `changes.devices` csak a vissza nem vont telefonokat tartalmazza.
  - **Leválasztás:** a `site_user` sora a leválasztáskor eltűnik, ezért egy új, csak szerveroldali tábla (`waypost_site_user_detachments`: `site_id`, `user_id`, `detached_at`) jegyzi, ki melyik telephelyről lett valaha leválasztva. A felhasználó `updated_at`-je frissül; ha a hívó telephelyeinek egyikén szerepelt, és már egyiken sem szerepel, a `deleted.users`-be kerül, különben a `changes`-be (új `site_ids`-szel).
  - **A hívó telephelyeinek változása** (a supervisort hozzárendelik vagy leválasztják, telephelyet hoz létre vagy töröl): ha a kurzor lenyomata nem egyezik a mostani telephelyekével, a pull elölről kezdi a teljes állapotot, `reset: true`-val (a `waypost-schema` döntése szerint a kliens ekkor először törli a helyi konfigurációját). Egy teljes állapot első lapján nincs `deleted`; a következő lapjai már inkrementálisak. A `deleted.sites` emiatt mindig üres (törölt vagy elvett telephely csak lenyomatváltozással jöhet).
  - **`last_seen_at`:** minden sikeres pull frissíti a telefonnál, az `updated_at` nélkül, különben a telefon minden pullban önmagát kapná változásként. Így a supervisor telefonja csak a telefon más változásával látja az új értéket.
- **Indok:** Egy kurzor, egy sorrend minden táblára: a kliens egyetlen értéket tárol. Telephelyenkénti kurzor nem férne el egy rövid szövegben, és egy új telephely régi rekordjait úgyis lapozva kell átküldeni, ezért egyszerűbb és biztosabb az újrakezdés; ritka esemény, és csak a supervisor saját telefonját érinti (a telephelyi telefon telephelye nem változik). A leválasztási tábla nélkül a leválasztott felhasználó vagy a telefonon maradna, vagy minden hívó minden más telephelyen változott felhasználó ID-ját megkapná.
- **Érintett:** `src/Sync/Pull.php`, `src/Sync/PullCursor.php`, `src/Http/Controllers/SyncController.php`, `src/Http/Controllers/SiteUserController.php`, `database/migrations/2026_10_28_000015_…`, `../waypost-schema` (`api/sync-pull.response`, `reset`), M2-06, M2-23, M3-07, Q3, Q9.

## 2026-10-28 – Az M2 elfogadása (M2-24)

- **Kérdés:** Hogyan biztosítható, hogy minden végpontnak legyen jogosultsági tesztje, és egy új végpont se maradjon ki?
- **Döntés:** A `tests/Feature/Api/EndpointsTest.php` bejárja a csomag összes (`waypost.*` nevű) útvonalát: a lista pontosan a specifikáció végpontjai; a belépésen kívül mindegyik token nélkül `unauthenticated`; és minden végpontot meghív supervisor tokennel, a telephelyi telefon tokenjével, valamint `X-Acting-User`-rel: amelyik hívót nem fogadja, annak 403, amelyiket igen, az túljut az azonosításon. Egy új útvonalhoz így a teszt listáját és (ha nem konfigurációs) a hívóit is meg kell adni. A végpontok saját tesztjei a válaszokat a sémával vetik össze (`toMatchSchema`).
- **Indok:** A végpontonkénti tesztek a viselkedést nézik; a mátrix az, ami egy elfelejtett `Authenticate::using(...)`-t vagy egy rossz csoportba tett útvonalat azonnal jelez.
- **Érintett:** `tests/Feature/Api/EndpointsTest.php`, `tests/Pest.php` (`requestApi()`, `pullAs()`), M2-24.

## 2026-11-01 – A push feldolgozása (M4-02 – M4-04)

- **Kérdés:** Mit jelent pontosan a specifikáció három ellenőrzése, ha a `UNIQUE(device_id, seq)` miatt egy `seq`-hez egy sor tartozhat, és mi történik az elutasított bejegyzésekkel?
- **Döntés:**
  - **Kérésszintű hiba (422, semmi nem tárolódik):** a köteg nem szigorúan növekvő `seq` szerint áll; a kérés vagy egy bejegyzés `device_id`-je nem a token eszközéé; sémahiba, több mint 200 bejegyzés.
  - **Bejegyzésszintű ellenőrzés** `seq` sorrendben, az eszköz sorának zárolása alatt, egy tranzakcióban: `seq` → `chain_gap`, `prev_hash` → `chain_fork`, HMAC → `bad_hmac`. Az elutasított bejegyzés is eltárolódik a `verify_status`-szal, bizonyítékként.
  - **Egy `seq` helyén:** a várakozó `gap` bejegyzést a szerver újraellenőrzi, amikor sorra kerül. A `fork` és a `bad_hmac` sor addig marad, amíg ugyanarra a `seq`-re új bejegyzés nem érkezik: az új (ha jó, ha nem) lecseréli. Különben aki a tokent ismeri, de a titkot nem, egyetlen hamis bejegyzéssel örökre megállíthatná a láncot. A következmény: a rossz bejegyzés nyoma a jó megérkezésekor eltűnik; ha fontos lesz, a diagnosztikai esemény (Q2) őrizheti meg.
  - **`last_seq` alatti `seq`:** ugyanaz a lenyomat újra `accepted`; más lenyomat `chain_fork`, és **nem tárolódik**, mert az elfogadott sor marad a helyén. Ha a sort az adatmegőrzés már törölte, a HMAC dönt (`accepted` vagy `bad_hmac`).
  - **Azonosító-ütközés:** más eszköz bejegyzésének vagy a saját, más `seq`-ű elfogadott bejegyzésnek az ID-ja: `chain_fork`, tárolás nélkül, a meglévő sor érintetlen. A saját, elutasított sor ilyenkor átadja a helyét.
  - **A köteg menet közben is tölt:** egy elfogadott bejegyzés után a közvetlenül következő várakozó sor azonnal sorra kerül, kivéve ha azt a `seq`-et a köteg még hozza (akkor a kötegbeli az érvényes). A várakozó, de hibásnak bizonyuló sor is a `rejected` listába kerül, és ott megáll a feldolgozás.
  - **Listák:** az `accepted` a most `ok` bejegyzések ID-ja, a várakozásból most elfogadottaké is. A séma 200 elemre korlátoz, ezért a lista levágódik; a levágott bejegyzés újraküldéskor kap nyugtát, a `last_seq` a valós értéket mutatja.
  - **`received_at`** az első érkezés ideje: az ugyanazzal a lenyomattal újraküldött, várakozó bejegyzésé nem változik (a `late_sync` ebből számol). A `last_seen_at`, `last_seq`, `last_hash` és `upload_closed_at` írása nem frissíti az `updated_at`-et, hogy a pull ne hozza a telefont változásként.
  - A kanonikus JSON a `1.0` alakú egész számot `1`-ként írja ki (a `waypost-schema` döntése szerint), minden más lebegőpontos számra és a ±(2⁵³ − 1)-en kívüli egészre kivételt dob; az ilyen bejegyzés `bad_hmac`.
- **Indok:** A specifikáció sorrendje szerint; a lecserélés az egyetlen mód, ahogy a `UNIQUE(device_id, seq)` és a „hamis bejegyzés nem állíthatja meg a láncot” együtt teljesül.
- **Érintett:** `src/Chain/ChainProcessor.php`, `PushResult.php`, `CanonicalJson.php`, `EntryHash.php`, `src/Models/ChainEntry.php`, `src/Http/Controllers/SyncController.php`, M4-02, M4-03, M4-04.

## 2026-11-01 – Halasztott vetület: a még ismeretlen hivatkozás (M4-05)

- **Kérdés:** A sorrend push → média → outbox → pull, ezért egy `ok` bejegyzés hivatkozhat olyan konfigurációra, amely még nincs a szerveren (offline betanított pont, offline létrehozott őr). Az idegen kulcs miatt a vetület elbukna, és a 500-as válasz örökre megállítaná az eszköz láncát.
- **Döntés:** A láncellenőrzés és a vetület külön lépés. Új oszlop: `chain_entries.projected_at`. Az `ok` bejegyzés előrébb viszi a láncot akkor is, ha még nem alkalmazható; a `Projector::apply()` előbb megnézi a hivatkozásokat, és ha valami ismeretlen, nem ír semmit (`false`), a bejegyzés `projected_at = null` marad. Minden push végén az eszköz összes alkalmazatlan bejegyzése újra sorra kerül, `seq` szerint.
  - **Hatókör:** a műszak és a kör csak ugyanazé az eszközé lehet, a `shift_started.site_id` csak az eszköz telephelye. Az útvonalverzió, a menetrend és a pont (`round_opened`, `scan`, `incident.nearest_checkpoint_id`) csak az eszköz telephelyéé, a menetrend a verzió útvonaláé, az `incident.scan_id` csak az eszköz saját láncának leolvasása lehet (2026-11-05, WP-BE-002: különben egy másik telephely útvonal- és pontnevei kerülnének a jelentésbe). Ami mégsem az, az örökre `projected_at = null` marad: nem írhat bele egy másik telefon műszakjába vagy egy másik telephely jelentésébe, a láncot sem állítja meg, és a jelentés „nem teljes adat” jelzést kap. Új szerverjelölés nem kell hozzá. Törölt (soft delete) őr, pont, útvonal és menetrend is érvényes hivatkozás.
  - **A sorrendtől független vetület**, mert egy halasztott bejegyzés később érkezhet: a kör `started_at`-je a legkorábbi leolvasásé, a már zárt kört egy késő leolvasás nem nyitja újra, a már zárt műszak és kör első értékei maradnak.
  - **A fotó helyőrző sora** a bejegyzés elfogadásakor akkor is létrejön, ha a bejegyzés még nem alkalmazható, mert az app a push után rögtön feltölti a fotót. A `taken_at` a bejegyzés `device_time`-ja (legfeljebb a `received_at`-je, WP-BE-014), amíg a feltöltés a pontos értéket nem hozza.
  - **Ismert korlát:** a halasztott bejegyzés csak a következő pushnál kerül vetületbe, addig a jelentésből hiányzik. Az M6-nak a `projected_at = null` sorokat a „nem teljes adat” jelzésbe kell számolnia.
  - Az időpontok az app `entryTrustedTime` számítása szerint (`Waypost\Chain\TrustedTime`).
  - **Hibás bejegyzés** (2026-11-05, WP-BE-008): minden bejegyzés saját savepointban kerül vetületbe. Ha egy bejegyzés kivételt dob (adatbázishiba, az oszlopba nem férő érték), `Log::error` kerül a naplóba (eszköz, bejegyzés, `seq`), és a bejegyzés vetület nélkül marad, mint az ismeretlen hivatkozásnál. A push a fogadott bejegyzésekkel commitol, a következő push újrapróbálja. Különben egyetlen ilyen bejegyzés az eszköz minden további pushát 500-zal visszagörgetné, és az adat a telefonon maradna.
- **Indok:** Egy érvényes, aláírt bejegyzés sosem veszhet el és nem akaszthatja meg a szinkront; a külön újrapróbáló job helyett a következő push elég, mert az app előtérben 5 percenként szinkronizál.
- **Érintett:** `src/Chain/Projector.php`, `TrustedTime.php`, `ChainProcessor.php`, `database/migrations/…_chain_entries_table.php`, M4-05, M5-02, M6-05.

## 2026-11-01 – A szerver jelölései (M4-06)

- **Kérdés:** Hol tárolódnak a jelölések, és mit nem mond ki a specifikáció a feltételeikről?
- **Döntés:**
  - **Tárolás:** a `chain_entries.flags` csak a szerver jelöléseit tartalmazza (`Waypost\Enums\ChainFlag`), az appé a hash-elt payloadban vannak. A `scans.flags` a kettő uniója (előbb az appé), hogy a jelentésnek ne kelljen a láncot olvasnia.
  - **Határok:** a specifikáció „több mint” és „kevesebb mint” szövege szigorú: pontosan a határon nincs jelölés.
  - **`time_suspect`:** az elfogadáskor, minden bejegyzéstípusra.
  - **`far_from_checkpoint`:** a `3 × pontosság` a **leolvasás** pontossága (a specifikáció mondatának alanya), a betanított pont pontossága csak az „50 m alatti” feltételben szerepel. Hiányzó hely vagy pontosság bármelyik oldalon: nincs jelölés. Csak a leolvasásra. Föld-sugár: 6 371 008,8 m.
  - **`too_fast`:** a kör előző, ponthoz tartozó leolvasásához képest (láncsorrendben; az ismeretlen címke nem számít közte), a `scanned_at` szerint, csak ha a pont a betanított sorrendben pontosan az előző után következik (`position + 1`), és van `walk_time_from_prev_s`.
  - **`late_sync`:** a zárás ideje csak a `shift_closed` bejegyzésből derül ki, és ennek nagyobb a `seq`-je, mint a műszak többi bejegyzéséé. Ezért a jelölés a zárás feldolgozásakor kerül fel, visszamenőleg: a műszak `seq`-tartományának (`shift_started` … `shift_closed`) minden olyan bejegyzésére, amelynek `received_at`-je 12 óránál többel a zárás után van. Így a körön kívüli leolvasás is megkapja, amelynek nincs műszakazonosítója.
- **Indok:** A specifikáció *Láncolt napló / Jelölések* és a `waypost-schema` „A `scan` payload belső szabályai” döntése szerint; a visszamenőleges megoldás nem igényel külön oszlopot.
- **Érintett:** `src/Chain/ServerFlags.php`, `Projector.php`, `src/Enums/ChainFlag.php`, M4-06, M6.

## 2026-11-01 – A fotó feltöltése és a push-final (M4-07, M4-08)

- **Kérdés:** Hogyan ér el a multipart `PUT` a kódig, kié a fotó, és hol tárolódik, hogy a visszavont eszköz lezárta a feltöltését?
- **Döntés:**
  - **Bontás:** a `ParseMultipartBody` middleware (`request_parse_body()`) az azonosítás és a throttling után, a `HandleIdempotencyKey` előtt fut, mert a lenyomathoz kellenek a mezők és a fájl. Nem bontható törzs: `400 bad_request`. A PHP feltöltési korlátja fölött: `413 bad_request`. A Pest a kérést kibontva kapja, ezért a valódi bontást a `scripts/install-smoke.sh` próbálja ki, HTTP-n át.
  - **Ellenőrzés:** a szöveges mezők az `api/media-upload.request` sémán át (a `ValidateSchema` csak JSON-t néz, ezért a controllerből); a típust a fájl tartalma dönti el (fileinfo), csak `image/jpeg`. A lenyomat a megadott mezővel és a sorban rögzítettel is egyezzen.
  - **Lánc fotója:** sor nélkül `404`, más eszköznek és supervisornak `403`, más tulajdonosra `422`.
  - **Pont fotója:** csak supervisor (saját token vagy `X-Acting-User`), a telefon önmagában `403`. Ha a pont már létezik, a `CheckpointPolicy::update` dönt (idegen telephelyé: `404`); ha még nem, a feltöltés létrehozza a sort. A `photo_media_id` csak feltöltött, ehhez a ponthoz tartozó fotóra mutathat, különben `422`.
  - **Tárolás:** `waypost/media/<id>.jpg` a `waypost.storage.disk` lemezen, kifejezetten `private` láthatósággal, mert egy S3-lemez alapból lehet nyilvános. Ismételt feltöltésnél a már fent lévő sor és fájl nem változik.
  - **`owner_type`:** az oszlopban a modell osztályneve áll (Eloquent morph, globális morph map nélkül, mert az a gazda saját aliasaival ütközhetne), az API nevét a `MediaOwnerType` adja.
  - **Visszavont eszköz:** az `Authenticate::REVOKED` paraméter engedi át a `POST /sync/push-final` és a `PUT /media/{id}` útvonalon, amíg az új `devices.upload_closed_at` üres; `X-Acting-User`-rel soha. A lezárás után mindkettő `401 device_revoked`. A token ezért megmarad, különben `unauthenticated` lenne a válasz. Az oszlopok (`upload_closed_at`, `projected_at`) a meglévő migrációkba kerültek, mert éles telepítés még nincs.
  - **Következmény az M4-10-re:** a 4xx válasz is tárolódik az `Idempotency-Key`-hez (M2-12). A bejegyzés elfogadása előtt kapott `404` után a feltöltést **új kulccsal** kell újrapróbálni, különben 24 órán át ugyanazt a `404`-et kapja vissza.
- **Indok:** A `waypost-schema` 2026-11-01-es döntései szerint; a lezárás külön oszlopa nélkül a `401 device_revoked` és a megmaradó token nem férne össze.
- **Érintett:** `src/Http/Middleware/ParseMultipartBody.php`, `Authenticate.php`, `src/Http/Controllers/MediaController.php`, `SyncController.php`, `CheckpointController.php`, `src/Enums/MediaOwnerType.php`, `scripts/install-smoke.sh`, M4-07, M4-08, M4-10, M4-11.

## 2026-11-02 – A diagnosztikai jelzés (Q2, M4-09)

- **Kérdés:** Hol tárolódik az eszköz jelzése az elutasított bejegyzéseiről?
- **Döntés:** Új tábla: `device_diagnostics`, végpont: `POST /devices/diagnostics` (`waypost-schema` 0.4.0). Eszköztokennel, a visszavont eszköz a feltöltése lezárásáig is küldhet. Idegen `device_id`: `422`. Ugyanaz az `id` újra nem változtat semmit (`firstOrCreate`), más eszköz meglévő `id`-jére sem. Az `entry_id` nem idegen kulcs, mert a bejegyzés nem biztos, hogy tárolódott, és később le is cserélődhet. Idegen kulcs csak az eszközre van.
- **Indok:** A rossz bejegyzés nyoma a jó megérkezésekor eltűnik (lásd „A push feldolgozása”); a jelzés ezt őrzi meg a jelentésnek. A szerver válasza mindig ugyanaz, így az újraküldés biztonságos.
- **Következmény:** **Az M6-nak** a műszakjelentés lánc-szakaszában ezt is meg kell mutatnia, és az adatmegőrzés jobjának törölnie kell a régi sorokat.
- **Érintett:** `database/migrations/…_device_diagnostics_table.php`, `src/Models/DeviceDiagnostic.php`, `src/Http/Controllers/DeviceController.php`, `routes/api.php`, M4-09, M6.

## 2026-11-02 – Az ismételt létrehozás (M5-02)

- **Kérdés:** Az outbox (M5-03) a létrehozást az app által generált `id`-val küldi. Ha a válasz elveszett, és az `Idempotency-Key` már lejárt (24 óra), vagy az app új kulccsal próbálkozik, a foglalt `id` eddig `409 conflict` volt, pedig a rekord épp ettől a kéréstől jött létre. Mit válaszoljon a szerver?
- **Döntés:** A `waypost-schema` 2026-11-02-es döntése szerint (0.4.0): `Controller::existing()` a létrehozás elején (a `create` jogosultság után, a többi ellenőrzés előtt) megkeresi az `id`-t, a törölt rekordok között is. Ha a rekord ugyanazzal a nem változó mezővel létezik (pont, útvonal: `site_id`; verzió: az útvonal; menetrend: `route_id`; felhasználó: `role`; előfizetés: `user_id` és `site_id`; telephely: nincs ilyen), és a hívó a policy `view` szabálya szerint látja, a válasz `200` (`Controller::repeated()`) a rekord jelenlegi állapotával, és nem változik semmi. A verziónak nincs policyja: a láthatóságot az útvonal adja, amelyet a controller már engedélyezett. A törzs többi mezőjét a szerver nem veti össze. A szerver generálta PIN-t az ismétlés nem adja vissza (csak a lenyomata van meg). A törölt rekord `409` saját üzenettel („…de azóta törölték”), a más szülőjű és a nem látható `409` „Foglalt azonosító”. Az ellenőrzés a többi előtt fut, mert a rekord azóta érvénytelenné válhatott (pl. a verzió pontját törölték, az előfizetőt leválasztották), az ismétlésnek mégis sikerülnie kell.
- **Indok:** Az outbox a 4xx-et a supervisornak jelzi, és nem küldi újra; egy sikeres létrehozás így hibának látszana. A `200`, mert ez a kérés nem hozott létre semmit. Két, egyszerre futó, különböző kulcsú kérés ugyanazzal az `id`-val továbbra is `409` (az elsődleges kulcs ütközése); az outbox egyszerre egy kérést küld, és a következő pull úgyis elhozza a rekordot.
- **Következmény az M5-03-ra:** a `200` és a `201` is siker. Új felhasználónál az app maga generálja a PIN-t (a kérésben küldi), mert az ismétlés a szerver generálta PIN-t nem adja vissza.
- **Érintett:** `src/Http/Controllers/Controller.php`, `SiteController.php`, `CheckpointController.php`, `RouteController.php`, `RouteVersionController.php`, `ScheduleController.php`, `UserController.php`, `ReportSubscriptionController.php`, `tests/Feature/Api/RepeatedCreateTest.php`, M5-02, M5-03, M5-18.

## 2026-11-02 – A még futó kérés jelzése (M5-03)

- **Kérdés:** Ha az app egy hálózati hiba (pl. időtúllépés) után ugyanazzal az `Idempotency-Key`-jel újraküldi a kérést, miközben az első még fut, a válasz `409 conflict`. Az outbox a 4xx-et (a 401 és a 429 kivételével) elutasításnak veszi, és nem küldi újra, így a még futó, valószínűleg sikeres kérés hibának látszana, és a következő próbálkozás sem menne ki. Miből tudja az app, hogy ez nem a kérés saját ütközése (foglalt PIN, címke vagy `id`)?
- **Döntés:** A még futó kulcs `409`-e `Retry-After: 5` fejlécet kap (`HandleIdempotencyKey::BUSY_RETRY_AFTER_S`); az üzenet és a törzs nem változik. A többi `409`-nek nincs `Retry-After`-je. Az app a `Retry-After`-es `409`-et ugyanazzal a kulccsal később újraküldi.
  - **Elhagyott kulcs** (2026-11-05, WP-BE-003): ha a kérés fatális hibával, időtúllépéssel vagy a folyamat leállásával ér véget, a kulcs válasz nélkül marad, és 24 órán át `409`-et adna; az outbox ez idő alatt minden mögötte álló műveletet visszatartana. Ezért a létrejötte után 5 perccel (`ABANDONED_AFTER_S`) válasz nélkül maradt kulcs elhagyottnak számít: a következő próbálkozás újra lefuttatja a kérést, és a `PruneIdempotencyKeys` is törli. Az 5 perc bőven több bármely író kérés futásánál. Ha a kérés mégis lefutott, csak a válasza nem tárolódott el, az újrafuttatás nem okoz kárt: a létrehozás a saját `id`-ját találja meg (`existing()`), a módosítás ugyanazt írja be még egyszer.
- **Indok:** A fejléc a HTTP-ben épp erre való („próbáld újra ennyi idő múlva”), és a hibaformátumot (`api/error.json`) nem kell bővíteni; az üzenet szövegére építeni törékeny lenne.
- **Érintett:** `src/Http/Middleware/HandleIdempotencyKey.php`, `src/Jobs/PruneIdempotencyKeys.php`, `tests/Feature/Api/IdempotencyTest.php`, M5-03.

## 2026-11-03 – Menetrend-kibontás PHP-ban (M6-02)

- **Kérdés:** Hogyan adja a PHP ugyanazokat a köröket, mint az app (M3-13), ha a PHP `DateTime` a két DST-esetben a Q5-szabállyal ellentétesen dönt?
- **Döntés:** `Waypost\Schedule\Expansion::expand()` az app `src/domain/schedule.ts`-ének szerkezetét követi (az ablak faliórás percei, a `weekdays` a kezdő napra, egy ablakon belül azonos pillanatra eső időpont egyszer). A helyi idő → UTC átváltást nem a PHP végzi: `Expansion::resolve()` a faliórás időből kivonja az egy nappal előtte és utána érvényes eltolást, és azt a jelöltet tartja meg, amelynél az eltolás valóban érvényes; két jelöltből (őszi, kétszer előforduló idő) a korábbit, egyetlen érvényes nélkül (tavaszi rés) a váltás pillanatát (2026-11-04 óta az eltolás szerinti bináris kereséssel: a `DateTimeZone::getTransitions()` a rendszer időzóna-adatbázisával – pl. Ubuntu PHP, slim TZif – a csak szabállyal megadott váltásokat nem adja vissza, és a CI-n a tavaszi rés kivételt dobott). A határidő (`Waypost\Schedule\Deadline`) az app `deadline.ts`-ével azonos. Ellenőrzés: a `schedule/test-vectors.json` minden esete (`tests/Unit/Schedule/ExpansionTest.php`), és külön, hogy a PHP saját választása mindkét DST-esetben a másik.
- **Indok:** A két nyelv egyezését a közös vektorok biztosítják; a PHP alapviselkedésére építve a DST-éjszaka körei eltérnének az appétól.
- **Érintett:** `src/Schedule/Expansion.php`, `ExpectedRound.php`, `Deadline.php`, M6-02, Q5.

## 2026-11-03 – A jelentésadat-összesítő (M6-03)

- **Kérdés:** Mi tartozik egy telephely egy időszakának (helyi napok) jelentésébe, ha a menetrend azóta változhatott, egy éjszakát több műszak is lefedhet, és a telefon adatai késhetnek?
- **Döntés:** `Waypost\Reports\Summarizer::period($site, $fromDate, $toDate, $now)` → `Summary`:
  - **A nap** a telephely helyi dátuma; a körök a kezdő napjuk ablakához tartoznak (a hétfő 20:00 – kedd 08:00 kör hétfői). Az időszak határai: az első nap kezdő és az utolsó nap utáni helyi éjfél (`periodStart`, `periodEnd`, UTC-ben; a DST-napok 23 vagy 25 órásak).
  - **Körök (sorok):** a menetrend várt időpontjai és a telefon által megnyitott körök uniója, menetrend + `scheduled_at` szerint párosítva. A várt időpontot a menetrend **mostani** állapota adja (aktivitásról nincs előzmény): aktív menetrend aktív útvonalon, amely az időpontban már létezett és még nem volt törölve (`created_at`, `deleted_at`), és az útvonalnak volt már betanított verziója. A párosítatlan kör (a menetrend azóta változott, vagy inaktív lett) is benne van, a menetrendje szerinti kezdő napon, vagy ha az már nem adja ki, az időpontja helyi dátumán (`expected = false`).
  - **Kimenet** (`RoundOutcome`): a kör saját állapota (`completed`, `incomplete`, `missed`); a szerveren még nyitott kör `pending`; kör nélküli időpont a határideje után `missed`, előtte `upcoming`. A határidő a specifikáció szerinti (időkeret, vagy az útvonal következő köre ugyanazon a napon, vagy +60 perc). Várt körök száma: minden sor, kivéve az `upcoming`-ot; teljesítési arány: `completed` / várt.
  - **Hiányzó pontok:** a kör útvonalverziójának leolvasás nélküli pontjai; kör nélküli időpontnál az útvonal akkor érvényes verziójának összes pontja.
  - **Műszakok és események:** a műszak a legkorábbi körének napjához tartozik (kör nélkül a kezdésének helyi dátumához), az eseményei vele. Így az éjfél után átadott éjszaka is egy jelentésben marad. A körön kívüli leolvasás a telefon akkori műszakjának napjához, műszak nélkül a saját helyi dátumához tartozik.
  - **Jelölések:** a leolvasások, amelyeknek van jelölésük (az appé vagy a szerveré, `scans.flags`), jelölésenkénti számmal.
  - **A lánc állapota:** a telephely telefonjainak nem `ok` bejegyzései `verify_status` szerint, és a diagnosztikai jelzések száma (Q2), a `device_time`, ill. az `occurred_at` szerint az időszak napjain.
  - **Nem teljes adat** (`IncompleteReason`): egy érintett telefon (a vége előtt hozzárendelve, az eleje előtt nem visszavonva, nem törölve) nem szinkronizált az adatok vége óta (`last_seen_at` < `dataEnd` = az időszak vége, vagy a körei közül a legkésőbbi határidő); a visszavont telefon nem számít, ha lezárta az utolsó feltöltését. Továbbá: várakozó láncbejegyzés (`gap`, vagy `ok`, de `projected_at = null`, lásd M4-05), a határidő után is nyitott kör, fel nem töltött fotó. Telefon nélküli telephely (vagy éjszaka) nem „nem teljes”: a várt körei elmaradtak.
- **Indok:** A specifikáció szerint az elmaradt köröket a szerver a menetrendből számolja, így a működésképtelen telefon is látszik; a műszakhoz kötött nap a legegyszerűbb szabály, amely egy éjszakát egyben tart. A menetrend előzménye nélkül a mostani állapot és a létezés ideje a legjobb közelítés; a párosítatlan kör megtartása miatt a tényleges adat ilyenkor sem vész el.
- **Érintett:** `src/Reports/Summarizer.php`, `Summary.php`, `RoundRow.php`, `RoundOutcome.php`, `IncompleteReason.php`, `tests/Feature/Reports/SummarizerTest.php`, M6-03, M6-05 – M6-10.

## 2026-11-03 – Aláírt URL-ek és a jelentésarchívum (M6-04)

- **Kérdés:** Hogyan nyílik meg egy fotó vagy egy tárolt PDF az e-mailből és az appból, ha a lemez privát, és meddig él a link?
- **Döntés:**
  - **Aláírt útvonalak** token nélkül: `GET /media/{id}/file` és `GET /reports/{id}/file` (`Waypost\Http\SignedUrls`, Laravel `temporarySignedRoute`, az alkalmazás `APP_KEY`-ével). A lejárt vagy módosított link (más azonosító, más lejárat, hozzáadott paraméter) `403 forbidden` („A link lejárt vagy érvénytelen.”), a már törölt fájl `404`. A válasz a fájl, `Cache-Control: private`. A végpontmátrixban (`EndpointsTest`) hívó nélküliek, mint a belépés.
  - **Élettartam:** a fotó linkje az adatmegőrzés végéig él (`taken_at` + a telephely megőrzési ideje), a már lejárt fotóhoz nincs link. A `taken_at` nem lehet későbbi a szerver idejénél (2026-11-05, WP-BE-014): a helyőrző sorban legfeljebb a bejegyzés `received_at`-je, feltöltéskor legfeljebb a feltöltés ideje. Így egy előresiető telefonóra nem hosszabbítja meg a linket és a fájl megőrzését; a megőrzés szerveroldali szabály marad. A jövőbeli `taken_at` elutasítása (422) szerződésváltozás lenne, és egy rossz órájú telefon fotóit veszítené el, ezért a szerver levágja.
  - Az archívum PDF-linkje `waypost.signed_urls.report_ttl_min` percig (alapból 60, `WAYPOST_REPORT_URL_TTL_MIN`), mert a lista minden lekérése újat ad, és egy kiszivárgott link így hamar használhatatlan.
  - **Megőrzési idő:** `Waypost\Reports\Retention::days()` = `max(31, 2 × a leghosszabb előfizetett időszak)`, ahogy az app is mutatja (M5-19); az M6-11 is ezt használja.
  - **`GET /reports`:** csak supervisor saját tokennel (a specifikáció táblázata szerint); a hívó telephelyei, `?site_id=` (idegen telephelyre üres lista, mint a többi listánál), `?type=` (ismeretlen: `422`), `period_start` és `id` szerint csökkenően, 100-as oldalakban, átlátszatlan `next_cursor`-ral (a `waypost-schema` 0.5.0 döntése szerint); hibás kurzor: `422`.
  - Mivel a link abszolút, a gazda-alkalmazás `APP_URL`-jének a valódi címet kell mutatnia (a queue-ban készülő e-mail linkje ebből lesz), és egy proxy mögött a `TrustProxies` beállításától függ, hogy a kérés címe egyezik-e az aláírttal.
- **Indok:** A specifikáció („letöltés aláírt, lejáró URL-lel”) Laravel-beli legegyszerűbb megoldása; a token nélküli link kell, mert az e-mail böngészőben nyílik meg.
- **Érintett:** `src/Http/SignedUrls.php`, `src/Http/Controllers/ReportController.php`, `MediaController.php`, `src/Http/Entities.php`, `src/Reports/Retention.php`, `routes/api.php`, `config/waypost.php`, `tests/Feature/Api/ReportsTest.php`, `EndpointsTest.php`, M6-01, M6-04, M6-05 – M6-09, M6-11.

## 2026-11-04 – A műszakjelentés (M6-05)

- **Kérdés:** Mi indítja, meddig vár a fotókra, mi tartozik bele, és mi lesz a lecserélt telefon le nem zárt műszakjával (M4-11)?
- **Döntés:**
  - **Indítás:** a `Projector` a `shift_closed` alkalmazásakor (amikor az `ended_at` beíródik) `SendShiftReport`-ot küld a queue-ra, a push tranzakciója után (`afterCommit`). A job percenként (`RECHECK_S`) újranézi, hiányzik-e még fotó, és ha igen, újra beütemezi magát a feldolgozás + 30 perc (`photosDueAt`) határig; utána a meglévő adattal küld, a hiányzó fotót a jelentés jelzi („nem teljes adat”, „1 fotó még nem érkezett meg”). A `sync` queue nem tud késleltetni (végtelen ciklus lenne), ott azonnal küld. A határidő a feldolgozás idejétől számít, nem az `ended_at`-től, mert az utóbbi késve érkező láncnál órákkal korábbi lehet.
  - **Egyszer:** a `reports` sora (egyedi: telephely, típus, `period_start` = a műszak kezdete) a küldés előtt foglalódik; az ismételt push, a második job vagy a később érkező fotó nem küldi újra. Ha a küldés hibát dob, a sor (és a PDF) törlődik, és a job újrapróbálja (3-szor).
    - **Elhagyott foglalás** (2026-11-05, WP-BE-004; korábban elfogadott kockázat volt): ha a job a foglalás és a küldés között hal meg (memória, időtúllépés, leállított worker), a sor nem törlődik. A 15 percnél (`ReportSender::CLAIM_TIMEOUT_MIN`) régebbi, el nem küldött foglalás elhagyottnak számít: az `exists()` nem veszi meglévőnek, a következő küldés törli (a PDF-jével együtt), figyelmeztetést naplóz (`Log::warning`), és újra foglal. A napi, heti és havi jelentést a `SendDueReports` 15 percenként újra elindítja a 12 órás pótlási időn belül; a műszakjelentés jobja a queue újrapróbálásakor a még élő foglalásnál `release()`-szel megvárja, hogy elhagyottá váljon. A 15 perc bőven több egy küldésnél (a queue-job időkorlátja ennél kisebb), így két élő job nem küldi kétszer.
  - **Tartalom** (`Summarizer::shift()`): a műszak körei, és a menetrend azon időpontjai, amelyek a műszak alatt jártak le kör nélkül (a telefon nem nyitotta meg őket, pl. a menetrend nem ért le rá): `missed`. A műszak eseményei, a telefon körön kívüli leolvasásai a műszak alatt, a telefon lánca és diagnosztikai jelzései a műszak idején. Átadásnál a következő őr neve a telefon következő műszakjából; a `late_sync` a pontoknál nem, csak egyszer, felül („Később érkezett: … szinkronizált”, a késő bejegyzések legutolsó `received_at`-je).
  - **Le nem zárt műszak:** ha a visszavont telefon lezárja az utolsó feltöltését (`push-final`, `done: true`), a nyitott műszakjainak jelentése elindul (fotóra nem vár, több nem jön). A vége a telefon műszak utáni utolsó elfogadott bejegyzésének megbízható ideje; a jelentés felül jelzi, hogy a műszak nem zárult le. Más nyitott műszakról (a telefon nem szinkronizál) nincs műszakjelentés: a napi jelentés mutatja.
  - **Címzettek:** a telephely aktív, a telephelyhez rendelt supervisorai, akiknek van élő `shift` előfizetésük és e-mail-címük; címzettenként külön levél. Címzett nélkül nincs levél és `reports` sor sem.
  - **Sablon:** a design `email/shift-report.blade.php`-je, „Waypost” fejléccel (Q12); kiegészítve a „nem teljes adat” sávval, a nem lezárt körök állapotával („Nincs lezárva”), a körön kívüli leolvasásokkal és a napló állapotával. A sablon csak kész, formázott értékeket kap (`ReportContent`).
- **Indok:** A specifikáció *Jelentések* táblázata és *Tartalom* pontja szerint; a jobon belüli újranézés egyszerűbb, mint a fotófeltöltésből indított küldés, és nem kell hozzá új oszlop.
- **Érintett:** `src/Jobs/SendShiftReport.php`, `src/Chain/Projector.php`, `ChainProcessor.php`, `src/Reports/Summarizer.php`, `ReportContent.php`, `ReportSender.php`, `Labels.php`, `LocalFormat.php`, `Palette.php`, `src/Mail/ReportMail.php`, `resources/views/mail/`, `tests/Feature/Reports/ShiftReportTest.php`, M6-05, M4-11.

## 2026-11-04 – A napi, heti és havi jelentés (M6-06 – M6-09)

- **Kérdés:** Hogyan fut le 10:00-kor minden telephely saját idejében, mi történik egy kimaradt futással, és hogyan készül a PDF?
- **Döntés:**
  - **Ütemezés:** egyetlen, 15 percenként ütemezett job (`SendDueReports`), mert a Laravel ütemezője egy időzónában gondolkodik. Telephelyenként és előfizetett típusonként kiszámolja a legutóbbi küldési időpontot (`ReportPeriod`: naponta 10:00 az előző napra; hétfőn 10:00 az előző hétfő–vasárnapra; elsején 10:00 az előző naptári hónapra; a helyi 10:00-t a menetrend szabályai váltják UTC-re), és ha az legfeljebb 12 órája volt (`CATCH_UP_HOURS`), és nincs még `reports` sora, `SendPeriodReport`-ot indít. Így egy leállás 22:00-ig pótolódik, de egy új előfizetés vagy egy hosszabb leállás után nem megy ki régi jelentés.
  - **Adat:** a küldéskori teljes adatból (`Summarizer::period()`); ami még nem érkezett meg, azt a tárgy („(nem teljes adat)”) és a levél teteje jelzi.
  - **Tartalom:** napi: a várt, teljesített, hiányos és elmaradt körök száma, a teljesítési arány, az elmaradt és hiányos körök a hiányzó pontokkal, a műszakok, a jelölések leolvasásonként, az események fotólinkkel. Heti és havi: ugyanez napi bontású táblázattal, az események kategóriánként; a jelölések csak összesítve (a PDF-ben leolvasásonként is).
  - **PDF:** a heti és a havi jelentésé, csatolva és a `waypost/reports/<id>.pdf` alá tárolva (privát, a `GET /reports` linkje erre mutat). A `dompdf/dompdf` közvetlenül, saját beállításokkal (nincs távoli és helyi fájl, PHP, JavaScript; DejaVu Sans, amelyben az ő és az ű is megvan), nem a `barryvdh/laravel-dompdf`-en át: annak a beállításait a gazda `config/dompdf.php`-je írná felül, és a Testbench sem tölti be a providerét. A fotók 480 px-es JPEG bélyegképként, data URI-ként ágyazódnak be (GD, új követelmény: `ext-gd`), így a PDF a fájlok törlése után is bizonyíték, és nem nő megabájtokkal. A 4096 × 3072 képpontnál (`Thumbnails::MAX_PIXELS`) nagyobb fotónak nincs bélyegképe (a fejlécéből, kibontás előtt): a GD a fájlmérettől függetlenül képpontonként 4–5 bájtra bontja ki, így egy kis, de 20 000 × 20 000-es fejlécű JPEG minden próbálkozásnál kifogyasztaná a job memóriáját (2026-11-05, WP-BE-004). Az EXIF-tájolást nem kezeljük (a PHP-képben nincs `exif`): az app a fotót az `expo-image-manipulator`-ral újrakódolja (`src/photo/photo.ts`), EXIF nélkül, a képpontok már a megjelenítés szerinti tájolásban vannak.
  - **Sablonok:** `waypost::` névtérben, `vendor:publish --tag=waypost-views`-szel átírhatók.
- **Indok:** A specifikáció *Jelentések* táblázata szerint; a 15 perces lépés minden időzóna 10:00-ját eléri (a félórás eltolásúakét is), és a `reports` sor egyedi indexe miatt egy kétszer induló job sem küld kétszer.
- **Érintett:** `src/Jobs/SendDueReports.php`, `SendPeriodReport.php`, `src/Reports/ReportPeriod.php`, `Pdf.php`, `Thumbnails.php`, `ReportContent.php`, `resources/views/mail/period-report.blade.php`, `resources/views/pdf/period-report.blade.php`, `src/WaypostServiceProvider.php`, `composer.json`, `scripts/install-smoke.sh`, `tests/Feature/Reports/PeriodReportTest.php`, `tests/Unit/Reports/`, M6-06 – M6-09.

## 2026-11-04 – A „Nincs adat” figyelmeztetés (M6-10)

- **Kérdés:** Mi az „utolsó várt kör”, mikor hallgat a telefon, mi egy menetrendi ablak, és kik kapják?
- **Döntés:**
  - **Futás:** 15 percenként (`SendNoDataAlerts`), azokra a telephelyekre, amelyeknek van nem visszavont telefonja.
  - **Utolsó várt kör:** a tegnapi és a mai (helyi) nap menetrendjéből (`Summarizer::period()`) az a várt kör, amelynek a határideje a legkésőbbi azok közül, amelyek legalább `no_data_alert_after_min` perce lejártak. Ha ehhez van kör a szerveren, nincs figyelmeztetés.
  - **Hallgat a telefon:** a telephely minden nem visszavont, a határidő előtt hozzárendelt telefonjának `last_seen_at`-je régebbi a határidőnél (vagy üres). A határidő után hozzárendelt telefontól még nem várunk adatot; telefon nélküli telephelyre nem megy figyelmeztetés (a jelentésekben a körei elmaradnak).
  - **Egyszer egy ablakra:** az ablak a kör menetrendi kezdőnapja (`RoundRow::startDay`, ugyanaz, mint a jelentésekben: az éjfélen átnyúló éjszaka a kezdőnapjáé). Az új `no_data_alerts` tábla (egyedi: telephely + `window_day`) a küldés előtt foglal; ha a küldés hibát dob, a sor törlődik, és a job újrapróbálja. A sor a figyelmeztetés adatait is megőrzi (kör, határidő, utoljára látva, címzettek, `sent_at`).
  - **Címzettek:** a telephely minden aktív, e-mail-címmel rendelkező supervisora; ez a telephely beállítása, nem előfizetés.
  - **Szöveg:** „Nincs adat – {telephely}” tárgy; a levél megmondja, mióta nem jelentkezett a telefon, melyik kör volt az utolsó várt, a határidejét és az azóta eltelt időt, és mit érdemes megnézni.
- **Indok:** A specifikáció *„Nincs adat” figyelmeztetés* pontja szerint. Az ablak kezdőnapja a jelentésekkel azonos fogalom, és a 15 perces futás egy ablakban többször is eléri a feltételt, ezért kell a tárolt sor.
- **Érintett:** `src/Jobs/SendNoDataAlerts.php`, `src/Mail/NoDataAlertMail.php`, `src/Models/NoDataAlert.php`, `database/migrations/2026_11_04_000017_create_waypost_no_data_alerts_table.php`, `resources/views/mail/no-data-alert.blade.php`, `src/WaypostServiceProvider.php`, `tests/Feature/Reports/NoDataAlertTest.php`, M6-10.

## 2026-11-04 – Az adatmegőrzés (M6-11)

- **Kérdés:** Mit, milyen sorrendben és melyik időpont szerint töröl a napi job, hogy a vetület és a lánc ne maradjon félig?
- **Döntés:**
  - **Futás:** naponta 03:30-kor (az alkalmazás időzónájában), `PruneExpiredData`.
  - **Határ:** telephelyenként `most − Retention::days()` (31 vagy 62 nap). A telephelyhez már nem tartozó telefon adatára a legrövidebb (31 nap).
  - **Mit, mi szerint:**
    - a műszak, ha az `ended_at`-je (lezáratlannál a `started_at`-je) a határ előtt van, mindenével együtt: körei, leolvasásai, eseményei és ezek fotói (fájllal);
    - a körön kívüli leolvasás a `scanned_at` szerint;
    - a fotók a `taken_at` szerint;
    - a láncbejegyzések a `received_at` szerint, de csak akkor, ha már egyetlen megmaradt vetület sem hivatkozik rájuk;
    - a `device_diagnostics` a `received_at` szerint;
    - a `no_data_alerts` a határidő szerint;
    - a tárolt PDF a `reports.period_end` szerint: a fájl törlődik, a sor marad `path = null`-lal, így az archívum mutatja, hogy a jelentés kiment, de már nem tölthető le.
    - a soha meg nem érkezett pont fotója (2026-11-05, WP-BE-015) a feltöltés után 31 nappal. A feltöltés a pont létrehozása előtt jön (offline betanítás), és a pont kérése elmaradhat. Telephely nincs hozzá, ezért a legrövidebb megőrzés. A törölt pont fotója a ponttal együtt marad. A feltöltés jogosultsága a törölt pontnál is a pont telephelye szerint dől el (`Checkpoint::withTrashed()`), így más telephely törölt pontjára senki nem tölthet fel.
  - Egy eseményben hivatkozott leolvasás az eseménnyel együtt törlődik.
  - A törlés 500-as darabokban megy.
  - **Megmarad:** a konfiguráció (pontok és fotóik, útvonalverziók, menetrendek, felhasználók), és a `devices.last_seq` és `last_hash`. A lánc ezekről folytatódik, és a telefon által újraküldött, már törölt bejegyzést a `ChainProcessor` a HMAC-ja alapján ismeri fel.
- **Indok:** A specifikáció *Adatmegőrzés* pontja szerint. A műszak az egység, mert a jelentések műszakonként olvassák az adatot. A láncbejegyzés csak az utolsó hivatkozó vetülettel mehet, különben egy megmaradt kör forrása nem lenne ellenőrizhető.
- **Érintett:** `src/Jobs/PruneExpiredData.php`, `src/WaypostServiceProvider.php`, `src/Http/Controllers/MediaController.php`, `tests/Feature/Reports/PruneExpiredDataTest.php`, M6-11.

## 2026-11-04 – A jelentések átnézése és a PDF memóriaigénye (M6-12)

- **Kérdés:** Hogyan nézhetők át a jelentések valószerű adattal, és elég-e a queue worker szokásos 128M memóriája a havi PDF-hez?
- **Döntés:**
  - **Demóadat:** `ReportDemoSeeder` (`composer seed-reports`, a `composer build` után). 40 éjszaka egy telephelyen, aláírt láncként a `ChainProcessor`-on át: elmaradt és hiányos körök, átadás, kézi leolvasás, időgyanús, túl gyors és pont mellett leolvasott pontok, események fotóval. A seeder csak kérésre fut, a `DatabaseSeeder` nem hívja.
  - **Előnézet:** a workbench `/reports` útvonalai (`shift`, `daily`, `weekly`, `monthly`, `weekly.pdf`, `monthly.pdf`, `no-data`) a mostani adatból renderelnek, küldés és tárolás nélkül.
  - **PDF-memória:** a dompdf a teljes elrendezést a memóriában tartja, egy hosszú táblázatot a soraival négyzetesen növekvő memóriával; 600 sornál ez 120 MB. Ezért:
    - a hosszú listák 25 soros táblázatokban, rögzített oszlopszélességgel (`table-layout: fixed`) kerülnek a PDF-be, így a memória a sorokkal lineárisan nő (kb. 0,1 MB soronként);
    - a heti és havi jelentés legfeljebb 100 jelölt leolvasást sorol fel (`ReportContent::FLAGGED_SCANS_LISTED`), a többit csak megszámolja („… és további N”). A specifikáció a heti és havi jelentésben nem kéri a leolvasásonkénti listát, a napiban viszont minden jelölt leolvasás benne marad;
    - a `Pdf::render()` a renderelés idejére legalább 512M-ra emeli a `memory_limit`-et (`Pdf::MEMORY_LIMIT`; a nagyobbat vagy a korlátlant nem csökkenti), utána visszaállítja.
  - **Fotók a PDF-ben:** lebegtetve (`float: left`), mert a dompdf a több beágyazott képből álló sort egy sorral feljebb rajzolja, a megjegyzés fölé.
  - **Dátum az elmaradt és hiányos köröknél:** a kör esedékességének a napja, mint a jelöléseknél és az eseményeknél (az éjszaka 01:00-s köre a következő napé); a napi táblázat továbbra is a menetrendi ablak kezdőnapja szerint számol.
- **Indok:** Egy rosszul betanított pont egy hónap minden leolvasását megjelöli, és ettől egy 128M-os workeren a havi jelentés nem készülne el. A 25 soros darabolás és a felső korlát együtt a legrosszabb esetet is 100 MB körül tartja.
- **Érintett:** `src/Reports/Pdf.php`, `ReportContent.php`, `resources/views/pdf/period-report.blade.php`, `workbench/database/seeders/ReportDemoSeeder.php`, `workbench/routes/web.php`, `composer.json`, `tests/Feature/ReportDemoSeederTest.php`, `tests/Feature/Reports/PeriodReportTest.php`, M6-12.

## 2026-11-04 – A `waypost/schema` rögzített verziója, CI és a sémaverzió-fejléc

- **Kérdés:** A `waypost-schema` auditja (SCH-001, SCH-002, a döntés a `../waypost-schema/docs/decisions.md`-ben, „CI és a szerződés verziójának rögzítése”) szerint a backend a szomszédos munkapéldányt használja szimlinken, a `composer.json` `options.versions` értékét kézzel kell léptetni, és semmi nem ellenőrzi, hogy a sémakönyvtár tényleg ezen a verzión áll; CI sincs. Hogyan legyen reprodukálható a build, és honnan tudja az app, melyik szerződéssel dolgozik a szerver?
- **Döntés:**
  - A várt tag a `.waypost-schema-version` fájlban van (most `v0.6.0`), mint az appban. A `tests/Unit/SchemaPackageTest.php` elbukik, ha a `Waypost\Schema\Paths::VERSION` nem ez, vagy ha a `composer.json` `require` feltétele (`^<major>.<minor>`) és `options.versions` értéke nem ehhez igazodik. Új sémaverziónál mindhárom egyszerre lép, utána `composer update waypost/schema`.
  - CI (`.github/workflows/ci.yml`) minden `main`-pushra és pull requestre: a csomag és mellé a `tamas-labs/waypost-schema` a rögzített tagen, PHP 8.4 a fejlesztői kép bővítményeivel, MySQL 8.4 szolgáltatás (`waypost_test`), majd `composer install`, `composer test`, `composer analyse`, `composer lint`. A `scripts/install-smoke.sh` (friss Laravel-telepítés) nincs benne, mert Dockerben fut, és a telepítés hálózatfüggő; kézzel futtatandó.
  - A csomag útvonalainak minden válasza (JSON, hiba, fájl, visszajátszott idempotens válasz) `X-Waypost-Schema: <Paths::VERSION>` fejlécet kap (`FormatApiResponse::SCHEMA_VERSION_HEADER`). Az útvonalon kívüli hibák (nincs ilyen végpont) a gazda kezelőjén mennek át, azokon nincs.
- **Indok:** A friss checkout csak akkor zöld, ha a séma a várt tagen áll, és ez most teszt bukással derül ki, nem a terepen. A fejléc a sémadöntés szerint az app `__DEV__`-ellenőrzésének forrása. A `vcs` repository helyett a rögzítő fájl, mert a tesztek az `examples/`-t olvassák, ami a kiadott archívumból hiányozna (SCH-011); a gazda-alkalmazás telepítésének forrása (M7-01) ettől függetlenül nyitott.
- **Érintett:** `.waypost-schema-version`, `composer.json`, `tests/Unit/SchemaPackageTest.php`, `src/Http/Middleware/FormatApiResponse.php`, `tests/Feature/Api/ErrorFormatTest.php`, `.github/workflows/ci.yml`.

## 2026-11-05 – CI PostgreSQL-lel, legalacsonyabb függőségekkel és telepítési próbával; index minden idegen kulcson (WP-BE-006, WP-BE-007)

- **Kérdés:** Az audit szerint a PostgreSQL-ágat, a `composer.json` legalacsonyabb megengedett függőségeit és a gazda-alkalmazásba telepítést semmi nem ellenőrzi automatikusan, és PostgreSQL-en az idegen kulcsoknak nincs indexük (az InnoDB magától létrehozza, a PostgreSQL és az SQLite nem). Mit futtasson a CI, és hogyan kapjon minden idegen kulcs indexet?
- **Döntés:**
  - **CI** (`.github/workflows/ci.yml`): a tesztek mátrixban futnak: MySQL 8.4 és PostgreSQL 16 a szokásos függőségekkel, valamint MySQL a `composer update --prefer-lowest --prefer-stable` szerinti legalacsonyabb függőségekkel (`composer.lock` nincs, a gazda azt kapja, ami a feltételekbe belefér). A `composer analyse` és a `composer lint` egyszer fut, MySQL-en, a szokásos függőségekkel. Egy külön job a `scripts/install-smoke.sh`-t futtatja a runneren (PHP 8.4, SQLite, hálózat van), Docker nélkül. A Windows-, a több PHP- és a Laravel 12-mátrix (a skeletoné) nem kell: a csomag `php: ^8.4` és `laravel/framework: ^13.0`, és Linuxon fut.
  - A `scripts/install-smoke.sh` a `waypost/schema` verzióját a `.waypost-schema-version`-ből veszi (eddig beégetett `0.5.0` volt, ami a `^0.6` feltétel mellett már nem települt), és a csomag minden `_waypost_` migrációját számolja, nem csak a `_create_waypost_` nevűeket.
  - **Indexek:** új migráció (`2026_11_05_000018_add_waypost_foreign_key_indexes.php`) minden olyan idegen kulcs oszlopra, amelynek még nincs indexe: 23 oszlop, köztük a jelentés és az adatmegőrzés illesztései (`rounds.shift_id`, `scans.round_id`, a `chain_entry_id` oszlopok, `media.device_id`). MySQL-en és MariaDB-n nem csinál semmit, mert ott minden idegen kulcsnak van indexe, és a meglévő mellé tett második index lefelé migráláskor nem lenne eldobható. A `MigrationsTest` minden adatbázison ellenőrzi, hogy minden idegen kulcs oszlopaival kezdődik egy index. Ez az első sémaváltozás a 0.2.0 után, ezért új migráció, nem a meglévők módosítása (WP-BE-037).
- **Indok:** A PostgreSQL-specifikus hibák (savepointok, a hiányzó indexek) és a függőségek alsó határa eddig csak a fejlesztő kézi futtatásán múlt. A telepítési próba az egyetlen, ami a gazda valódi exception handlerét és HTTP-kernelét kipróbálja. Az általános indexteszt egy később felvett idegen kulcsot is elkap.
- **Érintett:** `.github/workflows/ci.yml`, `scripts/install-smoke.sh`, `database/migrations/2026_11_05_000018_add_waypost_foreign_key_indexes.php`, `tests/Feature/MigrationsTest.php`, `CLAUDE.md`.
