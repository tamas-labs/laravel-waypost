# Laravel Waypost – szigorú, teljes körű kódminőségi és architekturális audit

## 1. A feladat célja

Végezz teljes körű, szigorú szakmai auditot a `laravel-waypost` csomagon.

A csomag a Waypost rendszer Laravel 13 alapú backend komponense, PHP 8.4 használatával.

A fejlesztés befejeződött. A feladatod nem a továbbfejlesztés és nem a refaktorálás, hanem annak objektív megállapítása, hogy a jelenlegi implementáció:

- megfelel-e a fejlesztői specifikációnak;
- megfelel-e a modern Laravel-csomagfejlesztési konvencióknak;
- megfelelő minőségű, karbantartható és bővíthető-e;
- biztonságosan működik-e;
- megfelelően kezeli-e az adatokat és a kliensalkalmazásokkal folytatott kommunikációt;
- alkalmas-e éles környezetben történő használatra.

Az audit legyen szigorú. Ne elégedj meg azzal, hogy a kód látszólag működik vagy a tesztek sikeresen lefutnak. Vizsgáld meg azt is, hogy a megoldások szakmailag megfelelőek-e.

**FONTOS: az audit során semmilyen forráskódot, konfigurációt, migrációt, tesztet vagy dokumentációt nem módosíthatsz. Kizárólag vizsgálatot és dokumentációt készíthetsz.**

---

## 2. A vizsgálat alapjául szolgáló források

Az audit során az alábbi forrásokat használd.

### 2.1. Waypost fejlesztői specifikáció

Keresd meg és olvasd el a projekt rendelkezésére álló Waypost fejlesztői specifikációját.

Ez az elsődleges funkcionális és architekturális követelményrendszer.

Ne csak a Laravel backendhez közvetlenül kapcsolódó fejezeteket vizsgáld. Értelmezd a mobilalkalmazás működését is, mert a backendnek azzal összhangban kell működnie.

Különösen fontos területek:

- REST API és végpontok;
- hitelesítés és eszközregisztráció;
- eszközazonosítás és jogosultságkezelés;
- offline működés és késleltetett szinkronizáció;
- outbox és idempotens adatfeldolgozás;
- láncolt események és integritásvédelem;
- médiafájlok feltöltése és kezelése;
- műszakok, körök, ellenőrzőpontok és események;
- ütemezések és időkezelés;
- felhasználók, helyszínek és eszközök;
- hibakezelés és API-válaszok;
- adatmegőrzési és konzisztencia-elvárások.

Ha a specifikáció valamely backendkövetelményt nem határoz meg egyértelműen, ezt jelezd. Ne találj ki hiányzó üzleti szabályokat.

### 2.2. Laravel hivatalos Package Skeleton

A vizsgálat egyik fontos referenciaforrása:

https://github.com/laravel/package-skeleton

A repository aktuális állapotát vizsgáld meg, ne kizárólag az emlékezetedre vagy korábbi ismereteidre támaszkodj.

A cél nem a skeleton mechanikus lemásolása, hanem annak ellenőrzése, hogy a `laravel-waypost` csomag követi-e a Laravel hivatalos csomagfejlesztési megközelítését, illetve ahol eltér, ott az eltérés szakmailag indokolt-e.

Vizsgáld meg különösen:

- Composer-csomagstruktúra és autoload;
- PSR-4 névtérhasználat;
- Service Provider felépítése és regisztrációja;
- Laravel package discovery;
- konfigurációk és konfigurációs publikálás;
- migrációk kezelése;
- route-ok regisztrációja és publikálása;
- nyelvi fájlok és erőforrások;
- publikus interfészek és Facade-ok, amennyiben relevánsak;
- Pest és Orchestra Testbench alapú tesztelés;
- Larastan / PHPStan statikus elemzés;
- Laravel Pint kódformázás;
- típuslefedettség és tesztelési gyakorlat;
- Workbench vagy hasonló izolált fejlesztői tesztkörnyezet;
- Composer-scriptek és CI-folyamatok;
- dokumentáció, changelog és csomagkiadási gyakorlat.

Minden eltérésnél vizsgáld meg, hogy:
1. valódi hiányosságról van-e szó;
2. a csomag sajátosságai miatt indokolt-e;
3. okoz-e karbantarthatósági, kompatibilitási vagy tesztelhetőségi problémát.

