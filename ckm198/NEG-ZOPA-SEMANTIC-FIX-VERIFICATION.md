# NEG-ZOPA semantic fix verification

Build: `0.3.23.309-dev.341-NEG-ZOPA-SEMANTIC-FIX`

Проверяемые инварианты:
- ZOPA не заявляет сравнение с BATNA без формализованной модели альтернативы.
- Технически допустимый пакет за пределами границы участника имеет статус `outside_player_boundary`.
- No-deal классификация по-прежнему может вернуть `rational_walkaway`, но основание — нарушение учебной границы, а не мнимое сравнение с BATNA.
- Live snapshot и скрытые данные не меняются.
- NEG DB: `1.8.0`; content: `1.4.0`.
