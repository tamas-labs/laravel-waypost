# laravel-waypost – auditjelentés

- **Dátum:** 2026-11-04
- **Vizsgált állapot:** `waypost/laravel-waypost`, `main` ág, `4fc8a6c` (Release 0.2.0; tagek: v0.1.0, v0.1.1, v0.2.0; 18 commit)
- **Szempontrendszer:** [docs/audit.20261104.md](docs/audit.20261104.md)
- **Kapcsolódó dokumentumok:** [AUDIT-ISSUES.md](AUDIT-ISSUES.md) (a megállapítások részletei), [AUDIT-COMPLIANCE.md](AUDIT-COMPLIANCE.md) (a megfelelőségi mátrix)

## Végső minősítés: **READY WITH CONDITIONS** (feltételekkel kész)

A csomag minősége kiemelkedő:
- teljes, végiggondolt döntésnapló;
- 606 zöld teszt MySQL-en és PostgreSQL-en;
- hibamentes PHPStan 8;
- helyes lánc- és időkezelés.

A próbaidőszakra (M7) akkor alkalmas, ha előtte teljesül:

1. **WP-BE-001 (HIGH):** az `X-Acting-User`-rel eljáró supervisor ne írhassa át a saját e-mail-címét, jelszavát és PIN-jét. Ma ezzel egy eszköztoken a supervisor összes telephelyének teljes hozzáférésévé alakítható.
2. **WP-BE-003 (MEDIUM):** az idempotenciakulcs „még fut” állapota ne tarthasson 24 órán át. Ma egy félbeszakadt kérés megállíthatja a telefon outboxát.
3. **WP-BE-004 (MEDIUM):** a foglalt, de el nem küldött jelentés ne vesszen el csendben, és a fotók méretét a szerver korlátozza. Az M7 feltétele: „minden jelentés megérkezik”.
4. **WP-BE-002 és WP-BE-005 (MEDIUM):** telephelyi ellenőrzés a vetület hivatkozásaira; a tokenek visszavonása jelszócserekor.
5. **WP-BE-028 (LOW):** az M7-01 telepítési leírása tartalmazza az üzemeltetési előfeltételeket (`APP_KEY`, NTP, `TrustProxies`, worker-memória).

PostgreSQL-telepítés előtt a WP-BE-006 (indexek) is feltétel. **NOT READY** nem indokolt: nincs CRITICAL hiba, a fő folyamatok (lánc, szinkron, jelentés) helyesek és jól teszteltek. **READY** sem: a HIGH hiba a specifikáció egyik kifejezett biztonsági szabályát sérti.

---

## 1. Cél

Szigorú, bizonyítékokon alapuló értékelés arról, hogy a `laravel-waypost` csomag megfelel-e a fejlesztői specifikációnak, a `laravel/package-skeleton` gyakorlatának és a szokásos minőségi, biztonsági és teljesítményelvárásoknak, és használható-e az M7 próbaidőszakban.

## 2. Hatókör

- **A repó minden fájlja:**
  - `src/` (121 PHP-fájl, kb. 9 900 sor);
  - `config/`, `database/` (17 migráció, factory-k), `routes/`;
  - `resources/views/`;
  - `tests/` (56 fájl, kb. 8 150 sor);
  - `workbench/`, `scripts/`, `docker/`, `compose.yaml`;
  - `composer.json`, `phpstan.neon.dist`, `pint.json`, `phpunit.xml.dist`, `testbench.yaml`;
  - `README.md`, `CHANGELOG.md`, `CLAUDE.md`, `docs/decisions.md`.
- **A szerződés:** `../waypost-schema` (v0.5.0), csak az érintett sémák és tesztvektorok.
- **Nincs a hatókörben:**
  - az app (`../waypost`) kódja, kivéve két hivatkozott helyet (outbox, push-kulcs) a szerződés megértéséhez;
  - a gazda-alkalmazás;
  - a fizikai eszközök.

## 3. Források

