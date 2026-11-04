# waypost/laravel-waypost

A Waypost őrjárat-rendszer backendje Laravel 13 csomagként. Egy meglévő Laravel alkalmazásba telepíthető, és a saját tábláival, útvonalaival és parancsaival adja a Waypost API-t: belépés, eszköz-hozzárendelés, konfigurációs CRUD és szinkronizáció (pull, push, média), valamint az e-mailes jelentéseket.

A csomag a gazda-alkalmazás tábláihoz nem nyúl: minden táblája `waypost_` előtagú, a tokenjei is saját táblában vannak.

**Állapot:** az M2 mérföldkő (alap backend), az M4 backendrésze (push, média, láncellenőrzés, vetületek) és az M6 (jelentések, „Nincs adat” figyelmeztetés, adatmegőrzés) kész, lásd a [feladatlistát](../waypost/docs/tasks.md).

## Követelmények

- PHP 8.4, `gd` bővítménnyel (a PDF-jelentések bélyegképeihez)
- Laravel 13
- MySQL 8.4 vagy PostgreSQL 16; a telepítési próba SQLite-on is lefut
- a `waypost/schema` csomag (a közös JSON Schema szerződés, a `waypost-schema` repo)

## Telepítés

A csomag és a `waypost/schema` nincs a Packagisten, ezért a gazda-alkalmazás `composer.json`-jába repositoryként kell felvenni őket (`path` vagy `vcs`), például:

```sh
composer config repositories.waypost-schema '{"type": "path", "url": "../waypost-schema"}'
composer config repositories.laravel-waypost '{"type": "path", "url": "../laravel-waypost"}'
composer require waypost/laravel-waypost
```

A service providert a Laravel magától felveszi. Utána:

```sh
php artisan waypost:install --migrate
php artisan waypost:create-supervisor --name="Kiss Anna" --email=anna@example.com
```

- A `waypost:install` publikálja a `config/waypost.php`-t és a migrációkat, a `--migrate` kapcsolóval le is futtatja őket.
  - Frissítés után újra futtatható: csak az új migrációkat publikálja, a meglévő konfigurációt pedig a `--force` nélkül nem írja felül.
  - A `vendor:publish --tag=waypost-migrations` helyett ezt használd: az minden futáskor újra publikálná a migrációkat.
- A `waypost:create-supervisor` létrehozza az első supervisort. A jelszót bekéri, ha nincs megadva.

A csomag négy feladatot ütemez:

- óránként törli a lejárt idempotenciakulcsokat;
- 15 percenként elküldi az esedékes napi, heti és havi jelentéseket (telephelyenként a helyi idő szerint 10:00-kor; ami kimaradt, azt legfeljebb 12 órán belül pótolja);
- 15 percenként elküldi a „Nincs adat” figyelmeztetéseket (a telephely supervisorainak, menetrendi ablakonként egyszer);
- naponta 03:30-kor törli a megőrzési időnél (31, havi jelentésnél 62 nap) régebbi adatokat, fotókat és tárolt PDF-eket.

Ehhez a gazda-alkalmazásban futnia kell az ütemezőnek (`php artisan schedule:run` percenként, vagy `schedule:work`), és egy queue workernek a csomag queue-ján (`php artisan queue:work`). A műszakjelentés a műszak zárásának feldolgozásakor indul, és legfeljebb 30 percig vár a hiányzó fotókra; a `sync` queue nem tud várni, ott azonnal megy ki.

A jelentések e-mailek, a gazda-alkalmazás levelezőjén (`MAIL_MAILER`) mennek ki; a fotók linkje abszolút, ezért az `APP_URL`-nek a valódi címet kell mutatnia. Az e-mail- és a PDF-sablonok a `vendor:publish --tag=waypost-views` paranccsal publikálhatók és átírhatók. A heti és a havi PDF készítésének idejére a csomag a PHP `memory_limit`-jét legalább 512M-ra emeli (a dompdf a teljes oldalképet a memóriában tartja), utána visszaállítja.

## Konfiguráció

A `config/waypost.php` beállításai környezeti változóval is megadhatók:

