# ClientReport - raporty czasu pod publicznym linkiem

Plugin do Kimai 2.x. Robi to, czego Kimai nie ma w rdzeniu: **adres, pod którym klient
otwiera raport godzin bez logowania** - jako stronę i jako PDF. Od 1.3.0 także
**wewnętrzny raport pracy** (kto nad czym pracował, osoba albo zespół, dowolny okres,
z PDF), **API JSON** do obu i **serwer MCP** dla agentów, które piszą raporty.

Napisany dla `kimai.web-systems.pl`, ale nie ma w nim niczego związanego z tą jedną
instancją.

[English README](README.md)

## Co daje

- **Publiczny link z tokenem**: `https://<kimai>/share/<32 znaki hex>`. Klient nie ma
  konta w Kimai i nie musi mieć.
- **PDF pod tym samym linkiem**: `/share/<token>/pdf`, ta sama treść, układ do wysłania.
- **Płatne i nieodpłatne na jednej kartce.** Podsumowanie zawsze liczy jedne i drugie,
  więc klient widzi „65:43 (75,41%) z 87:09", a nie krótszą listę bez wyjaśnienia,
  czego brakuje. Wiersze nieodpłatne dostają plakietkę „no charge". Można je ukryć
  w tabeli - podsumowanie i tak zostaje pełne.
- **Unieważnianie i data ważności.** Unieważniony albo wygasły link zwraca 404, tak
  samo jak literówka w tokenie - z zewnątrz nie da się odróżnić jednego od drugiego,
  więc nie ma jak sondować przestrzeni adresów.
- **Licznik wejść** z datą ostatniego otwarcia.
- **Dwa języki raportu** (angielski, polski), wybierane przy tworzeniu linku - klient
  zagraniczny dostaje angielski niezależnie od ustawień konta, które link stworzyło.
- Zakres: jeden projekt albo cały klient, dowolny zakres dat, opcjonalnie zawężony
  do wybranych osób, rodzajów pracy albo tagów. Raport dla całego klienta może pominąć
  wybrane projekty, np. ryczałtowy, fakturowany osobno.
- **Powiadomienie mailem przy pierwszym otwarciu** linku przez klienta - do osoby,
  która link utworzyła. Kolejne wejścia widać w liczniku, maila nie ma.
- **Przycisk „Udostępnij klientowi" na ekranie Eksportu Kimai**, z przeniesieniem
  ustawionych tam filtrów (zakres dat, projekt lub klient, płatność, osoby, aktywności).
- Logo firmy na stronie i w PDF.

Repo: <https://github.com/websystemspl/kimai-client-report> (publiczne, MIT).
Wydanie z paczką ZIP: `releases/tag/v1.2.0`.

## Raport pracy (wewnętrzny, od 1.3.0)

Menu **Raportowanie > Raport pracy**. Kto, nad czym i ile pracował: jedna osoba, kilka,
zespół albo wszyscy, za dzień, tydzień, miesiąc albo dowolny zakres do 366 dni:

- skróty okresów (dziś, wczoraj, ten/poprzedni tydzień, miesiąc, rok) i dowolne daty,
- filtry: osoby, zespoły, klienci, projekty, rodzaje pracy, tagi, płatność,
- sumy (razem, płatne, nieodpłatne, wpisy, dni z czasem),
- siatka osoby × dni (do 31 dni),
- godziny wg projektu (z tym, kto nad nim siedział) i wg rodzaju pracy,
- **ostrzeżenia**: nakładające się wpisy jednej osoby, liczniki w toku, wpisy bez opisu,
  wpisy ponad 10 h, dni ponad 12 h, wpisy przez północ,
- każdy wpis z opisem, pogrupowany po osobie i dniu,
- **Pobierz PDF** z tą samą treścią (poziomo, gdy siatka jest szersza niż tydzień).

Kto co widzi: każdy z dostępem do raportów. Osoba bez prawa do cudzych czasów widzi
tylko swój czas, teamlead swoje zespoły, admin wszystkich.

Zasady liczenia, te same w panelu, PDF i API:

- czasy to pełne sekundy, `h:mm` jest obcinane do pełnych minut,
- wpis liczy się w dniu, w którym się zaczął, w strefie raportu (domyślnie oglądającego),
- wpisy w toku są ostrzeżeniem i **nie** wchodzą do żadnej sumy,
- każdy podział (osoby, dni, projekty, klienci, rodzaje pracy, wpisy) jest sumowany
  ponownie i porównywany z sumą całkowitą, zanim raport się pokaże. Różnica jednej
  sekundy blokuje raport zamiast pokazać złe liczby.

## API