- `../waypost/docs/Waypost – fejlesztői specifikáció (1. lépcső).md` (a továbbiakban: *Spec*);
- `../waypost/docs/tasks.md` (M2, M4–M7, Q1–Q15);
- `docs/decisions.md` (a csomag döntései);
- `../waypost-schema/schemas/**`, `chain/test-vectors.json`, `schedule/test-vectors.json`, `pin/test-vectors.json`;
- `../waypost/docs/audit.20261104-jelentes.md` (az app korábbi auditja, a szerződésbeli összefüggésekhez);
- `laravel/package-skeleton` (élő GitHub-repó: `composer.json`, könyvtárszerkezet, `.github/workflows/tests.yml`; csak olvasás).

## 4. Módszer

1. A szempontrendszer és a Spec backendet érintő szakaszainak feldolgozása; a követelmények listája (AUDIT-COMPLIANCE.md).
2. A teljes forrás olvasása rétegenként: útvonalak és middleware-ek → azonosítás és jogosultság → controllerek → lánc (`ChainProcessor`, `Projector`, `CanonicalJson`, `EntryHash`, `ServerFlags`, `TrustedTime`) → pull → média → jelentések és jobok → migrációk és modellek → telepítés.
3. Célzott ellenőrzés a szempontrendszer kockázati területein:
   - IDOR és telephelyek közötti hozzáférés;
   - idempotencia;
   - lánc;
   - DST;
   - média;
   - OWASP API Top 10.
   Minden gyanút a kódúton végigkövettem; ahol lehetett, a döntésnaplóval és a sémával is összevetettem, hogy dokumentált szándék-e.
4. Automatikus ellenőrzések futtatása a projekt saját Docker-környezetében (7. fejezet).
5. A skeleton összevetése az élő repóval.
6. Besorolás: súlyosság (a következmény és a valószínűség szerint) és bizonyosság. A specifikációból következő, tudatos tervezési kockázat INFO, nem hiba.

## 5. Korlátok

- **Nem futtatott ellenőrzések:**
  - **`scripts/install-smoke.sh`:** hálózat kell hozzá (`laravel/laravel` letöltése), és a szempontrendszer szerint csomagot sem telepíthettem. A `tests/Install` tesztje viszont lefutott és zöld.
  - **`composer audit`:** a függőséglista elküldése egy külső szolgáltatásnak; a sebezhetőségi adatbázis lekérdezése nélkül a függőségek ismert CVE-it nem ellenőriztem.
  - **Eszközös és app-oldali próba:** az M4 „repülőgép-üzemmód”, a `device-test.md` és az M7.
  - **Terheléses és párhuzamossági próba:** a versenyhelyzeteket (WP-BE-003, -010, -019) kódolvasással azonosítottam, nem reprodukáltam.
  - **A PDF-ek kézi, vizuális átnézése:** a `tasks.md` szerint az M6-12-ben megtörtént; nem ismételtem meg.
  - **MySQL 8.0-s próba.**
- **Nem mértem:** a WP-BE-006, -011, -012 és -021 teljesítmény- és időzítési hatását; ezek bizonyossága ezért legfeljebb közepes.
- **Az app** viselkedésére (outbox, push-kulcsok) csak két kódhely és a szerződés alapján hivatkozom.
- **Környezeti esemény a futtatás közben:**
  - Az első MySQL-es tesztfutás megszakadt. Ennek oka nem a csomag volt, hanem az, hogy a PostgreSQL-futás indításakor a `docker compose run` a `mysql` függőséget újra akarta indítani, és a 3306-os portot egy másik projekt konténere (`mysql-db`) foglalta. Ez az én futtatási hibám volt.
  - A futásokat a projekt saját mechanizmusával ismételtem meg: `WAYPOST_MYSQL_PORT=33306`, illetve `--no-deps`.
  - Utána az általam indított `mysql` és `pgsql` konténert leállítottam. A másik projekt konténeréhez nem nyúltam.
  - Forrás, konfiguráció, lock fájl és adatbázis-tartalom nem változott; a tesztek a `waypost_test` adatbázist és a tmpfs-en futó PostgreSQL-t használták.

