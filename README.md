# MC-EMS – Exam Session Management for WordPress & Tutor LMS

MC-EMS è un plugin WordPress avanzato che trasforma Tutor LMS in un vero e proprio sistema di gestione degli esami. Il plugin permette di organizzare sessioni d'esame, gestire le prenotazioni degli studenti e controllare automaticamente l'accesso agli esami in base alla presenza di una prenotazione valida.

Grazie a MC-EMS è possibile strutturare esami programmati tramite sessioni calendarizzate, offrire un sistema di prenotazione intuitivo e gestire tutte le prenotazioni da un unico pannello amministrativo.

## Ideale per

- Enti di certificazione
- Università e accademie
- Piattaforme e‑learning basate su Tutor LMS
- Scuole e centri di formazione professionale
- Organizzazioni che gestiscono esami programmati

## Funzionalità principali

### Gestione delle Sessioni di Esame

MC-EMS introduce un Custom Post Type dedicato alle sessioni di esame (mcems_exam_session). Ogni sessione include data, orario, esame associato, capienza e configurazioni operative. Le sessioni sono gestibili direttamente dal pannello WordPress tramite un'interfaccia semplice e intuitiva.

### Prenotazione Esami tramite Calendario

**Shortcode**: `[mcems_book_exam]`

Gli studenti possono prenotare una sessione di esame tramite un calendario interattivo che mostra tutte le sessioni disponibili filtrate per data.

### Gestione della Prenotazione da parte dello Studente

**Shortcode**: `[mcems_manage_booking]`

Gli studenti possono visualizzare e gestire la propria prenotazione, controllare data e ora dell'esame, vedere i dettagli dell'esame associato e annullare la prenotazione quando consentito.

### Gestione Prenotazioni con Ricerca ed Export CSV

**Shortcode**: `[mcems_bookings_list]`

- Elenco completo delle prenotazioni
- Ricerca per data o intervallo di date
- Filtri per esame, candidato e stato
- Esportazione delle prenotazioni in CSV
- Visualizzazione esigenze speciali dei candidati
- Informazioni sul proctor assegnato

### Calendario Amministrativo delle Sessioni

**Shortcode**: `[mcems_sessions_calendar]`

Il calendario amministrativo permette di visualizzare tutte le sessioni d'esame, controllare i posti disponibili, assegnare i proctor e monitorare lo stato delle prenotazioni.

### Controllo Accesso agli Esami con Tutor LMS

MC-EMS integra un sistema di access gate che blocca automaticamente l'accesso all'esame finché lo studente non possiede una prenotazione valida per una sessione disponibile.

Quando l'accesso è bloccato:
- L'esame rimane inaccessibile
- Viene visualizzato il messaggio "Exam locked"
- Il contenuto dell'esame viene nascosto
- Lo studente riceve istruzioni per prenotare una sessione

### Sistema di Impostazioni Configurabili

- Configurazione delle pagine di prenotazione
- Gestione anticipo minimo per prenotazioni
- Gestione cancellazione prenotazioni
- Integrazione con Tutor LMS
- Personalizzazione dei messaggi del sistema
- Gestione dei permessi amministrativi

## Versione Base vs Versione Premium

| Funzionalità | Base | Premium |
|---|---|---|
| Sessioni di esame | ✓ | ✓ |
| Prenotazione esami | ✓ | ✓ |
| Calendario prenotazioni | ✓ | ✓ |
| Gestione prenotazione utente | ✓ | ✓ |
| Lista prenotazioni | ✓ | ✓ |
| Export CSV prenotazioni | ✓ | ✓ |
| Calendario amministrativo sessioni | ✓ | ✓ |
| Assegnazione proctor | ✓ | ✓ |
| Integrazione Tutor LMS | ✓ | ✓ |
| **Sessioni illimitate** | — | ✓ |
| **Capienza sessioni** | fino a 5 posti | fino a 500 posti |
| **Multipli slot per giorno** | — | ✓ |
| **Supporto prioritario** | — | ✓ |

## Requisiti

- WordPress 6.0 o superiore
- PHP 7.0 o superiore
- Tutor LMS installato per l'integrazione con gli esami

## Installazione

1. Scarica il plugin dal WordPress Repository
2. Attiva il plugin dalla sezione Plugin di WordPress
3. Configura le impostazioni nella sezione MC‑EMS
4. Crea le sessioni di esame
5. Inserisci gli shortcode nelle pagine del sito