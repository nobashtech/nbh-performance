# NBH Performance translations

NBH Performance bundles the canonical `nbh-performance.pot` template and 16 locale packs.

## Bundled locales

- Bengali (`bn_BD`) — broad-coverage translation; native copy review still required.
- Spanish (`es_ES`)
- French (`fr_FR`)
- German (`de_DE`)
- Portuguese, Brazil (`pt_BR`)
- Italian (`it_IT`)
- Dutch (`nl_NL`)
- Turkish (`tr_TR`)
- Russian (`ru_RU`)
- Arabic (`ar`, RTL)
- Hindi (`hi_IN`)
- Urdu (`ur`, RTL)
- Indonesian (`id_ID`)
- Japanese (`ja`)
- Korean (`ko_KR`)
- Simplified Chinese (`zh_CN`)

WordPress loads `nbh-performance-<locale>.mo` for PHP/admin strings and the hashed JSON catalogs for JavaScript handles. PO files are included as editable translator sources.

The 15 newly added locales are intentionally marked `starter-core-ui`: core navigation, status, recovery, licensing and common action labels are translated. Long technical descriptions safely fall back to English. Every bundled locale must pass native-speaker release QA before it is marketed as a complete translation. Never replace untranslated entries with unreviewed machine-generated text.

Preserve printf placeholders such as `%s`, `%1$s`, HTML tags, URLs, file paths and product names exactly. Translation coverage and tier information are recorded in `translation-status.json`.