## 6. Az audit által létrehozott fájlok

Csak a három jelentésfájl (`AUDIT-REPORT.md`, `AUDIT-ISSUES.md`, `AUDIT-COMPLIANCE.md`) a repó gyökerében. Semmilyen forrás, konfiguráció, migráció, teszt vagy dokumentáció nem módosult, és commit sem készült.

## 7. Lefuttatott ellenőrzések és eredményük

| Ellenőrzés | Parancs | Eredmény |
| --- | --- | --- |
| Kódstílus (Pint, `per`) | `docker compose run --rm php composer lint` | **PASS**: 218 fájl, exit 0 |
| Statikus elemzés (Larastan, 8-as szint) | `docker compose run --rm php composer analyse` | **PASS**: „[OK] No errors”, exit 0 |
| Composer-érvényesség | `composer validate` | **PASS** |
| Tesztek, MySQL 8.4 | `WAYPOST_MYSQL_PORT=33306 docker compose run --rm --no-deps php composer test` | **PASS**: 606 teszt, 3714 assertion, 187 s, exit 0 |
| Tesztek, PostgreSQL 16 | `docker compose run --rm --no-deps -e DB_CONNECTION=pgsql -e DB_HOST=pgsql -e DB_PORT=5432 php composer test` | **PASS**: 606 teszt, 3714 assertion, 121 s, exit 0 |
| Csomagszabályok (grep) | beégetett `waypost_`, `now()`, `strict_types`, `usePersonalAccessTokenModel`, `{!!`, nyers SQL | **PASS** (AUDIT-COMPLIANCE CL-01 – CL-09) |
| Telepítési próba | `scripts/install-smoke.sh` | **NEM FUTOTT** (hálózat) |
| Függőség-sebezhetőség | `composer audit` | **NEM FUTOTT** (külső szolgáltatás) |
| Eszközpróba, M7 | — | **NEM FUTOTT** (hatókörön kívül) |

A tesztek zöldje nem enyhített egyik megállapítást sem: a HIGH és a MEDIUM hibák pontosan azokon az utakon vannak, amelyeket egyetlen teszt sem jár be.

## 8. Architektúra

**Felépítés.**
- Egyetlen service provider regisztrálja:
  - a konfigurációt;
  - a saját `waypost` guardot;
  - a csomag saját `Gate`-jét;
  - a hibarenderelést (middleware és handler-callback);
  - a rate limiteket;
  - az útvonalakat;
  - a parancsokat;
  - az ütemezett jobokat.
- Rétegek:
  - **HTTP:** middleware-lánc → vékony controllerek → `Entities` (kimeneti alak);
  - **domén:** `Chain`, `Sync`, `Schedule`, `Reports`, `Pin`;
  - **perzisztencia:** modellek a közös `Model`-alaposztállyal és traitekkel;
  - **háttér:** jobok.

**Erősségek.**
- **Elszigetelés a gazdától:** saját tokentábla, guard és `Gate`, a gazda `before`-callbackjei nem érik el; konfigurálható tábla-előtag; a gazda handlere csak a saját előtag alatt formázódik. Ezt valódi telepítési próba (`install-smoke`) és egy `spatie/laravel-permission` incidens (M5-21) igazolta.
- **Séma az egyetlen szerződés:** a bemenet `opis/json-schema`-val, a kimenet a tesztekben `toMatchSchema`-val ellenőrzött; a controller csak az adatbázisfüggő szabályt nézi.
- **Lánc:**
  - a fogadás (`ChainProcessor`) és a vetület (`Projector`) szét van választva;
  - a vetület halasztható (`projected_at`) és idempotens;
  - az elutasított bejegyzés bizonyítékként tárolódik.
- **Idő:** UTC, `datetime(3)`, `Time::now()`, `TrustedTime`. A DST-kezelés a közös vektorokon igazolt.