Ne minősíts hibának egy olyan skeleton-elemet, amelyre a csomagnak ténylegesen nincs szüksége.

### 2.3. Kapcsolódó Waypost-csomagok

Amennyiben a munkakörnyezetben hozzáférsz az alábbi repository-khoz, használd őket a szerződések és integrációk ellenőrzéséhez:

- `waypost`
- `waypost-schema`

Ezeket ne auditáld újra teljes körűen ebben a feladatban. Most a `laravel-waypost` a vizsgálat tárgya.

Ellenőrizd azonban, hogy a Laravel backend:

- az API-szerződéseknek megfelelő adatokat fogad és küld;
- a megfelelő mezőneveket, adattípusokat és enumértékeket használja;
- nem tér el a közös sémától;
- helyesen kezeli a kötelező és opcionális mezőket;
- megfelelő hibaválaszokat ad;
- kompatibilis a mobilalkalmazás szinkronizációs folyamatával.

Ha valamelyik kapcsolódó repository nem érhető el, ezt egyértelműen jelezd. Ne feltételezd, hogy a szerződés megfelelő.

---

## 3. Általános kódminőségi és architekturális vizsgálat

### 3.1. Laravel- és PHP-konvenciók

Ellenőrizd:

- Laravel 13 konvencióinak betartását;
- PHP 8.4 lehetőségeinek megfelelő használatát;
- PSR-12 és releváns PSR-szabványok betartását;
- típusdeklarációk következetes használatát;
- nullable és union típusok helyes alkalmazását;
- return type-ok teljességét;
- readonly és enum típusok indokolt használatát;
- névkonvenciókat;
- namespace-struktúrát;
- függőségek és importok rendezettségét;
- Laravel helper-ek és facade-ok indokolt használatát.

Keresd a felesleges absztrakciókat és a túlzottan bonyolult megoldásokat is.

### 3.2. Clean Code, SOLID, DRY és KISS

Vizsgáld meg:

- Single Responsibility Principle;
- Open/Closed Principle;
- Liskov Substitution Principle;
- Interface Segregation Principle;
- Dependency Inversion Principle;
- DRY;
- KISS;
- Separation of Concerns;
- Law of Demeter.

Keresd különösen:

- túl nagy osztályokat;
- túl nagy metódusokat;
- túl sok felelősséget viselő Service-eket;
- túlterhelt Controller-eket;
- üzleti logikát tartalmazó Model-eket;
- ismétlődő üzleti logikát;
- indokolatlanul mély öröklődési hierarchiákat;
- szükségtelen interfészeket és absztrakciókat;
- túlzottan összetett függőségi gráfot;
- nehezen tesztelhető komponenseket;
- rejtett mellékhatásokat;
- túl sok paramétert igénylő metódusokat.

Ne csak felsorold az elveket sértő eseteket. Magyarázd el, milyen gyakorlati problémát okoznak.

### 3.3. Függőségek és architektúra

Vizsgáld meg a teljes függőségi gráfot.

Keresd:

- körkörös függőségeket;
- rétegek közötti helytelen függőségeket;
- Service–Service ciklusokat;
- Model–Service szoros összekapcsolódást;
- infrastruktúra- és üzleti logika keveredését;
- felesleges közvetett függőségeket;
- túlzottan globális állapotot;
- rejtett függőségeket;
- túlzottan összekapcsolt modulokat.

Értékeld, hogy az architektúra hosszú távon bővíthető és karbantartható-e.

### 3.4. Kódduplikáció

Keress:

- azonos vagy közel azonos metódusokat;
- ismétlődő validációs logikát;
- ismétlődő adatlekérdezéseket;
- ismétlődő jogosultságellenőrzéseket;
- ismétlődő hibakezelést;
- ismétlődő API-válaszformázást;
- azonos üzleti szabályok több helyen történő implementálását.

A duplikációt ne kizárólag szöveges hasonlóság alapján értékeld. Vizsgáld meg, hogy valóban azonos felelősségű logikáról van-e szó.

---

## 4. Laravel Package Skeleton megfelelőségi audit

Ez a fejezet kiemelt fontosságú.

A `laravel-waypost` egy Laravel-csomag, ezért ne kizárólag egy Laravel alkalmazás szempontjából vizsgáld.

Hasonlítsd össze a hivatalos `laravel/package-skeleton` megoldásaival.

### 4.1. Csomagfelépítés