| Változó | Alapérték | Jelentés |
|---|---|---|
| `WAYPOST_ROUTE_PREFIX` | `api/waypost/v1` | az API útvonalainak előtagja |
| `WAYPOST_RATE_LIMIT` | `240` | kérés percenként, tokentulajdonosonként |
| `WAYPOST_LOGIN_RATE_LIMIT` | `5` | belépési kísérlet percenként, e-mail-címenként és IP-címenként |
| `WAYPOST_TABLE_PREFIX` | `waypost_` | a csomag tábláinak előtagja; csak az első migráció előtt változtasd |
| `WAYPOST_DISK` | `local` | a fotók és a PDF-jelentések tárhelye; privát disk legyen |
| `WAYPOST_REPORT_URL_TTL_MIN` | `60` | a jelentésarchívum (`GET /reports`) letöltési linkjének élettartama percben; a fotók linkje az adatmegőrzés végéig él |
| `WAYPOST_QUEUE_CONNECTION`, `WAYPOST_QUEUE` | az alkalmazásé, `default` | a csomag queue-ja |
| `WAYPOST_MAIL_FROM_ADDRESS`, `WAYPOST_MAIL_FROM_NAME` | az alkalmazás `mail.from`-ja, `Waypost` | a jelentések és figyelmeztetések feladója |

Az útvonalak a `waypost.route.middleware` middleware-jeivel futnak, ez alapból az `api` csoport.

## Az API röviden

A kérések és válaszok pontos alakját a `waypost-schema` JSON Schemái rögzítik, a viselkedést a [fejlesztői specifikáció](../waypost/docs/Waypost%20–%20fejlesztői%20specifikáció%20(1.%20lépcső).md) *API-szerződés* szakasza. A legfontosabb szabályok:

- Minden JSON-válasz tartalmaz egy `server_time` mezőt. A hibák egységes formában jönnek: `{"server_time": …, "error": {"code", "message", "details"}}`.
- **Hívók:**
  - supervisor: a `POST /auth/login` tokenjével;
  - telephelyi telefon: a `POST /devices/enroll` tokenjével;
  - supervisor a telephelyi telefonon: telefon tokennel és `X-Acting-User` fejléccel, a PIN-nel belépett supervisor azonosítójával.
- **Tokenek:** a token a `Authorization: Bearer …` fejlécben megy. A visszavont telefon tokenje `401 device_revoked` választ kap.
- **Író kérések:** minden író kéréshez `Idempotency-Key` fejléc kell (UUID). Ugyanazzal a kulccsal megismételt kérésre a szerver 24 órán át ugyanazt a választ adja vissza, és nem hajtja végre újra.

| Végpont | Hívó |
|---|---|
| `POST /auth/login` | token nélkül |
| `POST /auth/logout` | supervisor |
| `POST /devices/enroll` | supervisor (a saját tokenjével) |
| `POST /sync/push` | telefon |
| `POST /sync/push-final` | visszavont telefon, a lezárásig |
| `POST /devices/diagnostics` | telefon, a visszavont is a lezárásig |
| `PUT /media/{id}` | a bejegyzés fotója: a telefon (visszavontan a lezárásig); a pont fotója: supervisor |
| `GET /sync/pull` | supervisor vagy telefon |
| `sites`, `sites/{id}/users`, `users`, `checkpoints`, `routes`, `routes/{id}/versions`, `schedules`, `report-subscriptions` (CRUD) | supervisor, vagy telefon `X-Acting-User`-rel |

A pull kurzorral lapoz, oldalanként legfeljebb 500 rekorddal. A `has_more` jelzi, hogy van-e még oldal. Ha a válaszban `reset: true` áll, a kliens a lap alkalmazása előtt törli a helyi konfigurációs rekordjait.

## Fejlesztés

Helyben nem kell PHP és Composer: minden a Docker Compose `php` szolgáltatásában fut. A `waypost-schema` repónak a szomszédos könyvtárban kell lennie, a `.waypost-schema-version` fájlban megadott tagen (`git -C ../waypost-schema checkout "$(cat .waypost-schema-version)"`); ha nem azon áll, a `tests/Unit/SchemaPackageTest.php` elbukik.

```sh
docker compose run --rm php composer install
docker compose run --rm php composer test      # Pest, MySQL 8.4
docker compose run --rm php composer analyse   # PHPStan (Larastan, 8-as szint)
docker compose run --rm php composer lint      # Pint
docker compose run --rm --service-ports php composer serve   # demóadatokkal: http://localhost:8088
```

A jelentések átnézéséhez a futó `serve` mellett, egy másik terminálban: `docker compose run --rm php composer seed-reports` (40 éjszaka adata egy telephelyen, kb. 15 s). Utána a `http://localhost:8088/reports` oldalról nyílnak az e-mailek és a PDF-ek, ahogy most kimennének (küldés nélkül).

A teljes parancslistát (PostgreSQL-futtatás, telepítési próba, workbench) és a kódolási szabályokat a [CLAUDE.md](CLAUDE.md) tartalmazza. A specifikációban nem rögzített döntések a [docs/decisions.md](docs/decisions.md)-ben vannak.