**Gyengeségek.**
- A vetület a hivatkozások telephelyét nem ellenőrzi (WP-BE-002).
- A vetületkivétel az egész pusht visszagörgeti (WP-BE-008).
- A jelentések „egyszer” garanciája a foglalással túl erős: hibánál elveszít, részleges hibánál kettőz (WP-BE-004, WP-BE-020).
- A `Summarizer::summarize()` túl nagy (WP-BE-025).
- Nincs naplózás (WP-BE-035).

**Függőségek.**
- `laravel/framework ^13`, `laravel/sanctum ^4.3`, `opis/json-schema ^2.6`, `dompdf/dompdf ^3.1`, `waypost/schema ^0.5`, `ext-gd`. Mind indokolt és dokumentált.
- Hiányzik az `ext-fileinfo` (WP-BE-023).
- A `waypost/schema` gazdabeli forrását az M7-01-nek kell rögzítenie (AUDIT-COMPLIANCE SK-20).

## 9. Összevetés a skeletonnal

Részletesen: AUDIT-COMPLIANCE 13. fejezet. Összegzés:

| Státusz | Darab |
| --- | --- |
| PASS | 11 |
| PARTIAL | 3 |
| FAIL | 5 |
| NOT APPLICABLE | 1 |

- **Megvan:** a szerkezet, az auto-discovery, a config, a migrációk, a factory-k, a nézetek, a Pest + Testbench + workbench, a Larastan, a Pint, a README és a CHANGELOG.
- **Indokolt eltérések:**
  - teljes `laravel/framework` az `illuminate/support` helyett;
  - `per` preset;
  - nincs `lang/`, mert a Spec csak magyart kér;
  - nincs facade.
- **Hiányzik:**
  - CI munkafolyamat (WP-BE-007);
  - típuslefedettség (WP-BE-029);
  - `.gitattributes`, `.editorconfig`, LICENSE (WP-BE-024).

## 10. Kódminőség (SOLID, DRY, KISS, duplikáció)

- **Jó:**
  - Kicsi, egy felelősségű osztályok (`EntryHash`, `CanonicalJson`, `ServerFlags`, `TrustedTime`, `PinHasher`, `SitePins`, `Retention`, `Deadline`, `TimeLimit`).
  - A közös controller-alaposztály (`newId`, `existing`, `write`, `invalid`) és a `AssignsPins` trait megszünteti a CRUD-végpontok ismétlődését.
  - Az `Entities` egyetlen helyen adja a rekord API-alakját, a pull is ezt használja (DRY).
  - Szigorú típusok mindenütt (`declare(strict_types=1)`, PHPStan 8, `final` osztályok, `readonly` mezők).
  - A kommentek a „miért”-et írják le, és a döntésnaplóra hivatkoznak.
- **Javítandó:**
  - A `Summarizer::summarize()` kb. 150 sor (WP-BE-025).
  - A `Retention::days()` fotónként fut (WP-BE-026).
  - A `$guarded = []` a sémák `additionalProperties: false` szabályára épül. Ezt minden kéréssémánál ellenőriztem, ma helyes; egy jövőbeli séma lazítása viszont tömeges értékadási hibát nyitna (nem külön megállapítás, de a sémaváltozások átnézésekor figyelni kell).
- **Duplikáció:** érdemi kódismétlést nem találtam. A PDF és az e-mail közös `ReportContent`-et kap.

## 11. Biztonság (OWASP API Security Top 10, 2023)