Vizsgáld meg:

- a `composer.json` helyességét;
- a csomag nevét, leírását és metaadatait;
- a PHP és Laravel verziókövetelményeket;
- a production és development függőségek szétválasztását;
- az autoload és autoload-dev beállításokat;
- a PSR-4 struktúrát;
- a Laravel package discovery beállításait;
- a Service Provider regisztrációját;
- a konfigurációs fájlok kezelését;
- a csomag erőforrásainak kezelését.

Ellenőrizd, hogy a csomag telepíthető-e egy másik Laravel alkalmazásba különleges kézi beavatkozás nélkül, amennyiben ez a csomag tervezett felhasználási módja.

### 4.2. Service Provider

Vizsgáld meg:

- a boot és register metódusok felelősségi körét;
- a szolgáltatások regisztrációját;
- a singleton és scoped bindingok indokoltságát;
- a route-ok regisztrációját;
- a konfigurációk betöltését;
- a migrációk regisztrációját;
- a publikálható erőforrásokat;
- a package discovery működését.

A Service Provider ne váljon minden funkciót összefogó központi osztállyá.

### 4.3. Konfigurálhatóság és integrálhatóság

Vizsgáld meg:

- a konfigurációs értékek megfelelő alapértelmezéseit;
- a környezeti változók helyes kezelését;
- a konfigurációs cache kompatibilitását;
- a csomag önálló telepíthetőségét;
- a host alkalmazás konfigurációival való együttműködést;
- az esetleges névütközéseket;
- a csomag által regisztrált route-ok és middleware-ek elkülönítését.

### 4.4. Tesztelhetőség

Vizsgáld meg, hogy a csomag rendelkezik-e megfelelő izolált tesztkörnyezettel.

A hivatalos skeletonban használt eszközök közül különösen:

- Pest;
- Orchestra Testbench;
- Larastan;
- PHPStan;
- Laravel Pint;
- típuslefedettség-ellenőrzés;
- Workbench.

Ellenőrizd, hogy a jelenlegi projektben ezek közül melyek vannak használatban, és a hiányzó elemek közül melyek lennének indokoltak.

Ne követelj meg automatikusan minden skeleton-funkciót. Az eltérések szakmai indoklását kérd számon.

---

## 5. Waypost-specifikus funkcionális audit

A következő területeket a fejlesztői specifikáció alapján ellenőrizd.

### 5.1. API és adatkontraktusok

Vizsgáld meg:

- az endpointok felépítését;
- a HTTP metódusok helyes használatát;
- a route-konvenciókat;
- a request és response struktúrákat;
- a validációt;
- az API-verziózást, ha van;
- a hibaválaszok konzisztenciáját;
- a státuszkódok helyességét;
- a pagination megvalósítását;
- a tömeges adatfeldolgozás korlátait;
- az API-szerződések és a tényleges implementáció egyezését.

Külön figyelj arra, hogy a backend ne adjon vissza olyan adatot, amelyet a mobilalkalmazás nem tud megfelelően feldolgozni.

### 5.2. Hitelesítés és jogosultságkezelés

Vizsgáld meg a teljes hitelesítési folyamatot.

Ellenőrizd:

- a supervisor felhasználók hitelesítését;
- az eszközök regisztrációját;
- az eszköztokenek kezelését;
- a tokenek érvényességét és visszavonását;
- az eszköz és felhasználó kapcsolatát;
- a middleware-eket;
- a policy-kat és gate-eket;
- a szerepköröket és jogosultságokat;
- a helyszínhez és szervezethez kötött hozzáférést;
- az illetéktelen hozzáférés lehetőségét;
- az IDOR jellegű hibákat;
- a jogosultságellenőrzések megkerülhetőségét.

Kiemelten ellenőrizd, hogy egy eszköz vagy felhasználó ne férhessen hozzá más helyszín vagy más szervezet adataihoz.

### 5.3. Offline szinkronizáció és adatkonzisztencia

Ez a rendszer egyik legkritikusabb területe.

Vizsgáld meg:

- a push folyamatot;
- a pull folyamatot;
- az outbox feldolgozását;
- az idempotens műveleteket;
- az ismételt kérések kezelését;
- a részleges hibák kezelését;
- a tranzakciók használatát;
- a konkurens kérések kezelését;
- az adatütközések kezelését;
- a sorrendiség megőrzését;
- a lapozást;
- a tömeges műveletek méretkorlátait;
- a hálózati hibák utáni újrapróbálkozás biztonságát.

