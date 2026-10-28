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
  - Ismert korlát: egy meglévő, PIN-nel rendelkező felhasználó új telephelyhez rendelésekor a szerver nem tudja ellenőrizni az ütközést, mert csak a hasht ismeri. **Az M2-16-ban dönteni kell:** a hozzárendeléskor PIN-t kér (vagy generál), vagy elfogadja a kockázatot. Javaslat: ha a felhasználónak van PIN-je, a hozzárendelés új PIN-t generál, és egyszer visszaadja. *(Eldöntve: a hozzárendelés mindig új PIN-t ad, lásd „Telephelyek, felhasználók és a PIN a hozzárendeléskor”.)*
- **Indok:** Az app ugyanezt a PBKDF2-t számolja offline, ezért mindkét oldal ugyanazokkal a vektorokkal tesztel; ha bármelyik paraméter vagy kódolás eltér, a teszt bukik, nem a belépés a terepen. A véletlenforrás cserélhető, így a tesztben az ütközés és a kimerülés is előidézhető.
- **Érintett:** `src/Pin/`, `database/factories/UserFactory.php`, M2-09, M2-16, M2-17, M3-15, Q11.

## 2026-10-28 – Az API-keret: útvonalak, hibaformátum, `server_time`, rate limit

- **Kérdés:** Hogyan kerül a csomag API-ja a gazda-alkalmazásba úgy, hogy minden válasz a sémák szerinti legyen, a gazda saját hibakezelése pedig ne változzon?
- **Döntés:**
  - Útvonalak: `routes/api.php`, a `WaypostServiceProvider::apiRoutes()` tölti be (`waypost.route.prefix` alatt, `waypost.` névelőtaggal, a `waypost.route.middleware`-rel), ha a gazda nem cache-eli az útvonalakat. A tesztek ugyanezzel regisztrálnak próba-útvonalakat.
  - Hibaformátum: a `Waypost\Http\ErrorRenderer` csak az előtag alatti kérések hibáit formázza, két helyen. A csomag útvonalain a legkülső middleware (`FormatApiResponse`) a gazda handlere által renderelt választ cseréli le (a Laravel a kivételt a válaszon hagyja), így a gazda handlerétől függetlenül működik. Ami útvonalon kívül történik (nincs ilyen végpont, rossz kérésmód, karbantartás, a gazda globális middleware-je), azt a gazda handlerének egy render-callbackje formázza, ha az a Laravel szokásos `Handler`-e. Leképezés: `AuthenticationException` → `unauthenticated`; a policy-elutasítás → `forbidden`, vagy `not_found`, ha a policy így tagad; hiányzó modell → `not_found`; `ValidationException` → `validation_failed` a Laravel `errors()` alakjával mint `details`; `UniqueConstraintViolationException` → `conflict`; throttling → `rate_limited` a `Retry-After` fejléccel; minden más 4xx → `bad_request`, 5xx → `server_error` (a HTTP-státusz marad, a két új kód a `waypost-schema` döntése szerint); bármi más → `server_error` (500). A saját hibák `Waypost\Http\Exceptions\ApiException`-ök (kód, magyar üzenet, `details`); HTTP-kivételek, ezért a gazda nem naplózza őket. Az üzenet mindig a kód magyar alapszövege vagy az `ApiException`-é, a kivétel szövege soha (debug módban sem).
  - `server_time`: ugyanez a `FormatApiResponse` minden JSON-objektum válasz elejére beteszi a mostani időt, a már ott lévőt felülírva; a renderelő a csoporton kívüli hibákba maga teszi bele. A `[]`-t visszaadó controller válasza `{ "server_time": … }`.
  - Rate limit: `throttle` két névvel. `waypost`: percenként `waypost.rate_limits.api` (240) kérés a token gazdájára (felhasználó vagy eszköz), token nélkül az IP-re; az `Authenticate` után fut. `waypost-login`: percenként `waypost.rate_limits.login` (5) belépési kísérlet e-mail-cím és IP párosra.
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
- **Indok:** A Laravel `RequestGuard`-ja a guardpéldányon tárolja a felhasználót, ami hosszú életű folyamatban (és a tesztekben) a következő kérésre is átragadna. A telephelyi korlátot a `Caller` hordozza, így minden policy és lekérdezés ugyanabból a listából dolgozik.
- **Érintett:** `src/Auth/TokenGuard.php`, `src/Auth/Caller.php`, `src/Http/Middleware/Authenticate.php`, `src/Models/PersonalAccessToken.php`, M2-13, M2-22, M4 (push-final), M5.

## 2026-10-28 – Belépés és kijelentkezés (M2-14)

- **Kérdés:** Mit ellenőriz a `POST /auth/login`, és mit ad vissza hibánál?
- **Döntés:** A beírt címet kisbetűsítve keresi (a `User` kisbetűsen tárolja). Csak supervisor léphet be jelszóval; ismeretlen cím, őr és hibás jelszó ugyanazt kapja: `unauthenticated`, „Hibás e-mail-cím vagy jelszó.”. Inaktív supervisor helyes jelszóval: `unauthenticated`, „Ez a fiók inaktív, nem lehet vele belépni.”. A token `supervisor` képességű, nem jár le, a neve a `device_name`. A `POST /auth/logout` a kérés tokenjét törli, a supervisor többi tokenje marad; eszköztokennel `forbidden`.
- **Indok:** A séma (`api/auth-login.response`) szerint a hibás belépés `401 unauthenticated`. Az inaktív fiók külön üzenete csak helyes jelszó után jelenik meg, így nem árulja el, hogy a cím létezik.
- **Érintett:** `src/Http/Controllers/AuthController.php`, `routes/api.php`, M2-14.

## 2026-10-28 – Policy-k (M2-15)

- **Kérdés:** Hogyan érvényesül, hogy a supervisor csak a hozzárendelt telephelyeit és azok entitásait látja, és mit kap egy idegen telephelyre?
- **Döntés:**
  - A csomagnak saját gate-je van (`Waypost\Auth\Gate`), a policy-k a `Caller`-t kapják, nem felhasználót. A policy-k a `Waypost\Auth\Policies` névtérben vannak, mert a gazda gate-je a `Waypost\Policies\…Policy` nevet a modellek mellett magától megtalálná.
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
  - **Másik felhasználót csak az módosíthat** (név, e-mail, jelszó, aktív, PIN, hozzárendelés új telephelyhez) **vagy törölhet, aki a felhasználó minden telephelyét kezeli** (`UserPolicy::update` / `delete`, különben 403 „A felhasználó olyan telephelyhez is tartozik, amelyet nem kezelsz.”); látni elég egy közös telephely. Különben egy közös telephely supervisora átírhatná egy kolléga e-mail-címét és jelszavát (vagy megtudná az új PIN-jét), és belépne a kolléga többi telephelyére. Az automatikus biztonsági ellenőrzés jelezte; a hozzárendelésnél is így van, mert az új PIN a hívóhoz kerül. Saját adatait (e-mail, jelszó, PIN) mindenki módosíthatja, de saját magát senki nem inaktiválhatja és nem törölheti (403).
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