| Kategória | Értékelés | Megállapítás |
| --- | --- | --- |
| API1 Objektumszintű jogosultság (IDOR) | Erős: minden útvonal-paraméter `whereUuid`, idegen rekord `not_found`, a policy-k a `Caller::siteIds()` szerint szűrnek; az `EndpointsTest` mátrixa lefedi | WP-BE-002 (a vetület hivatkozásai) |
| API2 Hibás azonosítás | Közepes | WP-BE-001, WP-BE-005, WP-BE-012, WP-BE-038 |
| API3 Tulajdonságszintű jogosultság | Erős: a sémák `additionalProperties: false`, az `Entities` szűr (pl. `email`, PIN-ellenőrző csak a telephelyi telefonnak) | WP-BE-001 (e-mail és jelszó az acting hívónak) |
| API4 Erőforrás-felhasználás | Közepes: rate limit van (240/perc, login 5/perc), a push 200-as köteg, a pull 500-as lap | WP-BE-004, WP-BE-011, WP-BE-015, WP-BE-017, WP-BE-018 |
| API5 Funkciószintű jogosultság | Jó: `Authenticate::using()` útvonalanként, az `acting` külön engedélyezendő | WP-BE-001, WP-BE-009 |
| API6 Érzékeny üzleti folyamatok | Megfelelő | — |
| API7 SSRF | Nincs kockázat: a dompdf távoli erőforrásai kikapcsolva, a fotó data URI | — |
| API8 Biztonsági beállítás | Jó: a kivétel szövege soha nem szivárog, a fájlok privátak | WP-BE-016, WP-BE-027 |
| API9 Leltár | Jó: verziózott `/v1`, a sémák a szerződés | WP-BE-042 |
| API10 Külső API-k nem biztonságos használata | Nem releváns | — |

További biztonsági pozitívumok:
- HMAC `hash_equals`-szal;
- az eszköztitok titkosítva;
- az idempotencia-válasz titkosítva (egyszer megmutatott PIN);
- nyers SQL csak egy konstans `selectRaw`;
- a Blade-ben nincs `{!!`;
- a PIN-ek PBKDF2-vel;
- a nem UUIDv7 token-ID-t a guard lekérdezés nélkül elutasítja.

## 12. Teljesítmény

- **MySQL-en:** a várt adatmennyiségre (néhány telephely, telephelyenként néhány tucat pont és felhasználó) a megoldás megfelelő. A fő lekérdezésekhez van index: `(updated_at, id)` a pullhoz, `(device_id, seq)`, `(device_id, projected_at)`, `(site_id, started_at)`.
- **Kockázatok:**
  - PostgreSQL-en a hiányzó FK-indexek (WP-BE-006);
  - a PIN-ellenőrzés CPU-igénye (WP-BE-011);
  - a pull-lapok előolvasása (WP-BE-021);
  - korlátlan listák a pushban (WP-BE-018);
  - több szerveren párhuzamos jobok (WP-BE-019);
  - N+1 a fotólinkeknél (WP-BE-026).
- **A PDF memóriaigénye** ismert és kezelt: 25 soros táblázatok, 512M, dokumentálva.

## 13. Tesztek és minőségbiztosítás

- **A készlet:** 606 teszt (Unit, Feature, Install), mock nélkül, valódi adatbázison (MySQL és PostgreSQL), `Mail` / `Queue` / `Storage` fake-kel. A közös tesztvektorok (lánc, menetrend, PIN) mindkét nyelven ugyanazok.
- **Erős területek:**
  - push (28 eset);
  - média (23);
  - vetület (21);
  - felhasználók (21);
  - idempotencia (15);
  - azonosítás (15);
  - pull (14);
  - jelentések (5 feature-fájl);
  - a végpont × hívó jogosultsági mátrix (`EndpointsTest`).
- **Hiányzó esetek:**
  - az acting supervisor saját hitelesítő adatai (WP-BE-001);
  - idegen telephelyre mutató lánchivatkozás (WP-BE-002);
  - árva idempotenciakulcs (WP-BE-003);
  - óriás képméretű JPEG (WP-BE-004);
  - token-visszavonás jelszócserekor (WP-BE-005);
  - párhuzamosság (WP-BE-010);
  - PostgreSQL-indexek (WP-BE-006).
- **Folyamat:** CI nincs (WP-BE-007), minden ellenőrzés kézi.

## 14. Dokumentáció

