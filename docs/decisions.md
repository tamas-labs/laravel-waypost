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
- **Döntés:** Egy közös `testbench.yaml`: környezeti változók (MySQL), a `WaypostServiceProvider` a `providers` listában, a build lépései `db-wipe` és `migrate-fresh` (seederrel). A `tests/TestCase` a `WithWorkbench` traittel ugyanezt az alkalmazást használja; a teszt-adatbázist és a teszt-`APP_KEY`-t a `phpunit.xml.dist` adja. A workbench `/` útvonala egy JSON-állapotoldal (API-előtag, adatbázis).
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
