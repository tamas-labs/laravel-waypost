# laravel-waypost – auditmegállapítások

- **Audit dátuma:** 2026-11-04
- **Vizsgált állapot:** `main`, `4fc8a6c` (Release 0.2.0), munkakönyvtár módosítás nélkül (csak a `docs/audit.20261104.md` nem követett)
- **Szempontok:** `docs/audit.20261104.md`
- **Kapcsolódó dokumentumok:** [AUDIT-REPORT.md](AUDIT-REPORT.md), [AUDIT-COMPLIANCE.md](AUDIT-COMPLIANCE.md); más repók teendői: [AUDIT-OTHER-REPOS.md](AUDIT-OTHER-REPOS.md)

A sorszámok a vizsgált commit fájljaira vonatkoznak. A „Bizonyosság” azt mutatja, mennyire biztos maga a hiba és a leírt következmény: **magas** = a kódból közvetlenül igazolható; **közepes** = a hiba igazolt, de a következmény mértéke vagy valószínűsége a környezettől függ; **alacsony** = valószínű, de nem igazolt.

Összesen **42** megállapítás:

| Súlyosság | Darab |
| --- | --- |
| CRITICAL | 0 |
| HIGH | 1 |
| MEDIUM | 6 |
| LOW | 22 |
| INFO | 13 |

A specifikációból következő (tudatos) tervezési kockázatok INFO szintűek: nem hibák, de az üzemeltetőnek tudnia kell róluk.

---

## CRITICAL

Nincs ilyen megállapítás.

---

## HIGH

### WP-BE-001 – Az X-Acting-User-rel eljáró supervisor átírhatja a saját e-mail-címét, jelszavát és PIN-jét, és így kilép a telephelyi korlátból

