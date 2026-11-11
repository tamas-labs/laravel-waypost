# waypost/laravel-waypost

A Waypost őrjárat-rendszer backendje Laravel 13 csomagként. Egy meglévő Laravel alkalmazásba telepíthető, és a saját tábláival, útvonalaival és parancsaival adja a Waypost API-t: belépés, eszköz-hozzárendelés, konfigurációs CRUD és szinkronizáció (pull, push, média), valamint az e-mailes jelentéseket.

A csomag a gazda-alkalmazás tábláihoz nem nyúl: minden táblája `waypost_` előtagú, a tokenjei is saját táblában vannak.

**Állapot:** az M2 mérföldkő (alap backend), az M4 backendrésze (push, média, láncellenőrzés, vetületek) és az M6 (jelentések, „Nincs adat” figyelmeztetés, adatmegőrzés) kész, lásd a [feladatlistát](../waypost/docs/tasks.md).

## Követelmények

- PHP 8.4, `gd` (a PDF-jelentések bélyegképeihez) és `fileinfo` bővítménnyel (a feltöltött fotó típusának ellenőrzéséhez)
- Laravel 13
- MySQL 8.4 (LTS) vagy PostgreSQL 16; a CI mindkettőn tesztel. A MySQL 8.0 támogatása 2026 áprilisában véget ért, azon a csomagot nem teszteljük. A telepítési próba SQLite-on is lefut.
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
| `WAYPOST_LOGIN_RATE_LIMIT` | `5` | belépési kísérlet percenként, ugyanarra az e-mail-címre ugyanarról az IP-címről |
| `WAYPOST_LOGIN_IP_RATE_LIMIT` | `30` | belépési kísérlet percenként, IP-címenként, bármelyik e-mail-címre |
| `WAYPOST_PIN_RATE_LIMIT` | `20` | PIN-t adó kérés (`POST /users`, `PUT /users/{id}/pin`, `POST /sites/{id}/users`) percenként, tokentulajdonosonként; mindegyik a telephelyek minden felhasználójára újraszámolja a PIN-t |
| `WAYPOST_LOG_CHANNEL` | a gazda alapértelmezettje | a csomag naplócsatornája (elutasított láncbejegyzések, a hivatkozásukra váró bejegyzések, a félbemaradt vagy még futó `Idempotency-Key`, az újraküldött jelentés, az éjszakai takarítás) |
| `WAYPOST_GUARD` | `waypost` | a csomag auth guardjának neve; ha a gazdának már van ilyen nevű guardja, az alkalmazás hibával áll le, és itt más nevet kell adni |
| `WAYPOST_TABLE_PREFIX` | `waypost_` | a csomag tábláinak előtagja; csak az első migráció előtt változtasd |
| `WAYPOST_DISK` | `local` | a fotók és a PDF-jelentések tárhelye; privát disk legyen |
| `WAYPOST_PULL_SETTLE_MS` | `5000` | ennyi ezredmásodpercig nem adja ki a pull a frissen változott rekordot, mert az író tranzakció még futhat; legyen több a leghosszabb tranzakciónál és az alkalmazásszerverek órájának eltérésénél |
| `WAYPOST_REPORT_URL_TTL_MIN` | `60` | a jelentésarchívum (`GET /reports`) letöltési linkjének élettartama percben; a fotók linkje az adatmegőrzés végéig él |
| `WAYPOST_QUEUE_CONNECTION`, `WAYPOST_QUEUE` | az alkalmazásé, `default` | a csomag queue-ja |
| `WAYPOST_MAIL_FROM_ADDRESS`, `WAYPOST_MAIL_FROM_NAME` | az alkalmazás `mail.from`-ja, `Waypost` | a jelentések és figyelmeztetések feladója |

Az útvonalak a `waypost.route.middleware` middleware-jeivel futnak, ez alapból az `api` csoport.

## Üzemeltetés