Trasy siedzą pod `/api` Kimai i korzystają z jego logowania:
`Authorization: Bearer <token API>` (Kimai > Profil > API). Pełny opis parametrów,
odpowiedzi i przykładów jest w [README.md](README.md#api), tu skrót:

```
GET    /api/work-report?period=this_week&users=j.doe
GET    /api/work-report?begin=2026-10-01&end=2026-10-31&teams=1&billable=1&entries=0
GET    /api/work-report/pdf?period=last_month&locale=pl
GET    /api/client-reports                      lista linków dla klientów
POST   /api/client-reports                      nowy link (JSON jak formularz)
GET    /api/client-reports/{id}/data            liczby, które zobaczy klient
DELETE /api/client-reports/{id}[?purge=1]       unieważnienie [usunięcie]
POST   /api/client-reports/{id}/restore         przywrócenie
```

Parser jest ścisły: nieznane id, niemożliwa data, koniec przed początkiem, zakres ponad
366 dni albo osoba, której pytający nie może widzieć, to błąd 400/403/404, nigdy po
cichu okrojony raport.

## Serwer MCP dla agentów

`mcp/kimai-mcp.mjs`: serwer MCP (stdio, Node 18+, bez zależności) na tym API i API
Kimai. Pod agenta, który robi raporty dla Ciebie i klientów, więc zbudowany tak, żeby
agent niczego nie dodawał sam: sumy przychodzą gotowe z `/api/work-report`, a pierwszy
blok każdej odpowiedzi to tekst do zacytowania. Osoby, klientów i projekty można podać
po nazwie; nazwa musi wskazać dokładnie jedną rzecz, inaczej narzędzie zwraca błąd
z listą kandydatów zamiast zgadywać. Instalacja i lista narzędzi: [README.md](README.md#mcp-server-for-agents).

## Testy

`tests/verify-work-report.mjs` (raport kontra surowe `/api/timesheets`, każda osoba
i okres), `tests/verify-api-contract.mjs` (walidacja, uprawnienia, linki, PDF),
`tests/verify-mcp.mjs` (serwer MCP od początku do końca). Uruchomienie w README.md.

## Instalacja

Rozpakuj `ClientReportBundle.zip` z wydania do `var/plugins/`, albo z kopii roboczej:

```
cp -r kimai-client-report <kimai>/var/plugins/ClientReportBundle
cd <kimai>
bin/console cache:clear --env=prod
bin/console doctrine:migrations:migrate --no-interaction
```

Katalog **musi** nazywać się `ClientReportBundle` - Kernel szuka katalogów `*Bundle`
i składa z tego nazwę klasy.

`var/plugins/` jest poza repozytorium Kimai, więc plugin przeżywa `git checkout <tag>`.
Po aktualizacji Kimai wystarczy `cache:clear`.

## Użycie

Menu **Raportowanie > Raporty dla klientów** (po angielsku *Reporting > Client reports*),
widoczne od roli teamlead w górę. **Nowy link** (*New link*) pyta o projekt albo klienta, zakres dat, język i to, czy pokazywać wpisy
nieodpłatne. Domyślny zakres to bieżący tydzień.

Panel mówi językiem zalogowanego użytkownika (w pluginie są tłumaczenia angielskie
i polskie, pozostałe języki dostają angielski). Język raportu dla klienta ustawia się
osobno przy każdym linku.

Podział na płatne i nieodpłatne bierze się wprost z pola „Płatne" przy wpisie w Kimai -
raport niczego nie liczy po swojemu. Trzeba to więc ustawić **przed** wysłaniem linku.

## Branding

Logo siedzi w `Resources/assets/logo.png`, wczytywane przez `Report/Branding.php`
i wstawiane jako data URI - strona musi działać dla kogoś niezalogowanego, a mPDF
rysuje PDF ze samodzielnego stringa HTML, więc trasa serwująca plik nic by tu nie dała.

Wersja z web-systems.pl jest biała (do ciemnego tła strony firmowej), a raport ma tło
jasne, więc w `logo.png` leży ta sama grafika przebarwiona na kolor tekstu raportu
(`#1f2430`; logo jest jednokolorowe, więc wystarczyła podmiana RGB przy zachowaniu
kanału alfa). Oryginał zostaje obok jako `logo-white-original.png`.

Żeby zmienić markę: podmień `Resources/assets/logo.png` i stałe `COMPANY` oraz
`LOGO_FILE` w `Report/Branding.php`.

To logo dotyczy tylko raportu. Logo panelu Kimai jest osobne i siedzi w konfiguracji
systemowej pod kluczem `theme.branding.logo` (System > Ustawienia > Branding),
jako adres pliku.

## Jak to działa

- **Dlaczego link nie wymaga logowania.** W `config/packages/security.yaml` reguły
  obejmują `^/{_locale}/` i `^/api`. Trasy `/share/...` są poza jednym i drugim, więc
  nie trzeba ruszać konfiguracji Kimai (co i tak nie przeżyłoby aktualizacji).
- **Dlaczego zapytanie nie ma bieżącego użytkownika.** `TimesheetRepository` pomija
  filtrowanie po zespołach, gdy nie ma zalogowanego użytkownika („make sure that all
  queries without a user see all projects"). To jest tu poprawne: o tym, co klient widzi,
  zdecydowała osoba tworząca link.
- **Formularz używa typów Kimai** (`ProjectType`, `CustomerType`, `DatePickerType`).
  Motyw Kimai podpina do każdego pola daty własny picker, który oczekuje zlokalizowanego
  widżetu tekstowego - zwykły `DateType` renderuje `<input type="date">` i picker czyści
  wtedy wartość początkową.
- **`ProjectType` dostaje `ignore_date: true`**, bez tego projekty bez ustawionego
  zakresu dat nie pojawiają się na liście.
- **Migracja pluginu** rejestruje się sama: rozszerzenie DI dokłada swój katalog do
  `doctrine_migrations.migrations_paths`.
- **PDF** powstaje przez `App\Pdf\HtmlToPdfConverter` (mPDF), z osobnego szablonu -
  mPDF nie zna flexboksa ani grida, więc układ tam stoi na tabelach.
- **Godziny renderowane są w strefie wpisu** (`|date('H:i', false)`), a nie w domyślnej
  strefie procesu. Dla niezalogowanego gościa ta domyślna to UTC, więc bez tego klient
  widziałby wszystko o dwie godziny za wcześnie, mimo że zalogowanemu strona pokazywała
  poprawne wartości. Każdy wpis Kimai niesie własną strefę, więc `false` jest tu
  właściwą odpowiedzią.
- **Przycisk na ekranie Eksportu** podpina się pod ogólne zdarzenie motywu
  `ThemeEvent::CONTENT_START`, bo kontroler eksportu nie ustawia `actionName`, więc
  `PageActionsEvent` dla tej strony w ogóle nie leci. Nadpisanie szablonu odpadło -
  plik należy do repozytorium Kimai i łatka ginęłaby przy aktualizacji.
- **Daty z paska filtrów** czytane są formatem locale'u żądania, z listą zapasowych
  formatów; każdy kandydat musi się zgadzać po powrotnym sformatowaniu, więc format,
  który tylko przypadkiem coś sparsuje, jest odrzucany.

## Struktura

```
ClientReportBundle.php          klasa pluginu (getName/getPath są final w Bundle)
DependencyInjection/            ładowanie usług + rejestracja katalogu migracji
Entity/SharedReport.php         udostępniony raport: token, zakres, ważność, licznik
Repository/                     dostęp do bazy
Report/ReportBuilder.php        pobranie wpisów i policzenie sum
Report/ReportData.php           model widoku
Report/Labels.php               teksty EN/PL raportu dla klienta, wybierane per link
Report/Branding.php             logo i nazwa firmy
Report/ShareNotifier.php        mail przy pierwszym otwarciu linku
Form/SharedReportType.php       formularz tworzenia linku
Controller/ShareController.php  strona publiczna i PDF
Controller/SharedReportController.php  panel: lista, tworzenie, unieważnianie
Controller/WorkReportController.php    panel: raport pracy i jego PDF
Controller/Api/                 /api/work-report i /api/client-reports
Report/SharedReportManager.php  jedna ścieżka tworzenia linku dla panelu i API
WorkReport/                     parser zapytania, liczenie sum i ostrzeżeń, PDF
Resources/views/work/           strona raportu pracy i szablon PDF
mcp/kimai-mcp.mjs               serwer MCP dla agentów (nie wgrywa się do Kimai)
tests/                          skrypty weryfikujące na żywej instancji
EventSubscriber/MenuSubscriber.php       pozycja w menu
EventSubscriber/ExportPageSubscriber.php przycisk na ekranie Eksportu Kimai
Migrations/                     tabele: raporty + tabele filtrów
Resources/assets/logo.png       logo wstawiane do raportu
Resources/views/public/         strona dla klienta i szablon PDF
Resources/views/admin/          lista i formularz w panelu
Resources/translations/         teksty panelu (domena client_report, EN/PL)
```

## Czego jeszcze nie ma

- Wielu projektów w jednym raporcie (jest albo jeden projekt, albo cały klient).
- Konfiguracji marki z panelu - logo i nazwa firmy siedzą w plikach pluginu.
- Stawek i kwot w raporcie dla klienta; pokazuje wyłącznie czas (raport pracy przez API
  zwraca kwoty, jeśli w Kimai są ustawione stawki).
- Podglądu raportu z poziomu panelu przed wysłaniem linku (trzeba otworzyć link).