Különösen keresd azokat az eseteket, amikor egy kliens ugyanazt a műveletet többször elküldi.

A backendnek ilyen esetekben sem szabad duplikált üzleti eseményeket vagy inkonzisztens adatállapotot létrehoznia.

Vizsgáld meg azt is, hogy egy részben sikeres batch feldolgozás után a kliens megfelelő információt kap-e arról, mely műveletek sikerültek és melyek nem.

### 5.4. Eseménylánc és integritásvédelem

A specifikációban meghatározott láncolt események kezelését különösen szigorúan vizsgáld.

Ellenőrizd:

- az események sorrendiségét;
- a sorszámok kezelését;
- az előző hash ellenőrzését;
- a HMAC-SHA256 ellenőrzést;
- a kanonikus JSON reprezentáció kezelését;
- a hiányzó vagy módosított események felismerését;
- az ismételt események kezelését;
- a konkurens eseménybeküldéseket;
- a tranzakciós konzisztenciát;
- a kulcsok biztonságos kezelését;
- a hibás vagy manipulált adatok elutasítását.

Ne feltételezd, hogy a kliens által küldött hash vagy egyéb integritási adat önmagában megbízható.

Vizsgáld meg, hogy a backend valóban ellenőrzi-e azokat az értékeket, amelyek ellenőrzése a szerver feladata.

### 5.5. Adatbázis és adatmodellezés

Ellenőrizd:

- a migrációk minőségét;
- az idegen kulcsokat;
- az indexeket;
- az egyedi megszorításokat;
- a nullázhatóságot;
- a mezőtípusokat;
- az enumok és státuszok kezelését;
- a törlési szabályokat;
- a soft delete használatának indokoltságát;
- az adatbázis-tranzakciókat;
- a konkurens módosítások kezelését;
- az adatbázis-integritást.

Keresd azokat az eseteket, ahol az alkalmazáskód ellenőrzése helyett adatbázis-szintű constrainttel kellene védeni az adatokat.

Vizsgáld meg, hogy a migrációk biztonságosan futtathatók-e, és nem okozhatnak-e váratlan adatvesztést.

### 5.6. Üzleti logika és állapotgépek

Vizsgáld meg a specifikációban szereplő üzleti folyamatokat:

- műszakok;
- körök;
- ellenőrzőpontok;
- beolvasások;
- manuális beolvasások;
- incidensek;
- műszakzárás;
- ütemezések;
- felhasználók;
- eszközök;
- helyszínek.

Ellenőrizd:

- az állapotátmenetek helyességét;
- a megengedett és tiltott átmeneteket;
- a párhuzamos műveletek kezelését;
- a hibás sorrendben érkező kérések kezelését;
- az üzleti szabályok egyetlen helyen történő érvényesítését;
- a domain logika és az infrastruktúra szétválasztását.

Keresd azokat az eseteket, amikor egy állapotot több, egymástól független helyen lehet módosítani.

### 5.7. Időkezelés és ütemezés

Vizsgáld meg:

- az időzónák kezelését;
- az UTC és helyi idő közötti átváltást;
- a nyári és téli időszámítás kezelését;
- az éjfélen átnyúló ütemezéseket;
- a határidők és időintervallumok kezelését;
- a szerveridő és kliensidő közötti különbségeket;
- a dátumok és időpontok adatbázisban történő tárolását.

Különösen figyelj a DST miatti kétértelmű vagy nem létező időpontokra.

### 5.8. Médiafájlok kezelése

Ellenőrizd:

- a feltöltött fájlok validációját;
- a fájlméret-korlátokat;
- a MIME-típus ellenőrzését;
- a fájlkiterjesztés és tényleges tartalom egyezését;
- a fájlnevek biztonságos kezelését;
- a tárolási útvonalakat;
- a hozzáférés-szabályozást;
- a fájlokhoz tartozó metaadatokat;
- a részleges vagy megszakadt feltöltések kezelését;
- a duplikált feltöltéseket;
- az adatbázis és a fájlrendszer közötti konzisztenciát.

---

## 6. Biztonsági audit

A biztonsági vizsgálat önálló, kiemelt fejezet legyen.

Ellenőrizd:

