# Heterodyna Cloud v03.00.159 — testy integracyjne (WP-CLI)

Test jest **tylko do odczytu**: nie wykonuje migracji, nie uruchamia resetu bazy ani nie tworzy testowych klientów.

## Wymagania
- oddzielna instalacja WordPress + MySQL/MariaDB na stagingu
- WP-CLI dostępny w katalogu WordPressa
- zainstalowana i aktywna wtyczka Heterodyna Cloud v03.00.159
- kopia zapasowa przed aktualizacją wtyczki

## Uruchomienie
Z katalogu głównego WordPressa:

```bash
wp eval-file wp-content/plugins/heterodyna-cloud-platform/tests/integration-smoke.php
```

Jeśli katalog wtyczki ma inną nazwę, zmień ścieżkę w poleceniu.

Skrypt sprawdza: bootstrap, numer wydania, obecność klas IAM/subskrypcji/zużycia/kolejki/alertów, obecność i odczyt tabel, shortcode portalu oraz podstawowe API WordPress.

## Interpretacja
`PASS` oznacza pozytywną kontrolę w istniejącym środowisku. `FAIL` wymaga przejrzenia dziennika WordPress/PHP i schematu bazy. Test nie udowadnia izolacji tenantów, poprawności rozliczeń, odporności na ataki ani działania wysyłki poczty. Nie należy uruchamiać testu pod adresem HTTP.

## Regresja offline
```bash
python3 tests/run-offline.py
```

Testy offline są głównie statyczne i nie zastępują integracji.