- **`docs/decisions.md`:** kivételesen alapos. Minden eltérést és nem specifikált részletet indokkal, érintett fájlokkal és feladatszámmal rögzít. Ez az audit legerősebb pozitívuma: a legtöbb gyanús eltérésről kiderült, hogy dokumentált és indokolt.
- **`README.md`:** telepítés, konfiguráció, ütemező és queue, fejlesztés. Hiányzik az üzemeltetési fejezet (WP-BE-028).
- **`CHANGELOG.md`:** megvan, a tagekkel egyezik. A 0.1.x → 0.2.0 frissítés korlátja nincs benne (WP-BE-037).
- **`CLAUDE.md`:** pontos, a kód követi (CL-01 – CL-09).

## 15. PHP 8.4 és Laravel 13 kompatibilitás

- PHP 8.4.27-en és Laravel 13.39-en (Testbench 11.3, Pest 5.3.4, Larastan 3.13.2, Pint 1.32.1) minden ellenőrzés zöld.
- A kód a modern nyelvi elemeket használja (típusos konstansok, `readonly`, enumok, attribútumok: `#[UseFactory]`). Elavult API-t nem találtam.
- A `prefer-lowest` függőségekkel nem futott próba (nincs CI, WP-BE-007).

## 16. Megfelelés a specifikációnak

Részletesen: AUDIT-COMPLIANCE 1–12. fejezet, 103 követelmény:

| Státusz | Darab |
| --- | --- |
| PASS | 88 |
| PARTIAL | 9 |
| FAIL | 1 |
| NOT TESTED | 3 |
| NOT APPLICABLE | 2 |

- **FAIL:** AUTH-09, az `X-Acting-User` telephelyi korlátja (WP-BE-001).
- **PARTIAL:**
  - STK-07: dompdf közvetlenül (indokolt eltérés);
  - STK-12: MySQL 8.0, PostgreSQL-indexek;
  - AUTH-08: PIN-verseny;
  - API-03: árva idempotenciakulcs;
  - SYN-03: 500 helyett 422;
  - CH-09: a vetület telephelye;
  - REP-08: egyszeri küldés;
  - MS-M4, MS-M5: a backendrész kész, a hibák és az eszközpróba miatt részleges.
- **NOT TESTED:** a kézi eszközteszt, a terheléses próba és az M7.

## 17. Pozitívumok

1. **Döntésnapló:** minden eltérés és nem specifikált részlet indokolt, visszakereshető döntés (`docs/decisions.md`, 40+ bejegyzés).
2. **Elszigetelés a gazdától:** saját tokentábla, guard és `Gate`, tábla-előtag, előtaghoz kötött hibaformázás; valódi gazdában kipróbálva.
3. **Láncfeldolgozás:**
   - zárolt tranzakció;
   - gap / fork / bad_hmac helyes sorrendben;
   - az elutasított bejegyzés bizonyítékként tárolódik, és a helyes bejegyzés felülírja;
   - halasztott, idempotens vetület;
   - a megőrzés után is ellenőrizhető `last_seq` / `last_hash`;
   - közös tesztvektorok.
4. **Idő- és DST-kezelés:** UTC, ezredmásodperc a gazda időzónájától függetlenül; `TrustedTime`; DST-helyes menetrend-kibontás közös vektorokon; a `timestamp` / `datetime` csapdát teszt őrzi.
5. **Tesztkészlet:** 606 teszt valódi adatbázison, két adatbázismotoron zöld; végpont × hívó jogosultsági mátrix; sémamegfelelés minden válaszra; PHPStan 8 hibamentes.
6. **Sémaközpontú szerződés:** egyetlen forrás az app és a backend között, magyar mezőszintű hibákkal; a szerződés verziózott.
7. **PostgreSQL-tudatosság:** savepoint minden várható ütközésnél, `uuid` oszlop védelme `whereUuid`-dal, a teljes készlet PostgreSQL-en is fut.
8. **Biztonsági alapok:**
   - HMAC `hash_equals`;
   - titkosított titkok;
   - a kivétel szövege nem szivárog;
   - privát fájlok, aláírt URL-ek;
   - nincs SQL-injekciós és XSS-felület;
   - a dompdf távoli erőforrásai kikapcsolva.