- OWASP API Security szempontokat;
- SQL injection lehetőségét;
- mass assignment hibákat;
- IDOR és Broken Access Control hibákat;
- nem megfelelő inputvalidációt;
- túlzott adatkiadást;
- tokenek és titkok tárolását;
- naplózásban megjelenő érzékeny adatokat;
- hibaválaszok információszivárgását;
- rate limitinget;
- brute-force elleni védelmet;
- fájlfeltöltési támadási felületeket;
- SSRF és egyéb külső erőforrás-elérési kockázatokat, ahol releváns;
- konfigurációs és környezeti változók kezelését;
- titkos kulcsok és kriptográfiai műveletek kezelését.

Ellenőrizd, hogy a jogosultságellenőrzések minden releváns végponton megtörténnek-e, nem csak a felhasználói felületen vagy a kliensalkalmazásban.

Ne tekints biztonságosnak egy megoldást pusztán azért, mert a mobilalkalmazás normál működése során nem lehet hibás kérést előállítani.

A szervernek a rosszindulatú vagy hibás klienskérésekkel szemben is védekeznie kell.

---

## 7. Teljesítmény és skálázhatóság

Vizsgáld meg:

- N+1 lekérdezéseket;
- felesleges adatbázis-lekérdezéseket;
- hiányzó indexeket;
- túl nagy lekérdezési eredményeket;
- memóriaterhelést;
- batch feldolgozást;
- queue használatát;
- cache használatát;
- lockolási stratégiákat;
- hosszú tranzakciókat;
- konkurens kérések kezelését;
- nagy mennyiségű esemény feldolgozását.

Különösen vizsgáld meg, hogy a szinkronizációs végpontok nagyobb adatmennyiség mellett is megfelelően működnek-e.

Ne javasolj cache-t vagy queue-t pusztán divatból. Csak akkor jelezd hiányosságként, ha a jelenlegi megoldás mérhető vagy jól alátámasztható problémát okoz.

---

## 8. Tesztelési és minőségbiztosítási audit

Vizsgáld meg a tesztelési stratégiát és a tényleges teszteket.

Ellenőrizd:

- Unit tesztek;
- Feature tesztek;
- API tesztek;
- integrációs tesztek;
- adatbázis-tesztek;
- jogosultsági tesztek;
- negatív tesztesetek;
- határértékek;
- konkurens műveletek;
- idempotencia;
- tranzakciós viselkedés;
- hibás bemenetek;
- szinkronizációs hibák;
- eseménylánc integritása.

Külön ellenőrizd, hogy a tesztek valóban a kívánt működést bizonyítják-e, vagy csak az implementáció jelenlegi viselkedését rögzítik.

Keresd a következő problémákat:

- túl kevés teszt;
- hiányzó negatív tesztek;
- túlzott mockolás;
- irreális tesztadatok;
- egymástól függő tesztek;
- nem determinisztikus tesztek;
- tesztek, amelyek nem ellenőrzik az adatbázis tényleges állapotát;
- tesztek, amelyek nem fedik le a specifikáció kritikus eseteit.

Amennyiben a projektben elérhetőek, futtasd a következő ellenőrzéseket:

- Composer tesztparancsok;
- Pest;
- PHPStan / Larastan;
- Laravel Pint ellenőrzés;
- egyéb meglévő statikus elemzők;
- biztonságos, lokális tesztparancsok.

A futtatás előtt ellenőrizd, hogy a parancs nem módosít-e projektfájlokat, nem futtat-e migrációt valós adatbázison, és nem hajt-e végre külső rendszerre irányuló műveletet.

Ne telepíts és ne frissíts függőségeket az audit során.

Ha egy ellenőrzés nem futtatható, dokumentáld az okát. Ne állítsd, hogy sikeres volt.

---

## 9. Dokumentáció és karbantarthatóság

Vizsgáld meg:

- README teljességét;
- telepítési leírást;
- konfigurációs dokumentációt;
- API-dokumentációt;
- példakódokat;
- publikus osztályok és metódusok dokumentáltságát;
- changelogot;
- verziózási gyakorlatot;
- hibakezelési és üzemeltetési dokumentációt.

Ellenőrizd, hogy a dokumentáció megfelel-e a tényleges implementációnak.

Keresd az elavult, félrevezető vagy hiányos leírásokat.

---

## 10. PHP és Laravel kompatibilitás

A csomag célkörnyezete PHP 8.4 és Laravel 13.