- **Az `APP_KEY` nem cserélhető csak úgy.** A telefonok láncának titka (`devices.secret`) és a tárolt idempotens válaszok az alkalmazás kulcsával titkosítva vannak, és a fotók és a PDF-ek aláírt linkje is ezzel készül. Új kulcs mellett a szerver nem tudja visszafejteni a titkokat, és a telefonok feltöltése nem ellenőrizhető. Kulcscserénél a régi kulcsot az `APP_PREVIOUS_KEYS`-be kell tenni, és ott is kell hagyni, amíg minden telefont újra nem rendeltek hozzá a telephelyéhez. Ha a régi kulcs mégis elveszett, a telefon feltöltése `500 server_error`-t kap („A szerver nem tudja ellenőrizni ennek a telefonnak a feltöltéseit…”), a gazda naplójában `UnreadableDeviceSecret` áll az eszköz azonosítójával; a telefont újra hozzá kell rendelni.
- **Mentés:** az adatbázist és a `WAYPOST_DISK` lemezt (fotók, PDF-ek) együtt, ugyanarra az időpontra, és az `APP_KEY`-t is. Egy régebbi mentés visszaállítása után a szerverről hiányoznak azok a bejegyzések, amelyeket a telefonok már nyugtázottnak tekintenek. A lánc ilyenkor `chain_gap`-et jelez, és a hiányzó rész nem pótolható.
- **Frissítés:** `composer update waypost/laravel-waypost`, majd `php artisan waypost:install --migrate`, amely csak az új migrációkat publikálja és futtatja, végül `php artisan queue:restart`. A változásokat és a frissítés teendőit a [CHANGELOG](CHANGELOG.md) sorolja fel.
- **Queue worker:** a heti és a havi PDF elkészítése másodpercekig tart, és a csomag a PHP `memory_limit`-jét idejére legalább 512M-ra emeli. Ezt a PHP-nak engednie kell (`ini_set`). A worker `--timeout`-ja legyen legalább 300 másodperc, a queue kapcsolat `retry_after`-je ennél nagyobb, és mindkettő 15 percnél kisebb: ennyi idő után számít egy félbemaradt jelentés elhagyottnak. A worker `--memory` korlátja (alapból 128 MB) egy PDF után újraindítja a workert, ezért folyamatfelügyelő (Supervisor, systemd) alatt fusson.
- **Pontos szerveróra** (NTP): a telefonok a válaszok `server_time` mezőjéhez igazítják a bejegyzéseik idejét, a pull pedig az `updated_at` szerint lapoz. Több alkalmazásszervernél az órák eltérése és a leghosszabb adatbázis-tranzakció maradjon a `WAYPOST_PULL_SETTLE_MS` (5 másodperc) alatt, különben egy konfigurációs változás végleg kimaradhat a telefon pullja közül.
- **Több alkalmazásszerver:** az ütemezett jobok csak egy szerveren indulnak (`onOneServer`), és egy még futó jobot a következő indítás nem kettőz meg (`ShouldBeUnique`). Mindkettő a gazda alapértelmezett cache-ében zárol, ezért ott közös, zárolást támogató cache kell (`redis`, `database`, `memcached`). A `file` és az `array` cache csak egy szerveren megfelelő.
- **Morph map:** a csomag a saját modelljeit `waypost.` előtagú aliasokkal (`waypost.user`, `waypost.scan` …) teszi a gazda morph mapjébe (`Relation::morphMap()`), a polimorf oszlopok ezeket tárolják. A gazda `Relation::enforceMorphMap()`-je így a csomaggal is működik; a `waypost.` előtagú aliasokat a gazda ne használja a saját modelljeihez.
- **Élesben csak https.** A fotók és a PDF-ek aláírt linkje belépés nélkül nyílik meg, ezért `production` környezetben a csomag mindig `https://` linket ad ki, akkor is, ha az `APP_URL` vagy a kérés `http`. A gazda saját URL-jei nem változnak. Az API-t élesben https-en kell kiszolgálni: egy `http`-n elérhető gazda linkjei nem nyílnak meg. Ha a TLS-t egy proxy bontja, és a gazda nem bízik meg benne (`TrustProxies`), a kérés `http`-nek látszik, és a link `403 forbidden`-t ad.
- **Proxy mögött** (terheléselosztó, CDN) a gazda `TrustProxies` middleware-jében meg kell bízni a proxyban, különben a belépési korlát IP-címe a proxyé: egyetlen felhasználó hibás próbálkozásai is mindenki belépését megfoghatják.
- **A telephelyi telefon tokenje a telephely kulcsa.** A PIN-t a telefon ellenőrzi, offline. Az `X-Acting-User` fejlécben kapott supervisorról a szerver csak azt nézi meg, hogy aktív-e, és hozzá van-e rendelve a telephelyhez. Aki a telefon tokenjét megszerzi, a telephelyen bármelyik supervisor nevében eljárhat: felhasználót (jelszavas supervisort is), pontot, útvonalat és menetrendet hozhat létre, módosíthat vagy törölhet, de csak ezen a telephelyen. Az elveszett vagy ellopott telefont ezért azonnal le kell váltani: egy új telefon hozzárendelése (`POST /devices/enroll`) a telephely régi telefonját visszavonja.
- **A jelentés fotólinkjei belépés nélkül nyílnak meg**, a megőrzés végéig (31 vagy 62 nap). Egy továbbított e-mail fotóit így a címzettje is megnézheti; egy kiszivárgott levél linkjei egyenként nem vonhatók vissza. A jelentések címzettjei (`report_subscriptions`) ezért csak a telephely megbízható supervisorai legyenek.
- **Az elveszett telefonon a PIN-ek is kiderülhetnek.** A telefon az offline belépéshez a telephely minden felhasználójának PIN-ellenőrzőjét tárolja (`pin_hash`, `pin_salt`, PBKDF2, 100 000 iteráció). A hatjegyű PIN-nek egymillió lehetséges értéke van, ezért aki a telefon adatbázisához hozzáfér, egy videókártyával percek alatt megtalálja mindegyiket, a supervisorokét is. Elveszett vagy ellopott telefon után ezért a hozzárendelés cseréje mellett a telephely összes PIN-jét is újra kell osztani (`PUT /users/{id}/pin`), azokét is, akik más telephelyen is dolgoznak.

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
docker compose run --rm php composer test:types   # típuslefedettség, 100%
docker compose run --rm php composer lint      # Pint
docker compose run --rm --service-ports php composer serve   # demóadatokkal: http://localhost:8088
```

A jelentések átnézéséhez a futó `serve` mellett, egy másik terminálban: `docker compose run --rm php composer seed-reports` (40 éjszaka adata egy telephelyen, kb. 15 s). Utána a `http://localhost:8088/reports` oldalról nyílnak az e-mailek és a PDF-ek, ahogy most kimennének (küldés nélkül).

A teljes parancslistát (PostgreSQL-futtatás, telepítési próba, workbench) és a kódolási szabályokat a [CLAUDE.md](CLAUDE.md) tartalmazza. A specifikációban nem rögzített döntések a [docs/decisions.md](docs/decisions.md)-ben vannak.