- **Súlyosság:** HIGH
- **Hely:**
  - [src/Auth/Policies/UserPolicy.php:48-51](src/Auth/Policies/UserPolicy.php#L48-L51): `update()` → `isSelf()` esetén feltétel nélkül `allow`;
  - [src/Auth/Policies/UserPolicy.php:67-70](src/Auth/Policies/UserPolicy.php#L67-L70): `isSelf()` nem nézi, hogy a hívó `isActing()`-e;
  - [src/Http/Controllers/UserController.php:87-106](src/Http/Controllers/UserController.php#L87-L106): `update()`, e-mail és jelszó írása;
  - [src/Http/Controllers/UserController.php:113-123](src/Http/Controllers/UserController.php#L113-L123): `pin()`;
  - [routes/api.php:92](routes/api.php#L92) és [routes/api.php:108-109](routes/api.php#L108-L109): a `PATCH /users/{id}` és a `PUT /users/{id}/pin` elfogadja az `ACTING` hívót;
  - [src/Http/Middleware/Authenticate.php:116-128](src/Http/Middleware/Authenticate.php#L116-L128): az `X-Acting-User` csak egy ID, a szerver nem kap bizonyítékot (PIN-t) arról, hogy a supervisor tényleg belépett.
- **Leírás:** Ha a hívó az eszköztoken és az `X-Acting-User: <supervisor ID>` (`Caller::acting`), a `UserPolicy::update()` a saját rekordjára mindig engedélyez. Így ezen a csatornán a supervisor e-mail-címe és jelszava (`PATCH /users/{id}`) és a PIN-je (`PUT /users/{id}/pin`) is átírható. A supervisor ID-ját a telephelyi telefon a pullból ismeri.
- **Miért probléma:** Az `X-Acting-User` jogosultsága a specifikáció szerint csak az eszköz telephelyére szól. Az e-mail és a jelszó viszont a supervisor *teljes* fiókjának hitelesítő adata, amellyel `POST /auth/login`-nal olyan supervisor token kapható, amely a supervisor **összes** telephelyére érvényes. A PIN is minden telephelyén azonosítja őt, ott is, ahol a telefon nincs. Az eszköztoken kinyerhető a telefonból, vagy a telefonhoz férő személy használhatja. Aki ezzel rendelkezik, az a pullból ismert bármelyik supervisor-ID-val átveheti a supervisor teljes fiókját. A `decisions.md` „Konfigurációs CRUD: közös szabályok” pontja pont ezt a fiókátvételt akarta kizárni („különben egy közös telephely supervisora átírhatná egy kolléga e-mail-címét és jelszavát … és belépne a kolléga többi telephelyére”). A saját adatokra vonatkozó kivételnél azonban az `acting` esetet nem vette figyelembe.
- **Következmény:** Jogosultság-kiterjesztés (OWASP API1/API5) eszköztokenről vagy egy telephelyi PIN-belépésről több telephely teljes supervisori hozzáférésére. A támadó a kolléga jelszavát lecserélve ki is zárhatja őt.
- **Megsértett követelmény:** Specifikáció, *Azonosítás / PIN-kód* („…de csak azon a telephelyen, amelyhez az eszköz tartozik”), *Supervisor fiók* („Csak azokat a telephelyeket látja és módosíthatja…”); `docs/decisions.md` 243. sor. A `waypost-schema` `api/user-update.request.json` leírása („a user changes their own e-mail address, password and PIN … or the site phone's device token with an X-Acting-User header (then only on the phone's site)”) ezt a kombinációt nem tiltja kifejezetten, így a szerződés is pontosításra szorul.
- **Javítási irány:**
  - `acting` hívónál a `UserPolicy::update()` saját magára is a `manage()` ágat kövesse;
  - vagy egyszerűbben: `acting` hívó ne módosíthasson `email` / `password` mezőt és PIN-t (403), saját magának sem;
  - a sémaleírásban rögzíteni (előbb a `waypost-schema`-ban), majd teszt az `EndpointsTest` / `UsersTest` mátrixba.
- **Bizonyosság:** magas (a kódút igazolt: policy → controller → `$user->update($input)`; nincs olyan teszt, amely ezt az esetet tiltaná).
- **Állapot:** javítva (2026-11-05): `acting` hívónál a `UserPolicy::update()` a saját rekordra is a `manage()` ágat követi; tesztek: `PoliciesTest`, `UsersTest`; döntés: `docs/decisions.md` (Konfigurációs CRUD: közös szabályok). A sémaleírás pontosítása (`waypost-schema`) még nyitott: [AUDIT-OTHER-REPOS.md](AUDIT-OTHER-REPOS.md) 1.

---

## MEDIUM

### WP-BE-002 – A vetület nem ellenőrzi, hogy a lánc hivatkozásai az eszköz telephelyéhez tartoznak

- **Súlyosság:** MEDIUM
- **Hely:**
  - [src/Chain/Projector.php:143-147](src/Chain/Projector.php#L143-L147): `roundOpened`, csak a `RouteVersion` és a `Schedule` létezését nézi;
  - [src/Chain/Projector.php:182-185](src/Chain/Projector.php#L182-L185): `scan`, a `checkpoint_id` bármely telephely pontja lehet, törölt is;
  - [src/Chain/Projector.php:267-271](src/Chain/Projector.php#L267-L271): `incident`, a `nearest_checkpoint_id` és a `scan_id` bármely telephelyről;
  - [src/Chain/Projector.php:290-299](src/Chain/Projector.php#L290-L299): csak a műszak és a kör kötődik az eszközhöz.
- **Leírás:**
  - A `round_opened` elfogadja egy másik telephely útvonalverzióját és menetrendjét, és azt sem nézi, hogy a `schedule_id` útvonala ugyanaz-e, mint a `route_version_id`-é.
  - A `scan` egy másik telephely pontjára is vetületet ír, és a `far_from_checkpoint` jelölést annak a koordinátáihoz számolja.
  - Az `incident` idegen pontra és idegen leolvasásra is hivatkozhat.
- **Miért probléma:** A lánc az eszköz telephelyének bizonyítéka. Egy hibás app, egy rossz pull-állapot vagy egy manipulált (de érvényesen aláírt) lánc más bérlő adataira mutató vetületet hoz létre. A telephely jelentése (`Summarizer`) a körhöz a `routeVersion`-t és a pontok nevét tölti be, így a jelentésben **egy másik telephely útvonal- és pontnevei** jelenhetnek meg. Ez bérlők közötti információszivárgás, és a kör teljesítése értelmetlen adatokból számolódik. A rögzített kört (`Expansion` / `expected`) a `schedule_id` + `scheduled_at` kulcs párosítja, így egy idegen menetrendű kör a saját telephely várt körei közül egyet sem „pótol”, csak zavaros sort ad.
- **Következmény:**
  - hamis vagy félrevezető jelentéssor;
  - más telephely konfigurációs neveinek megjelenése;
  - a `too_fast` / `far_from_checkpoint` jelölések értelmetlen alapból számolódnak.
- **Megsértett követelmény:** Specifikáció, *Supervisor fiók* (telephelyi elkülönítés), *Leolvasás 1.* („…keresünk pontot **a telephelyen**”), *Kör* (a kör az útvonal verzióját használja). A `decisions.md` „Halasztott vetület” pontja a műszakra és a telephelyre szűkít, a többi hivatkozásra nem.
- **Javítási irány:**
  - a `RouteVersion` → `Route.site_id`, a `Schedule` → `route_id` → `site_id`, a `Checkpoint.site_id` és a `Scan` → kör → műszak → eszköz ellenőrzése a műszak telephelyéhez képest;
  - ha nem egyezik: vetület nélkül tárolás, és egy szerverjelölés (új `ChainFlag`, előbb a sémában), vagy a jelentés hibás bejegyzésként jelölje;
  - teszt: idegen telephely hivatkozása minden bejegyzéstípusban.
- **Bizonyosság:** magas a hiányzó ellenőrzésre, közepes a gyakorlati valószínűségre (érvényes HMAC kell hozzá, tehát az eszköz titka vagy hibás app).
- **Állapot:** javítva (2026-11-05): a `Projector` az útvonalverziót, a menetrendet (a verzió útvonaláén) és a pontot csak az eszköz telephelyéről, az incidens leolvasását csak az eszköz saját láncából fogadja el; minden más ismeretlen hivatkozás, a bejegyzés vetület nélkül marad (`projected_at = null`, a jelentésben „nem teljes adat”). Sémaváltozás és új szerverjelölés nem kellett. Teszt: `ProjectorTest`; döntés: `docs/decisions.md` (Halasztott vetület / Hatókör).

### WP-BE-003 – Félbeszakadt kérés után 24 órán át „még fut” marad az idempotenciakulcs, és ez megállítja az outboxot

- **Súlyosság:** MEDIUM
- **Hely:**
  - [src/Http/Middleware/HandleIdempotencyKey.php:56-71](src/Http/Middleware/HandleIdempotencyKey.php#L56-L71): a kulcs saját, azonnal commitolt savepointban jön létre;
  - [src/Http/Middleware/HandleIdempotencyKey.php:140-157](src/Http/Middleware/HandleIdempotencyKey.php#L140-L157): törlés csak `Throwable`-re és 5xx-re;
  - [src/Http/Middleware/HandleIdempotencyKey.php:164-166](src/Http/Middleware/HandleIdempotencyKey.php#L164-L166): `response_status === null` → `busy()`;
  - [src/Http/Middleware/HandleIdempotencyKey.php:44](src/Http/Middleware/HandleIdempotencyKey.php#L44): TTL 24 óra.
- **Leírás:** A kulcs `response_status = null`-lal kerül az adatbázisba, mielőtt a controller fut. A `run()` a `Throwable`-t elkapja, de a PHP fatális hibáját, a `max_execution_time` túllépését, a memória kifogyását és a worker/FPM-folyamat leállását nem. Kimarad az a hiba is, amikor a controller tranzakciója már commitolt, és utána a `$record->update(...)` bukik. Ilyenkor a kulcs „futó” állapotban marad, és minden ismétlés `409 conflict` + `Retry-After: 5` választ kap a lejáratig (24 óra).
- **Miért probléma:** Az app outboxa a `Retry-After`-es 409-et a dokumentált szerződés szerint ugyanazzal a kulccsal újrapróbálja (az app `src/sync/outbox.ts` 271–278. sora; a `decisions.md` „A még futó kérés jelzése (M5-03)” pontja). Az outbox FIFO, ezért a mögötte álló összes supervisor-művelet is vár. A push minden próbálkozásnál új kulcsot kap, ezért a láncot ez nem érinti; a konfigurációs műveleteket viszont igen.
- **Következmény:** A telephelyi telefonon végzett beállítások akár 24 órára elakadnak, látható hiba nélkül (a 409 + Retry-After „várj” jelentésű). Ez az M5 elfogadási feltételét („offline végzett módosítások a hálózat visszatérte után végrehajtódnak”) sérti.
- **Megsértett követelmény:** Specifikáció, *API-szerződés* („ugyanarra a kulcsra a szerver 24 órán át ugyanazt a választ adja vissza”) és *Outbox* (FIFO, újrapróbálás); M5 elfogadási feltétele.
- **Javítási irány:**
  - a „futó” állapotnak legyen lejárata (pl. egy `locked_until` / `started_at` mező, és N perc után a kulcs újrafuttatható);
  - vagy a kulcs a controller tranzakciójával együtt commitoljon, és a „futó” jelzést zár (`lockForUpdate`) adja;
  - a `PruneIdempotencyKeys` a régi, válasz nélküli kulcsokat is takarítsa;
  - teszt: a `run()` kihagyásával előállított árva kulcs.
- **Bizonyosság:** magas a mechanizmusra; közepes a gyakoriságra (időtúllépés vagy OOM kell hozzá; a PDF-készítés nem ebben az útvonalban fut).
- **Állapot:** javítva (2026-11-05): a létrejötte után 5 perccel (`HandleIdempotencyKey::ABANDONED_AFTER_S`) válasz nélkül maradt kulcs elhagyottnak számít, a következő próbálkozás újra lefuttatja a kérést, és a `PruneIdempotencyKeys` is törli. Migráció nem kellett (a `created_at` megvolt). Teszt: `IdempotencyTest`; döntés: `docs/decisions.md` (A még futó kérés jelzése).

### WP-BE-004 – Egy determinisztikus fatális hiba a PDF-készítésben végleg elveszíti a heti vagy havi jelentést, és ez nem derül ki

- **Súlyosság:** MEDIUM
- **Hely:**
  - [src/Reports/Thumbnails.php:32-40](src/Reports/Thumbnails.php#L32-L40): `imagecreatefromstring()` a képméret ellenőrzése nélkül;
  - [src/Reports/ReportSender.php:62](src/Reports/ReportSender.php#L62): a `exists()` a még el nem küldött sort is meglévőnek veszi;
  - [src/Reports/ReportSender.php:66-78](src/Reports/ReportSender.php#L66-L78): foglalás küldés előtt;
  - [src/Reports/ReportSender.php:93-99](src/Reports/ReportSender.php#L93-L99): csak `Throwable`-re takarít;
  - [src/Reports/Pdf.php:28](src/Reports/Pdf.php#L28): 512M memóriakorlát.
- **Leírás:** A `reports` sor a küldés előtt foglalódik. Ha a folyamat fatális hibával áll le, a sor `sent_at = null`-lal megmarad, és a `SendDueReports` / `SendPeriodReport` (`exists()`) többé nem próbálja újra. Ilyen hiba például a memória kifogyása a GD-ben vagy a dompdf-ben, vagy a job `timeout`-ja. A GD a JPEG-et a fejléce szerinti teljes méretre bontja ki: egy kis fájlméretű, de 20 000 × 20 000 képpontos JPEG kb. 1,6 GB memóriát kér, ami determinisztikusan túllépi az 512M-t. A `MediaController` csak a MIME-típust és a lenyomatot nézi, a képméretet nem.
- **Miért probléma:** A `decisions.md` (422. sor) a foglalás és a küldés közti összeomlást „a ritkaságához mérten” fogadja el. Egy rossz fotó viszont nem ritka véletlen: minden futáskor ugyanott bukik, és az adott telephely adott heti vagy havi jelentése csendben elmarad. Sehol nincs nyoma: nincs riasztás, és az archívumban a `sent_at = null` sor nem jelenik meg hibaként.
- **Következmény:** Elveszett heti vagy havi jelentés és PDF-bizonyíték. Ez az M7 elfogadási feltételét („minden jelentés megérkezik”) sérti. Előidézni egy hibás vagy manipulált telefonfotóval lehet (eszköztoken kell hozzá).
- **Megsértett követelmény:** Specifikáció, *Jelentések* (heti/havi PDF), M7 elfogadási feltétele; OWASP API4 (korlátlan erőforrás-felhasználás).
- **Javítási irány:**
  - `getimagesizefromstring()` a feltöltéskor (felső határ, pl. 4096 × 4096, az app 1600 px-et küld) és a bélyegkép előtt is;
  - a foglalt, de el nem küldött sor egy határidő után legyen újrapróbálható (pl. `generated_at` + N perc és `sent_at = null`), vagy kerüljön riasztásba vagy naplóba.
- **Bizonyosság:** magas a mechanizmusra (a GD kibontási viselkedése és a foglalás logikája ismert); közepes a gyakorlati valószínűségre.
- **Állapot:** javítva (2026-11-05):
  - a `Thumbnails` a fejléc szerint 4096 × 3072 képpontnál nagyobb fotót nem bont ki, a PDF bélyegkép nélkül készül el;
  - a 15 percnél régebbi, el nem küldött foglalás elhagyottnak számít: a következő próbálkozás törli (a PDF-jével együtt), `Log::warning`-ot ír, és újra küld; a műszakjelentés jobja addig `release()`-szel vár;
  - a feltöltéskori méretkorlát (422) API-szerződésváltozás, ezért nem itt készült: lásd [AUDIT-OTHER-REPOS.md](AUDIT-OTHER-REPOS.md).
  - Teszt: `PeriodReportTest`, `ShiftReportTest`; döntés: `docs/decisions.md` (A műszakjelentés / Egyszer; a periodikus jelentések / PDF).

### WP-BE-005 – A jelszócsere nem vonja vissza a supervisor meglévő tokenjeit, és a tokenek nem járnak le

- **Súlyosság:** MEDIUM
- **Hely:**
  - [src/Http/Controllers/UserController.php:87-106](src/Http/Controllers/UserController.php#L87-L106): `update()`, jelszócserekor nincs `tokens()->delete()`;
  - [src/Http/Controllers/AuthController.php:37](src/Http/Controllers/AuthController.php#L37): lejárat nélküli token;
  - [src/Auth/TokenGuard.php:90](src/Auth/TokenGuard.php#L90): csak az `expires_at`-et nézi, ami mindig `null`.
- **Leírás:**
  - A supervisor token a specifikáció szerint nem jár le.
  - A jelszó módosítása (`PATCH /users/{id}` `password`) a többi tokent érintetlenül hagyja.
  - Nincs „kijelentkezés mindenhonnan” végpont. Visszavonni csak inaktiválással (amit saját magán nem tehet meg) vagy törléssel lehet.
- **Miért probléma:** Egy ellopott vagy kiszivárgott supervisor token az áldozat jelszócseréje után is örökre érvényes marad. Ez a szokásos incidenskezelést (a jelszó cseréje) hatástalanná teszi.
- **Következmény:** Tartós jogosulatlan hozzáférés a supervisor összes telephelyéhez.
- **Megsértett követelmény:** OWASP API2 (hibás azonosítás). A specifikáció („A token nem jár le, kijelentkezéskor törlődik”) ezt nem tiltja, de nem is írja elő, hogy jelszócsere után megmaradjon; ezt a `decisions.md` „Belépés és kijelentkezés” pontja sem dönti el.
- **Javítási irány:**
  - jelszócserekor a felhasználó összes tokenjének törlése, kivéve a hívó saját tokenjét, ha saját magát módosítja;
  - opcionálisan `last_used_at` és egy inaktivitási lejárat (lásd WP-BE-038).
- **Bizonyosság:** magas.

### WP-BE-006 – PostgreSQL-en az idegen kulcsoknak nincs indexük, a jelentés és az adatmegőrzés legfontosabb illesztései teljes táblát olvasnak

- **Súlyosság:** MEDIUM
- **Hely:**
  - [database/migrations/2026_10_28_000010_create_waypost_shifts_table.php:22](database/migrations/2026_10_28_000010_create_waypost_shifts_table.php#L22): `shifts.chain_entry_id`;
  - [database/migrations/2026_10_28_000010_create_waypost_shifts_table.php:30](database/migrations/2026_10_28_000010_create_waypost_shifts_table.php#L30): `rounds.shift_id`;
  - [database/migrations/2026_10_28_000010_create_waypost_shifts_table.php:31](database/migrations/2026_10_28_000010_create_waypost_shifts_table.php#L31): `rounds.route_version_id`;
  - [database/migrations/2026_10_28_000011_create_waypost_scans_table.php:17](database/migrations/2026_10_28_000011_create_waypost_scans_table.php#L17): `scans.round_id`;
  - [database/migrations/2026_10_28_000011_create_waypost_scans_table.php:18](database/migrations/2026_10_28_000011_create_waypost_scans_table.php#L18): `scans.checkpoint_id`;
  - [database/migrations/2026_10_28_000011_create_waypost_scans_table.php:27](database/migrations/2026_10_28_000011_create_waypost_scans_table.php#L27): `scans.chain_entry_id`;
  - [database/migrations/2026_10_28_000011_create_waypost_scans_table.php:34](database/migrations/2026_10_28_000011_create_waypost_scans_table.php#L34): `incidents.shift_id`;
  - [database/migrations/2026_10_28_000011_create_waypost_scans_table.php:41-43](database/migrations/2026_10_28_000011_create_waypost_scans_table.php#L41-L43): `incidents.nearest_checkpoint_id`, `incidents.scan_id`, `incidents.chain_entry_id`;
  - [database/migrations/2026_10_28_000012_create_waypost_media_table.php:17](database/migrations/2026_10_28_000012_create_waypost_media_table.php#L17): `media.device_id`;
  - [database/migrations/2026_10_28_000007_create_waypost_schedules_table.php:15](database/migrations/2026_10_28_000007_create_waypost_schedules_table.php#L15): `schedules.route_id`;
  - [database/migrations/2026_10_28_000006_create_waypost_routes_table.php:15](database/migrations/2026_10_28_000006_create_waypost_routes_table.php#L15): `routes.site_id`;
  - [database/migrations/2026_10_28_000008_create_waypost_report_subscriptions_table.php:16](database/migrations/2026_10_28_000008_create_waypost_report_subscriptions_table.php#L16): `report_subscriptions.site_id`.
- **Leírás:** A `foreignUuid()->constrained()` MySQL/InnoDB-n magától indexet hoz létre, PostgreSQL-en nem. A felsorolt oszlopokra nincs saját index, és egyetlen összetett index sem kezdődik velük.
- **Miért probléma:**
  - A `Summarizer` (`rounds.scans`, `whereIn('shift_id', …)`), a `ChainProcessor::missingMedia()` (`media.device_id`) és a `Projector::isTooFast()` (`scans.round_id` + `chain_entry_id` join) PostgreSQL-en teljes táblát olvas.
  - A `PruneExpiredData` (`whereNotExists` a `shifts` / `scans` / `incidents` `chain_entry_id`-jára, [src/Jobs/PruneExpiredData.php:117-124](src/Jobs/PruneExpiredData.php#L117-L124)) is, minden láncbejegyzésre.
  - Minden szülőtörlés FK-ellenőrzése szintén teljes táblás olvasás.
  - A 62 napos megőrzés alatt ez telephelyenként több tízezer sor.
- **Következmény:** PostgreSQL-en a push, a jelentések és a napi takarítás az adatmennyiséggel arányosan lassul. A push zárolt tranzakciója hosszabb lesz, ami a pull `SETTLE_MS` feltételezését is gyengíti (WP-BE-022).
- **Megsértett követelmény:** Specifikáció, *Repo és technológiai stack* („MySQL 8 vagy PostgreSQL 16”); audit szempont: *Adatbázis és migrációk*, *Teljesítmény*.
- **Javítási irány:** Új migráció (nem a meglévők módosítása, lásd WP-BE-037) a felsorolt oszlopok indexeivel. A PostgreSQL-próbában ellenőrzés a `pg_indexes` alapján (a `MigrationsTest` mintájára).
- **Bizonyosság:** magas a hiányzó indexekre; közepes a hatás mértékére (nem mértem nagy adatmennyiségen).

### WP-BE-007 – Nincs CI: a tesztek, a PHPStan és a Pint csak kézzel, helyben futnak

- **Súlyosság:** MEDIUM
- **Hely:** a repó gyökere (nincs `.github/workflows/` könyvtár és más CI-leíró sem).
- **Leírás:**
  - A `laravel/package-skeleton` `tests.yml` munkafolyamata minden pushra mátrixban futtatja a statikus elemzést, a stílusellenőrzést, a típuslefedettséget és a teszteket: PHP 8.3, 8.4 és 8.5, Laravel 12 és 13, `lowest` és `stable` függőségek, Ubuntu és Windows.
  - Itt ilyen nincs.
  - A PostgreSQL-futás külön, kézi lépés.
  - A `scripts/install-smoke.sh` hálózatot és Dockert kér.
- **Miért probléma:**
  - A minőség jelenleg a fejlesztő fegyelmén múlik.
  - A PostgreSQL-ágat (WP-BE-006, savepointok) és a `prefer-lowest` függőségeket semmi nem ellenőrzi automatikusan.
  - A `composer.lock` nincs commitolva, így egy függőségfrissítés okozta törés csak a következő kézi futásnál derül ki.
- **Következmény:** Regressziók (pl. egy PostgreSQL-specifikus hiba) észrevétlenül kerülhetnek kiadásba.
- **Megsértett követelmény:** Audit szempont: *Laravel package skeleton megfelelés*, *Tesztek és minőségbiztosítás*. A specifikáció *Automatikus tesztek* pontja CI-t csak a `waypost-schema`-hoz ír elő, ezért ez nem specifikációsértés, hanem gyakorlati hiány.
- **Javítási irány:** GitHub Actions (vagy a használt platform) munkafolyamata:
  - `composer lint`, `analyse` és `test` MySQL 8.4-en és PostgreSQL 16-on;
  - `prefer-lowest` / `prefer-stable` ág;
  - kiadás előtt az install-smoke;
  - a `waypost-schema` path repository miatt a sémarepó checkoutja vagy egy verziózott Composer-forrás.
- **Bizonyosság:** magas.

---

## LOW

### WP-BE-008 – Egy kivétel a vetületben az egész push tranzakcióját visszagörgeti, és az eszköz minden további pushát elakaszthatja

- **Súlyosság:** LOW
- **Hely:**
  - [src/Chain/ChainProcessor.php:40-65](src/Chain/ChainProcessor.php#L40-L65): a fogadás, a vetület és a válasz egy tranzakcióban;
  - [src/Chain/ChainProcessor.php:191-204](src/Chain/ChainProcessor.php#L191-L204): `projectPending()`, kivételkezelés nélkül.
- **Leírás:** A `projectPending()` minden pushnál az eszköz összes eddig ki nem vetített `ok` bejegyzését újra alkalmazza. Ha egy `Projector::apply()` kivételt dob, az egész tranzakció visszagörög, a már elfogadott új bejegyzésekkel együtt, és a válasz 500. Kivételt dobhat például egy adatbázis-hiba, egy az oszlop tartományán kívüli érték (WP-BE-013) vagy egy váratlan, `null` értékű hivatkozás. A rossz bejegyzés a következő pushnál is ott van, így minden további push 500-at kap.
- **Miért probléma:** Egyetlen „mérgező” bejegyzés az eszköz teljes szinkronját megállítja, és az adat a telefonon marad. A sémák és a `Projector` ellenőrzései a jelenlegi bejegyzéstípusoknál a legtöbb ilyen esetet kizárják, ezért a valószínűség alacsony.
- **Következmény:** A telefon nem tud feltölteni; ez az M4 elfogadási feltételét („minden adat hibátlanul a szerverre kerül”) sérti.
- **Megsértett követelmény:** Specifikáció, *Szerveroldali ellenőrzés* (a bejegyzés eltárolódik, a vetület idempotens), M4.
- **Javítási irány:** Bejegyzésenként savepoint a `projectPending()`-ben. Kivételnél a bejegyzés maradjon `projected_at = null`, és kapjon naplót vagy diagnosztikai jelzést, de a lánc fogadása commitoljon.
- **Bizonyosság:** közepes (a mechanizmus igazolt; konkrét, sémán átjutó kiváltó okot nem találtam, csak a WP-BE-013 szomszédos esetét).

### WP-BE-009 – Az X-Acting-User mögött nincs szerveroldali bizonyíték: az eszköztoken birtokosa a telephely bármelyik supervisoraként eljárhat

- **Súlyosság:** LOW
- **Hely:** [src/Http/Middleware/Authenticate.php:98-128](src/Http/Middleware/Authenticate.php#L98-L128), [src/Auth/Caller.php:43-46](src/Auth/Caller.php#L43-L46).
- **Leírás:** A PIN-ellenőrzés a specifikáció szerint offline, a telefonon történik. A szerver az `X-Acting-User`-ben kapott ID-t csak aktív, a telephelyhez rendelt supervisorként ellenőrzi. Aki az eszköztokent birtokolja, a telephelyen bármelyik supervisor nevében mindent megtehet, például jelszavas supervisort hozhat létre (`POST /users`). A WP-BE-001 nélkül ez a telephelyen belül marad.
- **Miért probléma:** Az eszköztoken így a telephely teljes adminisztratív jogát hordozza. A naplóban (`created_by`, jövőbeli audit) a valódi szereplő nem azonosítható.
- **Következmény:** Egy kinyert eszköztokennel a telephely konfigurációja teljesen átvehető.
- **Megsértett követelmény:** Nem sérti a specifikációt (*PIN-kód*, utolsó pont); ez tudatos tervezési kockázat, amelyet a dokumentáció nem nevez meg.
- **Javítási irány:**
  - Rövid távon: a WP-BE-001 javítása, és a README-ben vagy a telepítési leírásban rögzíteni, hogy az eszköztoken kompromittálása a telephely kompromittálása.
  - Hosszabb távon (2. lépcső): az `X-Acting-User` mellé egy, a supervisor PIN-jéből vagy az eszköz titkából származó, rövid életű igazolás.
- **Bizonyosság:** magas.

### WP-BE-010 – A PIN egyedisége versenyhelyzetben nem garantált

- **Súlyosság:** LOW
- **Hely:**
  - [src/Http/Controllers/Concerns/AssignsPins.php:24-29](src/Http/Controllers/Concerns/AssignsPins.php#L24-L29);
  - [src/Http/Controllers/UserController.php:63-68](src/Http/Controllers/UserController.php#L63-L68);
  - [src/Http/Controllers/SiteUserController.php:55-61](src/Http/Controllers/SiteUserController.php#L55-L61);
  - [src/Pin/SitePins.php:31-34](src/Pin/SitePins.php#L31-L34).
- **Leírás:** Az ütközésvizsgálat (`isTaken` / `generate`) zár és adatbázis-kényszer nélkül fut, a mentés pedig külön lépés. Az egyedi index nem lehetséges, mert a PIN sózott hash. Két egyidejű kérés (pl. két supervisor, vagy az outbox és egy webes kliens) ugyanazt a PIN-t adhatja két felhasználónak ugyanazon a telephelyen.
- **Miért probléma:** A PIN a telephelyen azonosító. Kétértelmű PIN-nél az app nem tudja eldönteni, ki lépett be.
- **Következmény:** Rossz személy nevén nyíló műszak vagy supervisor mód (ritka).
- **Megsértett követelmény:** Specifikáció, *PIN-kód* („Ugyanaz a PIN nem lehet két felhasználónál egy telephelyen belül … A szerver ezt a létrehozáskor ellenőrzi.”).
- **Javítási irány:** Telephelyenkénti zár a PIN-hozzárendelés idejére: `lockForUpdate` az érintett `sites` sorokon, rendezett sorrendben, ugyanabban a tranzakcióban, mint a mentés.
- **Bizonyosság:** magas a versenyablakra, alacsony a gyakoriságra.

### WP-BE-011 – A PIN-ütközés ellenőrzése kérésenként felhasználónként egy 100 000 iterációs PBKDF2-t futtat (CPU-erősítés)

- **Súlyosság:** LOW
- **Hely:** [src/Pin/SitePins.php:63-89](src/Pin/SitePins.php#L63-L89), [src/Pin/PinHasher.php:21](src/Pin/PinHasher.php#L21).
- **Leírás:** Minden PIN-hozzárendelés a telephely(ek) összes PIN-nel rendelkező felhasználójára újraszámolja a PBKDF2-t. A `decisions.md` szerint (163. sor) telephelyenként néhány tucat felhasználónál ez 1–2 másodperc. Több telephelyes felhasználónál az összes telephely felhasználói számítanak. A `generate()` ütközéskor a jelöltenkénti számolást megismétli.
- **Miért probléma:** Egy hitelesített hívó a `waypost` limit (240/perc) erejéig kérésenként másodperceknyi CPU-t köthet le. Ehhez supervisor token vagy eszköztoken + `X-Acting-User` kell. Nagyobb telephelyen a válaszidő a PHP vagy a proxy időkorlátját is elérheti.
- **Következmény:** Szolgáltatásromlás (OWASP API4).
- **Megsértett követelmény:** Audit szempont: *Teljesítmény*, *Biztonság (API4)*. A specifikáció az ellenőrzést előírja, a módját nem.
- **Javítási irány:** Külön, szigorúbb rate limit a PIN-et érintő végpontokra (`POST /users`, `PUT /users/{id}/pin`, `POST /sites/{id}/users`), és mérés a várható legnagyobb telephelyméretre.
- **Bizonyosság:** közepes.

### WP-BE-012 – A login időzítése elárulja, létezik-e a fiók, és a limit csak e-mail + IP párosra szól

- **Súlyosság:** LOW
- **Hely:** [src/Http/Controllers/AuthController.php:26-34](src/Http/Controllers/AuthController.php#L26-L34), [src/WaypostServiceProvider.php:133-137](src/WaypostServiceProvider.php#L133-L137).
- **Leírás:**
  - Ismeretlen címnél vagy őrnél a `Hash::check()` nem fut le (a `$user?->password === null` rövidzár), ezért a válasz mérhetően gyorsabb, mint egy létező supervisor hibás jelszavánál.
  - A `waypost-login` limit (5/perc) e-mail-cím és IP párosra szól, IP-re összesítve nincs korlát.
- **Miért probléma:**
  - Az időzítésből felhasználói lista építhető.
  - Egy IP-ről sok címre végzett jelszópróbálgatást (password spraying) a limit nem fékezi.
  - A per-IP limit a gazda `TrustProxies` beállításától függ; ez sincs dokumentálva (WP-BE-028).
- **Következmény:** Felhasználó-felderítés és gyengébb védelem a jelszópróbálgatással szemben (OWASP API2).
- **Megsértett követelmény:** A `decisions.md` 219. sora szerint „nem árulja el, hogy a cím létezik”; ezt az időzítés ellen nem biztosítja.
- **Javítási irány:**
  - ismeretlen felhasználónál is egy ál-`Hash::check()` (álhash) a futásidő kiegyenlítésére;
  - egy második, IP-re szóló login-limit;
  - a README-ben a `TrustProxies` megemlítése.
- **Bizonyosság:** magas az időzítési különbségre; nem mértem.

### WP-BE-013 – A gyalogidőknek nincs felső határa a sémában, a túl nagy érték 500-at ad 422 helyett

- **Súlyosság:** LOW
- **Hely:**
  - [database/migrations/2026_10_28_000006_create_waypost_routes_table.php:32](database/migrations/2026_10_28_000006_create_waypost_routes_table.php#L32): `walk_duration_s`, `unsignedInteger`;
  - [database/migrations/2026_10_28_000006_create_waypost_routes_table.php:48](database/migrations/2026_10_28_000006_create_waypost_routes_table.php#L48): `walk_time_from_prev_s`;
  - `../waypost-schema/schemas/api/route-version-create.request.json` 15. és 40. sor: nincs `maximum`.
- **Leírás:** A sémában a két mezőnek nincs felső határa. 2³²−1 felett (PostgreSQL-en már 2³¹−1 felett, mert ott az `unsignedInteger` `integer`) az adatbázis kivételt dob.
- **Miért probléma:** Az `ErrorRenderer` ezt `server_error` (500) válaszként adja ki. Az app outboxa az 5xx-et exponenciális visszatartással újrapróbálja, így a hibás kérés soha nem kerül ki a sorból, és a FIFO mögötte áll.
- **Következmény:** Elakadt outbox egy hibás betanítási érték miatt (valószínűtlen, de ugyanaz a hatás, mint a WP-BE-003-nál).
- **Megsértett követelmény:** Audit szempont: *API és szerződések* (a séma mint teljes szerződés).
- **Javítási irány:** `maximum` a sémában (előbb a `waypost-schema`-ban, új verzióval), és a többi `smallint` / `integer` oszlop sémamaximumának átnézése PostgreSQL-tartományra.
- **Bizonyosság:** magas.

### WP-BE-014 – A kliens által megadott `taken_at` szabja meg a fotólink élettartamát és az egyik törlési feltételt

- **Súlyosság:** LOW
- **Hely:** [src/Http/Controllers/MediaController.php:62](src/Http/Controllers/MediaController.php#L62), [src/Http/SignedUrls.php:35](src/Http/SignedUrls.php#L35), [src/Jobs/PruneExpiredData.php:111-114](src/Jobs/PruneExpiredData.php#L111-L114), [src/Chain/Projector.php:75](src/Chain/Projector.php#L75).
- **Leírás:**
  - A feltöltés `taken_at` mezője felülírja a bejegyzésből vett időt.
  - Az aláírt link lejárata `taken_at` + megőrzési idő, így egy jövőbeli `taken_at` évekig érvényes linket ad.
  - A `taken_at` szerinti törlés egy jövőbeli időre nem fut le.
  - A fotót a műszak szerinti törlés ([src/Jobs/PruneExpiredData.php:82-88](src/Jobs/PruneExpiredData.php#L82-L88)) végül eltávolítja, de a műszak ideje is az eszköz órájából (`TrustedTime`) jön.
- **Miért probléma:** A megőrzési idő a specifikáció szerint szerveroldali szabály, itt viszont kliensadat befolyásolja. A link a fájl törléséig él, utána 404.
- **Következmény:** A specifikáció szerinti „az adatmegőrzés végéig élő” link ennél tovább él. Ha az eszköz órája is rossz, a törlés késik.
- **Megsértett követelmény:** Specifikáció, *Adatmegőrzés*, *Tartalom* („aláírt link, amely az adatmegőrzés végéig él”).
- **Javítási irány:**
  - a link lejárata és a törlés a szerver idejéből (`received_at` / `uploaded_at`) számolódjon, vagy a `taken_at` legyen a bejegyzés `received_at`-jéhez szorítva;
  - jövőbeli `taken_at` (pl. > most + 1 nap) elutasítása.
- **Bizonyosság:** magas.

### WP-BE-015 – A még nem létező pontra feltöltött pontfotó soha nem törlődik

- **Súlyosság:** LOW
- **Hely:** [src/Http/Controllers/MediaController.php:115-139](src/Http/Controllers/MediaController.php#L115-L139), [src/Jobs/PruneExpiredData.php:111-114](src/Jobs/PruneExpiredData.php#L111-L114).
- **Leírás:**
  - A supervisor bármely (még nem létező) `checkpoint_id`-ra feltölthet fotót. Ezt a betanítás offline sorrendje indokolja.
  - Ha a pont soha nem jön létre, a fájl és a `media` sor örökre megmarad, mert az adatmegőrzés csak a `Scan` / `Incident` tulajdonosú fotókat törli.
  - A soft-delete-elt ponthoz (`Checkpoint::query()->find` nem látja) bármely supervisor új fotót tölthet fel.
- **Miért probléma:** Kontrollálatlan tárhelynövekedés hitelesített hívótól, és árva fájlok.
- **Következmény:** Tárhely-felhasználás (OWASP API4), elhanyagolható adatbiztonsági hatással.
- **Megsértett követelmény:** Audit szempont: *Média*.
- **Javítási irány:**
  - árva pontfotók takarítása (pl. N nap után, ha nincs ilyen pont);
  - a `Checkpoint::withTrashed()` használata a jogosultság-ellenőrzésnél.
- **Bizonyosság:** magas.

### WP-BE-016 – A fotó letöltésénél hiányzik az `X-Content-Type-Options: nosniff`

- **Súlyosság:** LOW
- **Hely:** [src/Http/Controllers/MediaController.php:80-83](src/Http/Controllers/MediaController.php#L80-L83).
- **Leírás:** A fájl `Content-Type: image/jpeg`-gel, token nélkül, az alkalmazás saját domainjén jön (aláírt URL). A feltöltés MIME-ellenőrzése a `finfo`-é, amely a fájl elejét nézi, ezért egy JPEG–HTML poliglott fájl átjuthat.
- **Miért probléma:** A modern böngészők `image/jpeg`-et nem futtatnak HTML-ként, de a `nosniff` olcsó, mélységi védelem, amely az ilyen tartalomtípus-trükköket kizárja.
- **Következmény:** Elméleti XSS-kockázat a gazda domainjén.
- **Megsértett követelmény:** OWASP API8 (biztonsági beállítás).
- **Javítási irány:** `X-Content-Type-Options: nosniff` és `Content-Disposition: inline; filename=…` a fotó és a PDF letöltésénél.
- **Bizonyosság:** közepes.

### WP-BE-017 – A láncon kívüli (gap) bejegyzések HMAC-ellenőrzés nélkül tárolódnak

- **Súlyosság:** LOW
- **Hely:** [src/Chain/ChainProcessor.php:91-94](src/Chain/ChainProcessor.php#L91-L94), [src/Chain/ChainProcessor.php:132-139](src/Chain/ChainProcessor.php#L132-L139).
- **Leírás:** A `verify()` először a `seq`-et nézi. A `gap` bejegyzés HMAC-ellenőrzés nélkül eltárolódik a saját `seq` helyén, és ott vár. Eszköztokennel (a titok nélkül) bármely `seq`-re tetszőleges bejegyzés tárolható: kötegenként 200, percenként 240 kérés. A valódi bejegyzés később felülírja (`replaceRejected`), a többit a megőrzés 31 nap után törli.
- **Miért probléma:** A specifikáció szerint a gap bejegyzés „eltárolódik”, de a tárolás korlát nélküli, és hitelesítetlen tartalmú.
- **Következmény:** Tárhely-felhasználás (percenként kb. 48 000 sor egy tokennel), és a diagnosztikában zajként megjelenő hamis gap-ek.
- **Megsértett követelmény:** OWASP API4. A specifikáció *Szerveroldali ellenőrzés* 1. pontja ezt megengedi.
- **Javítási irány:** A gap bejegyzésre is HMAC-ellenőrzés tárolás előtt (a hamisat ne tárolja, csak jelezze). Felső korlát a várakozó bejegyzések számára, illetve a `last_seq`-től való távolságra.
- **Bizonyosság:** magas.

### WP-BE-018 – Minden push az összes ki nem vetített bejegyzést és az összes hiányzó fotót újra lekérdezi, korlát nélkül

- **Súlyosság:** LOW
- **Hely:** [src/Chain/ChainProcessor.php:191-213](src/Chain/ChainProcessor.php#L191-L213).
- **Leírás:**
  - A `projectPending()` az eszköz minden `projected_at = null` bejegyzését betölti és újrapróbálja, azokat is, amelyek soha nem lesznek alkalmazhatók (pl. hivatkozásuk véglegesen hiányzik).
  - A `missingMedia()` az eszköz összes fel nem töltött fotójának ID-ját visszaadja.
  - Egyiken sincs korlát.
- **Miért probléma:** A költség az elakadt bejegyzések és fotók számával nő, és a zárolt tranzakcióban fut.
- **Következmény:** Lassuló push egy hosszú ideig hibás eszköznél; nagy válaszméret.
- **Megsértett követelmény:** Audit szempont: *Teljesítmény*.
- **Javítási irány:**
  - korlát vagy lapozás a `media_missing`-re;
  - a vissza nem térő hivatkozású bejegyzések (pl. N próbálkozás vagy nap után) jelölése és kizárása;
  - a `chain_entries (device_id, projected_at)` index már megvan.
- **Bizonyosság:** magas a mintára, alacsony a gyakorlati hatásra.

### WP-BE-019 – Az ütemezett jobok egyszerre több szerveren, és egymást átfedve is futhatnak

- **Súlyosság:** LOW
- **Hely:** [src/WaypostServiceProvider.php:170-175](src/WaypostServiceProvider.php#L170-L175), [src/Jobs/SendDueReports.php:22](src/Jobs/SendDueReports.php#L22).
- **Leírás:** A négy ütemezett job nem kapott `onOneServer()` / `withoutOverlapping()` beállítást, és a jobok nem `ShouldBeUnique`-ek. Több alkalmazásszerveren mindegyik ütemező elindítja őket. A kettős küldést a `reports` és a `no_data_alerts` egyedi indexe megakadályozza, de a PDF-készítés (512M, másodpercek) és a `PruneExpiredData` párhuzamosan, kétszer fut.
- **Miért probléma:** Felesleges terhelés, és a párhuzamos takarításnál egymást kerülgető törlések.
- **Következmény:** Teljesítményromlás többszerveres telepítésnél.
- **Megsértett követelmény:** Audit szempont: *Teljesítmény*.
- **Javítási irány:** `->onOneServer()->withoutOverlapping()` az ütemezésben, `ShouldBeUnique` a `SendPeriodReport`-on a (telephely, típus, időszak) kulccsal. A README-ben jelezni, hogy a cache drivernek zárolást kell támogatnia.
- **Bizonyosság:** magas.

### WP-BE-020 – Ha a több címzett egyikénél bukik a küldés, az újrapróbálás a már kiküldött címzetteknek is újra elküldi

- **Súlyosság:** LOW
- **Hely:** [src/Reports/ReportSender.php:89-99](src/Reports/ReportSender.php#L89-L99), [src/Jobs/SendPeriodReport.php:30](src/Jobs/SendPeriodReport.php#L30).
- **Leírás:** A címzettenkénti küldés egy ciklusban fut. Ha a k-adik címzettnél kivétel jön, a sor törlődik, és a job (legfeljebb 3-szor) elölről kezdi, így az első k−1 címzett ismét megkapja a levelet.
- **Miért probléma:** A `decisions.md` „Egyszer” elve (422. sor) a jelentésre szól; a részleges hiba ezt sérti.
- **Következmény:** Duplikált jelentések egyes címzetteknél.
- **Megsértett követelmény:** Specifikáció, *Jelentések* (egy kiküldött jelentés nem megy ki újra); `decisions.md` „A műszakjelentés (M6-05)”.
- **Javítási irány:** A már kiküldött címzettek tárolása a `reports` sorban, és újrapróbáláskor csak a maradékra küldeni; vagy címzettenként külön, egyedi (`report_id`, `address`) job.
- **Bizonyosság:** magas.

### WP-BE-021 – A pull egy lapja minden táblából 501 sort, az útvonalverzióknál a pontjaikkal együtt tölt be

- **Súlyosság:** LOW
- **Hely:** [src/Sync/Pull.php:88](src/Sync/Pull.php#L88), [src/Sync/Pull.php:156-170](src/Sync/Pull.php#L156-L170).
- **Leírás:**
  - Lapozáskor a pull táblánként `PAGE_SIZE + 1` jelöltet olvas, és ebből választja ki a lapra kerülőket.
  - A `route_versions` a `points`-szal, a `users` a `sites`-szal eager-loadol.
  - Az első teljes pull így 8 × 501 modellt és a hozzájuk tartozó pontokat tölti be, hogy legfeljebb 500-at adjon vissza.
- **Miért probléma:** Memória- és időigény, amely a telephely méretével nő. A jelenlegi, várhatóan kis telephelyeken ez nem gond.
- **Következmény:** Lassú első pull nagy telephelyen.
- **Megsértett követelmény:** Audit szempont: *Teljesítmény*. A specifikáció 500-as lapot ír elő, ezt a kód betartja.
- **Javítási irány:** Először csak a kulcsok (`updated_at`, `id`) és a méretek olvasása, a lapra kerülő rekordok teljes betöltése ezután.
- **Bizonyosság:** közepes.

### WP-BE-022 – A pull `SETTLE_MS` várakozása feltételezés, nem garancia

- **Súlyosság:** LOW
- **Hely:** [src/Sync/Pull.php:46](src/Sync/Pull.php#L46), [src/Sync/Pull.php:78](src/Sync/Pull.php#L78).
- **Leírás:** A pull az utolsó 5 másodperc változásait nem adja ki, hogy a még nem commitolt tranzakciók `updated_at`-je ne essen a kurzor mögé. Ha egy tranzakció 5 másodpercnél tovább fut, vagy a több alkalmazásszerver órája 5 másodpercnél jobban eltér, egy változás végleg kimaradhat a pullból. Hosszú tranzakciót okozhat egy nagy push PostgreSQL-en, lásd WP-BE-006.
- **Miért probléma:** Kimaradt konfigurációs változás: a telefon régi menetrend vagy PIN szerint dolgozik, amíg a rekord újra nem változik.
- **Következmény:** Csendes eltérés a szerver és az eszköz között.
- **Megsértett követelmény:** Specifikáció, *Pull* (a kurzor szerinti lapozás teljes), Q3.
- **Javítási irány:**
  - a README-ben rögzíteni (NTP, rövid tranzakciók);
  - opcionálisan a `SETTLE_MS` konfigurálhatóvá tétele;
  - vagy egy monoton, adatbázis által kiosztott változásszámláló a kurzorhoz.
- **Bizonyosság:** közepes.

### WP-BE-023 – Az `ext-fileinfo` nincs a függőségek között, pedig a feltöltés MIME-ellenőrzése rá épül

- **Súlyosság:** LOW
- **Hely:** [composer.json:9](composer.json#L9) (csak az `ext-gd` szerepel), [src/Http/Controllers/MediaController.php:96](src/Http/Controllers/MediaController.php#L96).
- **Leírás:** Az `UploadedFile::getMimeType()` a Symfony `FileinfoMimeTypeGuesser`-ét használja. `fileinfo` nélkül minden feltöltés „Csak JPEG fájl tölthető fel.” hibát kap.
- **Miért probléma:** Csendes funkcióvesztés egy minimalista PHP-telepítésen; a Composer nem figyelmeztet.
- **Következmény:** A fotófeltöltés nem működik.
- **Megsértett követelmény:** Audit szempont: *Függőségek*.
- **Javítási irány:** `"ext-fileinfo": "*"` a `require`-be.
- **Bizonyosság:** magas.

### WP-BE-024 – Nincs `.gitattributes` (export-ignore), LICENSE fájl és teljes Composer-metaadat

- **Súlyosság:** LOW
- **Hely:** a repó gyökere; [composer.json:5](composer.json#L5).
- **Leírás:**
  - A skeletonban a `.gitattributes` a teszteket, a workbenchet és a fejlesztői fájlokat kizárja a dist-csomagból; itt nincs ilyen fájl, ezért a `composer require` a `tests/`, `workbench/`, `docker/`, `docs/`, `scripts/` könyvtárat és a `compose.yaml`-t is letölti.
  - Nincs `.editorconfig`.
  - Nincs LICENSE fájl, a `license` értéke „proprietary”.
  - Nincs `authors`, `keywords`, `homepage` / `support`.
- **Miért probléma:**
  - Nagyobb csomag a gazdában, és fejlesztői Docker-konfiguráció a vendor alatt.
  - A „proprietary” licenc mellett a felhasználási feltételek nincsenek leírva.
- **Következmény:** Kisebb terjesztési és jogi tisztázatlanság.
- **Megsértett követelmény:** Audit szempont: *Laravel package skeleton megfelelés*.
- **Javítási irány:** `.gitattributes` `export-ignore` sorokkal, `.editorconfig`, LICENSE (vagy hivatkozás a feltételekre), teljesebb `composer.json`.
- **Bizonyosság:** magas.

### WP-BE-025 – A `Summarizer::summarize()` túl sok felelősséget visz egy metódusban

- **Súlyosság:** LOW
- **Hely:** [src/Reports/Summarizer.php:79-231](src/Reports/Summarizer.php#L79-L231).
- **Leírás:** Egy kb. 150 soros metódus végzi:
  - a menetrend kibontását a várt körökre;
  - a körök napokhoz rendelését;
  - a párosítást;
  - a sorok építését;
  - a műszakra szűrést;
  - az események és a leolvasások összegyűjtését;
  - a hiányossági okok összeállítását.

  Közben több zárt (closure) segédfüggvényt is definiál.
- **Miért probléma:** Az SRP és a KISS sérül. A metódus nehezen olvasható, és a változtatás kockázatos (a jelentések a termék fő kimenete). A tesztek (Reports/*) jól lefedik, ezért a kockázat jelenleg kezelt.
- **Következmény:** Nehezebb karbantartás; a 2. lépcső új jelentéstípusainál nő a hibakockázat.
- **Megsértett követelmény:** Audit szempont: *Kódminőség (SOLID, KISS)*.
- **Javítási irány:** A várt körök (`ExpectedRounds`) és a sorépítés (`RoundRowBuilder`) kiemelése saját osztályba, a meglévő tesztekkel mint biztonsági hálóval.
- **Bizonyosság:** magas (minőségi megállapítás).

### WP-BE-026 – Az aláírt fotólink minden fotónál újra lekérdezi a telephely megőrzési idejét (N+1)

- **Súlyosság:** LOW
- **Hely:** [src/Http/SignedUrls.php:35](src/Http/SignedUrls.php#L35) → [src/Reports/Retention.php:35-44](src/Reports/Retention.php#L35-L44).
- **Leírás:** A `Retention::until()` minden hívásnál a `Retention::days()`-t hívja, ami egy `report_subscriptions` lekérdezés. Egy jelentés minden fotójára egy plusz lekérdezés fut.
- **Miért probléma:** Felesleges adatbázis-forgalom, amely a fotók számával nő.
- **Következmény:** Lassabb jelentéskészítés (kicsi hatás).
- **Megsértett követelmény:** Audit szempont: *Teljesítmény (N+1)*.
- **Javítási irány:** A napok egyszeri kiszámolása jelentésenként, és átadása (`Retention::until($site, $time, $days)`).
- **Bizonyosság:** magas.

### WP-BE-027 – A service provider az `auth.guards.waypost` kulcsot feltétel nélkül felülírja

- **Súlyosság:** LOW
- **Hely:** [src/WaypostServiceProvider.php:75](src/WaypostServiceProvider.php#L75).
- **Leírás:** A `config()->set('auth.guards.waypost', ['driver' => 'waypost'])` a gazda esetleges, azonos nevű guardját csendben lecseréli.
- **Miért probléma:** A csomag „nem nyúl a gazda dolgaihoz” elvével ütközik egy (valószínűtlen) névütközésnél.
- **Következmény:** A gazda azonos nevű guardja megszűnik.
- **Megsértett követelmény:** Specifikáció, *A csomag szabályai* (a gazda erőforrásainak érintetlensége); audit szempont: *Architektúra*.
- **Javítási irány:** Ha a kulcs már létezik és más a drivere, kivétel vagy figyelmeztetés; vagy egy konfigurálható guardnév.
- **Bizonyosság:** magas (kis valószínűség).

### WP-BE-028 – A README-ből hiányoznak üzemeltetési előfeltételek

- **Súlyosság:** LOW
- **Hely:** [README.md:45-47](README.md#L45-L47).
- **Leírás:** A README leírja az ütemezőt, a queue workert, az `APP_URL`-t és az 512M-es PDF-memóriát. Hiányzik viszont:
  - az `APP_KEY` cseréjének hatása: titkosított eszköztitkok és idempotencia-válaszok, `APP_PREVIOUS_KEYS`;
  - a szerveróra szinkronja (NTP), amelyre a `server_time`, a `TrustedTime` és a `SETTLE_MS` épül;
  - a `TrustProxies`, amelytől a per-IP rate limit függ;
  - a mentés (adatbázis és privát lemez együtt);
  - a frissítési eljárás (`waypost:install` újrafuttatása új migrációkért);
  - a queue worker `memory_limit`-je és `timeout`-ja a PDF-hez.
- **Miért probléma:** A gazda üzemeltetője ezek nélkül könnyen olyan környezetet állít be, amelyben az eszközök tokenje vagy a lánc ellenőrzése elromlik. Például `APP_KEY`-csere után minden eszköz HMAC-ellenőrzése bukik, mert a titok nem fejthető vissza.
- **Következmény:** Üzemeltetési hibák az M7 próbaidőszakban.
- **Megsértett követelmény:** Audit szempont: *Dokumentáció*; M7-01 (telepítési leírás).
- **Javítási irány:** „Üzemeltetés” fejezet a README-ben az M7-01 részeként.
- **Bizonyosság:** magas.

### WP-BE-029 – Nincs típuslefedettség-mérés, ahogy a skeletonban van

- **Súlyosság:** LOW
- **Hely:** [composer.json](composer.json) `require-dev` (nincs `pestphp/pest-plugin-type-coverage`).
- **Leírás:** A skeleton `test:types` lépése a típuslefedettséget is méri. Itt a PHPStan 8-as szint a legtöbbet lefedi, de a hiányzó paraméter- és visszatérési típusokat nem kényszeríti ki.
- **Miért probléma:** Kis eltérés a skeleton minőségkapujától.
- **Következmény:** Elhanyagolható. A kódban szúrópróbaszerűen minden vizsgált metódus típusos volt.
- **Megsértett követelmény:** Audit szempont: *Laravel package skeleton megfelelés*.
- **Javítási irány:** `pest-plugin-type-coverage` és `composer test:types` (`--min=100`), a CI-ben is.
- **Bizonyosság:** magas.

---

## INFO

### WP-BE-030 – A kanonikus JSON tömbként dolgozik: `{}` és `[]`, illetve a nem ASCII kulcsok sorrendje eltérhet az RFC 8785-től

- **Súlyosság:** INFO
- **Hely:** [src/Chain/CanonicalJson.php:35-43](src/Chain/CanonicalJson.php#L35-L43), [src/Models/ChainEntry.php:54](src/Models/ChainEntry.php#L54) (`payload` → `array` cast).
- **Leírás:**
  - A PHP tömbje az üres objektumot és az üres listát nem különbözteti meg; a `{}` `[]`-ként, a `{"0":…,"1":…}` listaként íródna ki.
  - A `ksort(SORT_STRING)` bájtsorrendben rendez, a JCS UTF-16 kódegységek szerint; nem ASCII kulcsoknál a kettő eltérhet.
  - A jelenlegi láncsémák payloadjaiban nincs üres objektum és nincs nem ASCII kulcs, és a közös tesztvektorok átmennek, ezért ma ez nem hiba.
- **Miért probléma:** Egy jövőbeli payloadmező (pl. a 2. lépcső `sos` típusa egy szabad objektummal) HMAC-eltérést okozhat a JS és a PHP között.
- **Következmény:** Jövőbeli `bad_hmac` a valódi bejegyzésekre.
- **Megsértett követelmény:** Specifikáció, *Bejegyzés és lenyomat* (RFC 8785); nem sérül, amíg a sémák így maradnak.
- **Javítási irány:**
  - a sémákban rögzíteni („nincs üres vagy szabad kulcsú objektum”);
  - vagy objektumként dekódolni (`json_decode` `stdClass`-ra) a lenyomat számításához;
  - tesztvektor `{}`-ra és egy ékezetes kulcsra.
- **Bizonyosság:** magas a viselkedésre; a mai hatás nulla.

### WP-BE-031 – A telephelyi telefon megkapja a PIN-hasheket, amelyek offline visszafejthetők (a specifikáció előírása)

- **Súlyosság:** INFO
- **Hely:** [src/Sync/Pull.php:156](src/Sync/Pull.php#L156), `Entities::user()` (PIN-ellenőrző a telephelyi telefonnak).
- **Leírás:** 10⁶ lehetséges PIN × 100 000 PBKDF2-iteráció egy GPU-n percek alatt végigpróbálható. A telefon adatbázisához hozzáférő a telephely minden PIN-jét megismerheti, a supervisorokéit is.
- **Miért probléma:** A specifikáció (*PIN-kód*) tudatosan választotta az offline ellenőrzést; a kockázat ebből következik, és nincs a dokumentációban.
- **Következmény:** Egy ellopott telefonból a telephely PIN-jei, így a supervisor mód is, megszerezhetők. A WP-BE-001-gyel együtt ez több telephely átvételéhez is vezethet.
- **Megsértett követelmény:** Nem sérül; tervezési kockázat.
- **Javítási irány:** A dokumentációban rögzíteni. A telefon elvesztésekor az eszköz visszavonása mellett a telephely PIN-jeinek cseréje legyen az előírt eljárás.
- **Bizonyosság:** magas.

### WP-BE-032 – A fotók aláírt linkje token nélkül, 31–62 napig érvényes (a specifikáció előírása)

- **Súlyosság:** INFO
- **Hely:** [src/Http/SignedUrls.php:33-41](src/Http/SignedUrls.php#L33-L41), [routes/api.php:56-59](routes/api.php#L56-L59).
- **Leírás:** Egy továbbított e-mail a fotók linkjét a megőrzés végéig bárkinek megnyitja.
- **Miért probléma:** A specifikáció *Tartalom* pontja szerint így van; ez adatvédelmi szempont, nem hiba.
- **Következmény:** A fotók elérhetők a címzetteken kívül is, ha a levelet továbbítják.
- **Megsértett követelmény:** Nem sérül.
- **Javítási irány:** Dokumentálni; a 2. lépcsőben megfontolható egy rövidebb élettartam és egy belépéshez kötött megtekintő.
- **Bizonyosság:** magas.

### WP-BE-033 – Az eszköztitok és az idempotencia-válaszok a gazda `APP_KEY`-ével titkosítottak

- **Súlyosság:** INFO
- **Hely:** [src/Models/Device.php:56](src/Models/Device.php#L56), `src/Models/IdempotencyKey.php` (`response_body`, `encrypted` cast).
- **Leírás:** Az `APP_KEY` cseréjekor, ha a régi kulcs nincs az `APP_PREVIOUS_KEYS`-ben, minden eszköz titka visszafejthetetlen lesz. Ekkor minden push HMAC-ellenőrzése bukik, vagy kivétel jön, és minden eszközt újra hozzá kell rendelni.
- **Miért probléma:** Helyes döntés (a titok nem lehet nyílt), de nagy hatású üzemeltetési függőség.
- **Következmény:** Teljes szinkronleállás egy kulcscsere után.
- **Megsértett követelmény:** Nem sérül (specifikáció: `secret (encrypted cast)`).
- **Javítási irány:** A README-ben (WP-BE-028), és a visszafejtési hiba felismerhető hibakóddal jelenjen meg.
- **Bizonyosság:** magas.

### WP-BE-034 – A polimorf tulajdonos teljes osztálynévként tárolódik

- **Súlyosság:** INFO
- **Hely:** [src/Chain/Projector.php:60-63](src/Chain/Projector.php#L60-L63), [src/Http/Controllers/MediaController.php:135](src/Http/Controllers/MediaController.php#L135), `idempotency_keys.owner_type`.
- **Leírás:** A `media.owner_type` és az `idempotency_keys.owner_type` értéke pl. `Waypost\Models\Scan`. Nincs `Relation::enforceMorphMap()`, és ezt a gazda globális beállítása miatt egy csomag nem is tehetné meg biztonságosan.
- **Miért probléma:** Egy névtér-átnevezés adatmigrációt igényel. Ha a gazda `enforceMorphMap()`-et használ, a csomag modelljeire `ClassMorphViolationException` jöhet.
- **Következmény:** Jövőbeli refaktorálási költség és egy lehetséges telepítési inkompatibilitás.
- **Megsértett követelmény:** Nem sérül.
- **Javítási irány:** A csomag saját morph aliasait a `Relation::morphMap()`-pel regisztrálni (nem `enforce`), és a README-ben jelezni.
- **Bizonyosság:** közepes (a gazda `enforceMorphMap()` esetét nem próbáltam ki).

### WP-BE-035 – Nincs naplózás és megfigyelhetőség a kritikus eseményekre

- **Súlyosság:** INFO
- **Hely:** a teljes `src/` (nincs `Log::` / `logger()` hívás).
- **Leírás:**
  - A láncelutasítás (`fork`, `bad_hmac`), a halasztott vetület, a jelentés foglalása küldés nélkül, az idempotencia „busy” állapota és a takarítás eredménye sehol nem naplózódik.
  - A diagnosztika (`device_diagnostics`) és a jelentés „hibás bejegyzések” sora ad némi rálátást.
- **Miért probléma:** Az M7 próbaidőszakban a hibák okát nehéz lesz utólag kideríteni.
- **Következmény:** Lassabb hibakeresés.
- **Megsértett követelmény:** Audit szempont: *Biztonság (API9/API10)*, *Dokumentáció*. A specifikáció nem írja elő.
- **Javítási irány:** Strukturált `Log::warning` / `info` a felsorolt helyeken, egy konfigurálható csatornán (`waypost.log_channel`).
- **Bizonyosság:** magas.

### WP-BE-036 – A konfigurációs listák nem lapoznak

- **Súlyosság:** INFO
- **Hely:** pl. [src/Http/Controllers/CheckpointController.php:27](src/Http/Controllers/CheckpointController.php#L27), és ugyanígy a többi `index()`, kivéve `GET /reports`.
- **Leírás:** A `GET /checkpoints`, `/routes`, `/users` stb. a hívó összes látható rekordját visszaadja.
- **Miért probléma:** A várható méret (telephelyenként néhány tucat rekord) mellett nem gond; sok telephelyes supervisornál nőhet.
- **Következmény:** Nagy válasz (OWASP API4, alacsony kockázat).
- **Megsértett követelmény:** Nem sérül (a sémák nem írnak elő lapozást).
- **Javítási irány:** A 2. lépcsőben, új végpontként vagy opcionális paraméterrel.
- **Bizonyosság:** magas.

### WP-BE-037 – A 0.2.0 előtt a meglévő migrációk módosultak; ezentúl csak új migráció jöhet

- **Súlyosság:** INFO
- **Hely:** `docs/decisions.md` 355. sor („Az oszlopok … a meglévő migrációkba kerültek, mert éles telepítés még nincs”); `database/migrations/2026_10_28_*`.
- **Leírás:** Az `upload_closed_at` és a `projected_at` oszlop a már kiadott (0.1.x) migrációkba került. Aki a 0.1.x-et telepítette, az `waypost:install` újrafuttatásakor ezeket nem kapja meg, mert a parancs a már lefutott migrációt nem másolja újra.
- **Miért probléma:** Éles telepítés még nincs, ezért ma nincs hatása. Ha valaki a 0.1.x-et próbára telepítette, a 0.2.0-ra frissítés sémaeltérést ad.
- **Következmény:** Egy esetleges próbatelepítésnél újratelepítés kell.
- **Megsértett követelmény:** Nem sérül.
- **Javítási irány:** A CHANGELOG-ban jelezni („0.1.x → 0.2.0: friss telepítés szükséges”). A 0.2.0 után minden sémaváltozás új migrációban jöjjön; ez a `CLAUDE.md`-ben is szabály lehet.
- **Bizonyosság:** magas.

### WP-BE-038 – A tokenek `last_used_at` mezője soha nem frissül

- **Súlyosság:** INFO
- **Hely:** [src/Auth/TokenGuard.php:86-96](src/Auth/TokenGuard.php#L86-L96).
- **Leírás:** A saját guard nem frissíti a Sanctum `last_used_at` mezőjét. (A `devices.last_seen_at` frissül a pullnál és a pushnál.)
- **Miért probléma:** Elhagyott vagy ellopott supervisor tokenek nem ismerhetők fel; inaktivitási lejárat sem építhető rá.
- **Következmény:** Gyengébb tokenhigiénia (lásd WP-BE-005).
- **Megsértett követelmény:** Nem sérül.
- **Javítási irány:** Ritkított frissítés (pl. legfeljebb percenként egyszer), `timestamps = false`-szal.
- **Bizonyosság:** magas.

### WP-BE-039 – Egy előfizetés lemondása azonnal rövidíti a megőrzési időt

- **Súlyosság:** INFO
- **Hely:** [src/Reports/Retention.php:35-44](src/Reports/Retention.php#L35-L44).
- **Leírás:** A havi előfizetés törlése után a megőrzés 62 napról 31 napra csökken, és a következő éjszakai takarítás a 31–62 napos adatot törli.
- **Miért probléma:** A specifikáció betű szerint így szól („…leghosszabb előfizetett időszak”). Egy véletlen lemondás viszont visszafordíthatatlan adatvesztést okoz.
- **Következmény:** Váratlan adattörlés.
- **Megsértett követelmény:** Nem sérül; üzleti döntést igényel, ezért nem találtam ki szabályt.
- **Javítási irány:** Termékdöntés: türelmi idő (a megőrzés a lemondás után még egy időszakig a régi), vagy a felületen figyelmeztetés.
- **Bizonyosság:** magas.

### WP-BE-040 – Az inaktív fiók külön üzenete a helyes jelszó megerősítése

- **Súlyosság:** INFO
- **Hely:** [src/Http/Controllers/AuthController.php:34](src/Http/Controllers/AuthController.php#L34).
- **Leírás:** Inaktív fióknál helyes jelszóval „Ez a fiók inaktív…” a válasz, hibással „Hibás e-mail-cím vagy jelszó.”. Ez elárulja, hogy a jelszó helyes.
- **Miért probléma:** Dokumentált döntés (`decisions.md` 218–219. sor); kis kockázat, mert az inaktív fiókkal nem lehet belépni. Ha azonban a fiókot újra aktiválják, a megerősített jelszó használható.
- **Következmény:** Minimális.
- **Megsértett követelmény:** Nem sérül.
- **Javítási irány:** Elfogadható. Ha szigorítanának: újraaktiváláskor kötelező jelszócsere.
- **Bizonyosság:** magas.

### WP-BE-041 – 2. lépcső: egy új bejegyzéstípus kódváltozást kér a `Projector`-ban (a jelenlegi működés helyes)

- **Súlyosság:** INFO
- **Hely:** [src/Chain/Projector.php:43-50](src/Chain/Projector.php#L43-L50).
- **Leírás:**
  - A `match` kimerítő az `ChainEntryType` enumra.
  - Új típus (pl. `sos`) esetén az enumot, a sémát és a `Projector`-t is bővíteni kell, különben a `match` `UnhandledMatchError`-t dob, ami a WP-BE-008 szerint az egész pusht megakasztja.
  - Az enum és a séma szöveges oszlopon van, ezért adatbázis-migráció nem kell.
- **Miért probléma:** Nem hiba. A sorrend (előbb a szerver, utána az app) fontos, és ezt érdemes rögzíteni.
- **Következmény:** Ha az app előbb küld új típust, mint ahogy a szerver ismeri, a séma elutasítja (422), ami helyes.
- **Megsértett követelmény:** Nem sérül (specifikáció, *A 2. lépcső előkészítése*).
- **Javítási irány:** A `decisions.md`-ben rögzíteni a bevezetési sorrendet; egy `default => true` (tárolás vetület nélkül) ág megfontolható.
- **Bizonyosság:** magas.

### WP-BE-042 – Az M7 (próbaidőszak, telepítési leírás) nem készült el; a README MySQL 8.4-et ír, a specifikáció MySQL 8-at

- **Súlyosság:** INFO
- **Hely:** `../waypost/docs/tasks.md` (M7-01 és M7 nincs kipipálva); [README.md:13](README.md#L13).
- **Leírás:**
  - A csomag kódja kész (M2, M4–M6 kipipálva), de nincs eszközzel végzett, valós próbaidőszak.
  - A telepítési próbát (`scripts/install-smoke.sh`) ebben az auditban hálózat hiányában nem futtattam.
  - A README minimumként MySQL 8.4-et ír, a specifikáció MySQL 8-at; a 8.0-n senki nem próbálta (a generált oszlop és a `datetime(3)` 8.0-n is működik, de nincs bizonyíték).
- **Miért probléma:** Az éles használatra való alkalmasság (M7: 7 éjszaka adatvesztés nélkül) nincs igazolva.
- **Következmény:** A fenti megállapítások egy része (WP-BE-003, -004, -022) csak valós terhelés alatt jönne elő.
- **Megsértett követelmény:** Specifikáció, *Mérföldkövek* M7.
- **Javítási irány:**
  - az M7-01 elvégzése;
  - a támogatott MySQL-verzió egyeztetése (8.0 próba vagy a specifikáció pontosítása).
- **Bizonyosság:** magas.

---

## Prioritási lista

| # | ID | Súlyosság | Mikor | Indok |
| --- | --- | --- | --- | --- |
| 1 | WP-BE-001 | HIGH | az M7 előtt, azonnal | a telephelyi korlát megkerülése, fiókátvétel eszköztokennel |
| 2 | WP-BE-002 | MEDIUM | az M7 előtt | bérlők közti adatkeveredés a vetületben és a jelentésben |
| 3 | WP-BE-003 | MEDIUM | az M7 előtt | az outbox akár 24 órás elakadása; az M5 elfogadási feltétele |
| 4 | WP-BE-004 | MEDIUM | az M7 előtt | a heti vagy havi jelentés csendes, végleges elvesztése; az M7 elfogadási feltétele |
| 5 | WP-BE-005 | MEDIUM | az M7 előtt | a jelszócsere nem zárja ki a régi tokent |
| 6 | WP-BE-028 | LOW | az M7-01 részeként | üzemeltetési előfeltételek (APP_KEY, NTP, TrustProxies) |
| 7 | WP-BE-007 | MEDIUM | az M7 alatt | CI MySQL-re és PostgreSQL-re |
| 8 | WP-BE-006 | MEDIUM | PostgreSQL-telepítés előtt | hiányzó indexek |
| 9 | WP-BE-008 | LOW | az M7 alatt | „mérgező” bejegyzés elleni savepoint |
| 10 | WP-BE-013 | LOW | a következő sémaverzióval | 500 helyett 422, az outbox elakadása |
| 11 | WP-BE-010, WP-BE-011, WP-BE-012 | LOW | a következő kiadásban | PIN-verseny, CPU-erősítés, login-időzítés |
| 12 | WP-BE-014 – WP-BE-020 | LOW | a következő kiadásban | média, lánc, ütemező, kettős küldés |
| 13 | WP-BE-023, WP-BE-024, WP-BE-029 | LOW | a következő kiadásban | csomagolás és skeleton-megfelelés |
| 14 | WP-BE-021, WP-BE-022, WP-BE-025 – WP-BE-027 | LOW | tervezetten | teljesítmény és karbantarthatóság |
| 15 | WP-BE-030 – WP-BE-042 | INFO | dokumentálás, termékdöntés | tervezési kockázatok és 2. lépcső |