9. **Telepítés:** az `waypost:install` újrafuttatható, a lefutott migrációkat nem másolja újra, frissítéskor csak az újakat viszi ki.

## 18. Negatívumok

1. Az `X-Acting-User` saját adatokra vonatkozó kivétele a telephelyi korlátot megkerüli (WP-BE-001).
2. A vetület nem nézi a hivatkozások telephelyét (WP-BE-002).
3. Az idempotencia „busy” állapota nem jár le (WP-BE-003).
4. A jelentéskézbesítés hibatűrése gyenge: csendes elvesztés, illetve kettős küldés (WP-BE-004, WP-BE-020).
5. A tokenek élettartama és visszavonása gyenge: nem járnak le, és jelszócserekor sem vonódnak vissza (WP-BE-005).
6. Nincs CI és típuslefedettség, hiányoznak a csomagolási fájlok (WP-BE-007, -024, -029).
7. PostgreSQL-en hiányzó indexek (WP-BE-006).
8. Nincs naplózás és megfigyelhetőség (WP-BE-035).

## 19. Kockázatok

| Kockázat | Valószínűség | Hatás | Kapcsolódó |
| --- | --- | --- | --- |
| Egy ellopott vagy elvesztett telephelyi telefonból több telephely átvétele | közepes (a telefon terepen van) | magas | WP-BE-001, -009, -031 |
| Elakadt supervisor-műveletek a telefonon az M7 alatt | alacsony–közepes | közepes | WP-BE-003, -013 |
| Elmaradt heti vagy havi jelentés | alacsony | magas (M7 feltétel, bizonyíték) | WP-BE-004 |
| Bérlők közti adatkeveredés a jelentésben | alacsony | közepes | WP-BE-002 |
| Üzemeltetési hiba (APP_KEY-csere, órakülönbség) | közepes | magas | WP-BE-028, -033, -022 |
| Lassulás PostgreSQL-en | közepes (PostgreSQL-es gazdánál) | közepes | WP-BE-006 |
| Regresszió CI nélkül | közepes | közepes | WP-BE-007 |

## 20. Megállapítások összesítése

| Súlyosság | Darab | ID-k |
| --- | --- | --- |
| CRITICAL | 0 | — |
| HIGH | 1 | WP-BE-001 |
| MEDIUM | 6 | WP-BE-002 – WP-BE-007 |
| LOW | 22 | WP-BE-008 – WP-BE-029 |
| INFO | 13 | WP-BE-030 – WP-BE-042 |

A részletek és a prioritási lista: [AUDIT-ISSUES.md](AUDIT-ISSUES.md).

## 21. A minősítés indoklása

A **READY WITH CONDITIONS** minősítés indoka:

- **Mi szól mellette:** a csomag funkcionálisan teljes az 1. lépcső backendjére (M2, M4–M6). A specifikáció 103 követelményéből 88 teljesül bizonyítottan, és a részleges teljesítések nagy része dokumentált, indokolt döntés vagy kis hatású hiba. A legkényesebb, kétnyelvű részek (lánc-lenyomat, menetrend, PIN) a közös vektorokon igazoltak, a kód minősége és tesztelése egy kiadott könyvtár szintjén van.
- **Ami feltétel:**
  - Egy HIGH hiba a specifikáció egyik kifejezett biztonsági szabályát sérti (AUTH-09). A terepen lévő telephelyi telefon a legvalószínűbb támadási felület, ezért ezt a próbaidőszak **előtt** javítani kell.
  - Az M7 két elfogadási feltételét („adatvesztés nélkül”, „minden jelentés megérkezik”) a WP-BE-003 és a WP-BE-004 közvetlenül veszélyezteti.
- **Miért nem NOT READY:** a feltételek kis, jól körülhatárolt javítások (policy-ág, lejárat a „busy” állapotra, képméret-korlát és újrapróbálható foglalás, tokentörlés), nem tervezési átalakítás.