Ellenőrizd:

- a Composer verziókorlátokat;
- a ténylegesen használt PHP-funkciókat;
- a Laravel API-k kompatibilitását;
- a függőségek verziókorlátait;
- az esetleges elavult vagy deprecated API-kat;
- a csomag telepíthetőségét tiszta Laravel 13 környezetben;
- a csomag más Laravel alkalmazásokba történő integrálhatóságát.

Ne csak azt vizsgáld, hogy a jelenlegi fejlesztői környezetben működik-e.

A csomagnak a deklarált verziókövetelményekkel összhangban kell lennie.

---

## 11. Második fejlesztési fázisra való felkészültség

A Waypost későbbi fejlesztési fázisaiban további funkciók jelenhetnek meg, például SOS, élő helymeghatározás, diszpécseri funkciók és valós idejű riasztások.

Ezeket most nem kell implementálni.

Az audit során azonban vizsgáld meg, hogy a jelenlegi architektúra:

- nem zárja-e ki indokolatlanul a későbbi bővítést;
- megfelelően elkülöníti-e a felelősségi köröket;
- lehetővé teszi-e új eseménytípusok bevezetését;
- alkalmas-e további API-funkciók integrálására;
- nem tartalmaz-e olyan szoros összekapcsolásokat, amelyek később jelentős refaktorálást igényelnének.

Ne minősíts hibának olyan funkció hiányát, amely a jelenlegi specifikáció szerint csak a következő fejlesztési fázis része.

---

## 12. Hibák súlyossági besorolása

Minden feltárt problémát az alábbi kategóriák egyikébe sorolj.

- **CRITICAL** – súlyos biztonsági hiba, adatvesztés, adatszivárgás, adatmanipuláció vagy a rendszer kritikus működését veszélyeztető hiba.
- **HIGH** – jelentős funkcionális, architekturális vagy biztonsági probléma, amely éles működés során komoly hibát okozhat.
- **MEDIUM** – valós, javítandó probléma, amely korlátozottabb működési, karbantarthatósági vagy teljesítménybeli kockázatot jelent.
- **LOW** – kisebb minőségi, konzisztencia- vagy karbantarthatósági hiányosság.
- **INFO** – észrevétel, fejlesztési lehetőség vagy nem feltétlenül javítandó eltérés.

Minden megállapításnál tüntesd fel:

1. Azonosító.
2. Súlyosság.
3. Rövid, egyértelmű cím.
4. Érintett fájl és pontos sorszám.
5. A probléma részletes leírása.
6. Miért jelent problémát.
7. Milyen következménye lehet.
8. A specifikáció vagy referencia mely elvárásával ütközik.
9. Javasolt javítási irány.
10. A megállapítás bizonyossága: magas, közepes vagy alacsony.

Ne készíts bizonyíték nélküli hibajegyeket.

Ha valamit nem tudsz egyértelműen igazolni, jelöld feltételezésnek vagy további vizsgálatot igénylő kérdésnek.

---

## 13. Az audit eredménye – három külön Markdown-fájl

A vizsgálat végén készítsd el az alábbi három fájlt a `laravel-waypost` repository gyökérkönyvtárában.

### 13.1. AUDIT-REPORT.md

Ez legyen az átfogó auditjelentés.

Tartalmazza:

- az audit célját és hatókörét;
- a vizsgált forrásokat;
- az alkalmazott módszertant;
- a vizsgálat korlátait;
- a futtatott ellenőrzéseket és azok eredményét;
- az architektúra értékelését;
- a Laravel Package Skeleton összehasonlítás eredményét;
- a kódminőség értékelését;
- a biztonsági értékelést;
- a teljesítmény és skálázhatóság értékelését;
- a tesztelési minőség értékelését;
- a specifikációnak való megfelelés összefoglalását;
- a pozitívumokat;
- a negatívumokat;
- a legfontosabb kockázatokat;
- az összesített szakmai értékelést.

Ne csak a hibákat sorold fel. A jól megoldott, szakmailag értékes részeket is nevezd meg konkrét példákkal.

A jelentés végén adj egyértelmű minősítést:

- **READY** – a csomag megfelelő minőségű, éles használatra alkalmas;
- **READY WITH CONDITIONS** – éles használatra alkalmas lehet, de meghatározott javítások szükségesek;
- **NOT READY** – jelenlegi állapotában nem javasolt éles használatra.

