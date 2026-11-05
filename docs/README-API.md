# Heterodyna Cloud Platform — API v03.00.167

## Zakres
Plik `openapi-inventory.json` przedstawia **inwentaryzację**, nie kompletną specyfikację kontraktów API. Został wygenerowany z wywołań `register_rest_route` w `includes/*.php`. Zawiera istniejące ścieżki i metody odnalezione w źródłach. Nie dokumentuje jeszcze struktur żądań, kodów odpowiedzi ani wszystkich opcji runtime. `security` jest wskazówką dotyczącą sposobów uwierzytelniania, a nie obietnicą, że dana metoda je obsługuje. Nie należy traktować wartości `x-permission-callback-public` jako autoryzacji operacji: logika uwierzytelnienia może działać w handlerze.

## Bezpieczeństwo
Nie udostępniaj w publicznym katalogu szczegółów administratora, jeśli nie chcesz ujawniać metadanych API. Dokument jest dystrybuowany w plikach wtyczki, ale NIE dodaje nowego publicznego endpointu. Przed publikacją stabilnego API: ręczny audyt każdego handlera, testy autoryzacji, testy separacji tenantów, sprawdzenie limitów i dokumentacja payloadów.

## Rozwój
Użyj `python3 tools/generate-openapi-inventory.py` do wygenerowania inwentaryzacji. `python3 tests/test_openapi_inventory.py` sprawdza zgodność udokumentowanych tras ze źródłem i brak przypadkowego pominięcia. Generator nie wykonuje PHP i nie potrzebuje instalacji WordPress.
