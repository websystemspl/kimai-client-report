# ClientReport - raporty czasu pod publicznym linkiem

Plugin do Kimai 2.x. Robi to, czego Kimai nie ma w rdzeniu: **adres, pod którym klient
otwiera raport godzin bez logowania** - jako stronę i jako PDF.

Napisany dla `kimai.web-systems.pl`, ale nie ma w nim niczego związanego z tą jedną
instancją.

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
  do wybranych osób, rodzajów pracy albo tagów.
- **Powiadomienie mailem przy pierwszym otwarciu** linku przez klienta - do osoby,
  która link utworzyła. Kolejne wejścia widać w liczniku, maila nie ma.
- **Przycisk „Udostępnij klientowi" na ekranie Eksportu Kimai**, z przeniesieniem
  ustawionych tam filtrów (zakres dat, projekt lub klient, płatność, osoby, aktywności).
- Logo firmy na stronie i w PDF.

Repo: <https://github.com/websystemspl/kimai-client-report> (publiczne, MIT).
Wydanie z paczką ZIP: `releases/tag/v1.0.0`.

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

Menu **Raportowanie > Raporty dla klientów** (widoczne od roli teamlead w górę).
„Nowy link" pyta o projekt albo klienta, zakres dat, język i to, czy pokazywać wpisy
nieodpłatne. Domyślny zakres to bieżący tydzień.

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
Report/Labels.php               teksty EN/PL (za mało na katalog tłumaczeń)
Report/Branding.php             logo i nazwa firmy
Report/ShareNotifier.php        mail przy pierwszym otwarciu linku
Form/SharedReportType.php       formularz tworzenia linku
Controller/ShareController.php  strona publiczna i PDF
Controller/SharedReportController.php  panel: lista, tworzenie, unieważnianie
EventSubscriber/MenuSubscriber.php       pozycja w menu
EventSubscriber/ExportPageSubscriber.php przycisk na ekranie Eksportu Kimai
Migrations/                     tabele: raporty + trzy tabele filtrów
Resources/assets/logo.png       logo wstawiane do raportu
Resources/views/public/         strona dla klienta i szablon PDF
Resources/views/admin/          lista i formularz w panelu
```

## Czego jeszcze nie ma

- Wielu projektów w jednym raporcie (jest albo jeden projekt, albo cały klient).
- Konfiguracji marki z panelu - logo i nazwa firmy siedzą w plikach pluginu.
- Stawek i kwot; raport pokazuje wyłącznie czas.
- Podglądu raportu z poziomu panelu przed wysłaniem linku (trzeba otworzyć link).