A minősítést indokold meg.

### 13.2. AUDIT-ISSUES.md

Ez tartalmazza az összes feltárt hibát és hiányosságot.

Csoportosítsd súlyosság szerint:

1. CRITICAL
2. HIGH
3. MEDIUM
4. LOW
5. INFO

Minden hibánál szerepeljen az előző fejezetben meghatározott összes információ.

A hibákat úgy dokumentáld, hogy egy későbbi fejlesztési feladatként közvetlenül felhasználhatók legyenek.

Ne mosd össze az egymástól független problémákat.

Ha több hiba ugyanabból az architekturális okból ered, ezt jelezd, de az egyes konkrét előfordulásokat is dokumentáld.

A dokumentum végén legyen egy javítási prioritási lista.

### 13.3. AUDIT-COMPLIANCE.md

Ez legyen a fejlesztői specifikáció tételes megfelelőségi mátrixa.

Minden releváns követelményt külön sorban vizsgálj.

Használd az alábbi státuszokat:

- **PASS** – a követelmény igazoltan teljesül;
- **PARTIAL** – részben teljesül;
- **FAIL** – nem teljesül;
- **NOT TESTED** – nem sikerült ellenőrizni;
- **NOT APPLICABLE** – nem releváns a Laravel backend szempontjából.

Minden sornál szerepeljen:

- követelmény azonosítója vagy megnevezése;
- követelmény rövid leírása;
- státusz;
- érintett fájlok;
- bizonyíték;
- megjegyzés vagy eltérés.

Ne használj PASS státuszt pusztán azért, mert találtál egy, a funkcióra utaló osztályt vagy metódust. A tényleges működést is igazolni kell.

A Laravel Package Skeleton megfelelőségét külön szekcióban értékeld, ne keverd össze a Waypost funkcionális követelményeivel.

---

## 14. Az audit végrehajtásának szabályai

1. Először térképezd fel a teljes repository-t.
2. Olvasd el a Composer-konfigurációt, a dokumentációt és a projektutasításokat.
3. Azonosítsd a fő architekturális rétegeket és a függőségi kapcsolatokat.
4. Olvasd el a fejlesztői specifikációt.
5. Vizsgáld meg a Laravel Package Skeleton aktuális változatát.
6. Végezd el a funkcionális, architekturális, biztonsági és minőségi vizsgálatokat.
7. Futtasd a biztonságosan futtatható ellenőrzéseket.
8. A megállapításokat bizonyítékokkal támaszd alá.
9. Készítsd el a három auditfájlt.
10. Ellenőrizd, hogy a három dokumentum egymással összhangban van-e.

**További kötelező szabályok:**

- Ne módosítsd a forráskódot.
- Ne javítsd ki az audit során talált hibákat.
- Ne telepíts vagy frissíts csomagokat.
- Ne módosítsd a Composer lock fájlt.
- Ne futtass éles adatbázist érintő migrációkat.
- Ne módosíts külső rendszerekben adatokat.
- Ne készíts automatikus refaktorálást.
- Ne hagyj ki fájlokat pusztán azért, mert első ránézésre nem tűnnek fontosnak.
- Ne állítsd, hogy egy ellenőrzés sikeres volt, ha nem futtattad le.
- Ne találj ki hiányzó specifikációs követelményeket.
- Ne tekints minden eltérést hibának.
- Ne enyhíts egy problémát csak azért, mert a jelenlegi tesztek sikeresek.
- Ne készíts felesleges refaktorálási javaslatokat valós probléma nélkül.

Ha az audit során olyan problémát találsz, amelynek megítéléséhez hiányzik egy információ, dokumentáld a bizonytalanságot. Ne állítsd le emiatt a teljes auditot.

---

## 15. Végső összefoglaló

A három fájl elkészítése után a terminálban is adj rövid összefoglalót:

- az audit összesített minősítése;
- a feltárt CRITICAL, HIGH, MEDIUM, LOW és INFO hibák száma;
- a legfontosabb öt probléma;
- a legfontosabb öt pozitívum;
- a nem ellenőrizhető területek;
- a három elkészült dokumentum elérési útja.

**A cél egy őszinte, szigorú, bizonyítékokon alapuló szakmai audit. Nem az implementáció igazolása a feladatod, hanem annak megállapítása, hogy valóban jó minőségű-e.**
